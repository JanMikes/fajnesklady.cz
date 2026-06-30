<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Locks the client-side wiring the two onboarding date bugs depend on:
 *  - the date <input> keeps its flatpickr controller AND its date-bound attribute
 *    after the per-field validation action is merged in (Symfony deep-merges attr);
 *  - the field carries the live `validateField` action so picking/typing a date
 *    re-validates against the just-committed model value.
 */
final class AdminOnboardingDatepickerWiringTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $admin = $em->createQueryBuilder()->select('u')->from(User::class, 'u')
            ->where('u.email = :e')->setParameter('e', 'admin@example.com')
            ->getQuery()->getOneOrNullResult();
        \assert($admin instanceof User);
        $this->client->loginUser($admin, 'main');
    }

    public function testBirthDateInputCarriesDatepickerAndValidationWiring(): void
    {
        $this->client->request('GET', '/portal/admin/onboarding');
        self::assertResponseIsSuccessful();

        $html = $this->client->getResponse()->getContent();
        \assert(is_string($html));

        // The live form drives every field's model from its name on change.
        self::assertMatchesRegularExpression('/<form[^>]*\bdata-model="on\(change\)\|\*"/', $html);

        self::assertSame(1, preg_match('/<input[^>]*name="[^"]*\[birthDate\]"[^>]*>/', $html, $m));
        $tag = $m[0];

        // flatpickr controller still attached.
        self::assertStringContainsString('data-controller="datepicker"', $tag);
        // Date-bound attribute survived the attr merge (max = today - 18 years).
        self::assertStringContainsString('data-datepicker-max-date-value=', $tag);
        // Per-field live validation is wired.
        self::assertStringContainsString('data-live-action-param="validateField"', $tag);
        self::assertStringContainsString('data-live-field-param="birthDate"', $tag);
    }
}
