<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\OverdueDigestSent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class SendOverdueDigestEmailCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Application $application;
    private ClockInterface $clock;
    private Environment $twig;

    /** @var list<Email> */
    private array $sentEmails = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();

        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        $this->clock = $clock;

        /** @var Environment $twig */
        $twig = $container->get('test.twig');
        $this->twig = $twig;

        $this->application = new Application(self::$kernel);

        $dispatcher = $container->get('event_dispatcher');
        $this->sentEmails = [];
        // Register before BodyRenderer so the TemplatedEmail still has its
        // context attached for assertions.
        $dispatcher->addListener(MessageEvent::class, function (MessageEvent $event): void {
            $message = $event->getMessage();
            if ($message instanceof Email) {
                $this->sentEmails[] = clone $message;
            }
        }, priority: 1024);
    }

    public function testDispatchesOneEmailPerAdminWhenOverdueExists(): void
    {
        $admin = $this->getAdmin();

        $command = $this->application->find('app:send-overdue-digest-email');
        $tester = new CommandTester($command);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Overdue digest:', $output);
        $this->assertStringContainsString('dispatched to', $output);

        $emailsToAdmin = $this->emailsTo($admin->email);
        $this->assertCount(1, $emailsToAdmin, 'Admin should receive exactly one digest e-mail.');

        $email = $emailsToAdmin[0];
        $this->assertInstanceOf(TemplatedEmail::class, $email);
        $this->assertStringContainsString('Po splatnosti — denní přehled', $email->getSubject());

        $body = $this->renderHtmlBody($email);
        $this->assertStringContainsString('Zobrazit všechny po splatnosti', $body);
        $this->assertStringContainsString('po-splatnosti', $body);

        $this->entityManager->clear();
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(OverdueDigestSent::class, 'd')
            ->where('d.admin = :admin')
            ->setParameter('admin', $admin->id->toRfc4122())
            ->getQuery()
            ->getResult();
        $this->assertCount(1, $rows, 'Exactly one OverdueDigestSent row should exist for the admin.');
    }

    public function testNoEmailWhenNoOverdueContracts(): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(Contract::class, 'c')
            ->getQuery()
            ->execute();

        $command = $this->application->find('app:send-overdue-digest-email');
        $tester = new CommandTester($command);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->assertStringContainsString('No overdue contracts', $tester->getDisplay());
        $this->assertCount(0, $this->sentEmails);
        $this->assertSame(0, $this->countDigestRows());
    }

    public function testRerunSameDayDoesNotResend(): void
    {
        $command = $this->application->find('app:send-overdue-digest-email');

        $tester = new CommandTester($command);
        $tester->execute([]);
        $emailsAfterFirstRun = count($this->sentEmails);
        $rowsAfterFirstRun = $this->countDigestRows();
        $this->assertGreaterThan(0, $emailsAfterFirstRun);
        $this->assertGreaterThan(0, $rowsAfterFirstRun);

        $tester2 = new CommandTester($command);
        $tester2->execute([]);

        $this->assertCount($emailsAfterFirstRun, $this->sentEmails, 'Second run must not send any new e-mails.');
        $this->assertSame($rowsAfterFirstRun, $this->countDigestRows(), 'Second run must not insert new digest rows.');
        $this->assertStringContainsString('skipped', $tester2->getDisplay());
    }

    public function testHandlerSkipsAdminAlreadySentForDay(): void
    {
        $admin = $this->getAdmin();
        $now = $this->clock->now();

        $existing = new OverdueDigestSent(
            id: \Symfony\Component\Uid\Uuid::v7(),
            admin: $admin,
            date: $now->setTime(0, 0, 0),
            sentAt: $now,
            overdueCount: 1,
            totalAmount: 1000,
        );
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $command = $this->application->find('app:send-overdue-digest-email');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $emailsToAdmin = $this->emailsTo($admin->email);
        $this->assertCount(0, $emailsToAdmin, 'Admin already marked as sent must not receive another e-mail.');
    }

    private function getAdmin(): User
    {
        $admin = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', UserFixtures::ADMIN_EMAIL)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($admin instanceof User);

        return $admin;
    }

    /**
     * @return list<Email>
     */
    private function emailsTo(string $address): array
    {
        return array_values(array_filter($this->sentEmails, static function (Email $email) use ($address): bool {
            foreach ($email->getTo() as $to) {
                if ($to->getAddress() === $address) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function countDigestRows(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(OverdueDigestSent::class, 'd')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function renderHtmlBody(TemplatedEmail $email): string
    {
        $template = $email->getHtmlTemplate();
        \assert(null !== $template);

        return $this->twig->render($template, $email->getContext());
    }
}
