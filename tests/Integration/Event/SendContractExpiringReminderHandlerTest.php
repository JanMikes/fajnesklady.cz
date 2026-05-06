<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Entity\Contract;
use App\Event\ContractExpiringSoon;
use App\Event\SendContractExpiringReminderHandler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class SendContractExpiringReminderHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SendContractExpiringReminderHandler $handler;
    private ClockInterface $clock;
    private Environment $twig;

    /** @var list<Email> */
    private array $sentEmails = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->handler = $container->get(SendContractExpiringReminderHandler::class);
        $this->clock = $container->get(ClockInterface::class);
        $this->twig = $container->get('test.twig');

        $dispatcher = $container->get('event_dispatcher');
        $this->sentEmails = [];
        // Register before BodyRenderer (priority 0) so we capture the TemplatedEmail
        // with its context still attached — BodyRenderer clears context on render.
        $dispatcher->addListener(MessageEvent::class, function (MessageEvent $event): void {
            $message = $event->getMessage();
            if ($message instanceof Email) {
                $this->sentEmails[] = clone $message;
            }
        }, priority: 1024);
    }

    public function testEmailContainsRenewalCtaForLimitedContract(): void
    {
        $contract = $this->findContractByStorageNumber('D3');

        ($this->handler)(new ContractExpiringSoon(
            contractId: $contract->id,
            daysRemaining: 7,
            occurredOn: $this->clock->now(),
        ));

        $email = $this->lastSentEmail();
        $this->assertInstanceOf(TemplatedEmail::class, $email);

        $context = $email->getContext();
        $this->assertTrue($context['isLimited']);
        $this->assertStringEndsWith(
            '/objednavka/prodlouzit/'.$contract->order->id->toRfc4122(),
            $context['renewalUrl'],
        );

        $body = $this->renderHtmlBody($email);
        $this->assertStringContainsString('Prodloužit pronájem', $body);
        $this->assertStringContainsString('/objednavka/prodlouzit/'.$contract->order->id->toRfc4122(), $body);
        $this->assertStringContainsString('Zobrazit smlouvu v portálu', $body);
    }

    public function testEmailHidesRenewalCtaForUnlimitedContract(): void
    {
        $contract = $this->findContractByStorageNumber('C1');

        ($this->handler)(new ContractExpiringSoon(
            contractId: $contract->id,
            daysRemaining: 3,
            occurredOn: $this->clock->now(),
        ));

        $email = $this->lastSentEmail();
        $this->assertInstanceOf(TemplatedEmail::class, $email);
        $this->assertFalse($email->getContext()['isLimited']);

        $body = $this->renderHtmlBody($email);
        $this->assertStringNotContainsString('Prodloužit pronájem', $body);
        $this->assertStringContainsString('Zobrazit smlouvu v portálu', $body);
    }

    private function findContractByStorageNumber(string $number): Contract
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->join('c.storage', 's')
            ->where('s.number = :number')
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($contract instanceof Contract, sprintf('No fixture contract on storage %s', $number));

        return $contract;
    }

    private function lastSentEmail(): Email
    {
        $this->assertNotEmpty($this->sentEmails, 'No email was sent.');

        return $this->sentEmails[count($this->sentEmails) - 1];
    }

    private function renderHtmlBody(TemplatedEmail $email): string
    {
        $template = $email->getHtmlTemplate();
        \assert(null !== $template);

        return $this->twig->render($template, $email->getContext());
    }
}
