<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\AresLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/ares/{companyId}', name: 'api_ares_lookup', methods: ['GET'])]
final class AresLookupController extends AbstractController
{
    public function __construct(
        private readonly AresLookup $aresLookup,
    ) {
    }

    public function __invoke(string $companyId): JsonResponse
    {
        if (!preg_match('/^\d{8}$/', $companyId)) {
            return new JsonResponse(
                ['error' => 'IČO musí mít přesně 8 číslic.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $result = $this->aresLookup->loadByCompanyId($companyId);

        if (null === $result) {
            return new JsonResponse(
                ['error' => 'Společnost s tímto IČO nebyla nalezena v registru ARES.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse([
            'companyName' => $result->companyName,
            'companyId' => $result->companyId,
            'companyVatId' => $result->companyVatId,
            'billingStreet' => $result->street,
            'billingCity' => $result->city,
            'billingPostalCode' => $result->postalCode,
        ]);
    }
}
