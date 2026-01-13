<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\StorageUnavailabilityRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/unavailabilities', name: 'portal_unavailabilities_list')]
#[IsGranted('ROLE_LANDLORD')]
final class StorageUnavailabilityListController extends AbstractController
{
    public function __construct(
        private readonly StorageUnavailabilityRepository $unavailabilityRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isGranted('ROLE_ADMIN')) {
            $unavailabilities = $this->unavailabilityRepository->findAll();
        } else {
            $unavailabilities = $this->unavailabilityRepository->findByOwner($user);
        }

        return $this->render('portal/unavailability/list.html.twig', [
            'unavailabilities' => $unavailabilities,
            'today' => $this->clock->now(),
        ]);
    }
}
