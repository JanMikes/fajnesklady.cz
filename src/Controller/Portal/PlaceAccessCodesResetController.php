<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\ReleaseUnusedStorageCodesCommand;
use App\Repository\PlaceRepository;
use App\Service\Security\PlaceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/portal/places/{placeId}/access-codes/reset',
    name: 'portal_place_access_codes_reset',
    methods: ['POST'],
)]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceAccessCodesResetController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $placeId): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::EDIT, $place);

        if (!$place->storageCodesEnabled) {
            throw new BadRequestHttpException('Přístupové kódy nejsou pro toto místo povolené.');
        }

        $envelope = $this->commandBus->dispatch(new ReleaseUnusedStorageCodesCommand(placeId: $place->id));
        $count = (int) ($envelope->last(HandledStamp::class)?->getResult() ?? 0);

        $this->addFlash('success', sprintf('Uvolněno %d použitých kódů.', $count));

        return $this->redirectToRoute('portal_place_access_codes', ['placeId' => $place->id->toRfc4122()]);
    }
}
