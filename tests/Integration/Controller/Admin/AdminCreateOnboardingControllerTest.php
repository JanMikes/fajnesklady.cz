<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminCreateOnboardingControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testFormRendersStorageSelectAsTomSelectWithGroupedOptions(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $crawler = $this->client->request('GET', '/portal/admin/onboarding/digital');

        $this->assertResponseIsSuccessful();

        // The storage picker is wired to the TomSelect Stimulus controller.
        $this->assertSelectorExists('select[data-controller="tom-select"]');

        $select = $crawler->filter('select[name="admin_create_onboarding_form[storageId]"]')->first();
        $this->assertSame('tom-select', $select->attr('data-controller'));

        // Choices are grouped by "Place — StorageType". Praha Centrum has four available
        // small boxes (A1, A2, A3, A5; A4 is MANUALLY_UNAVAILABLE), proving both grouping
        // and natural-sorted option ordering inside the group.
        $optgroupLabels = $select->filter('optgroup')->each(static fn ($node): string => (string) $node->attr('label'));
        $this->assertNotEmpty($optgroupLabels, 'Storage select should render at least one <optgroup>.');

        $smallCentrumLabel = null;
        foreach ($optgroupLabels as $label) {
            if (str_contains($label, 'Centrum') && str_contains($label, 'Maly box')) {
                $smallCentrumLabel = $label;

                break;
            }
        }
        $this->assertNotNull(
            $smallCentrumLabel,
            sprintf('Praha Centrum / Maly box optgroup not found among labels: %s', implode(' | ', $optgroupLabels)),
        );

        $smallCentrumOptions = $select->filter(sprintf('optgroup[label="%s"] option', $smallCentrumLabel))
            ->each(static fn ($node): string => $node->text());
        $this->assertSame(['A1', 'A2', 'A3', 'A5', 'Z1'], $smallCentrumOptions);
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
