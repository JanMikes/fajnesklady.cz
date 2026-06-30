<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RegisterControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testRegisterPageLoads(): void
    {
        $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();
    }

    /**
     * Regression: mismatched passwords must show a visible error, not a silent
     * 422. The password is a RepeatedType (error_bubbling=false), so its errors
     * attach to the unrendered compound `password` field — previously rendered
     * nowhere, leaving the user with a reloaded page and no explanation.
     */
    public function testMismatchedPasswordsShowVisibleError(): void
    {
        $this->client->request('POST', '/register', ['registration_form' => [
            'email' => 'newuser@example.com',
            'firstName' => 'Jan',
            'lastName' => 'Novák',
            'phone' => '+420123456789',
            'password' => ['first' => 'password123', 'second' => 'different123'],
            'agreeTerms' => '1',
        ]]);

        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Hesla se musí shodovat.', $body, 'Password mismatch error must be visible to the user.');
    }

    /**
     * Regression: a matching-but-too-short password must show the length error,
     * not fail silently.
     */
    public function testShortPasswordShowsVisibleError(): void
    {
        $this->client->request('POST', '/register', ['registration_form' => [
            'email' => 'newuser2@example.com',
            'firstName' => 'Jan',
            'lastName' => 'Novák',
            'phone' => '+420123456789',
            'password' => ['first' => 'short', 'second' => 'short'],
            'agreeTerms' => '1',
        ]]);

        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('alespoň 8', $body, 'Password length error must be visible to the user.');
    }
}
