<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\Fine;
use App\Entity\User;
use App\Enum\FineType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AdminFineListControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->clock = static::getContainer()->get(ClockInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/portal/admin/pokuty');

        $this->assertResponseRedirects('/login');
    }

    public function testLandlordGetsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail(UserFixtures::LANDLORD_EMAIL), 'main');
        $this->client->request('GET', '/portal/admin/pokuty');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCompanyCustomerIsListedUnderTheCompanyName(): void
    {
        $this->createFineForCompanyTenant();

        $this->client->loginUser($this->findUserByEmail(UserFixtures::ADMIN_EMAIL), 'main');
        $this->client->request('GET', '/portal/admin/pokuty');

        $this->assertResponseIsSuccessful();
        $table = $this->client->getCrawler()->filter('table')->html();
        self::assertStringContainsString('Skladová Eva s.r.o.', $table);
        self::assertStringContainsString('Eva Najemce', $table);
    }

    public function testSearchByCompanyNameAndCompanyIdFindsFine(): void
    {
        $this->createFineForCompanyTenant();
        $this->client->loginUser($this->findUserByEmail(UserFixtures::ADMIN_EMAIL), 'main');

        foreach (['Skladová Eva', '27604977', 'CZ27604977'] as $query) {
            $this->client->request('GET', '/portal/admin/pokuty?search='.urlencode($query));

            $this->assertResponseIsSuccessful();
            self::assertStringContainsString(
                'Skladová Eva s.r.o.',
                $this->client->getCrawler()->filter('table')->html(),
                sprintf('Search "%s" must find the company customer fine.', $query),
            );
        }
    }

    private function createFineForCompanyTenant(): void
    {
        $tenant = $this->findUserByEmail(UserFixtures::TENANT_EMAIL);
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.user = :user')
            ->setParameter('user', $tenant)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($contract instanceof Contract, 'Tenant fixture must own a contract');

        $now = $this->clock->now();
        $fine = new Fine(
            id: Uuid::v7(),
            contract: $contract,
            user: $tenant,
            issuedBy: $this->findUserByEmail(UserFixtures::ADMIN_EMAIL),
            type: FineType::DIRTY_STORAGE,
            amountInHaler: 500000,
            description: 'Znečištěná skladovací jednotka.',
            issuedAt: $now,
            createdAt: $now,
        );
        // The constructor buffers a FineIssued event; drop it so persisting
        // straight from the test stays side-effect free.
        $fine->popEvents();
        $this->entityManager->persist($fine);
        $this->entityManager->flush();
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($user instanceof User, sprintf('User "%s" not found in fixtures', $email));

        return $user;
    }
}
