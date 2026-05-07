<?php

declare(strict_types=1);

namespace App\Service\Excel;

final readonly class ExcelColumn
{
    public function __construct(
        public string $header,
        public ExcelColumnType $type = ExcelColumnType::TEXT,
        public float $widthHint = 1.0,
    ) {
    }
}
