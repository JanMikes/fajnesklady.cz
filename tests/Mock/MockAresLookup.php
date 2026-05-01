<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\Exception\AresUnavailable;
use App\Service\AresLookup;
use App\Value\AresResult;

final class MockAresLookup implements AresLookup
{
    private ?AresResult $result = null;
    private bool $throwUnavailable = false;

    public function willReturn(?AresResult $result): void
    {
        $this->result = $result;
        $this->throwUnavailable = false;
    }

    public function willThrowUnavailable(): void
    {
        $this->throwUnavailable = true;
        $this->result = null;
    }

    public function loadByCompanyId(string $companyId): ?AresResult
    {
        if ($this->throwUnavailable) {
            throw AresUnavailable::withStatus(503);
        }

        return $this->result;
    }

    public function reset(): void
    {
        $this->result = null;
        $this->throwUnavailable = false;
    }
}
