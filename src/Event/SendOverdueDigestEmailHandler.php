<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\OverdueDigestSent;
use App\Repository\OverdueDigestSentRepository;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use App\Service\Overdue\OverdueChecker;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendOverdueDigestEmailHandler
{
    private const int TOP_N = 10;

    public function __construct(
        private OverdueChecker $overdueChecker,
        private UserRepository $userRepository,
        private OverdueDigestSentRepository $digestSentRepository,
        private MailerInterface $mailer,
        private ProvideIdentity $identityProvider,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(OverdueDigestRequested $event): void
    {
        $admin = $this->userRepository->get($event->adminId);

        if ($this->digestSentRepository->wasSentForAdminOn($admin, $event->date)) {
            return;
        }

        $now = $this->clock->now();
        $views = $this->overdueChecker->findOverdueViews($now);

        if (0 === count($views)) {
            return;
        }

        $top = array_slice($views, 0, self::TOP_N);
        $totalAmount = array_sum(array_map(static fn ($v): int => $v->overdueAmount, $views));
        $totalCount = count($views);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($admin->email, $admin->fullName))
            ->subject(sprintf('Po splatnosti — denní přehled (%d smluv)', $totalCount))
            ->htmlTemplate('email/overdue_digest.html.twig')
            ->context([
                'adminName' => $admin->fullName,
                'totalCount' => $totalCount,
                'totalAmount' => $totalAmount,
                'top' => $top,
                'overdueUrl' => $this->urlGenerator->generate(
                    'admin_overdue',
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                'date' => $event->date,
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send overdue digest e-mail', [
                'admin_id' => $event->adminId->toRfc4122(),
                'date' => $event->date->format('Y-m-d'),
                'exception' => $e,
            ]);

            return;
        }

        $this->digestSentRepository->save(new OverdueDigestSent(
            id: $this->identityProvider->next(),
            admin: $admin,
            date: $event->date,
            sentAt: $now,
            overdueCount: $totalCount,
            totalAmount: $totalAmount,
        ));
    }
}
