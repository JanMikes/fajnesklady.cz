<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Exception\StorageCodeRangeExhausted;
use App\Repository\HandoverProtocolRepository;
use App\Service\Security\HandoverProtocolVoter;
use App\Service\StorageCodeGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/portal/pronajimatel/predavaci-protokol/{id}/generate-code',
    name: 'portal_landlord_handover_generate_code',
    methods: ['POST'],
)]
#[IsGranted('ROLE_LANDLORD')]
final class LandlordHandoverGenerateCodeController extends AbstractController
{
    public function __construct(
        private readonly HandoverProtocolRepository $handoverProtocolRepository,
        private readonly StorageCodeGenerator $codeGenerator,
    ) {
    }

    public function __invoke(string $id): JsonResponse
    {
        $protocol = $this->handoverProtocolRepository->get(Uuid::fromString($id));
        $this->denyAccessUnlessGranted(HandoverProtocolVoter::COMPLETE_LANDLORD, $protocol);

        $place = $protocol->contract->storage->getPlace();
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
