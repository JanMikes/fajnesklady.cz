<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Payment;

use App\Service\Payment\VariableSymbolGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class VariableSymbolGeneratorTest extends TestCase
{
    public function testGeneratesExactlyTenDigits(): void
    {
        $em = $this->createMockEntityManager(null);
        $generator = new VariableSymbolGenerator($em);

        $vs = $generator->generate(Uuid::v7());

        self::assertSame(10, strlen($vs));
        self::assertMatchesRegularExpression('/^\d{10}$/', $vs);
    }

    public function testDeterministicForSameUuid(): void
    {
        $em = $this->createMockEntityManager(null);
        $generator = new VariableSymbolGenerator($em);

        $uuid = Uuid::v7();
        $vs1 = $generator->generate($uuid);
        $vs2 = $generator->generate($uuid);

        self::assertSame($vs1, $vs2);
    }

    public function testThrowsAfterTenAttempts(): void
    {
        $query = $this->createStub(Query::class);
        $query->method('getOneOrNullResult')->willReturn([1]);

        $qb = $this->createStub(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        $generator = new VariableSymbolGenerator($em);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot generate unique variable symbol after 10 attempts');

        $generator->generate(Uuid::v7());
    }

    private function createMockEntityManager(mixed $queryResult): EntityManagerInterface
    {
        $query = $this->createStub(Query::class);
        $query->method('getOneOrNullResult')->willReturn($queryResult);

        $qb = $this->createStub(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        return $em;
    }
}
