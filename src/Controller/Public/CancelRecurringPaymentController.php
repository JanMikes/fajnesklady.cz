<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\CancelRecurringPaymentCommand;
use App\Repository\ContractRepository;
use App\Service\Billing\CardPaymentStopSummaryFactory;
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

#[Route('/opakovana-platba/{contractId}/zrusit', name: 'public_cancel_recurring_payment', requirements: ['contractId' => '[0-9a-f-]{36}'])]
final class CancelRecurringPaymentController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly UriSigner $uriSigner,
        private readonly LoggerInterface $logger,
        private readonly CardPaymentStopSummaryFactory $summaryFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request, string $contractId): Response
    {
        if (!$this->uriSigner->checkRequest($request)) {
            // Plain 403 like the other signed public routes — a security
            // AccessDeniedException would bounce the tenant to /login.
            throw new AccessDeniedHttpException('Neplatný nebo expirovaný odkaz.');
        }

        $contract = $this->contractRepository->get(Uuid::fromString($contractId));

        if (!$contract->hasActiveRecurringPayment()) {
            return $this->render('public/cancel_recurring_payment.html.twig', [
                'contract' => $contract,
                'alreadyCancelled' => true,
                'summary' => $this->summaryFactory->create($contract, $this->clock->now()),
            ]);
        }

        if ($request->isMethod('POST')) {
            $changeCard = 'change_card' === $request->request->getString('action');

            try {
                // Stopping the card keeps the lease and its billing schedule
                // (Podmínky opakovaných plateb čl. VI) — the remainder moves to
                // bank transfer until the tenant sets up a new card.
                $this->commandBus->dispatch(new CancelRecurringPaymentCommand($contract));
            } catch (\Throwable $e) {
                $this->logger->error('Failed to cancel recurring payment via email link', ['exception' => $e]);

                return $this->render('public/cancel_recurring_payment.html.twig', [
                    'contract' => $contract,
                    'error' => true,
                ]);
            }

            $summary = $this->summaryFactory->create($contract, $this->clock->now());

            // Card change: the old token is gone, send the tenant straight to
            // the card-setup page (spec 077 compliance surface + consent).
            if ($changeCard && null !== $summary->cardSetupUrl) {
                return $this->redirect($summary->cardSetupUrl);
            }

            return $this->render('public/cancel_recurring_payment.html.twig', [
                'contract' => $contract,
                'success' => true,
                'summary' => $summary,
            ]);
        }

        return $this->render('public/cancel_recurring_payment.html.twig', [
            'contract' => $contract,
            'summary' => $this->summaryFactory->create($contract, $this->clock->now()),
        ]);
    }
}
