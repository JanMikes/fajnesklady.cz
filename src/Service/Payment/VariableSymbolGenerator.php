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

    private function computeVs(string $input): string
    {
        $hash = crc32($input);

        return str_pad((string) abs($hash % 10_000_000_000), 10, '0', STR_PAD_LEFT);
    }

    private function existsInOrders(string $vs): bool
    {
        return null !== $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(Order::class, 'o')
            ->where('o.variableSymbol = :vs')
            ->setParameter('vs', $vs)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function existsInFines(string $vs): bool
    {
        return null !== $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(Fine::class, 'f')
            ->where('f.variableSymbol = :vs')
            ->setParameter('vs', $vs)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
