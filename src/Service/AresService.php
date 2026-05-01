<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\AresUnavailable;
use App\Value\AresResult;
use App\Value\AresSubject;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AresService implements AresLookup
{
    private const string ARES_API_URL = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    public function loadByCompanyId(string $companyId): ?AresResult
    {
        try {
            $response = $this->httpClient->request('GET', self::ARES_API_URL.$companyId);
            $status = $response->getStatusCode();

            if (404 === $status) {
                return null;
            }

            if (200 !== $status) {
                throw AresUnavailable::withStatus($status);
            }

            $subject = AresSubject::fromArray($response->toArray());

            return $subject->toResult();
        } catch (AresUnavailable $e) {
            $this->logger->error('ARES lookup failed', [
                'company_id' => $companyId,
                'exception' => $e,
            ]);

            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('ARES lookup failed', [
                'company_id' => $companyId,
                'exception' => $e,
            ]);

            throw AresUnavailable::wrap($e);
        }
    }
}
