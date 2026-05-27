<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Event\FinePaymentReminderRequested;
use App\Repository\ContractRepository;
use App\Repository\FineRepository;
use App\Repository\OrderRepository;
use App\Service\AuditLogger;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/orders/{id}/send-fine-reminder/{fineId}', name: 'admin_order_send_fine_reminder', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderSendFineReminderController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly FineRepository $fineRepository,
        private readonly AuditLogger $auditLogger,
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, string $fineId, Request $request): Response
    {
        $order = $this->orderRepository->get(Uuid::fromString($id));

        if (!$this->isCsrfTokenValid('send_fine_reminder_'.$fineId, $request->request->getString('_token'))) {
            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $fine = $this->fineRepository->findById(Uuid::fromString($fineId));
        if (null === $fine) {
            $this->addFlash('error', 'Pokuta nebyla nalezena.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $contract = $this->contractRepository->findByOrder($order);
        if (null === $contract || $fine->contract->id->toRfc4122() !== $contract->id->toRfc4122()) {
            $this->addFlash('error', 'Pokuta nepatří k této objednávce.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        if (!$fine->isPayable()) {
            $this->addFlash('error', 'Pokuta není ve stavu, kdy lze odeslat připomínku.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $this->eventBus->dispatch(new FinePaymentReminderRequested(
            fineId: $fine->id,
            stage: 0,
            occurredOn: $this->clock->now(),
        ));

        $this->auditLogger->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'admin_manual_email_sent',
            payload: ['email_type' => 'fine_reminder', 'fine_id' => $fineId],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );

        $this->addFlash('success', 'Připomínka pokuty byla odeslána.');

        return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
    }
}
