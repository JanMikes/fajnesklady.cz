<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Address\AddressValidator;
use App\Value\Address\AddressSuggestion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/address/suggest', name: 'api_address_suggest', methods: ['GET'])]
final class AddressSuggestController extends AbstractController
{
    private const int MINIMUM_QUERY_LENGTH = 3;

    public function __construct(
        private readonly AddressValidator $addressValidator,
        private readonly RateLimiterFactoryInterface $addressSuggestLimiter,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $limiter = $this->addressSuggestLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) < self::MINIMUM_QUERY_LENGTH) {
            return new JsonResponse(['suggestions' => []]);
        }

        return new JsonResponse([
            'suggestions' => array_map(
                static fn (AddressSuggestion $suggestion): array => $suggestion->toArray(),
                $this->addressValidator->suggest($query),
            ),
        ]);
    }
}
