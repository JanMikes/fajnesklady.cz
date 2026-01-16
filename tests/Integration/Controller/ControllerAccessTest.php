<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Comprehensive controller access tests.
 *
 * Tests:
 * - Public pages accessibility
 * - Authentication requirements
 * - Role-based access control
 * - Cross-user resource protection
 */
class ControllerAccessTest extends WebTestCase
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

    // ===========================================
    // PUBLIC PAGES - No authentication required
    // ===========================================

    #[DataProvider('publicPagesProvider')]
    public function testPublicPagesAreAccessibleWithoutAuthentication(string $url, int $expectedStatus = 200): void
    {
        $this->client->request('GET', $url);

        $this->assertResponseStatusCodeSame(
            $expectedStatus,
            sprintf('Public page %s should return %d', $url, $expectedStatus)
        );
    }

    public static function publicPagesProvider(): iterable
    {
        yield 'homepage' => ['/'];
        yield 'login' => ['/login'];
        yield 'register' => ['/register'];
        yield 'verify_email_confirmation' => ['/verify-email/confirmation'];
        yield 'password_reset_request' => ['/reset-password/request'];
        yield 'health_check' => ['/-/health-check/liveness'];
    }

    public function testPublicPagesAreAlsoAccessibleWhenLoggedIn(): void
    {
        $user = $this->createUser('public-test@example.com', UserRole::USER);
        $this->login($user);

        // Homepage should work for authenticated users
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
    }

    public function testLoginPageRedirectsAuthenticatedUsers(): void
    {
        $user = $this->createUser('login-redirect@example.com', UserRole::USER);
        $this->login($user);

        $this->client->request('GET', '/login');

        // Should redirect to home or dashboard
        $this->assertResponseRedirects();
    }

    // ===========================================
    // PUBLIC PLACE DETAIL - Requires fixtures
    // ===========================================

    public function testPlaceDetailIsAccessiblePublicly(): void
    {
        $place = $this->createPlace('Test Place');

        $this->client->request('GET', '/pobocka/'.$place->id->toRfc4122());

        $this->assertResponseIsSuccessful();
    }

    // ===========================================
    // AUTHENTICATED PAGES - Any role can access
    // ===========================================

    public function testProfileRequiresAuthentication(): void
    {
        $this->client->request('GET', '/portal/profile');

        $this->assertResponseRedirects('/login');
    }

    public function testProfileIsAccessibleByAnyAuthenticatedUser(): void
    {
        // Use fixture user - already in the database
        $user = $this->findUserByEmail('user@example.com');
        $this->login($user);

        $this->client->request('GET', '/portal/profile');

        $this->assertResponseIsSuccessful();
    }

    public function testDashboardRequiresAuthentication(): void
    {
        $this->client->request('GET', '/portal/dashboard');

        $this->assertResponseRedirects('/login');
    }

    #[DataProvider('dashboardRolesProvider')]
    public function testDashboardIsAccessibleByAllRoles(UserRole $role): void
    {
        $user = $this->createUser('dashboard-'.$role->value.'@example.com', $role);
        $this->login($user);

        $this->client->request('GET', '/portal/dashboard');

        $this->assertResponseIsSuccessful();
    }

    public static function dashboardRolesProvider(): iterable
    {
        yield 'user' => [UserRole::USER];
        yield 'landlord' => [UserRole::LANDLORD];
        yield 'admin' => [UserRole::ADMIN];
    }

    public function testCalendarRequiresAuthentication(): void
    {
        $this->client->request('GET', '/portal/calendar');

        $this->assertResponseRedirects('/login');
    }

    public function testCalendarIsAccessibleByLandlord(): void
    {
        $user = $this->createUser('calendar-landlord@example.com', UserRole::LANDLORD);
        $this->login($user);

        $this->client->request('GET', '/portal/calendar');

        $this->assertResponseIsSuccessful();
    }

    public function testCalendarDeniesAccessToRegularUser(): void
    {
        $user = $this->createUser('calendar-user@example.com', UserRole::USER);
        $this->login($user);

        $this->client->request('GET', '/portal/calendar');

        $this->assertResponseStatusCodeSame(403);
    }

    // ===========================================
    // USER PORTAL - Orders and Contracts
    // ===========================================

    public function testUserOrderListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/portal/objednavky');

        $this->assertResponseRedirects('/login');
    }

    public function testUserContractListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/portal/smlouvy');

        $this->assertResponseRedirects('/login');
    }

    public function testUserCanAccessOwnOrders(): void
    {
        $user = $this->createUser('order-user@example.com', UserRole::USER);
        $this->login($user);

        $this->client->request('GET', '/portal/objednavky');

        $this->assertResponseIsSuccessful();
    }

    public function testUserCanAccessOwnContracts(): void
    {
        $user = $this->createUser('contract-user@example.com', UserRole::USER);
        $this->login($user);

        $this->client->request('GET', '/portal/smlouvy');

        $this->assertResponseIsSuccessful();
    }

    public function testUserCannotAccessOtherUserOrderDetail(): void
    {
        $owner = $this->createUser('owner@example.com', UserRole::USER);
        $otherUser = $this->createUser('other@example.com', UserRole::USER);

        $place = $this->createPlace('Order Place');
        $storageType = $this->createStorageType('Order Type');
        $storage = $this->createStorage($storageType, $place, 'O1');
        $order = $this->createOrder($storage, $owner);

        $this->login($otherUser);
        $this->client->request('GET', '/portal/objednavky/'.$order->id->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUserCannotAccessOtherUserContractDetail(): void
    {
        $owner = $this->createUser('contract-owner@example.com', UserRole::USER);
        $otherUser = $this->createUser('contract-other@example.com', UserRole::USER);

        $place = $this->createPlace('Contract Place');
        $storageType = $this->createStorageType('Contract Type');
        $storage = $this->createStorage($storageType, $place, 'C1');
        $contract = $this->createContract($storage, $owner);

        $this->login($otherUser);
        $this->client->request('GET', '/portal/smlouvy/'.$contract->id->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    // ===========================================
    // LANDLORD PORTAL - Places, Storages
    // ===========================================

    public function testPlaceListRequiresLandlordRole(): void
    {
        $user = $this->createUser('regular-user@example.com', UserRole::USER);
        $this->login($user);

        $this->client->request('GET', '/portal/places');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testPlaceListAccessibleByLandlord(): void
    {
        $landlord = $this->createUser('landlord-list@example.com', UserRole::LANDLORD);
        $this->login($landlord);

        $this->client->request('GET', '/portal/places');

        $this->assertResponseIsSuccessful();
    }

    public function testPlaceListAccessibleByAdmin(): void
    {
        $admin = $this->createUser('admin-place-list@example.com', UserRole::ADMIN);
        $this->login($admin);

        $this->client->request('GET', '/portal/places');

        $this->assertResponseIsSuccessful();
    }

    public function testLandlordCannotAccessPlaceEdit(): void
    {
        // In the new architecture, landlords cannot edit places - only admin can
        $landlord = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'landlord@example.com']);
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $this->login($landlord);
        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/edit');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLandlordCannotAccessAnyPlaceEdit(): void
    {
        $landlord = $this->createUser('own-place-landlord@example.com', UserRole::LANDLORD);
        $place = $this->createPlace('Own Place');

        $this->login($landlord);
        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/edit');

        // Landlords cannot edit places in the new architecture
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanAccessAnyPlaceEdit(): void
    {
        $admin = $this->createUser('admin-edit@example.com', UserRole::ADMIN);
        $place = $this->createPlace('Admin Edit Place');

        $this->login($admin);
        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/edit');

        $this->assertResponseIsSuccessful();
    }

    public function testStorageTypeListRequiresLandlordRole(): void
    {
        $user = $this->createUser('storage-type-user@example.com', UserRole::USER);
        $this->login($user);

        $this->client->request('GET', '/portal/storage-types');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testStorageTypeListRendersForLandlord(): void
    {
        $landlord = $this->createUser('st-list-landlord@example.com', UserRole::LANDLORD);
        $this->createStorageType('Test Storage Type');

        $this->login($landlord);
        $this->client->request('GET', '/portal/storage-types');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Test Storage Type');
    }

    public function testStorageTypeListRendersForAdmin(): void
    {
        $admin = $this->createUser('st-list-admin@example.com', UserRole::ADMIN);
        $this->createStorageType('Admin View Type');

        $this->login($admin);
        $this->client->request('GET', '/portal/storage-types');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Admin View Type');
    }

    public function testStorageListRequiresLandlordRole(): void
    {
        $user = $this->createUser('storage-list-user@example.com', UserRole::USER);
        $this->login($user);

        $this->client->request('GET', '/portal/storages');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLandlordCannotEditOtherLandlordStorageType(): void
    {
        $landlord2 = $this->createUser('st-landlord2@example.com', UserRole::LANDLORD);

        $storageType = $this->createStorageType('ST Type');

        $this->login($landlord2);
        $this->client->request('GET', '/portal/storage-types/'.$storageType->id->toRfc4122().'/edit');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLandlordCannotEditOtherLandlordStorage(): void
    {
        $landlord2 = $this->createUser('s-landlord2@example.com', UserRole::LANDLORD);

        $place = $this->createPlace('S Place');
        $storageType = $this->createStorageType('S Type');
        $storage = $this->createStorage($storageType, $place, 'S1');

        $this->login($landlord2);
        $this->client->request('GET', '/portal/storages/'.$storage->id->toRfc4122().'/edit');

        $this->assertResponseStatusCodeSame(403);
    }

    // ===========================================
    // LANDLORD ORDERS
    // ===========================================

    public function testLandlordOrderListRequiresLandlordRole(): void
    {
        $user = $this->createUser('landlord-orders-user@example.com', UserRole::USER);
        $this->login($user);

        $this->client->request('GET', '/portal/landlord/orders');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLandlordCanAccessOwnOrders(): void
    {
        $landlord = $this->createUser('landlord-own-orders@example.com', UserRole::LANDLORD);
        $this->login($landlord);

        $this->client->request('GET', '/portal/landlord/orders');

        $this->assertResponseIsSuccessful();
    }

    public function testLandlordCannotAccessOtherLandlordOrderDetail(): void
    {
        $landlord2 = $this->createUser('lo-landlord2@example.com', UserRole::LANDLORD);
        $user = $this->createUser('lo-user@example.com', UserRole::USER);

        $place = $this->createPlace('LO Place');
        $storageType = $this->createStorageType('LO Type');
        $storage = $this->createStorage($storageType, $place, 'LO1');
        $order = $this->createOrder($storage, $user);

        $this->login($landlord2);
        $this->client->request('GET', '/portal/landlord/orders/'.$order->id->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanAccessAnyLandlordOrderDetail(): void
    {
        $admin = $this->createUser('admin-lo-admin@example.com', UserRole::ADMIN);
        $user = $this->createUser('admin-lo-user@example.com', UserRole::USER);

        $place = $this->createPlace('Admin LO Place');
        $storageType = $this->createStorageType('Admin LO Type');
        $storage = $this->createStorage($storageType, $place, 'ALO1');
        $order = $this->createOrder($storage, $user);

        $this->login($admin);
        $this->client->request('GET', '/portal/landlord/orders/'.$order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
    }

    public function testAdminCanAccessLandlordOrderList(): void
    {
        $admin = $this->createUser('admin-lo-list@example.com', UserRole::ADMIN);
        $this->login($admin);

        $this->client->request('GET', '/portal/landlord/orders');

        $this->assertResponseIsSuccessful();
    }

    // ===========================================
    // ADMIN PAGES - Admin role required
    // ===========================================

    #[DataProvider('adminPagesProvider')]
    public function testAdminPagesRequireAdminRole(string $url): void
    {
        $landlord = $this->createUser('admin-page-landlord@example.com', UserRole::LANDLORD);
        $this->login($landlord);

        $this->client->request('GET', $url);

        $this->assertResponseStatusCodeSame(403);
    }

    #[DataProvider('adminPagesProvider')]
    public function testAdminPagesAccessibleByAdmin(string $url): void
    {
        $admin = $this->createUser('admin-access@example.com', UserRole::ADMIN);
        $this->login($admin);

        $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful();
    }

    public static function adminPagesProvider(): iterable
    {
        yield 'admin_places' => ['/portal/admin/places'];
        yield 'admin_orders' => ['/portal/admin/orders'];
        yield 'admin_contracts' => ['/portal/admin/contracts'];
        yield 'admin_audit_log' => ['/portal/admin/audit-log'];
    }

    #[DataProvider('adminPagesProvider')]
    public function testAdminPagesInaccessibleByRegularUser(string $url): void
    {
        $user = $this->createUser('admin-page-user@example.com', UserRole::USER);
        $this->login($user);

        $this->client->request('GET', $url);

        $this->assertResponseStatusCodeSame(403);
    }

    // ===========================================
    // USER MANAGEMENT - Admin only
    // ===========================================

    public function testUserListRequiresAdminRole(): void
    {
        $landlord = $this->createUser('user-list-landlord@example.com', UserRole::LANDLORD);
        $this->login($landlord);

        $this->client->request('GET', '/portal/users');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUserListAccessibleByAdmin(): void
    {
        $admin = $this->createUser('user-list-admin@example.com', UserRole::ADMIN);
        $this->login($admin);

        $this->client->request('GET', '/portal/users');

        $this->assertResponseIsSuccessful();
    }

    public function testUserEditRequiresAdminRole(): void
    {
        $landlord = $this->createUser('user-edit-landlord@example.com', UserRole::LANDLORD);
        $targetUser = $this->createUser('target-user@example.com', UserRole::USER);
        $this->login($landlord);

        $this->client->request('GET', '/portal/users/'.$targetUser->id->toRfc4122().'/edit');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUserEditAccessibleByAdmin(): void
    {
        $admin = $this->createUser('user-edit-admin@example.com', UserRole::ADMIN);
        $targetUser = $this->createUser('target-user-for-admin@example.com', UserRole::USER);
        $this->login($admin);

        $this->client->request('GET', '/portal/users/'.$targetUser->id->toRfc4122().'/edit');

        $this->assertResponseIsSuccessful();
    }

    public function testUserVerifyRequiresAdminRole(): void
    {
        $landlord = $this->createUser('user-verify-landlord@example.com', UserRole::LANDLORD);
        $targetUser = $this->createUnverifiedUser('unverified-for-verify@example.com');
        $this->login($landlord);

        $this->client->request('POST', '/portal/users/'.$targetUser->id->toRfc4122().'/verify');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUserVerifyInaccessibleByRegularUser(): void
    {
        $user = $this->createUser('user-verify-user@example.com', UserRole::USER);
        $targetUser = $this->createUnverifiedUser('unverified-for-user@example.com');
        $this->login($user);

        $this->client->request('POST', '/portal/users/'.$targetUser->id->toRfc4122().'/verify');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUserVerifyAccessibleByAdmin(): void
    {
        $admin = $this->createUser('user-verify-admin@example.com', UserRole::ADMIN);
        $targetUser = $this->createUnverifiedUser('unverified-for-admin@example.com');
        $this->login($admin);

        $this->client->request('POST', '/portal/users/'.$targetUser->id->toRfc4122().'/verify');

        // Should redirect after successful verify
        $this->assertResponseRedirects('/portal/users/'.$targetUser->id->toRfc4122());
    }

    public function testUserViewRequiresAdminRole(): void
    {
        $landlord = $this->createUser('user-view-landlord@example.com', UserRole::LANDLORD);
        $targetUser = $this->createUser('target-view-user@example.com', UserRole::USER);
        $this->login($landlord);

        $this->client->request('GET', '/portal/users/'.$targetUser->id->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUserViewAccessibleByAdmin(): void
    {
        $admin = $this->createUser('user-view-admin@example.com', UserRole::ADMIN);
        $targetUser = $this->createUser('target-view-for-admin@example.com', UserRole::USER);
        $this->login($admin);

        $this->client->request('GET', '/portal/users/'.$targetUser->id->toRfc4122());

        $this->assertResponseIsSuccessful();
    }

    // ===========================================
    // STORAGE CANVAS
    // ===========================================

    public function testStorageCanvasRequiresLandlordRole(): void
    {
        $place = $this->createPlace('Canvas Place');

        $user = $this->createUser('canvas-user@example.com', UserRole::USER);
        $this->login($user);

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/canvas');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLandlordCannotAccessPlaceCanvas(): void
    {
        // In the new architecture, landlords cannot access canvas (admin only)
        $landlord = $this->createUser('own-canvas-landlord@example.com', UserRole::LANDLORD);
        $place = $this->createPlace('Own Canvas Place');

        $this->login($landlord);
        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/canvas');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanAccessPlaceCanvas(): void
    {
        $admin = $this->createUser('canvas-admin@example.com', UserRole::ADMIN);
        $place = $this->createPlace('Admin Canvas Place');

        $this->login($admin);
        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/canvas');

        $this->assertResponseIsSuccessful();
    }

    // ===========================================
    // UNAVAILABILITIES
    // ===========================================

    public function testUnavailabilityListRequiresLandlordRole(): void
    {
        $user = $this->createUser('unavail-user@example.com', UserRole::USER);
        $this->login($user);

        $this->client->request('GET', '/portal/unavailabilities');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUnavailabilityListAccessibleByLandlord(): void
    {
        $this->login($this->getFixtureLandlord());

        $this->client->request('GET', '/portal/unavailabilities');

        $this->assertResponseIsSuccessful();
    }

    // ===========================================
    // HELPER METHODS
    // ===========================================

    private function login(User $user): void
    {
        $this->client->loginUser($user, 'main');
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }

    private function getFixtureUser(): User
    {
        return $this->findUserByEmail('user@example.com');
    }

    private function getFixtureLandlord(): User
    {
        return $this->findUserByEmail('landlord@example.com');
    }

    private function getFixtureAdmin(): User
    {
        return $this->findUserByEmail('admin@example.com');
    }

    private function createUser(string $email, UserRole $role): User
    {
        $now = new \DateTimeImmutable();
        $user = new User(
            id: Uuid::v7(),
            email: $email,
            password: 'hashed_password',
            firstName: 'Test',
            lastName: 'User',
            createdAt: $now,
        );
        $user->markAsVerified($now);

        if (UserRole::USER !== $role) {
            $user->changeRole($role, $now);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createUnverifiedUser(string $email): User
    {
        $now = new \DateTimeImmutable();
        $user = new User(
            id: Uuid::v7(),
            email: $email,
            password: 'hashed_password',
            firstName: 'Unverified',
            lastName: 'User',
            createdAt: $now,
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createPlace(string $name): Place
    {
        $now = new \DateTimeImmutable();
        $place = new Place(
            id: Uuid::v7(),
            name: $name,
            address: 'Test Address',
            city: 'Test City',
            postalCode: '12345',
            description: null,
            createdAt: $now,
        );

        $this->entityManager->persist($place);
        $this->entityManager->flush();

        return $place;
    }

    private function createStorageType(string $name): StorageType
    {
        $now = new \DateTimeImmutable();
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: $name,
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: $now,
        );

        $this->entityManager->persist($storageType);
        $this->entityManager->flush();

        return $storageType;
    }

    private function createStorage(StorageType $storageType, Place $place, string $number): Storage
    {
        $now = new \DateTimeImmutable();
        $storage = new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $now,
        );

        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        return $storage;
    }

    private function createOrder(Storage $storage, User $user): Order
    {
        $now = new \DateTimeImmutable();
        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now,
            endDate: $now->modify('+1 month'),
            totalPrice: 35000,
            expiresAt: $now->modify('+1 hour'),
            createdAt: $now,
        );

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function createContract(Storage $storage, User $user): Contract
    {
        $now = new \DateTimeImmutable();
        $order = $this->createOrder($storage, $user);
        $order->markPaid($now);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            startDate: $now,
            endDate: $now->modify('+1 month'),
            createdAt: $now,
        );

        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        return $contract;
    }
}
