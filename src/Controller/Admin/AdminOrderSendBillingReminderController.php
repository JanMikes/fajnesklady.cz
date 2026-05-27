<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\BillingMode;
use App\Event\ManualBillingPaymentRequested;
use App\Repository\ContractRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Repository\OrderRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/orders/{id}/send-billing-reminder', name: 'admin_order_send_billing_reminder', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderSendBillingReminderController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly ManualPaymentRequestRepository $manualPaymentRequestRepository,
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        $order = $this->orderRepository->get(Uuid::fromString($id));

        if (!$this->isCsrfTokenValid('send_billing_reminder', $request->request->getString('_token'))) {
            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $contract = $this->contractRepository->findByOrder($order);
        if (null === $contract || BillingMode::MANUAL_RECURRING !== $contract->billingMode) {
            $this->addFlash('error', 'Nelze odeslat — smlouva nemá manuální opakované platby.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $now = $this->clock->now();
        $pendingRequest = $this->manualPaymentRequestRepository->findPendingForCurrentCycle($contract, $now);
        if (null === $pendingRequest) {
            $this->addFlash('error', 'Nelze odeslat — neexistuje čekající platební požadavek pro aktuální období.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $this->eventBus->dispatch(new ManualBillingPaymentRequested(
            contractId: $contract->id,
            manualPaymentRequestId: $pendingRequest->id,
            stage: 'manual',
            occurredOn: $now,
        ));

        $this->addFlash('success', 'Výzva k platbě byla odeslána.');

        return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
    }
}
