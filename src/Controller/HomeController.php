<?php

declare(strict_types=1);

namespace App\Controller;

use App\Query\GetPlacesOverview;
use App\Query\QueryBus;
use App\Service\PlacesMapPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/', name: 'app_home')]
final class HomeController extends AbstractController
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly PlacesMapPayload $placesMapPayload,
    ) {
    }

    public function __invoke(): Response
    {
        $result = $this->queryBus->handle(new GetPlacesOverview());

        return $this->render('user/home.html.twig', [
            'placesWithStorageTypes' => $result->places,
            'placesData' => $this->placesMapPayload->build($result->places, 'public_place_detail'),
        ]);
    }
}
