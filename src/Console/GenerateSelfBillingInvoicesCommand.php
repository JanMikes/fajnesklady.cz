<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\GenerateSelfBillingInvoiceCommand;
use App\Entity\SelfBillingInvoice;
use App\Exception\NoPaymentsForPeriod;
use App\Repository\SelfBillingInvoiceRepository;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Console command to generate monthly self-billing invoices for landlords.
 *
 * Self-billing: Platform issues invoices on behalf of landlords for their commission share.
 * Should be run monthly (e.g., on 1st of each month).
 */
#[AsCommand(
    name: 'app:generate-self-billing-invoices',
    description: 'Generate monthly self-billing invoices for landlords',
)]
final class GenerateSelfBillingInvoicesCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly SelfBillingInvoiceRepository $selfBillingInvoiceRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'year',
                null,
                InputOption::VALUE_REQUIRED,
                'Year to generate invoices for (defaults to previous month\'s year)',
            )
            ->addOption(
                'month',
                null,
                InputOption::VALUE_REQUIRED,
                'Month to generate invoices for (defaults to previous month)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();

        // Calculate target period (default: previous month)
        [$year, $month] = $this->getTargetPeriod($input, $now);

        $io->title(sprintf('Generating self-billing invoices for %02d/%d', $month, $year));

        // Get all landlords eligible for self-billing (excluding admins)
        $landlords = $this->userRepository->findLandlordsForSelfBilling();

        if ([] === $landlords) {
            $io->info('No landlords found for self-billing.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found %d landlord(s) to process.', count($landlords)));
        $io->newLine();

        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($landlords as $landlord) {
            // Skip landlords without self-billing prefix
            if (!$landlord->hasSelfBillingPrefix()) {
                ++$skipped;
                $io->writeln(sprintf(
                    '  <comment>[SKIP]</comment> %s: no self-billing prefix configured',
                    $landlord->fullName,
                ));

                continue;
            }

            try {
                // Check if invoice already exists before processing
                $existedBefore = null !== $this->selfBillingInvoiceRepository
                    ->findByLandlordAndPeriod($landlord, $year, $month);

                $envelope = $this->commandBus->dispatch(new GenerateSelfBillingInvoiceCommand(
                    landlordId: $landlord->id,
                    year: $year,
                    month: $month,
                ));

                /** @var HandledStamp $handledStamp */
                $handledStamp = $envelope->last(HandledStamp::class);
                /** @var SelfBillingInvoice $invoice */
                $invoice = $handledStamp->getResult();

                if ($existedBefore) {
                    ++$skipped;
                    $io->writeln(sprintf(
                        '  <comment>[EXISTS]</comment> %s: invoice already exists',
                        $landlord->fullName,
                    ));
                } else {
                    ++$created;
                    $io->writeln(sprintf(
                        '  <info>[NEW]</info> %s: %s (%.2f CZK)',
                        $landlord->fullName,
                        $invoice->invoiceNumber,
                        $invoice->getNetAmountInCzk(),
                    ));
                }
            } catch (HandlerFailedException $e) {
                $previous = $e->getPrevious();

                if ($previous instanceof NoPaymentsForPeriod) {
                    ++$skipped;
                    $io->writeln(sprintf(
                        '  <comment>[SKIP]</comment> %s: no payments in period',
                        $landlord->fullName,
                    ));
                } else {
                    ++$errors;
                    $io->writeln(sprintf(
                        '  <error>[ERROR]</error> %s: %s',
                        $landlord->fullName,
                        $previous?->getMessage() ?? $e->getMessage(),
                    ));
                }
            } catch (\Throwable $e) {
                ++$errors;
                $io->writeln(sprintf(
                    '  <error>[ERROR]</error> %s: %s',
                    $landlord->fullName,
                    $e->getMessage(),
                ));
            }
        }

        $io->newLine();

        if ($errors > 0) {
            $io->warning(sprintf(
                'Completed with errors: %d created, %d skipped, %d errors',
                $created,
                $skipped,
                $errors,
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Done: %d invoice(s) created, %d skipped',
            $created,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{int, int} [year, month]
     */
    private function getTargetPeriod(InputInterface $input, \DateTimeImmutable $now): array
    {
        $yearOption = $input->getOption('year');
        $monthOption = $input->getOption('month');

        // If both are provided, use them
        if (null !== $yearOption && null !== $monthOption) {
            return [(int) $yearOption, (int) $monthOption];
        }

        // Default: previous month
        $previousMonth = $now->modify('first day of last month');

        return [
            (int) $previousMonth->format('Y'),
            (int) $previousMonth->format('n'),
        ];
    }
}
