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
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        $generator = new VariableSymbolGenerator($em);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot generate unique variable symbol after 10 attempts');

        $generator->generate(Uuid::v7());
    }

    /**
     * Spec 090: banks treat the variable symbol as a NUMBER, so a leading zero is
     * stripped in transit and the symbol no longer matches the order it was
     * issued for. Nothing we mint may start with 0.
     */
    public function testNeverGeneratesALeadingZero(): void
    {
        $em = $this->createMockEntityManager(null);
        $generator = new VariableSymbolGenerator($em);

        for ($i = 0; $i < 500; ++$i) {
            $vs = $generator->generate(Uuid::v7());

            self::assertSame(10, strlen($vs), sprintf('VS "%s" is not 10 digits', $vs));
            self::assertTrue(ctype_digit($vs), sprintf('VS "%s" is not all digits', $vs));
            self::assertNotSame('0', $vs[0], sprintf('VS "%s" starts with a zero', $vs));
        }
    }

    public function testFineSymbolsAlsoNeverStartWithZero(): void
    {
        $em = $this->createMockEntityManager(null);
        $generator = new VariableSymbolGenerator($em);

        for ($i = 0; $i < 500; ++$i) {
            $vs = $generator->generateForFine(Uuid::v7());

            self::assertSame(10, strlen($vs));
            self::assertNotSame('0', $vs[0]);
        }
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function normalizeProvider(): iterable
    {
        yield 'strips a single leading zero' => ['0451060965', '451060965'];
        yield 'leaves an unpadded symbol alone' => ['451060965', '451060965'];
        yield 'strips whitespace then zeros' => ['  0012  ', '12'];
        yield 'all zeros is nothing' => ['0000000000', null];
        yield 'empty string is nothing' => ['', null];
        yield 'null is nothing' => [null, null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('normalizeProvider')]
    public function testNormalize(?string $input, ?string $expected): void
    {
        self::assertSame($expected, VariableSymbolGenerator::normalize($input));
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
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        return $em;
    }
}
