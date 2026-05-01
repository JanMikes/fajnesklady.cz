<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\AresUnavailable;
use App\Service\AresLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/api/ares/{companyId}',
    name: 'api_ares_lookup',
    requirements: ['companyId' => '\d{1,12}'],
    methods: ['GET'],
)]
final class AresLookupController extends AbstractController
{
    public function __construct(
        private readonly AresLookup $aresLookup,
        private readonly RateLimiterFactoryInterface $aresLookupLimiter,
    ) {
    }

    public function __invoke(Request $request, string $companyId): JsonResponse
    {
        $limiter = $this->aresLookupLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (1 !== preg_match('/^\d{8}$/', $companyId)) {
            return new JsonResponse(['error' => 'invalid_format'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->aresLookup->loadByCompanyId($companyId);
        } catch (AresUnavailable) {
            return new JsonResponse(['error' => 'unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (null === $result) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'companyName' => $result->companyName,
            'companyVatId' => $result->companyVatId,
            'billingStreet' => $result->street,
            'billingCity' => $result->city,
            'billingPostalCode' => $result->postalCode,
        ]);
    }
}
