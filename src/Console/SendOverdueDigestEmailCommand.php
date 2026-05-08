<?php

declare(strict_types=1);

namespace App\Console;

use App\Enum\UserRole;
use App\Event\OverdueDigestRequested;
use App\Repository\OverdueDigestSentRepository;
use App\Repository\UserRepository;
use App\Service\Overdue\OverdueChecker;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:send-overdue-digest-email',
    description: 'Send a daily digest e-mail to every admin when ≥1 contract is overdue.',
)]
final class SendOverdueDigestEmailCommand extends Command
{
    public function __construct(
        private readonly OverdueChecker $overdueChecker,
        private readonly UserRepository $userRepository,
        private readonly OverdueDigestSentRepository $digestSentRepository,
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();
        $today = $now->setTime(0, 0, 0);

        $summary = $this->overdueChecker->summarise($now);

        if (0 === $summary->count) {
            $io->info('No overdue contracts — digest skipped.');

            return Command::SUCCESS;
        }

        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        if (0 === count($admins)) {
            $io->warning('No admins to notify.');

            return Command::SUCCESS;
        }

        $dispatched = 0;
        $skipped = 0;

        foreach ($admins as $admin) {
            if ($this->digestSentRepository->wasSentForAdminOn($admin, $today)) {
                ++$skipped;

                continue;
            }

            $this->eventBus->dispatch(new OverdueDigestRequested(
                adminId: $admin->id,
                occurredOn: $now,
                date: $today,
            ));
            ++$dispatched;
        }

        $io->success(sprintf(
            'Overdue digest: %d overdue contracts; dispatched to %d admin(s), %d skipped (already sent today).',
            $summary->count,
            $dispatched,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
