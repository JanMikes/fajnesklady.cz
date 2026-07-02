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

    // Min 7 is the VOP floor — čl. XI only permits no-notice termination after
    // more than 7 days of arrears; a lower value would be an unlawful termination.
    #[Assert\NotNull(message: 'Zadejte počet dní.')]
    #[Assert\Range(min: 7, max: 60, notInRangeMessage: 'Počet dní musí být mezi {{ min }} a {{ max }}.')]
    public ?int $overdueTerminationDays = null;

    public static function fromSettings(PlatformSettings $settings): self
    {
        $data = new self();
        $data->bankTransferSurchargeInCzk = $settings->getBankTransferSurchargeInCzk();
        $data->overdueTerminationDays = $settings->overdueTerminationDays;

        return $data;
    }

    public function toHaler(): int
    {
        return (int) (($this->bankTransferSurchargeInCzk ?? 0) * 100);
    }
}
