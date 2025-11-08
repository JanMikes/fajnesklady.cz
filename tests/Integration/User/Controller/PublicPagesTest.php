<?php

declare(strict_types=1);

namespace App\Tests\Integration\User\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests to ensure public pages render correctly and return 200 OK
 */
class PublicPagesTest extends WebTestCase
{
    #[DataProvider('publicUrlProvider')]
    public function testPublicPageRendersSuccessfully(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        $this->assertResponseIsSuccessful(
            sprintf('Failed to load page: %s', $url)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicUrlProvider(): iterable
    {
        yield 'homepage' => ['/'];
        yield 'login' => ['/login'];
        yield 'register' => ['/register'];
        yield 'verify_email_confirmation' => ['/verify-email/confirmation'];
    }

    public function testPasswordResetRequestPageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $client->request('GET', '/reset-password/request');

        $this->assertResponseIsSuccessful(
            'Failed to load password reset request page'
        );
    }
}
