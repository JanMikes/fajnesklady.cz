<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\ReconcileGoPayRefundsCommand;
use App\Repository\PaymentRefundRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Backstop for card refunds (spec 092). GoPay may answer a refund with
 * ACCEPTED (asynchronous processing); the webhook normally completes it, this
 * cron covers a lost webhook by reading GoPay's refund history.
 *
 * With --gopay-payment=<id> it reconciles one payment on demand — that also
 * books a refund issued by hand in the GoPay console, so it shows in the
 * order history and nets out of revenue.
 */
#[AsCommand(
    name: 'app:reconcile-refunds',
    description: 'Confirm or fail pending card refunds from GoPay refund history (cron); --gopay-payment=<id> reconciles one payment on demand.',
)]
final class ReconcileRefundsCommand extends Command
{
    private const int GRACE_MINUTES = 10;

    public function __construct(
        private readonly PaymentRefundRepository $refundRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('gopay-payment', null, InputOption::VALUE_REQUIRED, 'Reconcile this GoPay payment id only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $single = $input->getOption('gopay-payment');
        if (\is_string($single) && '' !== $single) {
            return $this->reconcile($single, $io) ? Command::SUCCESS : Command::FAILURE;
        }

        $cutoff = $this->clock->now()->modify(sprintf('-%d minutes', self::GRACE_MINUTES));
        $goPayIds = [];
        foreach ($this->refundRepository->findPendingRequestedBefore($cutoff) as $refund) {
            if (null !== $refund->payment->goPayPaymentId) {
                $goPayIds[$refund->payment->goPayPaymentId] = true;
            }
        }

        if ([] === $goPayIds) {
            $io->info('No pending refunds to reconcile.');

            return Command::SUCCESS;
        }

        $failures = 0;
        foreach (array_keys($goPayIds) as $goPayId) {
            if (!$this->reconcile((string) $goPayId, $io)) {
                ++$failures;
            }
        }

        return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function reconcile(string $goPayPaymentId, SymfonyStyle $io): bool
    {
        try {
            // Through the bus: its middleware flushes AND dispatches the
            // PaymentRefunded event (customer e-mail + credit note).
            $this->commandBus->dispatch(new ReconcileGoPayRefundsCommand($goPayPaymentId));
            $io->text(sprintf('  [OK] GoPay payment %s reconciled.', $goPayPaymentId));

            return true;
        } catch (\Throwable $raw) {
            $exception = HandlerFailureUnwrap::unwrap($raw);

            try {
                $this->logger->error('Refund reconciliation failed', [
                    'gopay_payment_id' => $goPayPaymentId,
                    'exception' => $exception,
                ]);
            } catch (\Throwable) {
                $this->doctrine->resetManager();
            }

            $io->error(sprintf('  [FAIL] GoPay payment %s: %s', $goPayPaymentId, $exception->getMessage()));

            return false;
        }
    }
}
