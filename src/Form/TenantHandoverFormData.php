<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class TenantHandoverFormData
{
    #[Assert\NotBlank(message: 'Zadejte komentář k předání skladu.')]
    #[Assert\Length(max: 2000, maxMessage: 'Komentář nesmí být delší než {{ limit }} znaků.')]
    public string $comment = '';

    #[Assert\IsTrue(message: 'Musíte potvrdit, že jste sklad vyklidili.')]
    public bool $confirmed = false;
}
