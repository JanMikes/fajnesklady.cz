<?php

declare(strict_types=1);

namespace App\Command;

final readonly class UpdatePlatformSettingsCommand
{
    public function __construct(
        public int $bankTransferSurchargeInHaler,
    ) {
    }
}
