<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Enum\EmailLogStatus;
use App\Repository\EmailLogFilter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Builds an {@see EmailLogFilter} from a Request's query string.
 *
 * Both {@see \App\Controller\Admin\AdminEmailLogController} and
 * {@see \App\Controller\Admin\AdminEmailLogExportController} need the exact
 * same parsing rules — identical date semantics, identical trim-to-null
 * conventions, identical status enum lookup. Centralising the build keeps the
 * two controllers in lockstep without copy-pasting helpers.
 */
final readonly class EmailLogFilterFactory
{
    public function fromRequest(Request $request): EmailLogFilter
    {
        $statusValue = $this->trimToNull($request->query->get('status'));
        $status = null !== $statusValue ? EmailLogStatus::tryFrom($statusValue) : null;

        $orderIdValue = $this->trimToNull($request->query->get('orderId'));
        $orderId = null;
        if (null !== $orderIdValue) {
            try {
                $orderId = Uuid::fromString($orderIdValue);
            } catch (\InvalidArgumentException) {
            }
        }

        return new EmailLogFilter(
            dateFrom: $this->parseDate($request->query->get('date_from'), endOfDay: false),
            dateTo: $this->parseDate($request->query->get('date_to'), endOfDay: true),
            recipient: $this->trimToNull($request->query->get('recipient')),
            subject: $this->trimToNull($request->query->get('subject')),
            templateName: $this->trimToNull($request->query->get('template')),
            status: $status,
            orderId: $orderId,
        );
    }

    private function parseDate(mixed $value, bool $endOfDay): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (false === $date) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date;
    }

    private function trimToNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
