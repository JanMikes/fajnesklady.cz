<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\DispatchOnboardingReminderCommand;
use App\Repository\OrderRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Service\Onboarding\OnboardingReminderSchedule;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:send-onboarding-payment-reminders',
    description: 'Send D+2 / D+5 payment-reminder e-mails for admin-onboarded GoPay orders that the customer signed but never paid',
)]
final class SendOnboardingPaymentRemindersCommand extends Command
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();

        $orders = $this->orderRepository->findUnpaidSignedOnboarding($now);

        if (0 === count($orders)) {
            $io->info('No unpaid signed onboarding orders to nudge.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Inspecting %d unpaid signed onboarding orders.', count($orders)));

        $dispatched = 0;
        $failures = 0;

        foreach ($orders as $order) {
            try {
                if (null === $order->signedAt) {
                    continue;
                }

                $stage = OnboardingReminderSchedule::stageDueOn($now, $order->signedAt);

                if (null === $stage) {
                    continue;
                }

                $this->commandBus->dispatch(new DispatchOnboardingReminderCommand(
                    orderId: $order->id,
                    stage: $stage,
                ));
                ++$dispatched;
                $io->text(sprintf(
                    '  [OK] Order %s — stage %s dispatched.',
                    $order->id,
                    $stage,
                ));
            } catch (\Throwable $rawException) {
                ++$failures;
                $exception = HandlerFailureUnwrap::unwrap($rawException);

                $this->logger->error('Failed to dispatch onboarding payment reminder', [
                    'order_id' => $order->id->toRfc4122(),
                    'exception' => $exception,
                ]);
                $io->error(sprintf('  [FAIL] Order %s: %s', $order->id, $exception->getMessage()));

                $manager = $this->doctrine->getManager();
                if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                    $this->doctrine->resetManager();
                }
            }
        }

        $io->success(sprintf('Dispatched: %d, failures: %d.', $dispatched, $failures));

        return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
