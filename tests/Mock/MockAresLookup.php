<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\Service\AresLookup;
use App\Service\AresResult;

final class MockAresLookup implements AresLookup
{
    private ?AresResult $result = null;

    public function willReturn(?AresResult $result): void
    {
        $this->result = $result;
    }

    public function loadByCompanyId(string $companyId): ?AresResult
    {
        return $this->result;
    }

    public function reset(): void
    {
        $this->result = null;
    }
}
