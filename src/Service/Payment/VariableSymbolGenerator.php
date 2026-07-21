<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Fine;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class VariableSymbolGenerator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function generate(Uuid $orderId): string
    {
        $base = $orderId->toRfc4122();

        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $input = 0 === $attempt ? $base : $base.'-'.$attempt;
            $vs = $this->computeVs($input);

            if (!$this->existsInOrders($vs) && !$this->existsInFines($vs)) {
                return $vs;
            }
        }

        throw new \RuntimeException('Cannot generate unique variable symbol after 10 attempts');
    }

    public function generateForFine(Uuid $fineId): string
    {
        $base = $fineId->toRfc4122();

        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $input = 0 === $attempt ? $base : $base.'-'.$attempt;
            $vs = $this->computeVs($input);

            if (!$this->existsInOrders($vs) && !$this->existsInFines($vs)) {
                return $vs;
            }
        }

        throw new \RuntimeException('Cannot generate unique variable symbol after 10 attempts');
    }

    /**
     * Strip leading zeros so a symbol we issued matches the one the bank hands
     * back. Returns null when nothing meaningful remains (null, "", "0000000000").
     */
    public static function normalize(?string $variableSymbol): ?string
    {
        if (null === $variableSymbol) {
            return null;
        }

        $normalized = ltrim(trim($variableSymbol), '0');

        return '' === $normalized ? null : $normalized;
    }

    private function computeVs(string $input): string
    {
        // The variable symbol is a NUMBER per the ČNB/ČBA recommendation ("jedno až
        // desetimístné číslo"), so banks do not preserve leading zeros — a padded
        // "0451060965" comes back from FIO as "451060965" and no longer matches the
        // order. Keep the fixed 10-digit width (invoices, QR codes and e-mails all
        // render it) but force the first digit into 1-9 so the value survives the
        // round trip unchanged. crc32() yields 0..4294967295, so the result lands in
        // [1000000000, 5294967295] — always exactly 10 digits.
        return (string) (1_000_000_000 + crc32($input) % 9_000_000_000);
    }

    private function existsInOrders(string $vs): bool
    {
        return null !== $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(Order::class, 'o')
            // Compare numerically-equivalent symbols so we can never hand out one
            // that is numerically equal to a legacy zero-padded value (spec 090).
            ->where("TRIM(LEADING '0' FROM o.variableSymbol) = :vs")
            ->setParameter('vs', self::normalize($vs))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function existsInFines(string $vs): bool
    {
        return null !== $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(Fine::class, 'f')
            ->where("TRIM(LEADING '0' FROM f.variableSymbol) = :vs")
            ->setParameter('vs', self::normalize($vs))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
