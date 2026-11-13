<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\RecurringPaymentCancelUrlGenerator;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * Tenant stops card payments via the signed e-mail link. Podmínky opakovaných
 * plateb čl. VI: that never ends the lease nor the duty to pay — the unpaid
 * remainder must stay billed (bank transfer) and terminable when overdue.
 * Regression for 2026-11-12 (contract 019fec6a): cancelling cleared the
 * billing anchor and the tenant kept the unit unbilled.
 */
class CancelRecurringPaymentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;
    private MockGoPayClient $goPayClient;
    private RecurringPaymentCancelUrlGenerator $cancelUrlGenerator;
    private Environment $twig;

    /** @var list<Email> */
    private array $sentEmails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->clock = $container->get(ClockInterface::class);
        $this->goPayClient = $container->get(MockGoPayClient::class);
        $this->goPayClient->reset();
        $this->cancelUrlGenerator = $container->get(RecurringPaymentCancelUrlGenerator::class);
        $this->twig = $container->get('test.twig');

        $this->sentEmails = [];
        $container->get('event_dispatcher')->addListener(MessageEvent::class, function (MessageEvent $event): void {
            $message = $event->getMessage();
            if ($message instanceof Email) {
                $this->sentEmails[] = clone $message;
            }
        }, priority: 1024);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testUnsignedRequestIsDenied(): void
    {
        $contract = $this->createCardContract('gp_unsigned', nextBillingInDays: 15);

        $this->client->request('GET', '/opakovana-platba/'.$contract->id->toRfc4122().'/zrusit');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testTamperedSignatureIsDenied(): void
    {
        $contract = $this->createCardContract('gp_tampered', nextBillingInDays: 15);
        $other = $this->createCardContract('gp_other', nextBillingInDays: 15);

        // Signature of one contract must not open another.
        $url = str_replace($contract->id->toRfc4122(), $other->id->toRfc4122(), $this->cancelUrlGenerator->generate($contract));
        $this->request('GET', $url);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSignedPageOffersCardChangeFirst(): void
    {
        $contract = $this->createCardContract('gp_get', nextBillingInDays: 15);

        $this->request('GET', $this->cancelUrlGenerator->generate($contract));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Změnit platební kartu', $body);
        $this->assertStringContainsString('Zrušit platbu kartou', $body);
        $this->assertStringContainsString('nekončí', $body);
        $this->assertFalse($this->goPayClient->wasRecurrenceVoided('gp_get'), 'GET must not cancel anything.');
    }

    public function testCancelWhilePaidAheadKeepsScheduleOnBankTransfer(): void
    {
        $contract = $this->createCardContract('gp_ahead', nextBillingInDays: 15);
        $nextBillingDate = $contract->nextBillingDate;
        $paidThrough = $contract->paidThroughDate;

        $this->request('POST', $this->cancelUrlGenerator->generate($contract), ['action' => 'cancel']);

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Nájem tím nekončí', $body);
        $this->assertStringContainsString('Nastavit novou platební kartu', $body);
        $this->assertStringNotContainsString('po splatnosti', $body, 'Paid-ahead tenant is not overdue.');

        $this->assertTrue($this->goPayClient->wasRecurrenceVoided('gp_ahead'));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->goPayParentPaymentId);
        self::assertSame(BillingMode::MANUAL_RECURRING, $reloaded->billingMode, 'Remainder must be billed by bank transfer.');
        self::assertSame($nextBillingDate?->format('Y-m-d'), $reloaded->nextBillingDate?->format('Y-m-d'), 'Billing anchor day must survive the cancellation.');
        self::assertSame($paidThrough?->format('Y-m-d'), $reloaded->paidThroughDate?->format('Y-m-d'));
        self::assertFalse($reloaded->isTerminated(), 'Cancelling card payments never ends the lease.');
        self::assertSame(PaymentMethod::BANK_TRANSFER, $reloaded->order->paymentMethod);
        self::assertNotNull($reloaded->order->variableSymbol, 'Bank-transfer track needs a variable symbol.');

        $tenantBody = $this->renderedEmail('user@example.com', 'Platba kartou zrušena, nájem pokračuje');
        $this->assertStringContainsString('Nájem tím nekončí', $tenantBody);
        $this->assertStringContainsString('Nastavit novou platební kartu', $tenantBody);
        $this->assertStringNotContainsString('Děkujeme, že jste využívali', $tenantBody);

        $adminBody = $this->renderedEmail('admin@example.com', 'Platba kartou zrušena');
        $this->assertStringContainsString('Další platba', $adminBody);
        $this->assertStringContainsString('bankovním převodem', $adminBody);
    }

    public function testCancelWhileOverdueStatesDeadlineAndStaysTerminable(): void
    {
        // Pavel's case: due 3 days ago, card declined, tenant cancels.
        $contract = $this->createCardContract('gp_overdue', nextBillingInDays: -3);
        $dueDate = $contract->nextBillingDate;
        self::assertNotNull($dueDate);

        $this->request('POST', $this->cancelUrlGenerator->generate($contract), ['action' => 'cancel']);

        $this->assertResponseIsSuccessful();
        $lastDayToPay = $dueDate->setTime(0, 0)->modify('+7 days')->format('d.m.Y');
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Platba je po splatnosti', $body);
        $this->assertStringContainsString($lastDayToPay, $body);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertSame($dueDate->format('Y-m-d'), $reloaded->nextBillingDate?->format('Y-m-d'), 'Overdue cycle must stay on the books.');
        self::assertSame(BillingMode::MANUAL_RECURRING, $reloaded->billingMode);

        $adminBody = $this->renderedEmail('admin@example.com', 'Platba kartou zrušena');
        $this->assertStringContainsString('3 dny po splatnosti', $adminBody);
        $this->assertStringContainsString($lastDayToPay, $adminBody);

        // The new-card page calls the overdue cycle a debt, not "the upcoming period".
        $cardLink = (string) $this->client->getCrawler()->selectLink('Nastavit novou platební kartu')->attr('href');
        $this->request('GET', $cardLink);
        $this->assertResponseIsSuccessful();
        $cardPage = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('dlužnou platbu', $cardPage);
        $this->assertStringNotContainsString('nadcházející období', $cardPage);
    }

    public function testCardFailureCountIsClearedWhenBillingMovesToBankTransfer(): void
    {
        $contract = $this->createCardContract('gp_failed_then_cancel', nextBillingInDays: -3);
        $contract->recordFailedBillingAttempt($this->clock->now()->modify('-1 day'));
        $this->entityManager->flush();

        $this->request('POST', $this->cancelUrlGenerator->generate($contract), ['action' => 'cancel']);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->failedBillingAttempts);
        self::assertNull($reloaded->lastBillingFailedAt);
        self::assertNotNull($reloaded->nextBillingDate, 'Still overdue via the kept anchor.');
    }

    public function testChangeCardRedirectsToCardSetup(): void
    {
        $contract = $this->createCardContract('gp_change', nextBillingInDays: 15);

        $this->request('POST', $this->cancelUrlGenerator->generate($contract), ['action' => 'change_card']);

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/smlouva/'.$contract->id->toRfc4122().'/prodlouzit/karta', $location);
        $this->assertTrue($this->goPayClient->wasRecurrenceVoided('gp_change'));

        // The card-setup page must accept the tenant (signed link, no live token).
        $this->request('GET', $location);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Parametry opakované platby', (string) $this->client->getResponse()->getContent());
    }

    public function testCancelWhenPaidToEndJustStopsCard(): void
    {
        $contract = $this->createCardContract('gp_paid_end', nextBillingInDays: 15);
        // Last cycle already charged — nothing left to bill.
        $contract->recordBillingCharge($this->clock->now(), null, $contract->endDate);
        $this->entityManager->flush();

        $this->request('POST', $this->cancelUrlGenerator->generate($contract), ['action' => 'cancel']);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('zaplacen do konce smlouvy', (string) $this->client->getResponse()->getContent());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->goPayParentPaymentId);
        self::assertNull($reloaded->nextBillingDate);
        self::assertSame(BillingMode::AUTO_RECURRING, $reloaded->billingMode, 'Nothing to bill — no switch to bank transfer.');
    }

    public function testAlreadyCancelledLinkShowsWhatIsStillOwed(): void
    {
        $contract = $this->createCardContract('gp_twice', nextBillingInDays: 15);
        $url = $this->cancelUrlGenerator->generate($contract);

        $this->request('POST', $url, ['action' => 'cancel']);
        $this->request('GET', $url);

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Platba kartou již byla zrušena', $body);
        $this->assertStringContainsString('Další platba', $body);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function request(string $method, string $absoluteUrl, array $parameters = []): void
    {
        $parsed = parse_url($absoluteUrl);
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $host = ($parsed['host'] ?? 'localhost').(isset($parsed['port']) ? ':'.$parsed['port'] : '');

        $this->client->request($method, $path.$query, $parameters, [], ['HTTP_HOST' => $host]);
    }

    private function renderedEmail(string $to, string $subjectContains): string
    {
        foreach ($this->sentEmails as $email) {
            if (!$email instanceof TemplatedEmail || !str_contains((string) $email->getSubject(), $subjectContains)) {
                continue;
            }
            foreach ($email->getTo() as $address) {
                if ($address->getAddress() === $to) {
                    $template = $email->getHtmlTemplate();
                    \assert(null !== $template);

                    return $this->twig->render($template, $email->getContext());
                }
            }
        }

        self::fail(sprintf('No e-mail "%s" sent to %s.', $subjectContains, $to));
    }

    private function createCardContract(string $tokenId, int $nextBillingInDays): Contract
    {
        $now = $this->clock->now();
        $startDate = $now->modify('-45 days');
        $endDate = $now->modify('+120 days');
        $user = $this->findUser('user@example.com');
        $storage = $this->findStorage('A2');

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: 50000,
            expiresAt: $startDate->modify('+7 days'),
            createdAt: $startDate,
        );
        $order->setBillingMode(BillingMode::AUTO_RECURRING);
        $order->setPaymentMethod(PaymentMethod::GOPAY);
        $order->markPaid($startDate);
        $order->popEvents();
        $this->entityManager->persist($order);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: $startDate,
        );
        $contract->applyBillingMode(BillingMode::AUTO_RECURRING);
        $contract->sign($startDate);
        $nextBilling = $now->modify(sprintf('%+d days', $nextBillingInDays));
        $contract->setRecurringPayment($tokenId, $nextBilling, $nextBilling);
        $this->goPayClient->seedRecurrenceStatus($tokenId, 'PAID', $tokenId, 50000);
        $order->complete($contract->id, $startDate);
        $order->popEvents();
        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        return $contract;
    }

    private function findUser(string $email): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($user instanceof User);

        return $user;
    }

    private function findStorage(string $number): Storage
    {
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.number = :number')
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($storage instanceof Storage);

        return $storage;
    }
}
