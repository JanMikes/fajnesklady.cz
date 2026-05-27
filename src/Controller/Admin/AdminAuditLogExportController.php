<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use App\Service\AuditLogDescriptionRenderer;
use App\Service\Excel\ExcelColumn;
use App\Service\Excel\ExcelColumnType;
use App\Service\Excel\ExcelExporter;
use App\Service\Excel\ExcelSheet;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/audit-log/export', name: 'admin_audit_log_export')]
#[IsGranted('ROLE_ADMIN')]
final class AdminAuditLogExportController extends AbstractController
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AuditLogDescriptionRenderer $descriptionRenderer,
        private readonly ExcelExporter $excelExporter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $now = $this->clock->now();

        $entityType = self::trimToNull($request->query->get('entity_type'));
        $eventType = self::trimToNull($request->query->get('event_type'));
        $search = self::trimToNull($request->query->get('search'));
        $orderIdRaw = self::trimToNull($request->query->get('orderId'));
        $orderId = null !== $orderIdRaw && Uuid::isValid($orderIdRaw) ? Uuid::fromString($orderIdRaw) : null;

        $columns = [
            new ExcelColumn('Čas', ExcelColumnType::DATETIME),
            new ExcelColumn('Uživatel'),
            new ExcelColumn('Typ entity'),
            new ExcelColumn('ID entity'),
            new ExcelColumn('Událost'),
            new ExcelColumn('Popis'),
            new ExcelColumn('ID objednávky'),
            new ExcelColumn('ID zákazníka'),
        ];

        $logs = $this->auditLogRepository->streamWithFilters($entityType, $eventType, $search, $orderId);
        $renderer = $this->descriptionRenderer;
        $rows = (static function () use ($logs, $renderer): \Generator {
            foreach ($logs as $log) {
                /* @var AuditLog $log */
                yield [
                    $log->createdAt,
                    null === $log->user ? '' : ('' !== $log->user->fullName ? $log->user->fullName : $log->user->email),
                    $log->entityType,
                    $log->entityId,
                    $log->eventType,
                    $renderer->describe($log),
                    $log->orderId?->toRfc4122() ?? '',
                    $log->userIdContext?->toRfc4122() ?? '',
                ];
            }
        })();

        $sheet = new ExcelSheet(
            sheetTitle: 'Audit log',
            filename: sprintf('audit-log-%s.xlsx', $now->format('Y-m-d')),
            columns: $columns,
            rows: $rows,
        );

        return $this->excelExporter->stream($sheet);
    }

    private static function trimToNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
