<?php

declare(strict_types=1);

namespace App\Service\Excel;

final readonly class ExcelSheet
{
    /**
     * @param ExcelColumn[]                                                       $columns
     * @param iterable<array<int, bool|\DateTimeInterface|float|int|string|null>> $rows
     */
    public function __construct(
        public string $sheetTitle,
        public string $filename,
        public array $columns,
        public iterable $rows,
    ) {
    }
}
