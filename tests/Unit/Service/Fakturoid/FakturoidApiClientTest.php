<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Fakturoid;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\SelfBillingInvoice;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Service\Fakturoid\FakturoidApiClient;
use App\Service\Fakturoid\StaleFakturoidSubjectException;
use Fakturoid\DispatcherInterface;
use Fakturoid\Exception\RequestException;
use Fakturoid\FakturoidManager;
use Fakturoid\Provider\InvoicesProvider;
use Fakturoid\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

class FakturoidApiClientTest extends TestCase
{
    public function testCreateInvoiceSendsVatPriceModeFromTotalWithVat(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user);

        $captured = $this->captureCreatePayload(static fn (FakturoidApiClient $client): mixed => $client->createInvoice(123, $order));

        // Prices in our system are gross (vč. DPH); without this flag Fakturoid would add 21 % on top.
        $this->assertSame('from_total_with_vat', $captured['vat_price_mode']);
        $this->assertEquals(600, $captured['lines'][0]['unit_price']);
        $this->assertSame(21, $captured['lines'][0]['vat_rate']);
    }

    public function testCreateDebtInvoiceSendsVatPriceModeFromTotalWithVat(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user);
        $order->setOnboardingDebt(60_000); // 600 Kč gross — the figure the admin entered.

        $captured = $this->captureCreatePayload(static fn (FakturoidApiClient $client): mixed => $client->createDebtInvoice(123, $order));

        // The debt amount is gross (vč. DPH); without this flag Fakturoid would add 21 % on top — guards spec 034.
        $this->assertSame('from_total_with_vat', $captured['vat_price_mode']);
        $this->assertEquals(600, $captured['lines'][0]['unit_price']);
        $this->assertSame(21, $captured['lines'][0]['vat_rate']);
        $this->assertStringContainsString('dluh', mb_strtolower((string) $captured['lines'][0]['name']));
    }

    public function testCreateRecurringInvoiceSendsVatPriceModeFromTotalWithVat(): void
    {
        $user = $this->createUser();
        $contract = $this->createContract($user);

        $captured = $this->captureCreatePayload(static fn (FakturoidApiClient $client): mixed => $client->createRecurringInvoice(
            123,
            $contract,
            60_000,
            new \DateTimeImmutable('2025-06-15'),
        ));

        $this->assertSame('from_total_with_vat', $captured['vat_price_mode']);
        $this->assertEquals(600, $captured['lines'][0]['unit_price']);
        $this->assertSame(21, $captured['lines'][0]['vat_rate']);
    }

    public function testCreateInvoiceTranslatesStaleSubject422IntoTypedException(): void
    {
        // Fakturoid returns this exact body when subject_id points at a
        // contact that no longer exists in the account — match it so
        // InvoicingService can catch the typed exception and recover.
        $staleBody = '{"errors":{"client_name":["je povinná položka"],"subject_id":["Kontakt neexistuje."]}}';

        $client = $this->buildClientThatThrowsOnCreate($this->buildRequestException(422, $staleBody));

        $this->expectException(StaleFakturoidSubjectException::class);

        try {
            $client->createInvoice(30388961, $this->createOrder($this->createUser()));
        } catch (StaleFakturoidSubjectException $e) {
            $this->assertSame(30388961, $e->subjectId);

            throw $e;
        }
    }

    public function testCreateInvoiceLeavesOtherErrorsAlone(): void
    {
        // A different 422 (e.g. validation on the invoice payload itself)
        // must NOT be converted — InvoicingService has no recovery path for it.
        $unrelatedBody = '{"errors":{"lines":["Lines must not be empty."]}}';

        $client = $this->buildClientThatThrowsOnCreate($this->buildRequestException(422, $unrelatedBody));

        $this->expectException(RequestException::class);
        $client->createInvoice(123, $this->createOrder($this->createUser()));
    }

    private function buildClientThatThrowsOnCreate(\Throwable $exception): FakturoidApiClient
    {
        $dispatcher = $this->createStub(DispatcherInterface::class);
        $dispatcher->method('post')->willThrowException($exception);

        $manager = $this->createStub(FakturoidManager::class);
        $manager->method('getInvoicesProvider')->willReturn(new InvoicesProvider($dispatcher));

        return new FakturoidApiClient($manager, new NullLogger(), 21);
    }

    private function buildRequestException(int $status, string $body): RequestException
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('getContents')->willReturn($body);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getReasonPhrase')->willReturn('Unprocessable Entity');
        $response->method('getBody')->willReturn($stream);

        $request = $this->createStub(RequestInterface::class);

        return new RequestException($request, $response);
    }

    public function testCreateSelfBillingInvoiceSendsVatPriceModeFromTotalWithVat(): void
    {
        $invoice = $this->createSelfBillingInvoice();

        $captured = $this->captureCreatePayload(static fn (FakturoidApiClient $client): mixed => $client->createSelfBillingInvoice(123, $invoice));

        $this->assertSame('from_total_with_vat', $captured['vat_price_mode']);
        $this->assertEquals(450, $captured['lines'][0]['unit_price']);
        $this->assertSame(21, $captured['lines'][0]['vat_rate']);
    }

    /**
     * Wire a FakturoidApiClient against a stub FakturoidManager that captures
     * the array passed to InvoicesProvider::create(). Returns the captured array.
     *
     * @param callable(FakturoidApiClient): mixed $action
     *
     * @return array<string, mixed>
     */
    private function captureCreatePayload(callable $action): array
    {
        $captured = [];

        $dispatcher = $this->createStub(DispatcherInterface::class);
        $dispatcher->method('post')
            ->willReturnCallback(function (string $path, array $data = []) use (&$captured): Response {
                $captured = $data;

                return $this->stubResponse();
            });

        $manager = $this->createStub(FakturoidManager::class);
        $manager->method('getInvoicesProvider')->willReturn(new InvoicesProvider($dispatcher));

        $client = new FakturoidApiClient($manager, new NullLogger(), 21);

        $action($client);

        return $captured;
    }

    private function stubResponse(): Response
    {
        $response = $this->createStub(Response::class);
        $body = new \stdClass();
        $body->id = 99_999;
        $body->number = 'FV-2025-0001';
        $body->total = 600.0;
        $response->method('getBody')->willReturn($body);

        return $response;
    }

    private function createUser(): User
    {
        return new User(
            Uuid::v7(),
            'tenant@example.com',
            'password',
            'Jan',
            'Novák',
            new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
    }

    private function createPlace(): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: 'Test Warehouse',
            address: 'Testovací 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
    }

    private function createStorage(Place $place): Storage
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 200,
            innerLength: 150,
            defaultPricePerWeek: 10_000,
            defaultPricePerMonth: 60_000,
            defaultPricePerMonthLongTerm: 60_000,
            defaultPricePerYear: 60_000 * 12,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        return new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
    }

    private function createOrder(User $user): Order
    {
        $storage = $this->createStorage($this->createPlace());

        // 60 000 haléře = 600 Kč gross — the figure the customer sees.
        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-20'),
            endDate: new \DateTimeImmutable('2025-07-20'),
            firstPaymentPrice: 60_000,
            expiresAt: new \DateTimeImmutable('2025-06-22 12:00:00'),
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
    }

    private function createContract(User $user): Contract
    {
        $order = $this->createOrder($user);

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $order->storage,
            startDate: new \DateTimeImmutable('2025-06-20'),
            endDate: new \DateTimeImmutable('2026-06-20'),
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
    }

    private function createSelfBillingInvoice(): SelfBillingInvoice
    {
        $landlord = $this->createUser();

        // 45 000 haléře = 450 Kč landlord payout — the figure that should equal the invoice total.
        return new SelfBillingInvoice(
            id: Uuid::v7(),
            landlord: $landlord,
            year: 2025,
            month: 6,
            invoiceNumber: 'SB-2025-0001',
            grossAmount: 60_000,
            commissionRate: '0.75',
            netAmount: 45_000,
            issuedAt: new \DateTimeImmutable('2025-06-30 12:00:00'),
            createdAt: new \DateTimeImmutable('2025-06-30 12:00:00'),
        );
    }
}
