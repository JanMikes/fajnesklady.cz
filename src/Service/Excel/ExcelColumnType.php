<?php

declare(strict_types=1);

namespace App\Service\Excel;

enum ExcelColumnType: string
{
    case TEXT = 'text';
    case INTEGER = 'integer';
    case DECIMAL = 'decimal';
    case MONEY_KC = 'money_kc';
    case DATE = 'date';
    case DATETIME = 'datetime';
    case BOOLEAN = 'boolean';
}
