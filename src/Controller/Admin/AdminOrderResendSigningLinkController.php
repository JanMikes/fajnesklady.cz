<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Event\AdminOnboardingInitiated;
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

#[Route('/portal/admin/orders/{id}/resend-signing-link', name: 'admin_order_resend_signing_link', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderResendSigningLinkController extends AbstractController
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

        if (null === $order->signingToken || null !== $order->signedAt) {
            $this->addFlash('error', 'Odkaz k podpisu nelze odeslat — objednávka nemá aktivní podpisový token.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $this->eventBus->dispatch(new AdminOnboardingInitiated(
            orderId: $order->id,
            userId: $order->user->id,
            customerEmail: $order->user->email,
            signingToken: $order->signingToken,
            occurredOn: $this->clock->now(),
        ));

        $this->auditLogger->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'admin_manual_email_sent',
            payload: ['email_type' => 'signing_link'],
            orderId: $order->id,
            userIdContext: $order->user->id,
        );

        $this->addFlash('success', 'Odkaz k podpisu byl znovu odeslán.');

        return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
    }
}
