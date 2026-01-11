<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/-/health-check/liveness', name: 'health_check')]
final readonly class HealthCheckController
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $checks = [
            'status' => 'healthy',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            'checks' => [],
        ];

        // Check database connection
        try {
            $this->connection->executeQuery('SELECT 1');
            $checks['checks']['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['status'] = 'unhealthy';
            $checks['checks']['database'] = 'failed: '.$e->getMessage();
        }

        // Check PHP version
        $checks['checks']['php_version'] = PHP_VERSION;

        // Check if in debug mode
        $checks['checks']['debug_mode'] = $_ENV['APP_DEBUG'] ?? false;

        $httpStatus = 'healthy' === $checks['status'] ? 200 : 503;

        return new JsonResponse($checks, $httpStatus);
    }
}
