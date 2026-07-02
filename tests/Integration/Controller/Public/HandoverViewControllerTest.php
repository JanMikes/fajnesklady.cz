<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\Entity\Contract;
use App\Entity\HandoverProtocol;
use App\Service\Handover\HandoverUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class HandoverViewControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private HandoverUrlGenerator $urlGenerator;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->urlGenerator = $container->get(HandoverUrlGenerator::class);
        $this->clock = $container->get(ClockInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testUnsignedRequestReturns403(): void
    {
        $protocol = $this->createPendingHandoverForRecurringContract();

        $this->client->request('GET', '/predavaci-protokol/'.$protocol->id->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testTamperedSignatureReturns403(): void
    {
        $protocol = $this->createPendingHandoverForRecurringContract();
        $signed = $this->urlGenerator->generateTenantView($protocol);
        $tampered = preg_replace('/_hash=[^&]+/', '_hash=tampered', $signed);
        \assert(is_string($tampered));

        $this->requestSigned($tampered);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testValidSignatureRendersForm(): void
    {
        $protocol = $this->createPendingHandoverForRecurringContract();

        $this->requestSigned($this->urlGenerator->generateTenantView($protocol));

        $this->assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertGreaterThan(
            0,
            $crawler->filter('form input[name="tenant_handover_form[comment]"], form textarea[name="tenant_handover_form[comment]"]')->count(),
            'Tenant comment input must render.',
        );
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Předávací protokol', $body);
        self::assertStringContainsString('Odeslat předávací protokol', $body);
    }

    public function testValidPostCompletesTenantSide(): void
    {
        $protocol = $this->createPendingHandoverForRecurringContract();
        $signed = $this->urlGenerator->generateTenantView($protocol);

        $this->requestSigned($signed);
        $this->assertResponseIsSuccessful();

        $crawler = $this->client->getCrawler();
        $form = $crawler->selectButton('Odeslat předávací protokol')->form();
        $form['tenant_handover_form[comment]'] = 'Sklad vyklizen, předáváme zpět.';
        $form['tenant_handover_form[confirmed]']->tick();

        $this->client->submit($form);

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/predavaci-protokol/'.$protocol->id->toRfc4122(), $location);
        self::assertStringContainsString('_hash=', $location, 'Redirect target must be signed.');

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(HandoverProtocol::class, $protocol->id);
        \assert($reloaded instanceof HandoverProtocol);
        self::assertNotNull($reloaded->tenantCompletedAt, 'Tenant side must be marked completed.');
        self::assertSame('Sklad vyklizen, předáváme zpět.', $reloaded->tenantComment);
    }

    public function testRevisitAfterCompletionShowsReadOnlySummaryWithoutForm(): void
    {
        $protocol = $this->createPendingHandoverForRecurringContract();
        $protocol->completeTenantSide('Vyplněno dříve.', $this->clock->now());
        $this->entityManager->flush();

        $this->requestSigned($this->urlGenerator->generateTenantView($protocol));

        $this->assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertSame(
            0,
            $crawler->filter('button:contains("Odeslat předávací protokol")')->count(),
            'Tenant form must NOT render after completion.',
        );
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Vyplněno dříve.', $body);
        self::assertStringContainsString('Vaše část', $body);
    }

    private function createPendingHandoverForRecurringContract(): HandoverProtocol
    {
        // REF_CONTRACT_RECURRING → C1 in Praha Centrum (storageCodesEnabled=true).
        // Contract is active and storage is owned by the fixture landlord.
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->join('c.storage', 's')
            ->where('s.number = :number')
            ->andWhere('c.terminatedAt IS NULL')
            ->setParameter('number', 'C1')
            ->getQuery()
            ->getOneOrNullResult();
        \assert($contract instanceof Contract, 'Active recurring contract on C1 must exist in fixtures');

        $existing = $this->entityManager->createQueryBuilder()
            ->select('hp')
            ->from(HandoverProtocol::class, 'hp')
            ->where('hp.contract = :contract')
            ->setParameter('contract', $contract)
            ->getQuery()
            ->getOneOrNullResult();
        if ($existing instanceof HandoverProtocol) {
            return $existing;
        }

        $protocol = new HandoverProtocol(
            id: Uuid::v7(),
            contract: $contract,
            createdAt: $this->clock->now(),
        );
        $this->entityManager->persist($protocol);
        $this->entityManager->flush();

        return $protocol;
    }

    /**
     * Request the signed URL via the test client, preserving host:port so the
     * request URI rebuilt inside Symfony matches the signed input.
     */
    private function requestSigned(string $absoluteUrl, string $method = 'GET'): void
    {
        $parsed = parse_url($absoluteUrl);
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $host = ($parsed['host'] ?? 'localhost').(isset($parsed['port']) ? ':'.$parsed['port'] : '');

        $this->client->request($method, $path.$query, [], [], ['HTTP_HOST' => $host]);
    }
}
