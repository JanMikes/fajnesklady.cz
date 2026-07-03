<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\FineType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class LandlordHandoverFormData
{
    #[Assert\NotBlank(message: 'Zadejte komentář k převzetí skladu.')]
    #[Assert\Length(max: 2000, maxMessage: 'Komentář nesmí být delší než {{ limit }} znaků.')]
    public string $comment = '';

    #[Assert\Length(max: 50, maxMessage: 'Kód zámku nesmí být delší než {{ limit }} znaků.')]
    public ?string $newLockCode = null;

    public bool $issueFine = false;

    // Fine fields are flat (not an embedded FineFormData) so their requiredness
    // can stay conditional on $issueFine — see validateFine().
    public ?FineType $fineType = null;

    public ?float $fineAmountInCzk = null;

    public ?int $fineNonReturnDays = null;

    public ?float $fineLatePaymentBaseInCzk = null;

    public ?int $fineLatePaymentDays = null;

    #[Assert\Length(max: 2000)]
    public string $fineDescription = '';

    #[Assert\Callback]
    public function validateFine(ExecutionContextInterface $context): void
    {
        if (!$this->issueFine) {
            return;
        }

        if (null === $this->fineType) {
            $context->buildViolation('Vyberte typ pokuty.')->atPath('fineType')->addViolation();
        }
        if (null === $this->fineAmountInCzk || $this->fineAmountInCzk <= 0) {
            $context->buildViolation('Zadejte kladnou částku pokuty.')->atPath('fineAmountInCzk')->addViolation();
        }
        if ('' === trim($this->fineDescription)) {
            $context->buildViolation('Zadejte popis pokuty.')->atPath('fineDescription')->addViolation();
        }
    }
}
