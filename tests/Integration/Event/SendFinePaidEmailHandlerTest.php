<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Entity\Contract;
use App\Entity\Fine;
use App\Entity\Invoice;
use App\Entity\User;
use App\Enum\FineType;
use App\Event\FinePaid;
use App\Event\SendFinePaidEmailHandler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * Runs the fine-paid receipt through the real handler + Twig with the mock
 * Fakturoid client, so both the invoice bookkeeping (Invoice row linked to
 * the fine, markEmailed suppression) and the template rendering are covered.
 */
class SendFinePaidEmailHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SendFinePaidEmailHandler $handler;
    private ClockInterface $clock;
    private Environment $twig;

    /** @var list<Email> */
    private array $sentEmails = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->handler = $container->get(SendFinePaidEmailHandler::class);
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

    public function testIssuesInvoiceLinkedToFineAndBundlesPdfIntoReceipt(): void
    {
        $fine = $this->createPaidFine();

        ($this->handler)(new FinePaid(
            fineId: $fine->id,
            contractId: $fine->contract->id,
            userId: $fine->user->id,
            amountInHaler: $fine->amountInHaler,
            occurredOn: $this->clock->now(),
        ));

        // The handler runs outside a messenger envelope here, so flush the
        // invoice the InvoicingService persisted before querying it back.
        $this->entityManager->flush();

        $invoice = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.fine = :fine')
            ->setParameter('fine', $fine)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($invoice instanceof Invoice, 'Expected an invoice linked to the paid fine.');
        $this->assertTrue($invoice->order->id->equals($fine->contract->order->id));
        $this->assertSame($fine->amountInHaler, $invoice->amount);
        $this->assertTrue($invoice->hasPdf(), 'Mock Fakturoid PDF should have been stored.');
        $this->assertTrue($invoice->isEmailed(), 'Standalone invoice e-mail must be suppressed.');

        $email = $this->lastTemplatedEmail();
        $names = array_map(static fn ($a) => $a->getFilename(), $email->getAttachments());
        $this->assertContains(sprintf('faktura_%s.pdf', $invoice->invoiceNumber), $names);

        $body = $this->twig->render((string) $email->getHtmlTemplate(), $email->getContext());
        $this->assertStringContainsString('Pokuta zaplacena', $body);
        $this->assertStringContainsString('6 000 Kč', $body);
        $this->assertStringContainsString($invoice->invoiceNumber, $body);
        $this->assertStringContainsString('Zobrazit detail objednávky', $body);
        // Permanent-access CTA must point at the signed /stav permalink.
        $this->assertMatchesRegularExpression('~/stav\?_hash=~', $body);
    }

    private function createPaidFine(): Fine
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($contract instanceof Contract, 'No fixture contract available.');

        $admin = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', 'admin@example.com')
            ->getQuery()
            ->getOneOrNullResult();
        \assert($admin instanceof User, 'Admin fixture not found.');

        $now = $this->clock->now();
        $fine = new Fine(
            id: Uuid::v7(),
            contract: $contract,
            user: $contract->user,
            issuedBy: $admin,
            type: FineType::DIRTY_STORAGE,
            amountInHaler: 600_000,
            description: 'Znečištěná skladovací jednotka.',
            issuedAt: $now->modify('-1 day'),
            createdAt: $now->modify('-1 day'),
        );
        $fine->markPaid($now);
        // Constructor + markPaid buffer domain events; drop them so persisting
        // directly in the test stays side-effect free (mirrors the fixtures' pattern).
        $fine->popEvents();

        $this->entityManager->persist($fine);
        $this->entityManager->flush();

        return $fine;
    }

    private function lastTemplatedEmail(): TemplatedEmail
    {
        $this->assertNotEmpty($this->sentEmails, 'No email was sent.');
        $email = $this->sentEmails[count($this->sentEmails) - 1];
        \assert($email instanceof TemplatedEmail);

        return $email;
    }
}
