<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\DataFixtures\ContractFixtures;
use App\Entity\Contract;
use App\Entity\HandoverProtocol;
use App\Entity\User;
use App\Repository\HandoverProtocolRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Codes-enabled fixture place is "Sklad Praha - Centrum" — REF_CONTRACT_UNLIMITED
 * lives at storage C1 there, with the place's storageCodesEnabled=true and
 * digits=4. Tests pin to that contract so the codes path is exercised.
 *
 * MockClock is fixed to 2025-06-15 12:00:00 UTC by the test kernel.
 */
class LandlordHandoverViewControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->clock = $container->get(ClockInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testGetPreFillsFourDigitCodeWhenCodesEnabled(): void
    {
        $protocol = $this->createPendingHandoverForUnlimitedContract();

        $landlord = $this->findUserByEmail('landlord@example.com');
        $this->client->loginUser($landlord, 'main');

        $this->client->request('GET', '/portal/pronajimatel/predavaci-protokol/'.$protocol->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        $input = $crawler->filter('input[name="landlord_handover_form[newLockCode]"]');
        self::assertGreaterThan(0, $input->count(), 'newLockCode input must be rendered.');
        $value = $input->attr('value') ?? '';
        $this->assertMatchesRegularExpression(
            '/^\d{4}$/',
            $value,
            sprintf('Expected a 4-digit pre-filled code, got %s.', var_export($value, true)),
        );
    }

    public function testInvalidCodeSubmissionRendersFormWithError(): void
    {
        $protocol = $this->createPendingHandoverForUnlimitedContract();

        $landlord = $this->findUserByEmail('landlord@example.com');
        $this->client->loginUser($landlord, 'main');

        $this->client->request(
            'POST',
            '/portal/pronajimatel/predavaci-protokol/'.$protocol->id->toRfc4122(),
            [
                'landlord_handover_form' => [
                    'comment' => 'Předáno bez závad.',
                    'newLockCode' => 'ABCD', // 4 chars but non-numeric → InvalidStorageCode::notNumeric
                ],
            ],
        );

        // Symfony's AbstractController::render() returns 422 automatically when an
        // invalid form is in the render context (since 6.3) — not a server error.
        $this->assertResponseStatusCodeSame(422);
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('pouze číslice', $body);

        // The protocol must remain pending (no rotation, no completion).
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(HandoverProtocol::class, $protocol->id);
        \assert($reloaded instanceof HandoverProtocol);
        $this->assertTrue($reloaded->needsLandlordCompletion());
    }

    public function testRoleUserHittingLandlordUrlIsRedirectedToLoginAndSessionCleared(): void
    {
        $protocol = $this->createPendingHandoverForUnlimitedContract();

        $tenant = $this->findUserByEmail('user@example.com');
        $this->client->loginUser($tenant, 'main');

        $this->client->request('GET', '/portal/pronajimatel/predavaci-protokol/'.$protocol->id->toRfc4122());

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/login', $location);
        $this->assertStringContainsString(
            '_target_path=',
            $location,
            'Login redirect must carry _target_path so user lands back on the handover page.',
        );
        $this->assertStringContainsString('predavaci-protokol', urldecode($location));

        // Session token must be cleared so a follow-up portal hit redirects to
        // /login (firewall, anonymous) rather than 403 (still authenticated).
        $this->client->request('GET', '/portal/dashboard');
        $this->assertResponseRedirects();
        $this->assertStringContainsString(
            '/login',
            (string) $this->client->getResponse()->headers->get('Location'),
        );
    }

    public function testWrongLandlordIsRedirectedToLoginAndSessionCleared(): void
    {
        // C1 belongs to landlord@; landlord2@ owns Ostrava, so the voter denies VIEW.
        $protocol = $this->createPendingHandoverForUnlimitedContract();

        $otherLandlord = $this->findUserByEmail('landlord2@example.com');
        $this->client->loginUser($otherLandlord, 'main');

        $this->client->request('GET', '/portal/pronajimatel/predavaci-protokol/'.$protocol->id->toRfc4122());

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/login', $location);

        $this->client->request('GET', '/portal/dashboard');
        $this->assertResponseRedirects();
        $this->assertStringContainsString(
            '/login',
            (string) $this->client->getResponse()->headers->get('Location'),
        );
    }

    private function createPendingHandoverForUnlimitedContract(): HandoverProtocol
    {
        // REF_CONTRACT_UNLIMITED → C1 in Praha Centrum (storageCodesEnabled=true).
        // Contract is active and storage is owned by the fixture landlord, so
        // the HandoverProtocolVoter::COMPLETE_LANDLORD permission resolves.
        $protocolRepository = static::getContainer()->get(HandoverProtocolRepository::class);

        $contract = $this->findContractByReference(ContractFixtures::REF_CONTRACT_UNLIMITED);
        $existing = $protocolRepository->findByContract($contract);
        if (null !== $existing) {
            return $existing;
        }

        $now = $this->clock->now();
        $protocol = new HandoverProtocol(
            id: Uuid::v7(),
            contract: $contract,
            createdAt: $now,
        );
        $this->entityManager->persist($protocol);
        $this->entityManager->flush();

        return $protocol;
    }

    private function findContractByReference(string $reference): Contract
    {
        // The test kernel doesn't expose the ReferenceRepository; resolve by
        // unique attributes that pin to the same fixture row.
        $contract = match ($reference) {
            // Active unlimited contract → only one in fixtures with endDate=null
            // and terminatedAt=null on storage C1.
            ContractFixtures::REF_CONTRACT_UNLIMITED => $this->entityManager->createQueryBuilder()
                ->select('c')
                ->from(Contract::class, 'c')
                ->join('c.storage', 's')
                ->where('s.number = :number')
                ->andWhere('c.endDate IS NULL')
                ->andWhere('c.terminatedAt IS NULL')
                ->setParameter('number', 'C1')
                ->getQuery()
                ->getOneOrNullResult(),
            default => throw new \LogicException('Unknown reference '.$reference),
        };

        \assert($contract instanceof Contract, sprintf('Contract %s not found in fixtures', $reference));

        return $contract;
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
