<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Place;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class PlaceStorageCodeConfigFormData
{
    public bool $enabled = false;

    #[Assert\Range(min: 1, max: 10, notInRangeMessage: 'Počet číslic musí být mezi {{ min }} a {{ max }}.')]
    public int $digits = 4;

    #[Assert\Range(min: 0, notInRangeMessage: 'Hodnota nesmí být záporná.')]
    public int $from = 0;

    #[Assert\Range(min: 0, notInRangeMessage: 'Hodnota nesmí být záporná.')]
    public int $to = 9999;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($this->from > $this->to) {
            $context->buildViolation('"Od" musí být menší nebo rovno "Do".')
                ->atPath('from')
                ->addViolation();
        }

        $maxForDigits = (10 ** $this->digits) - 1;
        if ($this->to > $maxForDigits) {
            $context->buildViolation(sprintf('Pro %d číslic je maximální hodnota %d.', $this->digits, $maxForDigits))
                ->atPath('to')
                ->addViolation();
        }
    }

    public static function fromPlace(Place $place): self
    {
        $data = new self();
        $data->enabled = $place->storageCodesEnabled;
        $data->digits = $place->storageCodeDigits;
        $data->from = $place->storageCodeFrom;
        $data->to = $place->storageCodeTo;

        return $data;
    }
}
