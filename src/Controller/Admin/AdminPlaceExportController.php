<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Place;
use App\Repository\ContractRepository;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Service\Excel\ExcelColumn;
use App\Service\Excel\ExcelColumnType;
use App\Service\Excel\ExcelExporter;
use App\Service\Excel\ExcelSheet;
use App\Service\Place\PlaceAddressFormatter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/places/export', name: 'admin_places_export')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPlaceExportController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly ContractRepository $contractRepository,
        private readonly ExcelExporter $excelExporter,
        private readonly PlaceAddressFormatter $addressFormatter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(): Response
    {
        $now = $this->clock->now();

        $columns = [
            new ExcelColumn('Pobočka'),
            new ExcelColumn('Vlastník'),
            new ExcelColumn('E-mail vlastníka'),
            new ExcelColumn('Adresa'),
            new ExcelColumn('Skladů celkem', ExcelColumnType::INTEGER),
            new ExcelColumn('Skladů obsazených', ExcelColumnType::INTEGER),
            new ExcelColumn('Skladů volných', ExcelColumnType::INTEGER),
            new ExcelColumn('Aktivních smluv', ExcelColumnType::INTEGER),
            new ExcelColumn('MRR (Kč/měs)', ExcelColumnType::MONEY_KC),
            new ExcelColumn('YRR (Kč/rok)', ExcelColumnType::MONEY_KC),
        ];

        $places = $this->placeRepository->findAllForExport();

        // Bulk-load every per-place stat in 3 queries (storage counts, owners,
        // contract MRR) instead of running 5 per row — eliminates N+1 the
        // previous implementation introduced.
        $placeIds = array_map(static fn (Place $p) => $p->id, $places);
        $storageStats = $this->storageRepository->loadStorageStatsByPlaceIds($placeIds);
        $owners = $this->storageRepository->loadOwnersByPlaceIds($placeIds);
        $contractStats = $this->contractRepository->loadContractStatsByPlaceIds($placeIds);

        $rows = [];
        foreach ($places as $place) {
            $key = (string) $place->id;
            $placeStorageStats = $storageStats[$key] ?? ['total' => 0, 'occupied' => 0, 'available' => 0];
            $placeContractStats = $contractStats[$key] ?? ['activeRecurring' => 0, 'expectedMrrInHaler' => 0, 'expectedYrrInHaler' => 0];
            $placeOwners = $owners[$key] ?? [];

            $rows[] = [
                $place->name,
                implode(', ', array_map(static fn (array $o): string => $o['fullName'], $placeOwners)),
                implode(', ', array_map(static fn (array $o): string => $o['email'], $placeOwners)),
                $this->addressFormatter->format($place),
                $placeStorageStats['total'],
                $placeStorageStats['occupied'],
                $placeStorageStats['available'],
                $placeContractStats['activeRecurring'],
                $placeContractStats['expectedMrrInHaler'],
                $placeContractStats['expectedYrrInHaler'],
            ];
        }

        $sheet = new ExcelSheet(
            sheetTitle: 'Pobočky',
            filename: sprintf('pobocky-%s.xlsx', $now->format('Y-m-d')),
            columns: $columns,
            rows: $rows,
        );

        return $this->excelExporter->stream($sheet);
    }
}
