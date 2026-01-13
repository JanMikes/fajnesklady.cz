<?php

declare(strict_types=1);

namespace App\Service;

use App\Value\AresResult;

interface AresLookup
{
    public function loadByCompanyId(string $companyId): ?AresResult;
}
