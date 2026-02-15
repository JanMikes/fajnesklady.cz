<?php

declare(strict_types=1);

namespace App\Controller\Portal\Admin;

use App\Repository\PlaceAccessRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/place-access-requests', name: 'portal_admin_place_access_requests')]
#[IsGranted('ROLE_ADMIN')]
final class PlaceAccessRequestListController extends AbstractController
{
    public function __construct(
        private readonly PlaceAccessRequestRepository $placeAccessRequestRepository,
    ) {
    }

    public function __invoke(): Response
    {
        $requests = $this->placeAccessRequestRepository->findPending();

        return $this->render('portal/admin/place_access_request/list.html.twig', [
            'requests' => $requests,
        ]);
    }
}
