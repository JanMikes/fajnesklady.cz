<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class LandlordHandoverFormData
{
    #[Assert\NotBlank(message: 'Zadejte komentář k převzetí skladu.')]
    #[Assert\Length(max: 2000, maxMessage: 'Komentář nesmí být delší než {{ limit }} znaků.')]
    public string $comment = '';

    #[Assert\Length(max: 50, maxMessage: 'Kód zámku nesmí být delší než {{ limit }} znaků.')]
    public ?string $newLockCode = null;
}
