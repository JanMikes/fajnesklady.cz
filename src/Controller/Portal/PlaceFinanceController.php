<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\ContractRepository;
use App\Repository\PaymentRepository;
use App\Repository\PlaceRepository;
use App\Service\Security\PlaceVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/portal/places/{placeId}/finance',
    name: 'portal_places_finance',
    requirements: ['placeId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
)]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceFinanceController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly ContractRepository $contractRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $placeId): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::VIEW, $place);

        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $owner = $isAdmin ? null : $user;
        $now = $this->clock->now();

        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $nextMonthStart = $now->modify('first day of next month')->setTime(0, 0);
        $lastMonthStart = $now->modify('first day of last month')->setTime(0, 0);
        $yearStart = $now->modify('first day of January this year')->setTime(0, 0);

        $thisMonthRevenue = $this->paymentRepository->sumAtPlaceForRange($place, $monthStart, $nextMonthStart, $owner);
        $lastMonthRevenue = $this->paymentRepository->sumAtPlaceAndPeriod(
            $place,
            (int) $lastMonthStart->format('Y'),
            (int) $lastMonthStart->format('n'),
            $owner,
        );
        $ytdRevenue = $this->paymentRepository->sumAtPlaceForRange($place, $yearStart, $nextMonthStart, $owner);
        $expectedMonthly = $this->contractRepository->sumExpectedRecurringAtPlace($place, $owner);

        $monthlyRevenue = $this->paymentRepository->getMonthlyRevenueAtPlace($place, 12, $now, $owner);

        return $this->render('portal/place/finance.html.twig', [
            'place' => $place,
            'thisMonthRevenue' => $thisMonthRevenue,
            'lastMonthRevenue' => $lastMonthRevenue,
            'ytdRevenue' => $ytdRevenue,
            'expectedMonthly' => $expectedMonthly,
            'monthlyRevenue' => $monthlyRevenue,
            'isAdmin' => $isAdmin,
        ]);
    }
}
