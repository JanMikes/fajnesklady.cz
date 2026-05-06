<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Event\RecurringPaymentEstablished;
use App\Event\SendRecurringPaymentEstablishedEmailHandler;
use App\Repository\OrderRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class SendRecurringPaymentEstablishedEmailHandlerTest extends TestCase
{
    public function testHandlerSendsEmailToCustomerWithExpectedSubject(): void
    {
        $order = $this->createOrder();
        $event = new RecurringPaymentEstablished(
            orderId: $order->id,
            goPayParentPaymentId: 'gp-parent-1',
            amount: 350000,
            occurredOn: new \DateTimeImmutable('2026-05-05 12:00:00'),
        );

        $sentEmail = null;
        $handler = $this->createHandler($order, $sentEmail);
        $handler($event);

        $this->assertNotNull($sentEmail);
        $to = $sentEmail->getTo();
        $this->assertCount(1, $to);
        $this->assertSame('tenant@example.com', $to[0]->getAddress());
        $this->assertNotNull($sentEmail->getSubject());
        $this->assertStringContainsString('Opakovaná platba', $sentEmail->getSubject());
    }

    public function testEmailContextCarriesParametersForTemplate(): void
    {
        $order = $this->createOrder();
        $event = new RecurringPaymentEstablished(
            orderId: $order->id,
            goPayParentPaymentId: 'gp-parent-1',
            amount: 350000,
            occurredOn: new \DateTimeImmutable('2026-05-12 12:00:00'),
        );

        $sentEmail = null;
        $handler = $this->createHandler($order, $sentEmail);
        $handler($event);

        $this->assertInstanceOf(TemplatedEmail::class, $sentEmail);
        $this->assertSame('email/recurring_payment_established.html.twig', $sentEmail->getHtmlTemplate());

        $context = $sentEmail->getContext();
        $this->assertSame('Jan Novak', $context['name']);
        $this->assertSame('3 500,00', $context['amountInCzk'], 'Contract amount in CZK must be formatted with comma decimal.');
        $this->assertSame('12.', $context['debitDay'], 'Debit day-of-month must be derived from the consent timestamp.');
        $this->assertSame('12.05.2026', $context['establishedOn']);
    }

    /**
     * @param Email|null $sentEmail Captured email reference
     */
    private function createHandler(Order $order, ?Email &$sentEmail): SendRecurringPaymentEstablishedEmailHandler
    {
        $orderRepository = $this->createStub(OrderRepository::class);
        $orderRepository->method('get')->willReturn($order);

        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail): void {
            $sentEmail = $email;
        });

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/portal/order');

        return new SendRecurringPaymentEstablishedEmailHandler(
            $orderRepository,
            $mailer,
            $urlGenerator,
            new NullLogger(),
        );
    }

    private function createOrder(): Order
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

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::UNLIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2026-05-12'),
            endDate: null,
            firstPaymentPrice: 350000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable('2026-05-05'),
        );
    }
}
