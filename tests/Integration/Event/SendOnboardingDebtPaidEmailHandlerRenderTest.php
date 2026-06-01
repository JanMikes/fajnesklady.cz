<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Entity\Order;
use App\Enum\RentalType;
use App\Event\OnboardingDebtPaid;
use App\Event\SendOnboardingDebtPaidEmailHandler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * Renders the debt-paid receipt through the real handler + Twig (Fakturoid is
 * the test mock) so a template regression — a bad filter, a missing context
 * key — is caught for real, not just at the type level.
 */
class SendOnboardingDebtPaidEmailHandlerRenderTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SendOnboardingDebtPaidEmailHandler $handler;
    private ClockInterface $clock;
    private Environment $twig;

    /** @var list<Email> */
    private array $sentEmails = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->handler = $container->get(SendOnboardingDebtPaidEmailHandler::class);
        $this->clock = $container->get(ClockInterface::class);
        $this->twig = $container->get('test.twig');

        $this->sentEmails = [];
        $container->get('event_dispatcher')->addListener(MessageEvent::class, function (MessageEvent $event): void {
            $message = $event->getMessage();
            if ($message instanceof Email) {
                $this->sentEmails[] = clone $message;
            }
        }, priority: 1024);
    }

    public function testRendersReceiptWithStorageAmountInvoiceAndFirstRentCta(): void
    {
        $order = $this->createDebtOrder();

        ($this->handler)(new OnboardingDebtPaid($order->id, $order->user->id, 120_000, $this->clock->now()));

        $email = $this->lastTemplatedEmail();
        $body = $this->twig->render((string) $email->getHtmlTemplate(), $email->getContext());

        $this->assertStringContainsString('Dluh uhrazen', $body);
        $this->assertStringContainsString('1 200 Kč', $body);
        $this->assertStringContainsString('č. '.$order->storage->number, $body);
        // Standard billing (still payable) → first-rent CTA, not the "aktivní" branch.
        $this->assertStringContainsString('zaplatit nájemné', mb_strtolower($body));
        // Debt invoice issued via the mock Fakturoid client → bundled + referenced.
        $this->assertStringContainsString('Faktura', $body);
        $names = array_map(static fn ($a) => $a->getFilename(), $email->getAttachments());
        $this->assertNotEmpty(array_filter($names, static fn ($n) => str_starts_with((string) $n, 'faktura_')));
    }

    public function testAdminTemplateRenders(): void
    {
        $body = $this->twig->render('email/debt_paid_admin.html.twig', [
            'adminName' => 'Admin One',
            'customerName' => 'Jan Novák',
            'customerEmail' => 'tenant@example.com',
            'amountCzk' => '1 200',
            'paidAt' => new \DateTimeImmutable('2025-06-15 11:00:00'),
            'placeName' => 'Sklady Praha',
            'storageLabel' => 'Small Box č. A1',
            'orderReference' => '2025-0615-ABCDEF12',
        ]);

        $this->assertStringContainsString('Dluh uhrazen', $body);
        $this->assertStringContainsString('Jan Novák', $body);
        $this->assertStringContainsString('1 200 Kč', $body);
        $this->assertStringContainsString('Small Box č. A1', $body);
    }

    private function createDebtOrder(): Order
    {
        $source = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($source instanceof Order, 'No fixture order available to borrow a storage + user from.');

        $now = $this->clock->now();
        $order = new Order(
            id: Uuid::v7(),
            user: $source->user,
            storage: $source->storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-20'),
            endDate: new \DateTimeImmutable('2025-07-20'),
            firstPaymentPrice: 35000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );
        $order->setOnboardingDebt(120_000); // 1 200 Kč
        $order->markDebtPaid($now);
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
}
