<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\Order;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use App\Service\Overdue\OverdueChecker;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/users/{id}', name: 'portal_users_view', requirements: ['id' => '[0-9a-f-]{36}'])]
#[IsGranted('ROLE_ADMIN')]
final class UserViewController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly OverdueChecker $overdueChecker,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $user = $this->userRepository->get(Uuid::fromString($id));
        $now = $this->clock->now();

        $orders = $this->orderRepository->findByUserWithDetails($user->id);
        $contractsByOrderId = $this->contractRepository->findByOrderIds(
            array_map(static fn (Order $o): Uuid => $o->id, $orders),
        );
        $stats = $this->contractRepository->loadCustomerStatsByUserIds([$user->id], $now)[$user->id->toRfc4122()] ?? null;

        $overdueViewsByContractId = [];
        foreach ($this->overdueChecker->findOverdueViewsForUser($now, $user->id) as $view) {
            $overdueViewsByContractId[$view->contract->id->toRfc4122()] = $view;
        }

        // Total debt = contract outstanding debt across all the user's contracts
        // + still-unpaid order-level onboarding debt (spec 051). Dedup contracts
        // via the keyed map so a contract is never counted twice.
        $totalDebtInHaler = 0;
        foreach ($contractsByOrderId as $contract) {
            $totalDebtInHaler += $contract->outstandingDebtAmount ?? 0;
        }
        foreach ($orders as $order) {
            if ($order->hasUnpaidDebt()) {
                $totalDebtInHaler += $order->onboardingDebtInHaler ?? 0;
            }
        }

        return $this->render('portal/user/view.html.twig', [
            'user' => $user,
            'orders' => $orders,
            'contractsByOrderId' => $contractsByOrderId,
            'stats' => $stats,
            'overdueViewsByContractId' => $overdueViewsByContractId,
            'totalDebtInHaler' => $totalDebtInHaler,
            'now' => $now,
        ]);
    }
}
