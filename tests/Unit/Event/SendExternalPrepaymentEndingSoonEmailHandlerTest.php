<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Event\ExternalPrepaymentEndingSoon;
use App\Event\SendExternalPrepaymentEndingSoonEmailHandler;
use App\Repository\ContractRepository;
use App\Repository\UserRepository;
use App\Service\OrderStatusUrlGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Pins the cron-spam idempotency contract:
 *
 * The handler MUST mark `Contract.lastAdvanceNoticeSentAt` BEFORE attempting
 * the customer mailer send. If we marked it only on success, every transient
 * SMTP failure would leave the contract eligible for the next daily cron
 * run, re-spamming the customer once the mailer recovers.
 */
final class SendExternalPrepaymentEndingSoonEmailHandlerTest extends TestCase
{
    public function testRecordsAdvanceNoticeSentEvenWhenCustomerMailerFails(): void
    {
        $contract = $this->createPrepaidContract();
        $this->assertNull($contract->lastAdvanceNoticeSentAt);

        $contractRepository = $this->createStub(ContractRepository::class);
        $contractRepository->method('get')->willReturn($contract);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findByRole')->willReturn([]);

        // Customer mailer always blows up — simulate transient SMTP failure.
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->willThrowException(new TransportException('SMTP unreachable'));

        $handler = $this->makeHandler($contractRepository, $userRepository, $mailer);

        $handler(new ExternalPrepaymentEndingSoon(
            contractId: $contract->id,
            occurredOn: new \DateTimeImmutable('2025-06-15 12:00:00'),
        ));

        // The crucial invariant: the cron will not re-fire tomorrow because we
        // recorded the attempt. Re-delivery is the mailer queue's problem.
        $this->assertNotNull(
            $contract->lastAdvanceNoticeSentAt,
            'Handler must mark lastAdvanceNoticeSentAt even if customer mailer fails — otherwise the daily cron re-spams.',
        );
        $this->assertSame('2025-06-15', $contract->lastAdvanceNoticeSentAt->format('Y-m-d'));
    }

    public function testRecordsAdvanceNoticeSentOnSuccess(): void
    {
        $contract = $this->createPrepaidContract();

        $contractRepository = $this->createStub(ContractRepository::class);
        $contractRepository->method('get')->willReturn($contract);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findByRole')->willReturn([]);

        $sentEmail = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail): void {
            $sentEmail = $email;
        });

        $handler = $this->makeHandler($contractRepository, $userRepository, $mailer);

        $handler(new ExternalPrepaymentEndingSoon(
            contractId: $contract->id,
            occurredOn: new \DateTimeImmutable('2025-06-15 12:00:00'),
        ));

        $this->assertNotNull($sentEmail);
        $this->assertNotNull($contract->lastAdvanceNoticeSentAt);
    }

    private function makeHandler(
        ContractRepository $contractRepository,
        UserRepository $userRepository,
        MailerInterface $mailer,
    ): SendExternalPrepaymentEndingSoonEmailHandler {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/portal/status');

        $statusUrlGenerator = new OrderStatusUrlGenerator($urlGenerator, new UriSigner('test-secret'));

        return new SendExternalPrepaymentEndingSoonEmailHandler(
            $contractRepository,
            $userRepository,
            $mailer,
            $statusUrlGenerator,
            new MockClock(new \DateTimeImmutable('2025-06-15 12:00:00')),
            new NullLogger(),
        );
    }

    private function createPrepaidContract(): Contract
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Sklad Praha',
            address: 'Testovací 1',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $now,
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Malý box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10_000,
            defaultPricePerMonth: 35_000,
            defaultPricePerMonthLongTerm: 35_000,
            defaultPricePerYear: 35_000 * 12,
            createdAt: $now,
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $now,
        );

        $user = new User(
            Uuid::v7(),
            'tenant@example.com',
            'password',
            'Pavel',
            'Nájemník',
            $now,
        );

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('-30 days'),
            endDate: $now->modify('-30 days')->modify('+12 months'),
            firstPaymentPrice: 35_000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now->modify('-30 days'),
        );

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: $now->modify('-30 days'),
            endDate: $now->modify('-30 days')->modify('+12 months'),
            createdAt: $now->modify('-30 days'),
        );

        $contract->markExternallyPrepaid($now->modify('+5 days'));

        return $contract;
    }
}
