<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Event\PaymentAmountMismatch;
use App\Event\SendAmountMismatchAlertEmailHandler;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Pins the admin-fanout contract: every admin gets a dedicated alert when
 * GoPay reports a payment amount that differs from the expected (recurring
 * monthly or order's firstPaymentPrice). One email per admin, Czech subject
 * with the warning glyph, body shows expected vs received and suggested action.
 */
final class SendAmountMismatchAlertEmailHandlerTest extends TestCase
{
    public function testSendsOneEmailPerAdminWithMismatchDetails(): void
    {
        $contract = $this->createContract();
        $admins = [
            $this->createAdmin('admin-a@example.com', 'Admin A'),
            $this->createAdmin('admin-b@example.com', 'Admin B'),
        ];

        $contractRepository = $this->createStub(ContractRepository::class);
        $contractRepository->method('get')->willReturn($contract);

        $orderRepository = $this->createStub(OrderRepository::class);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findByRole')->willReturn($admins);

        $sentEmails = [];
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmails): void {
            $sentEmails[] = $email;
        });

        $handler = new SendAmountMismatchAlertEmailHandler(
            $contractRepository,
            $orderRepository,
            $userRepository,
            $mailer,
            new NullLogger(),
        );

        $event = PaymentAmountMismatch::forContract(
            contractId: $contract->id,
            goPayPaymentId: 'gp_recurring_xyz',
            expectedAmount: 150_000,
            receivedAmount: 100_000,
            occurredOn: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        $handler($event);

        $this->assertCount(2, $sentEmails, 'One email per admin.');

        foreach ($sentEmails as $email) {
            $this->assertInstanceOf(TemplatedEmail::class, $email);
            $subject = (string) $email->getSubject();
            $this->assertStringContainsString('Neshoda částky platby', $subject);
            $this->assertStringContainsString('gp_recurring_xyz', $subject);
            $this->assertStringContainsString('⚠', $subject);

            $context = $email->getContext();
            $this->assertSame('gp_recurring_xyz', $context['goPayPaymentId']);
            $this->assertSame('1 500,00', $context['expectedAmount']);
            $this->assertSame('1 000,00', $context['receivedAmount']);
            $this->assertSame('-500,00', $context['differenceAmount']);
        }
    }

    public function testSilentlyReturnsWhenNoAdmins(): void
    {
        $contract = $this->createContract();

        $contractRepository = $this->createStub(ContractRepository::class);
        $contractRepository->method('get')->willReturn($contract);

        $orderRepository = $this->createStub(OrderRepository::class);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findByRole')->willReturn([]);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $handler = new SendAmountMismatchAlertEmailHandler(
            $contractRepository,
            $orderRepository,
            $userRepository,
            $mailer,
            new NullLogger(),
        );

        $handler(PaymentAmountMismatch::forContract(
            contractId: $contract->id,
            goPayPaymentId: 'gp_no_admins',
            expectedAmount: 150_000,
            receivedAmount: 100_000,
            occurredOn: new \DateTimeImmutable('2025-06-15 12:00:00'),
        ));
    }

    private function createContract(): Contract
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
            defaultPricePerMonth: 150_000,
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
            rentalType: RentalType::UNLIMITED,
            paymentFrequency: null,
            startDate: $now,
            endDate: null,
            firstPaymentPrice: 150_000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            rentalType: RentalType::UNLIMITED,
            startDate: $now,
            endDate: null,
            createdAt: $now,
        );
    }

    private function createAdmin(string $email, string $name): User
    {
        return new User(
            Uuid::v7(),
            $email,
            'password',
            $name,
            'Admin',
            new \DateTimeImmutable('2025-01-01 00:00:00'),
        );
    }
}
