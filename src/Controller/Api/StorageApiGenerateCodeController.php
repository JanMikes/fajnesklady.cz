<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\StorageCodeRangeExhausted;
use App\Repository\PlaceRepository;
use App\Service\Security\PlaceVoter;
use App\Service\StorageCodeGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/api/places/{placeId}/storages/generate-code',
    name: 'api_storages_generate_code',
    methods: ['POST'],
)]
#[IsGranted('ROLE_LANDLORD')]
final class StorageApiGenerateCodeController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageCodeGenerator $codeGenerator,
    ) {
    }

    public function __invoke(string $placeId): JsonResponse
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::EDIT, $place);

        if (!$place->storageCodesEnabled) {
            return new JsonResponse(
                ['message' => 'Přístupové kódy nejsou pro toto místo povolené.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $code = $this->codeGenerator->propose($place);
        } catch (StorageCodeRangeExhausted $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['code' => $code]);
    }
}
