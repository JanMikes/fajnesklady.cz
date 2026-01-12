<?php

declare(strict_types=1);

namespace App\Identity;

use Symfony\Component\Uid\Uuid;

interface ProvideIdentity
{
    public function next(): Uuid;
}
