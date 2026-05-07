<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Storage\StorageOccupancyService;
use App\Value\RentalSpan;
use App\Value\RentalSpanKind;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/calendar', name: 'portal_calendar')]
#[IsGranted('ROLE_LANDLORD')]
final class CalendarController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly StorageOccupancyService $occupancyService,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        $today = $this->clock->now();
        $defaultYear = (int) $today->format('Y');
        $defaultMonth = (int) $today->format('n');

        $year = (int) $request->query->get('year', (string) $defaultYear);
        $month = (int) $request->query->get('month', (string) $defaultMonth);

        if ($month < 1 || $month > 12) {
            $month = $defaultMonth;
        }
        if ($year < 2020 || $year > 2100) {
            $year = $defaultYear;
        }

        $view = (string) $request->query->get('view', 'month');
        if (!in_array($view, ['month', 'timeline'], true)) {
            $view = 'month';
        }

        $placeId = $request->query->get('place', 'all');
        $storageTypeId = $request->query->get('storage_type', 'all');

        if ($isAdmin) {
            $places = $this->placeRepository->findAll();
        } else {
            $places = $this->placeRepository->findByOwner($user);
        }

        $selectedPlace = null;
        if ('' !== $placeId && 'all' !== $placeId) {
            $selectedPlace = $this->placeRepository->find(Uuid::fromString($placeId));

            if (null !== $selectedPlace && !$isAdmin && !$this->placeRepository->isOwnedBy($selectedPlace, $user)) {
                throw $this->createAccessDeniedException();
            }
        }

        if (null !== $selectedPlace) {
            $storageTypes = $this->storageTypeRepository->findByPlace($selectedPlace);
        } elseif ($isAdmin) {
            $storageTypes = $this->storageTypeRepository->findAll();
        } else {
            $storageTypes = [];
        }

        $selectedStorageType = null;
        if ('' !== $storageTypeId && 'all' !== $storageTypeId) {
            $selectedStorageType = $this->storageTypeRepository->find(Uuid::fromString($storageTypeId));
        }

        $ownerFilter = $isAdmin ? null : $user;
        $storages = $this->storageRepository->findFiltered($ownerFilter, $selectedPlace, $selectedStorageType);

        $startOfMonth = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        $endOfMonth = $startOfMonth->modify('last day of this month');
        $daysInMonth = (int) $endOfMonth->format('j');

        $calendarData = [];
        $endingToday = [];
        $startingToday = [];
        $dayDetails = [];
        $spans = [];
        $rentalViews = [];

        if ([] !== $storages) {
            $spans = $this->occupancyService->spansInRange($storages, $startOfMonth, $endOfMonth);
            $rentalViews = $this->occupancyService->currentViews($storages, $today);

            // Per-day rollups for the month grid.
            $currentDate = $startOfMonth;
            while ($currentDate <= $endOfMonth) {
                $dayKey = $currentDate->format('Y-m-d');
                $available = 0;
                $occupied = 0;
                $unavailable = 0;
                $endsCount = 0;
                $startsCount = 0;
                $details = [];

                foreach ($storages as $storage) {
                    $storageId = $storage->id->toRfc4122();
                    $storageSpans = $spans[$storageId] ?? [];

                    $isOccupied = false;
                    $isUnavailable = false;
                    foreach ($storageSpans as $span) {
                        if (!$this->spanCovers($span, $currentDate)) {
                            continue;
                        }
                        if (RentalSpanKind::BLOCK === $span->kind) {
                            $isUnavailable = true;
                        } else {
                            $isOccupied = true;
                        }
                    }

                    if ($isOccupied) {
                        ++$occupied;
                    } elseif ($isUnavailable) {
                        ++$unavailable;
                    } else {
                        ++$available;
                    }

                    foreach ($storageSpans as $span) {
                        if (null !== $span->endDate && $span->endDate->format('Y-m-d') === $dayKey
                            && RentalSpanKind::BLOCK !== $span->kind) {
                            ++$endsCount;
                            $details[] = [
                                'kind' => 'ending',
                                'storageNumber' => $storage->number,
                                'tenantName' => $span->tenantName,
                                'date' => $span->endDate,
                            ];
                        }
                        if ($span->startDate->format('Y-m-d') === $dayKey
                            && RentalSpanKind::BLOCK !== $span->kind) {
                            ++$startsCount;
                            $details[] = [
                                'kind' => 'starting',
                                'storageNumber' => $storage->number,
                                'tenantName' => $span->tenantName,
                                'date' => $span->startDate,
                            ];
                        }
                    }
                }

                $calendarData[$dayKey] = [
                    'available' => $available,
                    'occupied' => $occupied,
                    'unavailable' => $unavailable,
                    'total' => count($storages),
                ];

                if ($endsCount > 0) {
                    $endingToday[$dayKey] = $endsCount;
                }
                if ($startsCount > 0) {
                    $startingToday[$dayKey] = $startsCount;
                }
                if ([] !== $details) {
                    $dayDetails[$dayKey] = array_slice($details, 0, 50);
                }

                $currentDate = $currentDate->modify('+1 day');
            }
        }

        $prevMonth = $month - 1;
        $prevYear = $year;
        if ($prevMonth < 1) {
            $prevMonth = 12;
            --$prevYear;
        }

        $nextMonth = $month + 1;
        $nextYear = $year;
        if ($nextMonth > 12) {
            $nextMonth = 1;
            ++$nextYear;
        }

        return $this->render('portal/calendar/index.html.twig', [
            'places' => $places,
            'selectedPlace' => $selectedPlace,
            'selectedPlaceId' => $placeId,
            'storageTypes' => $storageTypes,
            'selectedStorageType' => $selectedStorageType,
            'selectedStorageTypeId' => $storageTypeId,
            'storages' => $storages,
            'calendarData' => $calendarData,
            'spans' => $spans,
            'rentalViews' => $rentalViews,
            'today' => $today,
            'endingToday' => $endingToday,
            'startingToday' => $startingToday,
            'dayDetails' => $dayDetails,
            'startOfMonth' => $startOfMonth,
            'endOfMonth' => $endOfMonth,
            'daysInMonth' => $daysInMonth,
            'view' => $view,
            'year' => $year,
            'month' => $month,
            'monthName' => $this->getCzechMonthName($month),
            'prevMonth' => $prevMonth,
            'prevYear' => $prevYear,
            'nextMonth' => $nextMonth,
            'nextYear' => $nextYear,
        ]);
    }

    private function spanCovers(RentalSpan $span, \DateTimeImmutable $day): bool
    {
        if ($span->startDate > $day) {
            return false;
        }

        return null === $span->endDate || $span->endDate >= $day;
    }

    private function getCzechMonthName(int $month): string
    {
        $months = [
            1 => 'Leden',
            2 => 'Únor',
            3 => 'Březen',
            4 => 'Duben',
            5 => 'Květen',
            6 => 'Červen',
            7 => 'Červenec',
            8 => 'Srpen',
            9 => 'Září',
            10 => 'Říjen',
            11 => 'Listopad',
            12 => 'Prosinec',
        ];

        return $months[$month] ?? '';
    }
}
