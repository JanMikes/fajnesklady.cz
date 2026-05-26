<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\FineType;
use Symfony\Component\Validator\Constraints as Assert;

final class FineFormData
{
    #[Assert\NotNull]
    public ?FineType $type = null;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public ?int $amountInHaler = null;

    public ?int $nonReturnDays = null;

    public ?int $latePaymentBaseInHaler = null;

    public ?int $latePaymentDays = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 2000)]
    public string $description = '';
}
