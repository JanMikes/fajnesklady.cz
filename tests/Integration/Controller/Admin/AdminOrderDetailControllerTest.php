<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminOrderDetailControllerTest extends WebTestCase
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

    public function testRendersHistoriCenyPanelForOnboardedContract(): void
    {
        $this->client->loginUser($this->findAdmin(), 'main');

        $order = $this->findFirstOnboardedOrder();

        $this->client->request('GET', '/portal/admin/orders/'.$order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Historie ceny');
        $this->assertSelectorTextContains('body', 'Initial value (fixture)');
    }

    public function testNoHistoriCenyPanelForVanillaOrder(): void
    {
        $this->client->loginUser($this->findAdmin(), 'main');

        $order = $this->findVanillaContractOrder();

        $this->client->request('GET', '/portal/admin/orders/'.$order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('body', 'Historie ceny');
    }

    private function findAdmin(): User
    {
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        \assert($admin instanceof User);

        return $admin;
    }

    private function findFirstOnboardedOrder(): Order
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.individualMonthlyAmount IS NOT NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($contract instanceof Contract, 'Expected fixture: at least one contract with individualMonthlyAmount.');

        return $contract->order;
    }

    private function findVanillaContractOrder(): Order
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.individualMonthlyAmount IS NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($contract instanceof Contract, 'Expected fixture: at least one contract without individualMonthlyAmount.');

        return $contract->order;
    }
}
