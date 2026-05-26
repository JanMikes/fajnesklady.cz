<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Entity\Order;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Event\OrderCancelled;
use App\Event\SendOrderCancelledEmailHandler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

class SendOrderCancelledEmailHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SendOrderCancelledEmailHandler $handler;
    private ClockInterface $clock;
    private Environment $twig;

    /** @var list<Email> */
    private array $sentEmails = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->handler = $container->get(SendOrderCancelledEmailHandler::class);
        $this->clock = $container->get(ClockInterface::class);
        $this->twig = $container->get('test.twig');

        $dispatcher = $container->get('event_dispatcher');
        $this->sentEmails = [];
        $dispatcher->addListener(MessageEvent::class, function (MessageEvent $event): void {
            $message = $event->getMessage();
            if ($message instanceof Email) {
                $this->sentEmails[] = clone $message;
            }
        }, priority: 1024);
    }

    public function testEmailShowsMonthlyLabelForFixedTermRecurring(): void
    {
        // 30-day rental → one-time (< 31 days threshold).
        $order = $this->findOrderByStorageNumber('B1');

        ($this->handler)(new OrderCancelled($order->id, $this->clock->now()));

        $body = $this->renderHtmlBody($this->lastTemplatedEmail());

        $this->assertStringContainsString('Celková cena', $body);
        $this->assertStringNotContainsString('Měsíční platba', $body);
    }

    public function testEmailShowsMonthlyLabelForUnlimited(): void
    {
        $order = $this->findOrderByStorageNumber('C1');

        ($this->handler)(new OrderCancelled($order->id, $this->clock->now()));

        $body = $this->renderHtmlBody($this->lastTemplatedEmail());

        $this->assertStringContainsString('Měsíční platba', $body);
        $this->assertStringContainsString('/ měsíc', $body);
        $this->assertStringNotContainsString('Celková cena', $body);
    }

    public function testEmailShowsTotalLabelForOneTime(): void
    {
        $order = $this->createOneTimeOrder();

        ($this->handler)(new OrderCancelled($order->id, $this->clock->now()));

        $body = $this->renderHtmlBody($this->lastTemplatedEmail());

        $this->assertStringContainsString('Celková cena', $body);
        $this->assertStringNotContainsString('Měsíční platba', $body);
        $this->assertStringNotContainsString('/ měsíc', $body);
    }

    private function findOrderByStorageNumber(string $number): Order
    {
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.number = :number')
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($order instanceof Order, sprintf('No fixture order on storage %s', $number));

        return $order;
    }

    private function createOneTimeOrder(): Order
    {
        $sourceOrder = $this->findOrderByStorageNumber('D1');

        $now = $this->clock->now();
        $start = new \DateTimeImmutable('2025-06-15');
        $end = $start->modify('+14 days');

        $order = new Order(
            id: Uuid::v7(),
            user: $sourceOrder->user,
            storage: $sourceOrder->storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $start,
            endDate: $end,
            firstPaymentPrice: 180_000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );
        $order->popEvents();
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function lastTemplatedEmail(): TemplatedEmail
    {
        $this->assertNotEmpty($this->sentEmails, 'No email was sent.');
        $email = $this->sentEmails[count($this->sentEmails) - 1];
        \assert($email instanceof TemplatedEmail);

        return $email;
    }

    private function renderHtmlBody(TemplatedEmail $email): string
    {
        $template = $email->getHtmlTemplate();
        \assert(null !== $template);

        return $this->twig->render($template, $email->getContext());
    }
}
