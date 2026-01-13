<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PlaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/', name: 'app_home')]
final class HomeController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
    ) {
    }

    public function __invoke(): Response
    {
        $places = $this->placeRepository->findAllActive();

        return $this->render('user/home.html.twig', [
            'places' => $places,
        ]);
    }
}
