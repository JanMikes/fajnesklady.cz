<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Query\GetPlacesOverview;
use App\Query\GetPlacesOverviewRow;
use App\Query\QueryBus;
use App\Service\PlacesMapPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/pobocky', name: 'portal_browse_places')]
#[IsGranted('ROLE_USER')]
final class PlaceBrowseListController extends AbstractController
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly PlacesMapPayload $placesMapPayload,
    ) {
    }

    public function __invoke(): Response
    {
        $result = $this->queryBus->handle(new GetPlacesOverview());

        // The overview table is alphabetical; availability-first ordering is a
        // homepage conversion concern.
        $rows = $result->places;
        usort($rows, static fn (GetPlacesOverviewRow $a, GetPlacesOverviewRow $b): int => strnatcasecmp($a->place->name, $b->place->name));

        return $this->render('portal/user/browse/place_list.html.twig', [
            'placesWithStorageTypes' => $rows,
            'placesData' => $this->placesMapPayload->build($rows, 'portal_browse_place_detail'),
        ]);
    }
}
