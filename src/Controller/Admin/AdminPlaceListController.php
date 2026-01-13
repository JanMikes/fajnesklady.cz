<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\PlaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/places', name: 'admin_places_list')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPlaceListController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 20;

        $places = $this->placeRepository->findAllPaginated($page, $limit);
        $totalPlaces = $this->placeRepository->countTotal();
        $totalPages = (int) ceil($totalPlaces / $limit);

        return $this->render('admin/place/list.html.twig', [
            'places' => $places,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalPlaces' => $totalPlaces,
        ]);
    }
}
