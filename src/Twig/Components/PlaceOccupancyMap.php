<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Enum\OrderStatus;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\UserRepository;
use App\Service\Storage\StorageOccupancyService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class PlaceOccupancyMap
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $placeId = '';

    #[LiveProp]
    public ?string $landlordId = null;

    /** YYYY-MM-DD. Empty string means "today" via the clock. */
    #[LiveProp(writable: true)]
    public string $viewDate = '';

    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly UserRepository $userRepository,
        private readonly StorageOccupancyService $occupancyService,
        private readonly ClockInterface $clock,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getViewDateOrToday(): \DateTimeImmutable
    {
        if ('' !== $this->viewDate) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->viewDate);
            if (false !== $parsed) {
                return $parsed;
            }
        }

        return $this->clock->now()->setTime(0, 0);
    }

    #[LiveAction]
    public function setToday(): void
    {
        $this->viewDate = $this->clock->now()->format('Y-m-d');
    }

    #[LiveAction]
    public function shiftDays(#[LiveArg] int $days): void
    {
        $base = $this->getViewDateOrToday();
        $this->viewDate = $base->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * @return array{place: \App\Entity\Place, storagesJson: string, viewDate: \DateTimeImmutable, hasMapImage: bool}
     */
    public function getMapData(): array
    {
        $place = $this->placeRepository->get(Uuid::fromString($this->placeId));
        $owner = null !== $this->landlordId
            ? $this->userRepository->get(Uuid::fromString($this->landlordId))
            : null;

        $storages = null === $owner
            ? $this->storageRepository->findByPlace($place)
            : $this->storageRepository->findByOwnerAndPlace($owner, $place);

        $viewDate = $this->getViewDateOrToday();
        $views = $this->occupancyService->currentViews($storages, $viewDate);
        $orderRouteName = null !== $owner ? 'portal_landlord_order_detail' : 'admin_order_detail';

        $payload = [];
        foreach ($storages as $storage) {
            $view = $views[$storage->id->toRfc4122()] ?? null;
            $contract = $view?->currentContract;
            $order = $view?->currentOrder;
            $block = $view?->blockedBy;

            $status = match (true) {
                null !== $contract => 'occupied',
                null !== $order && OrderStatus::PAID === $order->status => 'occupied',
                null !== $order => 'reserved',
                null !== $block => 'manually_unavailable',
                default => 'available',
            };

            $orderForLink = null !== $contract ? $contract->order : $order;
            $orderUrl = null !== $orderForLink
                ? $this->urlGenerator->generate($orderRouteName, ['id' => $orderForLink->id->toRfc4122()])
                : null;

            $tenantName = null;
            if (null !== $contract) {
                $tenantName = $contract->user->fullName;
            } elseif (null !== $order) {
                $tenantName = $order->user->fullName;
            }

            $rentedUntilStr = $view?->rentedUntil?->format('Y-m-d');
            $rentedFromStr = $view?->rentedFrom?->format('Y-m-d');
            $viewDateStr = $viewDate->format('Y-m-d');

            $payload[] = [
                'id' => $storage->id->toRfc4122(),
                'number' => $storage->number,
                'storageTypeId' => $storage->storageType->id->toRfc4122(),
                'storageTypeName' => $storage->storageType->name,
                'dimensions' => $storage->storageType->getDimensionsInMeters(),
                'coordinates' => $storage->coordinates,
                'status' => $status,
                'lockCode' => $storage->lockCode,
                'tenantName' => $tenantName,
                'rentedFrom' => $rentedFromStr,
                'rentedUntil' => $rentedUntilStr,
                'isUnlimited' => null !== $contract && null === $contract->endDate && null === $contract->terminatesAt,
                'isTerminating' => null !== $contract && null !== $contract->terminatesAt,
                'startsOnViewDate' => null !== $rentedFromStr && $rentedFromStr === $viewDateStr,
                'endsOnViewDate' => null !== $rentedUntilStr && $rentedUntilStr === $viewDateStr,
                'orderUrl' => $orderUrl,
                'photoUrls' => [],
                'pricePerMonth' => $storage->getEffectivePricePerMonthInCzk(),
                'pricePerWeek' => $storage->getEffectivePricePerWeekInCzk(),
            ];
        }

        return [
            'place' => $place,
            'storagesJson' => json_encode($payload, JSON_THROW_ON_ERROR),
            'viewDate' => $viewDate,
            'hasMapImage' => null !== $place->mapImagePath,
        ];
    }
}
