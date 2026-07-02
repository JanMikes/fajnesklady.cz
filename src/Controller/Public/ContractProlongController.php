<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\ProlongContractCommand;
use App\Entity\Contract;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Repository\ContractRepository;
use App\Repository\PlatformSettingsRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Service\OrderStatusUrlGenerator;
use App\Service\StorageAvailabilityChecker;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 077 — customer-facing prolongation of an existing contract. Reached
 * from the expiration-reminder e-mail (signed link), the portal order detail
 * (authenticated owner) and the public /stav page (signed link). All
 * mutations go through ProlongContractCommand — the strategy seam for a
 * future "create follow-up contract" model.
 */
#[Route('/smlouva/{contractId}/prodlouzit', name: 'public_contract_prolong', requirements: ['contractId' => '[0-9a-f-]{36}'])]
final class ContractProlongController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly StorageAvailabilityChecker $availabilityChecker,
        private readonly OrderStatusUrlGenerator $statusUrlGenerator,
        private readonly PlatformSettingsRepository $platformSettingsRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly UriSigner $uriSigner,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request, string $contractId): Response
    {
        $contract = $this->contractRepository->get(Uuid::fromString($contractId));

        // Owner OR HMAC signature — mirrors OrderRenewController: the page
        // exposes contract details, so a guessed id must not open it, while
        // the signed e-mail link keeps working for passwordless customers.
        $currentUser = $this->getUser();
        $isOwner = $currentUser instanceof User && $contract->user->id->equals($currentUser->id);
        if (!$isOwner && !$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException('Neplatný nebo expirovaný odkaz.');
        }

        $now = $this->clock->now();
        $today = $now->setTime(0, 0);

        $baseContext = [
            'contract' => $contract,
            'order' => $contract->order,
            'storage' => $contract->storage,
            'place' => $contract->storage->getPlace(),
            'statusUrl' => $this->statusUrlGenerator->generate($contract->order),
        ];

        // Ended/terminated → the storage may already be someone else's;
        // continuation means a fresh order (the spec-014 renew flow).
        if ($contract->isTerminated() || $contract->hasPendingTermination() || $contract->endDate < $today) {
            return $this->render('public/contract_prolong.html.twig', $baseContext + [
                'state' => 'ended',
                'newOrderUrl' => $this->statusUrlGenerator->generateRenewal($contract->order),
            ]);
        }

        if (OrderStatus::COMPLETED !== $contract->order->status) {
            throw $this->createNotFoundException('Smlouva nenalezena.');
        }

        // A deactivated place is pulled from the market entirely — existing
        // tenants must not be able to extend there either (mirrors the
        // place-active gate in OrderRenewController / OrderCreateController).
        if (!$contract->storage->getPlace()->isActive) {
            return $this->render('public/contract_prolong.html.twig', $baseContext + [
                'state' => 'place_inactive',
            ]);
        }

        // No prolongation while in arrears — settle first (mirrors the
        // contract terms' no-prolongation-in-arrears principle).
        if ($contract->failedBillingAttempts > 0 || $contract->hasOutstandingDebt()) {
            return $this->render('public/contract_prolong.html.twig', $baseContext + [
                'state' => 'arrears',
            ]);
        }

        // Latest possible new end = day before the earliest booking by someone
        // else after the current end. Live-token card contracts always get an
        // open horizon here — their own open-ended block is excluded.
        $earliestConflict = $this->availabilityChecker->earliestConflictStart(
            $contract->storage,
            $contract->endDate,
            excludeContract: $contract,
            excludeOrder: $contract->order,
        );
        $maxEndDate = null !== $earliestConflict ? $earliestConflict->modify('-1 day') : null;
        $minEndDate = $contract->endDate->modify('+1 day');

        if (null !== $maxEndDate && $maxEndDate < $minEndDate) {
            return $this->render('public/contract_prolong.html.twig', $baseContext + [
                'state' => 'blocked',
                'newOrderUrl' => $this->statusUrlGenerator->generateRenewal($contract->order),
            ]);
        }

        $hasLiveCardRecurring = BillingMode::AUTO_RECURRING === $contract->billingMode
            && null !== $contract->goPayParentPaymentId;
        $canSwitchToCard = !$hasLiveCardRecurring && !$contract->isFree() && !$contract->isYearly();

        $formContext = $baseContext + [
            'state' => 'form',
            'minEndDate' => $minEndDate,
            'maxEndDate' => $maxEndDate,
            'hasLiveCardRecurring' => $hasLiveCardRecurring,
            'canSwitchToCard' => $canSwitchToCard,
            'bankTransferSurchargeInCzk' => $this->platformSettingsRepository->getSettings()->getBankTransferSurchargeInCzk(),
            'submitted' => [],
        ];

        if (!$request->isMethod('POST')) {
            return $this->render('public/contract_prolong.html.twig', $formContext);
        }

        // No CSRF token: csrf_protection is disabled framework-wide (every POST
        // form in this app, incl. ContractTerminateController, runs without it).
        // The signed-URL path is HMAC-protected; the owner path matches the
        // existing portal POST posture.
        $newEndDateRaw = $request->request->getString('new_end_date');
        $paymentChoice = $request->request->getString('payment_choice') ?: 'keep';
        $formContext['submitted'] = ['newEndDate' => $newEndDateRaw, 'paymentChoice' => $paymentChoice];

        $newEndDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $newEndDateRaw) ?: null;
        if (null === $newEndDate || $newEndDate < $minEndDate || (null !== $maxEndDate && $newEndDate > $maxEndDate)) {
            return $this->render('public/contract_prolong.html.twig', $formContext + [
                'error' => 'Zvolte platné datum konce — nejdříve '.$minEndDate->format('d.m.Y').(null !== $maxEndDate ? ', nejpozději '.$maxEndDate->format('d.m.Y') : '').'.',
            ]);
        }

        if (!in_array($paymentChoice, ['keep', 'bank', 'gopay'], true)
            || ('bank' === $paymentChoice && !$hasLiveCardRecurring)
            || ('gopay' === $paymentChoice && !$canSwitchToCard)) {
            return $this->render('public/contract_prolong.html.twig', $formContext + [
                'error' => 'Zvolte platný způsob platby.',
            ]);
        }

        try {
            $this->commandBus->dispatch(new ProlongContractCommand(
                contractId: $contract->id,
                newEndDate: $newEndDate,
                switchTo: 'bank' === $paymentChoice ? PaymentMethod::BANK_TRANSFER : null,
                actorId: $currentUser instanceof User ? $currentUser->id : null,
            ));
        } catch (\Throwable $rawException) {
            $exception = HandlerFailureUnwrap::unwrap($rawException);

            if ($exception instanceof \DomainException) {
                return $this->render('public/contract_prolong.html.twig', $formContext + [
                    'error' => $exception->getMessage(),
                ]);
            }

            $this->logger->error('Contract prolongation failed', [
                'contract_id' => $contractId,
                'exception' => $exception,
            ]);

            return $this->render('public/contract_prolong.html.twig', $formContext + [
                'error' => 'Prodloužení se nepodařilo dokončit. Zkuste to prosím znovu.',
            ]);
        }

        return $this->render('public/contract_prolong.html.twig', $baseContext + [
            'state' => 'success',
            'newEndDate' => $newEndDate,
            'cardSetupUrl' => 'gopay' === $paymentChoice ? $this->statusUrlGenerator->generateCardSetup($contract) : null,
        ]);
    }
}
