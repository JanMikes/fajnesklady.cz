<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/audit-log', name: 'admin_audit_log')]
#[IsGranted('ROLE_ADMIN')]
final class AdminAuditLogController extends AbstractController
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 50;

        $entityType = $request->query->get('entity_type');
        $eventType = $request->query->get('event_type');
        $search = $request->query->get('search');
        $orderIdRaw = $request->query->get('orderId');
        $orderId = null;
        if (is_string($orderIdRaw) && '' !== $orderIdRaw && Uuid::isValid($orderIdRaw)) {
            $orderId = Uuid::fromString($orderIdRaw);
        }

        $logs = $this->auditLogRepository->findPaginatedWithFilters(
            $page,
            $limit,
            '' !== $entityType && null !== $entityType ? $entityType : null,
            '' !== $eventType && null !== $eventType ? $eventType : null,
            '' !== $search && null !== $search ? $search : null,
            $orderId,
        );

        $totalLogs = $this->auditLogRepository->countWithFilters(
            '' !== $entityType && null !== $entityType ? $entityType : null,
            '' !== $eventType && null !== $eventType ? $eventType : null,
            '' !== $search && null !== $search ? $search : null,
            $orderId,
        );

        $totalPages = (int) ceil($totalLogs / $limit);

        return $this->render('admin/audit_log/list.html.twig', [
            'logs' => $logs,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalLogs' => $totalLogs,
            'entityTypes' => $this->auditLogRepository->getDistinctEntityTypes(),
            'eventTypes' => $this->auditLogRepository->getDistinctEventTypes(),
            'currentEntityType' => $entityType,
            'currentEventType' => $eventType,
            'currentSearch' => $search,
            'currentOrderId' => $orderIdRaw,
        ]);
    }
}
