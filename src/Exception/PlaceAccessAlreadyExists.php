<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\Uid\Uuid;

#[WithHttpStatus(409)]
final class PlaceAccessAlreadyExists extends \RuntimeException
{
    public static function forUserAndPlace(Uuid $userId, Uuid $placeId): self
    {
        return new self(sprintf(
            'User "%s" already has access to place "%s".',
            $userId->toRfc4122(),
            $placeId->toRfc4122()
        ));
    }
}
