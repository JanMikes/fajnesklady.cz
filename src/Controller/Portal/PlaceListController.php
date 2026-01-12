<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\PlaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/places', name: 'portal_places_list')]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceListController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 20;

        /** @var User $user */
        $user = $this->getUser();

        // Admin sees all, landlord sees only own
        if ($this->isGranted('ROLE_ADMIN')) {
            $places = $this->placeRepository->findAllPaginated($page, $limit);
            $totalPlaces = $this->placeRepository->countTotal();
        } else {
            $places = $this->placeRepository->findByOwnerPaginated($user, $page, $limit);
            $totalPlaces = $this->placeRepository->countByOwner($user);
        }

        $totalPages = (int) ceil($totalPlaces / $limit);

        return $this->render('portal/place/list.html.twig', [
            'places' => $places,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalPlaces' => $totalPlaces,
        ]);
    }
}
