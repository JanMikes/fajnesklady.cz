<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\OrderRepository;
use App\Service\Excel\ExcelColumn;
use App\Service\Excel\ExcelColumnType;
use App\Service\Excel\ExcelExporter;
use App\Service\Excel\ExcelSheet;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/landlord/orders/export', name: 'portal_landlord_orders_export')]
#[IsGranted('ROLE_LANDLORD')]
final class LandlordOrderExportController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ExcelExporter $excelExporter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(): Response
    {
        /** @var User $landlord */
        $landlord = $this->getUser();
        $now = $this->clock->now();

        $columns = [
            new ExcelColumn('Číslo objednávky'),
            new ExcelColumn('Vytvořeno', ExcelColumnType::DATETIME),
            new ExcelColumn('Stav'),
            new ExcelColumn('Zákazník'),
            new ExcelColumn('E-mail'),
            new ExcelColumn('Telefon'),
            new ExcelColumn('IČO'),
            new ExcelColumn('Pobočka'),
            new ExcelColumn('Sklad'),
            new ExcelColumn('Typ skladu'),
            new ExcelColumn('Délka pronájmu'),
            new ExcelColumn('Začátek', ExcelColumnType::DATE),
            new ExcelColumn('Konec', ExcelColumnType::DATE),
            new ExcelColumn('Měsíční platba (Kč)', ExcelColumnType::MONEY_KC),
        ];

        $orders = $this->orderRepository->findByLandlord($landlord);
        $rows = [];
        foreach ($orders as $order) {
            $rows[] = [
                substr($order->id->toRfc4122(), 0, 8),
                $order->createdAt,
                $order->status->label(),
                $order->user->fullName,
                $order->user->email,
                $order->user->phone,
                $order->user->companyId,
                $order->storage->place->name,
                $order->storage->number,
                $order->storage->storageType->name,
                $order->rentalType->label(),
                $order->startDate,
                $order->endDate,
                $order->firstPaymentPrice,
            ];
        }

        $sheet = new ExcelSheet(
            sheetTitle: 'Moje objednávky',
            filename: sprintf('objednavky-%s-%s.xlsx', self::slug($landlord->fullName), $now->format('Y-m-d')),
            columns: $columns,
            rows: $rows,
        );

        return $this->excelExporter->stream($sheet);
    }

    private static function slug(string $name): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; [:Nonspacing Mark:] Remove; Lower();', $name);
        if (false === $ascii || '' === $ascii) {
            return 'pronajimatel';
        }
        $slug = preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? $ascii;
        $slug = trim($slug, '-');

        return '' === $slug ? 'pronajimatel' : $slug;
    }
}
