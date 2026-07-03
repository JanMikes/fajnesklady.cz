<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class ExcludeStorageCodesCommand
{
    /**
     * @param list<string> $codes
     */
    public function __construct(
        public Uuid $placeId,
        public array $codes,
        public ?string $note,
    ) {
    }
}
