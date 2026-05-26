<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlatformSettings;
use App\Service\Identity\ProvideIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

class PlatformSettingsRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProvideIdentity $identityProvider,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getSettings(): PlatformSettings
    {
        $settings = $this->entityManager->createQueryBuilder()
            ->select('ps')
            ->from(PlatformSettings::class, 'ps')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null !== $settings) {
            return $settings;
        }

        $settings = new PlatformSettings(
            id: $this->identityProvider->next(),
            createdAt: $this->clock->now(),
        );

        $this->entityManager->persist($settings);
        // Documented exception: singleton bootstrap outside messenger envelope
        $this->entityManager->flush();

        return $settings;
    }

    public function save(PlatformSettings $settings): void
    {
        $this->entityManager->persist($settings);
    }
}
