<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\EmailLogStatus;

final readonly class EmailLogFilter
{
    public function __construct(
        public ?\DateTimeImmutable $dateFrom = null,
        public ?\DateTimeImmutable $dateTo = null,
        public ?string $recipient = null,
        public ?string $subject = null,
        public ?string $templateName = null,
        public ?EmailLogStatus $status = null,
    ) {
    }
}
