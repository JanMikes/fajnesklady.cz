<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\SettleOnboardingDebtCommand;
use App\Repository\OrderRepository;
use App\Service\Security\OrderVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/orders/{id}/settle-onboarding-debt', name: 'admin_order_settle_onboarding_debt', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderSettleOnboardingDebtController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $order = $this->orderRepository->get(Uuid::fromString($id));
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        if (!$order->hasUnpaidDebt()) {
            $this->addFlash('error', 'Tato objednávka nemá neuhrazený dluh z předchozí smlouvy.');

            return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
        }

        $this->commandBus->dispatch(new SettleOnboardingDebtCommand($order));

        $this->addFlash('success', 'Dluh z předchozí smlouvy byl označen jako uhrazený. Zákazníkovi se odesílá potvrzení o úhradě s fakturou.');

        return $this->redirectToRoute('admin_order_detail', ['id' => $id]);
    }
}
