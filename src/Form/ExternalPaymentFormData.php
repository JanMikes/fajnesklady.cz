<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ExternalPaymentFormData
{
    public const string COVERAGE_WHOLE_CYCLE = 'whole_cycle';
    public const string COVERAGE_SPECIFIC_DATE = 'specific_date';

    public string $coverage = self::COVERAGE_WHOLE_CYCLE;

    public ?\DateTimeImmutable $paidThroughDate = null;

    #[Assert\NotNull(message: 'Zadejte částku.')]
    #[Assert\GreaterThanOrEqual(value: 0, message: 'Částka nemůže být záporná.')]
    public ?float $amountInCzk = null;

    public bool $issueInvoice = false;

    #[Assert\Callback]
    public function validatePaidThroughDate(ExecutionContextInterface $context): void
    {
        if (self::COVERAGE_SPECIFIC_DATE !== $this->coverage) {
            return;
        }

        if (null === $this->paidThroughDate) {
            $context->buildViolation('Vyberte datum, do kterého je zaplaceno.')
                ->atPath('paidThroughDate')
                ->addViolation();

            return;
        }

        if ($this->paidThroughDate <= new \DateTimeImmutable('today')) {
            $context->buildViolation('Datum musí být v budoucnosti.')
                ->atPath('paidThroughDate')
                ->addViolation();
        }
    }
}
