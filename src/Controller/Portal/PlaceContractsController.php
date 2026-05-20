<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\PlaceRepository;
use App\Service\Security\PlaceVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/portal/places/{placeId}/smlouvy',
    name: 'portal_places_contracts',
    requirements: ['placeId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
)]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceContractsController extends AbstractController
{
    private const ALLOWED_FILTERS = ['all', 'active', 'expiring', 'upcoming', 'recent'];

    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly ContractRepository $contractRepository,
        private readonly OrderRepository $orderRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $placeId, Request $request): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::VIEW, $place);

        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $owner = $isAdmin ? null : $user;
        $now = $this->clock->now();

        $show = $request->query->get('show', 'all');
        if (!in_array($show, self::ALLOWED_FILTERS, true)) {
            $show = 'all';
        }

        // Counts: always compute the full breakdown so chip badges stay accurate
        // regardless of which chip is active.
        $activeContracts = $this->contractRepository->findActiveAtPlace($place, $owner, $now);
        $expiringContracts = $this->contractRepository->findExpiringWithinDaysAtPlace(60, $now, $place, $owner);
        $upcomingOrders = $this->orderRepository->findUpcomingAtPlace($place, 30, $now, $owner);
        $recentOrders = $this->orderRepository->findRecentAtPlace($place, 20, $owner);

        return $this->render('portal/place/contracts.html.twig', [
            'place' => $place,
            'show' => $show,
            'activeContracts' => $activeContracts,
            'expiringContracts' => $expiringContracts,
            'upcomingOrders' => $upcomingOrders,
            'recentOrders' => $recentOrders,
            'isAdmin' => $isAdmin,
        ]);
    }
}
