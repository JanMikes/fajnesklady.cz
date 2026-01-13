<?php

declare(strict_types=1);

namespace App\Service;

use App\Value\AresResult;
use App\Value\AresSubject;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AresService implements AresLookup
{
    private const string ARES_API_URL = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/';

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function loadByCompanyId(string $companyId): ?AresResult
    {
        try {
            $response = $this->httpClient->request('GET', self::ARES_API_URL.$companyId);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $subject = AresSubject::fromArray($response->toArray());

            return $subject->toResult();
        } catch (\Throwable) {
            return null;
        }
    }
}
