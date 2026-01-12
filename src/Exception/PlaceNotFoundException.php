<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\Uid\Uuid;

class PlaceNotFoundException extends \RuntimeException
{
    public static function withId(Uuid $id): self
    {
        return new self(sprintf('Place with ID "%s" not found.', $id->toRfc4122()));
    }
}
