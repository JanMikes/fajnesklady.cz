<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\ExcludeStorageCodesCommand;
use App\Exception\InvalidStorageCode;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Service\Security\PlaceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/portal/places/{placeId}/access-codes/exclude',
    name: 'portal_place_access_codes_exclude',
    methods: ['POST'],
)]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceAccessCodesExcludeController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $placeId): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::MANAGE_CODES, $place);

        if (!$place->storageCodesEnabled) {
            throw new BadRequestHttpException('Přístupové kódy nejsou pro toto místo povolené.');
        }

        $codes = array_values(array_unique(array_filter(
            preg_split('/[\s,]+/', $request->request->getString('codes')) ?: [],
            static fn (string $code): bool => '' !== $code,
        )));
        // Column is VARCHAR(255) — truncate instead of 500ing on long input.
        $note = mb_substr(trim($request->request->getString('note')), 0, 255);

        if ([] === $codes) {
            $this->addFlash('error', 'Zadejte alespoň jeden kód k vyloučení.');

            return $this->redirectToRoute('portal_place_access_codes', ['placeId' => $place->id->toRfc4122()]);
        }

        try {
            $envelope = $this->commandBus->dispatch(new ExcludeStorageCodesCommand(
                placeId: $place->id,
                codes: $codes,
                note: '' === $note ? null : $note,
            ));
        } catch (HandlerFailedException $e) {
            $nested = HandlerFailureUnwrap::unwrap($e);
            if ($nested instanceof InvalidStorageCode) {
                $this->addFlash('error', $nested->getMessage());

                return $this->redirectToRoute('portal_place_access_codes', ['placeId' => $place->id->toRfc4122()]);
            }

            throw $e;
        }

        $count = (int) ($envelope->last(HandledStamp::class)?->getResult() ?? 0);
        $this->addFlash('success', sprintf('Vyloučeno %d kódů.', $count));

        $activeNumbersByCode = $this->storageRepository->findNumbersByPlaceAndLockCodes($place, $codes);
        if ([] !== $activeNumbersByCode) {
            $this->addFlash('warning', sprintf(
                'Kód(y) %s jsou aktuálně přiřazené skladům — zůstávají aktivní, ale nebudou znovu nabídnuty.',
                implode(', ', array_keys($activeNumbersByCode)),
            ));
        }

        return $this->redirectToRoute('portal_place_access_codes', ['placeId' => $place->id->toRfc4122()]);
    }
}
