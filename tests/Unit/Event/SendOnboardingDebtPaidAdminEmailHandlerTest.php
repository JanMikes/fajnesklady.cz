<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Event\OnboardingDebtPaid;
use App\Event\SendOnboardingDebtPaidAdminEmailHandler;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use App\Service\Order\OrderReferenceFormatter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

class SendOnboardingDebtPaidAdminEmailHandlerTest extends TestCase
{
    public function testSendsOneEmailPerAdmin(): void
    {
        $order = $this->createDebtOrder();
        $admins = [
            new User(Uuid::v7(), 'admin1@example.com', 'pw', 'Admin', 'One', new \DateTimeImmutable('2025-06-15 12:00:00')),
            new User(Uuid::v7(), 'admin2@example.com', 'pw', 'Admin', 'Two', new \DateTimeImmutable('2025-06-15 12:00:00')),
        ];

        $sentEmails = $this->dispatch($order, $admins);

        $this->assertCount(2, $sentEmails);

        $recipients = [];
        foreach ($sentEmails as $email) {
            $this->assertStringStartsWith('Dluh uhrazen — ', (string) $email->getSubject());
            foreach ($email->getTo() as $address) {
                $recipients[] = $address->getAddress();
            }
        }
        $this->assertSame(['admin1@example.com', 'admin2@example.com'], $recipients);
    }

    public function testIncludesCustomerAndAmountInContext(): void
    {
        $order = $this->createDebtOrder();
        $admins = [new User(Uuid::v7(), 'admin@example.com', 'pw', 'Admin', 'One', new \DateTimeImmutable('2025-06-15 12:00:00'))];

        $sentEmails = $this->dispatch($order, $admins);

        $this->assertCount(1, $sentEmails);
        $email = $sentEmails[0];
        \assert($email instanceof TemplatedEmail);
        $context = $email->getContext();
        $this->assertSame('Jan Novák', $context['customerName']);
        $this->assertSame('tenant@example.com', $context['customerEmail']);
        $this->assertSame('1 200', $context['amountCzk']);
        $this->assertSame('Small Box č. A1', $context['storageLabel']);
        $this->assertSame('Admin One', $context['adminName']);
    }

    public function testSendsNothingWhenNoAdmins(): void
    {
        $order = $this->createDebtOrder();

        $sentEmails = $this->dispatch($order, []);

        $this->assertCount(0, $sentEmails);
    }

    /**
     * @param array<User> $admins
     *
     * @return array<Email>
     */
    private function dispatch(Order $order, array $admins): array
    {
        $orderRepository = $this->createStub(OrderRepository::class);
        $orderRepository->method('get')->willReturn($order);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findByRole')->willReturn($admins);

        $sentEmails = [];
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmails) {
            $sentEmails[] = $email;
        });

        $handler = new SendOnboardingDebtPaidAdminEmailHandler(
            $orderRepository,
            $userRepository,
            new OrderReferenceFormatter(),
            $mailer,
            new NullLogger(),
        );

        $handler(new OnboardingDebtPaid(
            $order->id,
            $order->user->id,
            $order->onboardingDebtInHaler ?? 0,
            new \DateTimeImmutable('2025-06-15 12:00:00'),
        ));

        return $sentEmails;
    }

    private function createDebtOrder(): Order
    {
        $user = new User(Uuid::v7(), 'tenant@example.com', 'pw', 'Jan', 'Novák', new \DateTimeImmutable('2025-06-15 12:00:00'));

        $place = new Place(
            id: Uuid::v7(),
            name: 'Sklady Praha',
            address: 'Testovací 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-16'),
            endDate: new \DateTimeImmutable('2025-07-16'),
            firstPaymentPrice: 35000,
            expiresAt: new \DateTimeImmutable('2025-06-22'),
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
        $order->setOnboardingDebt(120_000); // 1 200 Kč
        $order->markDebtPaid(new \DateTimeImmutable('2025-06-15 11:00:00'));

        return $order;
    }
}
