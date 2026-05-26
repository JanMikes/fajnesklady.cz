<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\AdvanceNoticeReason;
use App\Enum\RentalType;
use App\Event\RecurringPaymentAdvanceNoticeNeeded;
use App\Event\SendRecurringPaymentAdvanceNoticeEmailHandler;
use App\Repository\ContractRepository;
use App\Service\OrderStatusUrlGenerator;
use App\Service\RecurringPaymentCancelUrlGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class SendRecurringPaymentAdvanceNoticeEmailHandlerTest extends TestCase
{
    public function testSixMonthGapVariantUsesReminderSubjectAndCarriesReason(): void
    {
        $contract = $this->createContract();
        $event = new RecurringPaymentAdvanceNoticeNeeded(
            contractId: $contract->id,
            reason: AdvanceNoticeReason::SIX_MONTH_GAP,
            occurredOn: new \DateTimeImmutable('2026-05-05 12:00:00'),
        );

        $sentEmail = null;
        $handler = $this->createHandler($contract, $sentEmail);
        $handler($event);

        $this->assertInstanceOf(TemplatedEmail::class, $sentEmail);
        $this->assertStringContainsString('Připomenutí', (string) $sentEmail->getSubject());

        $context = $sentEmail->getContext();
        $this->assertSame(AdvanceNoticeReason::SIX_MONTH_GAP, $context['reason']);
        $this->assertNull($context['newAmount']);
        $this->assertNull($context['adminNote']);
    }

    public function testParameterChangeVariantCarriesNewAmountAndAdminNote(): void
    {
        $contract = $this->createContract();
        $event = new RecurringPaymentAdvanceNoticeNeeded(
            contractId: $contract->id,
            reason: AdvanceNoticeReason::PARAMETER_CHANGE,
            occurredOn: new \DateTimeImmutable('2026-05-05 12:00:00'),
            newAmount: 380000,
            adminNote: 'Důvod: úprava ceníku platná od 1. 7. 2026.',
        );

        $sentEmail = null;
        $handler = $this->createHandler($contract, $sentEmail);
        $handler($event);

        $this->assertInstanceOf(TemplatedEmail::class, $sentEmail);
        $this->assertStringContainsString('Změna parametrů', (string) $sentEmail->getSubject());

        $context = $sentEmail->getContext();
        $this->assertSame(AdvanceNoticeReason::PARAMETER_CHANGE, $context['reason']);
        $this->assertSame('3 800,00', $context['newAmount']);
        $this->assertSame('Důvod: úprava ceníku platná od 1. 7. 2026.', $context['adminNote']);
    }

    public function testHandlerRecordsAdvanceNoticeSentAtOnContract(): void
    {
        $contract = $this->createContract();
        $this->assertNull($contract->lastAdvanceNoticeSentAt);

        $event = new RecurringPaymentAdvanceNoticeNeeded(
            contractId: $contract->id,
            reason: AdvanceNoticeReason::SIX_MONTH_GAP,
            occurredOn: new \DateTimeImmutable('2026-05-05 12:00:00'),
        );

        $sentEmail = null;
        $handler = $this->createHandler($contract, $sentEmail);
        $handler($event);

        $this->assertNotNull($contract->lastAdvanceNoticeSentAt);
        $this->assertSame('2026-05-05', $contract->lastAdvanceNoticeSentAt->format('Y-m-d'));
    }

    /**
     * @param Email|null $sentEmail Captured email reference
     */
    private function createHandler(Contract $contract, ?Email &$sentEmail): SendRecurringPaymentAdvanceNoticeEmailHandler
    {
        $contractRepository = $this->createStub(ContractRepository::class);
        $contractRepository->method('get')->willReturn($contract);

        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail): void {
            $sentEmail = $email;
        });

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/portal/cancel');

        $cancelUrlGenerator = new RecurringPaymentCancelUrlGenerator($urlGenerator, new UriSigner('test-secret'));
        $statusUrlGenerator = new OrderStatusUrlGenerator($urlGenerator, new UriSigner('test-secret'));

        return new SendRecurringPaymentAdvanceNoticeEmailHandler(
            $contractRepository,
            $mailer,
            $cancelUrlGenerator,
            $statusUrlGenerator,
            new MockClock(new \DateTimeImmutable('2026-05-05 12:00:00')),
            new NullLogger(),
        );
    }

    private function createContract(): Contract
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Warehouse',
            address: 'Testovaci 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 200,
            innerLength: 150,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 350000,
            defaultPricePerMonthLongTerm: 350000,
            defaultPricePerYear: 350000 * 12,
            createdAt: new \DateTimeImmutable(),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $user = new User(
            Uuid::v7(),
            'tenant@example.com',
            'password',
            'Jan',
            'Novak',
            new \DateTimeImmutable(),
        );

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::UNLIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-15'),
            endDate: null,
            firstPaymentPrice: 350000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable('2025-06-10'),
        );

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            rentalType: RentalType::UNLIMITED,
            startDate: new \DateTimeImmutable('2025-06-15'),
            endDate: null,
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );

        // Activate recurring payment so cancelUrlGenerator branch fires.
        $contract->setRecurringPayment(
            'gp-parent-1',
            new \DateTimeImmutable('2026-05-15'),
            new \DateTimeImmutable('2026-05-15'),
        );

        return $contract;
    }
}
