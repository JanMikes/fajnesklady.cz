<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\User;
use App\Repository\ContractRepository;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\GoPay\GoPayClient;
use App\Service\OrderStatusUrlGenerator;
use App\Service\PriceCalculator;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 077 — bank→card switch after a prolongation. Full compliance surface:
 * recurring-payment parameter card, dedicated consent checkbox, PCI-DSS
 * disclosure and card/3DS/GoPay logos (see .claude/COMPLIANCE.md). The
 * charge itself replaces the next manual cycle and establishes the ON_DEMAND
 * token via the webhook.
 */
#[Route('/smlouva/{contractId}/prodlouzit/karta', name: 'public_contract_card_setup', requirements: ['contractId' => '[0-9a-f-]{36}'])]
final class ContractCardSetupController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly RecurringAmountCalculator $amountCalculator,
        private readonly OrderStatusUrlGenerator $statusUrlGenerator,
        private readonly GoPayClient $goPayClient,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UriSigner $uriSigner,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request, string $contractId): Response
    {
        $contract = $this->contractRepository->get(Uuid::fromString($contractId));

        $currentUser = $this->getUser();
        $isOwner = $currentUser instanceof User && $contract->user->id->equals($currentUser->id);
        if (!$isOwner && !$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException('Neplatný nebo expirovaný odkaz.');
        }

        $now = $this->clock->now();
        $statusUrl = $this->statusUrlGenerator->generate($contract->order);

        // Recurring track without a live token (mirrors InitiateCardSetupHandler).
        $eligible = $contract->billingMode->isRecurring()
            && null === $contract->goPayParentPaymentId
            && !$contract->isFree()
            && !$contract->isYearly()
            && !$contract->isTerminated()
            && $contract->endDate >= $now->setTime(0, 0);

        if (!$eligible) {
            return $this->render('public/contract_card_setup.html.twig', [
                'contract' => $contract,
                'order' => $contract->order,
                'storage' => $contract->storage,
                'place' => $contract->storage->getPlace(),
                'state' => 'ineligible',
                'statusUrl' => $statusUrl,
            ]);
        }

        $nextBillingDate = $contract->nextBillingDate ?? $contract->paidThroughDate ?? $contract->endDate;
        $amountInHaler = $this->amountCalculator->calculate($contract, $now);

        // Remaining charge count for the "Doba trvání" disclosure: the setup
        // charge itself plus one per cadence step until the contract end.
        $remainingCharges = 1;
        $cursor = $nextBillingDate->modify($contract->getBillingCadenceStep());
        while ($cursor < $contract->endDate) {
            ++$remainingCharges;
            $cursor = $cursor->modify($contract->getBillingCadenceStep());
        }

        return $this->render('public/contract_card_setup.html.twig', [
            'contract' => $contract,
            'order' => $contract->order,
            'storage' => $contract->storage,
            'place' => $contract->storage->getPlace(),
            'state' => 'form',
            'statusUrl' => $statusUrl,
            'amountInCzk' => $amountInHaler / 100,
            'monthlyAmountInCzk' => $contract->getEffectiveRecurringAmount() / 100,
            'nextBillingDate' => $nextBillingDate,
            'remainingCharges' => $remainingCharges,
            'recurringPaymentLegalMaxInCzk' => PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER / 100,
            'goPayEmbedJs' => $this->goPayClient->getEmbedJsUrl(),
            // Signed separately: the page's own signature does not carry to the
            // POST endpoint, and passwordless customers have no session auth.
            'initiateUrl' => $this->uriSigner->sign($this->urlGenerator->generate(
                'public_contract_card_setup_initiate',
                ['contractId' => $contract->id->toRfc4122()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )),
        ]);
    }
}
