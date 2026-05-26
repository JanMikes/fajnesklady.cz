<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Excel\ExcelColumn;
use App\Service\Excel\ExcelColumnType;
use App\Service\Excel\ExcelExporter;
use App\Service\Excel\ExcelSheet;
use App\Service\Security\PlaceVoter;
use App\Service\Storage\StorageOccupancyService;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{placeId}/storages/export', name: 'portal_storages_export', requirements: ['placeId' => '[0-9a-f-]{36}'])]
#[IsGranted('ROLE_LANDLORD')]
final class StorageExportController extends AbstractController
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly PlaceRepository $placeRepository,
        private readonly StorageOccupancyService $occupancyService,
        private readonly ExcelExporter $excelExporter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request, string $placeId): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::VIEW, $place);

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $owner = $isAdmin ? null : $user;

        $storageTypeId = $request->query->get('storage_type');
        $selectedType = null;
        if (is_string($storageTypeId) && '' !== $storageTypeId) {
            $selectedType = $this->storageTypeRepository->find(Uuid::fromString($storageTypeId));
        }

        $storages = $this->storageRepository->findFiltered($owner, $place, $selectedType);
        $now = $this->clock->now();
        $rentalViews = [] === $storages ? [] : $this->occupancyService->currentViews($storages, $now);

        $columns = [
            new ExcelColumn('Číslo'),
            new ExcelColumn('Typ skladu'),
            new ExcelColumn('Stav'),
            new ExcelColumn('Cena/měsíc krátkodobá (Kč)', ExcelColumnType::MONEY_KC),
            new ExcelColumn('Cena/měsíc dlouhodobá (Kč)', ExcelColumnType::MONEY_KC),
            new ExcelColumn('Aktuální nájemce'),
            new ExcelColumn('Pronajato OD', ExcelColumnType::DATE),
            new ExcelColumn('Pronajato DO', ExcelColumnType::DATE),
            new ExcelColumn('Vytvořeno', ExcelColumnType::DATE),
        ];
        if ($isAdmin) {
            $columns[] = new ExcelColumn('Vlastník');
        }

        $rows = [];
        foreach ($storages as $storage) {
            $view = $rentalViews[$storage->id->toRfc4122()] ?? null;
            $row = [
                $storage->number,
                $storage->storageType->name,
                $storage->status->label(),
                $storage->getEffectivePricePerMonth(),
                $storage->getEffectivePricePerMonthLongTerm(),
                null === $view ? '' : (string) $view->tenantName,
                $view?->rentedFrom,
                $view?->rentedUntil,
                $storage->createdAt,
            ];
            if ($isAdmin) {
                $row[] = null === $storage->owner ? '' : $storage->owner->fullName;
            }
            $rows[] = $row;
        }

        $typeSlug = null !== $selectedType ? '-typ-'.self::slug($selectedType->name) : '';
        $sheet = new ExcelSheet(
            sheetTitle: 'Sklady',
            filename: sprintf(
                'sklady-%s%s-%s.xlsx',
                self::slug($place->name),
                $typeSlug,
                $now->format('Y-m-d'),
            ),
            columns: $columns,
            rows: $rows,
        );

        return $this->excelExporter->stream($sheet);
    }

    private static function slug(string $name): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; [:Nonspacing Mark:] Remove; Lower();', $name);
        if (false === $ascii || '' === $ascii) {
            return 'export';
        }
        $slug = preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? $ascii;
        $slug = trim($slug, '-');

        return '' === $slug ? 'export' : $slug;
    }
}
