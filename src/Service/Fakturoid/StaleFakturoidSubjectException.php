<?php

declare(strict_types=1);

namespace App\Service\Fakturoid;

/**
 * Thrown when a Fakturoid API call references a subject_id that no longer
 * exists in Fakturoid (usually deleted manually in the dashboard, or wiped
 * during an account merge). The InvoicingService catches this and recovers
 * by clearing the stored ID, creating a fresh subject, and retrying once.
 */
final class StaleFakturoidSubjectException extends \RuntimeException
{
    public function __construct(public readonly int $subjectId, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Fakturoid subject %d no longer exists.', $subjectId),
            0,
            $previous,
        );
    }
}
