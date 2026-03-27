<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\CancelRecurringPaymentCommand;
use App\Repository\ContractRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
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
    ) {
    }

    public function __invoke(Request $request, string $contractId): Response
    {
        if (!$this->uriSigner->checkRequest($request)) {
            throw $this->createAccessDeniedException('Neplatný nebo expirovaný odkaz.');
        }

        $contract = $this->contractRepository->get(Uuid::fromString($contractId));

        if (!$contract->hasActiveRecurringPayment()) {
            return $this->render('public/cancel_recurring_payment.html.twig', [
                'contract' => $contract,
                'alreadyCancelled' => true,
            ]);
        }

        if ($request->isMethod('POST')) {
            try {
                $this->commandBus->dispatch(new CancelRecurringPaymentCommand($contract));

                return $this->render('public/cancel_recurring_payment.html.twig', [
                    'contract' => $contract,
                    'success' => true,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to cancel recurring payment via email link', ['exception' => $e]);

                return $this->render('public/cancel_recurring_payment.html.twig', [
                    'contract' => $contract,
                    'error' => true,
                ]);
            }
        }

        return $this->render('public/cancel_recurring_payment.html.twig', [
            'contract' => $contract,
        ]);
    }
}
