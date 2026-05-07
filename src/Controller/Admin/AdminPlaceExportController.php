<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\ContractRepository;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Service\Excel\ExcelColumn;
use App\Service\Excel\ExcelColumnType;
use App\Service\Excel\ExcelExporter;
use App\Service\Excel\ExcelSheet;
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
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(): Response
    {
        $now = $this->clock->now();

        $columns = [
            new ExcelColumn('Pobočka'),
            new ExcelColumn('Adresa'),
            new ExcelColumn('Stav'),
            new ExcelColumn('Skladů celkem', ExcelColumnType::INTEGER),
            new ExcelColumn('Skladů obsazených', ExcelColumnType::INTEGER),
            new ExcelColumn('Skladů volných', ExcelColumnType::INTEGER),
            new ExcelColumn('Aktivních smluv', ExcelColumnType::INTEGER),
            new ExcelColumn('MRR (Kč/měs)', ExcelColumnType::MONEY_KC),
            new ExcelColumn('Vytvořeno', ExcelColumnType::DATE),
        ];

        $places = $this->placeRepository->findAllForExport();
        $rows = [];
        foreach ($places as $place) {
            $rows[] = [
                $place->name,
                trim(sprintf('%s, %s %s', (string) $place->address, $place->postalCode, $place->city), ', '),
                $place->isActive ? 'Aktivní' : 'Neaktivní',
                $this->storageRepository->countAtPlace($place, null),
                $this->storageRepository->countOccupiedAtPlace($place, null),
                $this->storageRepository->countAvailableAtPlace($place, null),
                $this->contractRepository->countActiveRecurringAtPlace($place, null),
                $this->contractRepository->sumExpectedRecurringAtPlace($place, null),
                $place->createdAt,
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
