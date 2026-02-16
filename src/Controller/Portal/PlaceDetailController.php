<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\PlaceAccessRepository;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Security\PlaceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{id}', name: 'portal_places_detail')]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceDetailController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly PlaceAccessRepository $placeAccessRepository,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($id));

        $this->denyAccessUnlessGranted(PlaceVoter::VIEW, $place);

        /** @var User $user */
        $user = $this->getUser();

        $storageTypeCount = $this->storageTypeRepository->countByPlace($place);

        if ($this->isGranted('ROLE_ADMIN')) {
            $storageCount = $this->storageRepository->countByPlace($place);
        } else {
            $storageCount = $this->storageRepository->countByOwnerAndPlace($user, $place);
        }

        $hasAccess = $this->isGranted('ROLE_ADMIN') || $this->placeAccessRepository->hasAccess($user, $place);

        return $this->render('portal/place/detail.html.twig', [
            'place' => $place,
            'storageTypeCount' => $storageTypeCount,
            'storageCount' => $storageCount,
            'hasAccess' => $hasAccess,
        ]);
    }
}
