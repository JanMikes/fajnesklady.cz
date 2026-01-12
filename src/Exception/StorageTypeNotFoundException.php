<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\Uid\Uuid;

class StorageTypeNotFoundException extends \RuntimeException
{
    public static function withId(Uuid $id): self
    {
        return new self(sprintf('StorageType with ID "%s" not found.', $id->toRfc4122()));
    }
}
