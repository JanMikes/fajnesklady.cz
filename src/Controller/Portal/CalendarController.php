<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\OrderRepository;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Repository\StorageUnavailabilityRepository;
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
        private readonly OrderRepository $orderRepository,
        private readonly StorageUnavailabilityRepository $unavailabilityRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        // Get month/year from query params, default to current month
        $year = (int) $request->query->get('year', (string) date('Y'));
        $month = (int) $request->query->get('month', (string) date('n'));

        // Validate month/year
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2020 || $year > 2100) {
            $year = (int) date('Y');
        }

        $placeId = $request->query->get('place', 'all');
        $storageTypeId = $request->query->get('storage_type', 'all');

        // Get places for the filter dropdown
        if ($isAdmin) {
            $places = $this->placeRepository->findAll();
        } else {
            $places = $this->placeRepository->findByOwner($user);
        }

        // Determine selected place
        $selectedPlace = null;
        if ('' !== $placeId && 'all' !== $placeId) {
            $selectedPlace = $this->placeRepository->find(Uuid::fromString($placeId));

            if (null !== $selectedPlace && !$isAdmin && !$this->placeRepository->isOwnedBy($selectedPlace, $user)) {
                throw $this->createAccessDeniedException();
            }
        }

        // Get storage types for the filter dropdown (based on selected place)
        if ($isAdmin) {
            if (null !== $selectedPlace) {
                $storageTypes = $this->storageTypeRepository->findByPlace($selectedPlace);
            } else {
                $storageTypes = $this->storageTypeRepository->findAll();
            }
        } else {
            if (null !== $selectedPlace) {
                $storageTypes = $this->storageTypeRepository->findByOwnerAndPlace($user, $selectedPlace);
            } else {
                $storageTypes = $this->storageTypeRepository->findByOwner($user);
            }
        }

        // Determine selected storage type
        $selectedStorageType = null;
        if ('' !== $storageTypeId && 'all' !== $storageTypeId) {
            $selectedStorageType = $this->storageTypeRepository->find(Uuid::fromString($storageTypeId));

            if (null !== $selectedStorageType) {
                // Verify ownership
                if (!$isAdmin) {
                    if (null !== $selectedPlace) {
                        if (!$this->storageTypeRepository->isOwnedByAtPlace($selectedStorageType, $user, $selectedPlace)) {
                            throw $this->createAccessDeniedException();
                        }
                    } elseif (!$this->storageTypeRepository->isOwnedBy($selectedStorageType, $user)) {
                        throw $this->createAccessDeniedException();
                    }
                }
            }
        }

        $calendarData = [];

        // Get storages based on filters
        $ownerFilter = $isAdmin ? null : $user;
        $storages = $this->storageRepository->findFiltered($ownerFilter, $selectedPlace, $selectedStorageType);

        if ([] !== $storages) {
            // Build calendar data for the month
            $startOfMonth = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
            $endOfMonth = $startOfMonth->modify('last day of this month');

            // Get all orders and unavailabilities for these storages in date range
            $orders = $this->orderRepository->findActiveByStoragesInDateRange(
                $storages,
                $startOfMonth,
                $endOfMonth
            );
            $unavailabilities = $this->unavailabilityRepository->findByStoragesInDateRange(
                $storages,
                $startOfMonth,
                $endOfMonth
            );

            // Build lookup tables for quick access
            $ordersByStorage = [];
            foreach ($orders as $order) {
                $storageId = $order->storage->id->toRfc4122();
                $ordersByStorage[$storageId][] = $order;
            }

            $unavailabilitiesByStorage = [];
            foreach ($unavailabilities as $unavailability) {
                $storageId = $unavailability->storage->id->toRfc4122();
                $unavailabilitiesByStorage[$storageId][] = $unavailability;
            }

            // For each day in the month, calculate availability
            $currentDate = $startOfMonth;
            while ($currentDate <= $endOfMonth) {
                $dayKey = $currentDate->format('Y-m-d');
                $available = 0;
                $occupied = 0;
                $unavailable = 0;

                foreach ($storages as $storage) {
                    $storageId = $storage->id->toRfc4122();
                    $isOccupied = false;
                    $isUnavailable = false;

                    // Check orders
                    if (isset($ordersByStorage[$storageId])) {
                        foreach ($ordersByStorage[$storageId] as $order) {
                            if ($order->startDate <= $currentDate
                                && (null === $order->endDate || $order->endDate >= $currentDate)) {
                                $isOccupied = true;

                                break;
                            }
                        }
                    }

                    // Check manual unavailabilities
                    if (!$isOccupied && isset($unavailabilitiesByStorage[$storageId])) {
                        foreach ($unavailabilitiesByStorage[$storageId] as $unavail) {
                            if ($unavail->startDate <= $currentDate
                                && (null === $unavail->endDate || $unavail->endDate >= $currentDate)) {
                                $isUnavailable = true;

                                break;
                            }
                        }
                    }

                    if ($isOccupied) {
                        ++$occupied;
                    } elseif ($isUnavailable) {
                        ++$unavailable;
                    } else {
                        ++$available;
                    }
                }

                $calendarData[$dayKey] = [
                    'available' => $available,
                    'occupied' => $occupied,
                    'unavailable' => $unavailable,
                    'total' => count($storages),
                ];

                $currentDate = $currentDate->modify('+1 day');
            }
        }

        // Calculate previous and next month links
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
            'year' => $year,
            'month' => $month,
            'monthName' => $this->getCzechMonthName($month),
            'prevMonth' => $prevMonth,
            'prevYear' => $prevYear,
            'nextMonth' => $nextMonth,
            'nextYear' => $nextYear,
        ]);
    }

    private function getCzechMonthName(int $month): string
    {
        $months = [
            1 => 'Leden',
            2 => 'Unor',
            3 => 'Brezen',
            4 => 'Duben',
            5 => 'Kveten',
            6 => 'Cerven',
            7 => 'Cervenec',
            8 => 'Srpen',
            9 => 'Zari',
            10 => 'Rijen',
            11 => 'Listopad',
            12 => 'Prosinec',
        ];

        return $months[$month] ?? '';
    }
}
