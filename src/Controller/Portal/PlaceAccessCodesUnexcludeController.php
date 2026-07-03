<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\RemoveStorageCodeExclusionCommand;
use App\Repository\PlaceRepository;
use App\Repository\PlaceStorageCodeUsageRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Service\Security\PlaceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/portal/places/{placeId}/access-codes/exclusions/{usageId}/remove',
    name: 'portal_place_access_codes_unexclude',
    methods: ['POST'],
)]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceAccessCodesUnexcludeController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly PlaceStorageCodeUsageRepository $usageRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $placeId, string $usageId): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::MANAGE_CODES, $place);

        if (!$place->storageCodesEnabled) {
            throw new BadRequestHttpException('Přístupové kódy nejsou pro toto místo povolené.');
        }

        $usage = $this->usageRepository->find(Uuid::fromString($usageId));
        if (null === $usage || !$usage->place->id->equals($place->id)) {
            throw $this->createNotFoundException('Vyloučený kód nebyl nalezen.');
        }

        try {
            $this->commandBus->dispatch(new RemoveStorageCodeExclusionCommand(usageId: $usage->id));
            $this->addFlash('success', 'Vyloučení kódu bylo zrušeno.');
        } catch (HandlerFailedException $e) {
            $nested = HandlerFailureUnwrap::unwrap($e);
            if (!$nested instanceof \DomainException) {
                throw $e;
            }

            $this->addFlash('error', $nested->getMessage());
        }

        return $this->redirectToRoute('portal_place_access_codes', ['placeId' => $place->id->toRfc4122()]);
    }
}
