<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Command\CreateHandoverProtocolCommand;
use App\Command\ProlongContractCommand;
use App\Entity\AuditLog;
use App\Entity\Contract;
use App\Entity\HandoverProtocol;
use App\Event\ContractProlonged;
use App\Event\DiscardPrematureHandoverOnContractProlongedHandler;
use App\Repository\HandoverProtocolRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Regression for order 01a0cd43 (2026-11): the handover cron opened a protocol
 * 7 days before the contract end, the tenant prolonged the same day, and the
 * stale protocol kept sending "hand over your unit" reminders to a tenant who
 * was staying — invisible on the order page because the handover e-mails were
 * not linked to the order.
 *
 * Fixtures: B3 (ends +29 d) carries an untouched PENDING protocol created
 * -3 d; E1 (termination notice, terminatesAt +30 d) a TENANT_COMPLETED one; the
 * terminated E1 contract an overdue PENDING one; D3 ends in +7 d with none.
 */
class HandoverProtocolProlongationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private HandoverProtocolRepository $protocolRepository;
    private ClockInterface $clock;

    /** @var list<Email> */
    private array $sentEmails = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->commandBus = $container->get('test.command.bus');
        $this->protocolRepository = $container->get(HandoverProtocolRepository::class);
        $this->clock = $container->get(ClockInterface::class);

        $this->sentEmails = [];
        $container->get('event_dispatcher')->addListener(MessageEvent::class, function (MessageEvent $event): void {
            $message = $event->getMessage();
            if ($message instanceof Email) {
                $this->sentEmails[] = clone $message;
            }
        }, priority: 1024);
    }

    public function testProlongationDiscardsUntouchedPrematureProtocol(): void
    {
        $contract = $this->contractOnStorage('B3');
        $protocol = $this->protocolRepository->findByContract($contract);
        self::assertNotNull($protocol);
        $protocolId = $protocol->id;

        $this->commandBus->dispatch(new ProlongContractCommand(
            contractId: $contract->id,
            newEndDate: $contract->endDate->modify('+60 days'),
            switchTo: null,
            actorId: null,
        ));
        $this->entityManager->clear();

        self::assertNull($this->entityManager->find(HandoverProtocol::class, $protocolId), 'Premature untouched protocol must be discarded.');

        $audit = $this->findAudit($protocolId, 'discarded_on_prolongation');
        self::assertNotNull($audit);
        self::assertTrue($contract->order->id->equals($audit->orderId), 'Discard must show on the order timeline.');
    }

    public function testProlongationKeepsPartiallyFilledProtocol(): void
    {
        $protocol = $this->tenantCompletedProtocol();
        $contract = $protocol->contract;

        $this->discardHandler()(new ContractProlonged(
            contractId: $contract->id,
            previousEndDate: $contract->endDate->modify('-1 month'),
            newEndDate: $contract->endDate,
            prolongedBy: null,
            occurredOn: $this->clock->now(),
        ));
        $this->entityManager->flush();

        self::assertNotNull($this->entityManager->find(HandoverProtocol::class, $protocol->id), 'A started protocol is evidence — never deleted.');
        self::assertNotNull($this->findAudit($protocol->id, 'kept_on_prolongation'));
    }

    public function testShortProlongationInsideWindowKeepsProtocol(): void
    {
        $contract = $this->contractOnStorage('D3'); // ends in +7 days → inside the window
        $this->commandBus->dispatch(new CreateHandoverProtocolCommand($contract->id));
        $created = $this->protocolRepository->findByContract($contract);
        self::assertNotNull($created);

        $this->discardHandler()(new ContractProlonged(
            contractId: $contract->id,
            previousEndDate: $contract->endDate->modify('-2 days'),
            newEndDate: $contract->endDate,
            prolongedBy: null,
            occurredOn: $this->clock->now(),
        ));
        $this->entityManager->flush();

        self::assertNotNull($this->entityManager->find(HandoverProtocol::class, $created->id));
        self::assertNull($this->findAudit($created->id, 'discarded_on_prolongation'));
    }

    public function testRemindersOnlyForProtocolsInsideTheHandoverWindow(): void
    {
        $now = $this->clock->now();
        $ids = array_map(
            static fn (HandoverProtocol $p): string => $p->contract->storage->number.'/'.$p->status->value,
            $this->protocolRepository->findIncompleteForReminders($now),
        );

        // B3 (ends +29 d) and the terminating E1 (+30 d) are outside the
        // window; only the overdue protocol of the terminated contract is due.
        self::assertNotContains('B3/pending', $ids);
        self::assertNotContains('E1/tenant_completed', $ids);
        self::assertContains('E1/pending', $ids);
    }

    public function testFirstReminderWaitsThreeDaysAfterTheRequest(): void
    {
        $contract = $this->contractOnStorage('D3');
        $this->commandBus->dispatch(new CreateHandoverProtocolCommand($contract->id));
        $protocol = $this->protocolRepository->findByContract($contract);
        self::assertNotNull($protocol);

        $now = $this->clock->now();
        self::assertNotContains($protocol, $this->protocolRepository->findIncompleteForReminders($now), 'The request e-mail just went out — no same-run reminder.');
        self::assertContains($protocol, $this->protocolRepository->findIncompleteForReminders($now->modify('+3 days')));
    }

    public function testHandoverRequestEmailIsLinkedToTheOrder(): void
    {
        $contract = $this->contractOnStorage('D3');

        $this->commandBus->dispatch(new CreateHandoverProtocolCommand($contract->id));

        $handoverEmails = array_values(array_filter(
            $this->sentEmails,
            static fn (Email $email): bool => str_contains((string) $email->getSubject(), 'Předávací protokol'),
        ));
        self::assertNotEmpty($handoverEmails);
        foreach ($handoverEmails as $email) {
            $header = $email->getHeaders()->get('X-Order-Id');
            self::assertNotNull($header, 'Handover e-mails must carry X-Order-Id so they appear on the order page.');
            self::assertSame($contract->order->id->toRfc4122(), $header->getBodyAsString());
        }
    }

    private function discardHandler(): DiscardPrematureHandoverOnContractProlongedHandler
    {
        return static::getContainer()->get(DiscardPrematureHandoverOnContractProlongedHandler::class);
    }

    private function contractOnStorage(string $number): Contract
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->join('c.storage', 's')
            ->where('s.number = :number')
            ->andWhere('c.terminatedAt IS NULL')
            ->setParameter('number', $number)
            ->getQuery()
            ->getSingleResult();
        \assert($contract instanceof Contract);

        return $contract;
    }

    private function tenantCompletedProtocol(): HandoverProtocol
    {
        $protocol = $this->entityManager->createQueryBuilder()
            ->select('hp')
            ->from(HandoverProtocol::class, 'hp')
            ->where('hp.tenantCompletedAt IS NOT NULL')
            ->getQuery()
            ->getSingleResult();
        \assert($protocol instanceof HandoverProtocol);

        return $protocol;
    }

    private function findAudit(Uuid $protocolId, string $eventType): ?AuditLog
    {
        return $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->where('al.entityType = :type')
            ->andWhere('al.entityId = :id')
            ->andWhere('al.eventType = :event')
            ->setParameter('type', 'handover')
            ->setParameter('id', $protocolId->toRfc4122())
            ->setParameter('event', $eventType)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
