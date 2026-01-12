<?php

declare(strict_types=1);

namespace App\Identity;

use Symfony\Component\Uid\Uuid;

final readonly class RandomIdentityProvider implements ProvideIdentity
{
    public function next(): Uuid
    {
        return Uuid::v7();
    }
}
