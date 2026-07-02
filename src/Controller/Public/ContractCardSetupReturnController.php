<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\ProcessPaymentNotificationCommand;
use App\Repository\ContractRepository;
use App\Service\OrderStatusUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * GoPay return URL for the prolongation card-setup charge (spec 077).
 * Mirrors PaymentReturnController: reconcile synchronously so the customer
 * lands on /stav with the switch already applied when the charge is PAID —
 * the webhook remains the safety net for abandoned tabs.
 */
#[Route('/smlouva/{contractId}/prodlouzit/karta/navrat', name: 'public_contract_card_setup_return', requirements: ['contractId' => '[0-9a-f-]{36}'])]
final class ContractCardSetupReturnController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly OrderStatusUrlGenerator $statusUrlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $contractId): Response
    {
        $contract = $this->contractRepository->get(Uuid::fromString($contractId));

        // No signature check: this route only reconciles an already-created
        // payment by its stored id and redirects to the signed /stav URL —
        // nothing sensitive is rendered here (mirrors PaymentReturnController).
        if (null !== $contract->pendingCardSetupPaymentId) {
            try {
                $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($contract->pendingCardSetupPaymentId));
            } catch (\Throwable $e) {
                $this->logger->error('Card setup return reconciliation failed', [
                    'contract_id' => $contractId,
                    'exception' => $e,
                ]);
            }
        }

        return new RedirectResponse($this->statusUrlGenerator->generate($contract->order));
    }
}
