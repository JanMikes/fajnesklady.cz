<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Event\OnboardingPaymentReminderRequested;
use App\Repository\OrderRepository;
use App\Service\AuditLogger;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/orders/{id}/send-onboarding-reminder', name: 'admin_order_send_onboarding_reminder', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderSendOnboardingReminderController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly AuditLogger $auditLogger,
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $order = $this->orderRepository->get(Uuid::fromString($id));

        if (null === $order->createdByAdmin || null === $order->signedAt || null !== $order->paidAt) {
            $this->addFlash('error', 'Připomínku nelze odeslat — objednávka nesplňuje podmínky.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        // Audit BEFORE dispatch: the event bus's doctrine_transaction flush is
        // the only flush in this request — anything persisted after dispatch()
        // returns is silently lost.
        $this->auditLogger->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'admin_manual_email_sent',
            payload: ['email_type' => 'onboarding_reminder'],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );

        $this->eventBus->dispatch(new OnboardingPaymentReminderRequested(
            orderId: $order->id,
            stage: 'manual',
            occurredOn: $this->clock->now(),
        ));

        $this->addFlash('success', 'Připomínka platby byla odeslána.');

        return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
    }
}
