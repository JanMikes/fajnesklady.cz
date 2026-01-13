<?php

declare(strict_types=1);

namespace App\Service;

interface AresLookup
{
    public function loadByCompanyId(string $companyId): ?AresResult;
}
