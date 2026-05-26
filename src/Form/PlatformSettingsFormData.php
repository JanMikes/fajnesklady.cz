<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\PlatformSettings;
use Symfony\Component\Validator\Constraints as Assert;

final class PlatformSettingsFormData
{
    #[Assert\NotNull(message: 'Zadejte hodnotu příplatku.')]
    #[Assert\PositiveOrZero(message: 'Příplatek nemůže být záporný.')]
    public ?float $bankTransferSurchargeInCzk = null;

    public static function fromSettings(PlatformSettings $settings): self
    {
        $data = new self();
        $data->bankTransferSurchargeInCzk = $settings->getBankTransferSurchargeInCzk();

        return $data;
    }

    public function toHaler(): int
    {
        return (int) (($this->bankTransferSurchargeInCzk ?? 0) * 100);
    }
}
