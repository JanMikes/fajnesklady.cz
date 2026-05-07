<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\Overdue\OverdueChecker;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/orders', name: 'admin_orders_list')]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderListController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly OverdueChecker $overdueChecker,
        private readonly ClockInterface $clock,
    ) {
    }

    private const array FILTERS = ['individual', 'external', 'ending', 'free'];

    public function __invoke(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 20;
        $now = $this->clock->now();

        $filterParam = $request->query->get('filter');
        $filter = is_string($filterParam) && in_array($filterParam, self::FILTERS, true) ? $filterParam : null;

        if (null === $filter) {
            $orders = $this->orderRepository->findAllPaginated($page, $limit);
            $totalOrders = $this->orderRepository->countTotal();
        } else {
            $orders = $this->orderRepository->findAdminFiltered($now, $filter, $page, $limit);
            $totalOrders = $this->orderRepository->countByAdminFilter($now, $filter);
        }

        $totalPages = (int) ceil($totalOrders / $limit);

        $pageUserIds = array_map(static fn (Order $o) => $o->user->id, $orders);
        $debtorIdSet = array_flip($this->overdueChecker->filterOverdueUserIds($now, $pageUserIds));

        return $this->render('admin/order/list.html.twig', [
            'orders' => $orders,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalOrders' => $totalOrders,
            'debtorIdSet' => $debtorIdSet,
            'filter' => $filter,
            'filterCounts' => $this->orderRepository->countAllAdminFilters($now),
        ]);
    }
}
