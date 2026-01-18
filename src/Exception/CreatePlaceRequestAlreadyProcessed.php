<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use Symfony\Component\Uid\Uuid;

#[WithHttpStatus(409)]
final class CreatePlaceRequestAlreadyProcessed extends \RuntimeException
{
    public static function withId(Uuid $id): self
    {
        return new self(sprintf('Žádost o vytvoření místa s ID "%s" již byla zpracována.', $id->toRfc4122()));
    }
}
