<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\DataFixtures\HandoverProtocolFixtures;
use App\Entity\HandoverProtocol;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminHandoverViewControllerTest extends WebTestCase
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

    public function testRequiresAuthentication(): void
    {
        $protocol = $this->findFixtureProtocol();

        $this->client->request('GET', '/portal/admin/predavaci-protokol/'.$protocol->id->toRfc4122());

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForRegularUser(): void
    {
        $protocol = $this->findFixtureProtocol();
        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/predavaci-protokol/'.$protocol->id->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeniedForLandlord(): void
    {
        $protocol = $this->findFixtureProtocol();
        $this->client->loginUser($this->findUserByEmail('landlord@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/predavaci-protokol/'.$protocol->id->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAccessibleByAdminAndRendersView(): void
    {
        $protocol = $this->findFixtureProtocol();
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('GET', '/portal/admin/predavaci-protokol/'.$protocol->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Předávací protokol');

        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Časová osa', $body);
        $this->assertStringContainsString('Nájemce', $body);
        $this->assertStringContainsString('Pronajímatel', $body);
        // Spec 080: one-click shortcut to the classic fine form.
        $this->assertStringContainsString('Vystavit pokutu', $body);
        $this->assertStringContainsString('/portal/admin/pokuty/vytvorit/', $body);
        // Spec 083: header CTA to the landlord fill form while that side is open.
        $this->assertStringContainsString('Vyplnit protokol', $body);
        $this->assertStringContainsString('/portal/pronajimatel/predavaci-protokol/'.$protocol->id->toRfc4122(), $body);
        // Spec 083: skip action for the pending tenant side (the only form on the page).
        $this->assertStringContainsString('Přeskočit stranu nájemce', $body);
        $this->assertStringContainsString('/portal/admin/predavaci-protokol/'.$protocol->id->toRfc4122().'/preskocit-najemce', $body);
        // Existing fill CTAs keep working.
        $this->assertStringContainsString('Vyplnit za nájemce', $body);
        $this->assertStringContainsString('Vyplnit za pronajímatele', $body);
    }

    public function testSkippedTenantSideRendersHonestNote(): void
    {
        $protocol = $this->findFixtureProtocol();
        $admin = $this->findUserByEmail('admin@example.com');
        $protocol->skipTenantSide($admin, new \DateTimeImmutable('2025-06-14 10:00:00'));
        $this->entityManager->flush();

        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/portal/admin/predavaci-protokol/'.$protocol->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Strana nájemce byla přeskočena administrátorem', $body);
        $this->assertStringContainsString('Protokol nevyžaduje vyjádření nájemce.', $body);
        // Timeline shows the honest "Přeskočeno" state instead of a completion
        // date. (Time omitted — Twig renders in Europe/Prague, not UTC.)
        $this->assertStringContainsString('Přeskočeno 14.06.2025', $body);
        // Skip form and tenant fill CTA are gone.
        $this->assertStringNotContainsString('Přeskočit stranu nájemce', $body);
        $this->assertStringNotContainsString('Vyplnit za nájemce', $body);
        // Landlord side is still open → header CTA stays.
        $this->assertStringContainsString('Vyplnit protokol', $body);
    }

    public function testAdminCanViewCompletedProtocol(): void
    {
        $protocol = $this->findFixtureProtocol();
        // Mark it COMPLETED so the timeline rendering exercises the green-path code.
        $protocol->completeTenantSide('Předáno.', new \DateTimeImmutable('2025-06-14 10:00:00'));
        $protocol->completeLandlordSide('Převzato.', null, new \DateTimeImmutable('2025-06-14 11:00:00'));
        $protocol->popEvents();
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/predavaci-protokol/'.$protocol->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Předáno.', $body);
        $this->assertStringContainsString('Převzato.', $body);
    }

    private function findFixtureProtocol(): HandoverProtocol
    {
        // REF_HANDOVER_PENDING is the freshest fixture protocol.
        $protocol = $this->entityManager->createQueryBuilder()
            ->select('hp')
            ->from(HandoverProtocol::class, 'hp')
            ->join('hp.contract', 'c')
            ->join('c.storage', 's')
            // Active LIMITED fixture contract (B3) — see ContractFixtures::REF_CONTRACT_ACTIVE.
            ->where('s.number = :number')
            ->setParameter('number', 'B3')
            ->getQuery()
            ->getSingleResult();
        \assert($protocol instanceof HandoverProtocol, 'Expected fixture protocol on B3 — see '.HandoverProtocolFixtures::class);

        return $protocol;
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
