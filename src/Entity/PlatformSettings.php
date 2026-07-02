<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class PlatformSettings
{
    #[ORM\Column(options: ['default' => 10_000])]
    public private(set) int $bankTransferSurchargeInHaler = 10_000;

    /**
     * Days after `Contract.nextBillingDate` before an unpaid contract is
     * terminated without notice by `app:terminate-overdue-contracts`
     * (VOP čl. XI — requires arrears of more than 7 days, so 7 is the floor).
     */
    #[ORM\Column(options: ['default' => 7])]
    public private(set) int $overdueTerminationDays = 7;

    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public function updateSurcharge(int $surchargeInHaler, \DateTimeImmutable $now): void
    {
        $this->bankTransferSurchargeInHaler = $surchargeInHaler;
        $this->updatedAt = $now;
    }

    public function updateOverdueTerminationDays(int $days, \DateTimeImmutable $now): void
    {
        $this->overdueTerminationDays = $days;
        $this->updatedAt = $now;
    }

    public function getBankTransferSurchargeInCzk(): float
    {
        return $this->bankTransferSurchargeInHaler / 100;
    }
}
