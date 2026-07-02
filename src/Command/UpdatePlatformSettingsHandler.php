<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PlatformSettingsRepository;
use App\Service\AuditLogger;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdatePlatformSettingsHandler
{
    public function __construct(
        private PlatformSettingsRepository $settingsRepository,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(UpdatePlatformSettingsCommand $command): void
    {
        $settings = $this->settingsRepository->getSettings();
        $oldValue = $settings->bankTransferSurchargeInHaler;
        $oldOverdueDays = $settings->overdueTerminationDays;

        $now = $this->clock->now();
        $settings->updateSurcharge($command->bankTransferSurchargeInHaler, $now);
        $settings->updateOverdueTerminationDays($command->overdueTerminationDays, $now);
        $this->settingsRepository->save($settings);

        $this->auditLogger->log(
            entityType: 'platform_settings',
            entityId: $settings->id->toRfc4122(),
            eventType: 'surcharge_changed',
            payload: [
                'old_value_haler' => $oldValue,
                'new_value_haler' => $command->bankTransferSurchargeInHaler,
                'old_overdue_termination_days' => $oldOverdueDays,
                'new_overdue_termination_days' => $command->overdueTerminationDays,
            ],
        );
    }
}
