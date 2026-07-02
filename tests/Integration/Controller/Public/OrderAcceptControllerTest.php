<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\Entity\Order;
use App\Entity\Storage;
use App\Enum\BillingMode;
use App\Enum\PaymentMethod;
use App\Form\OrderFormData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

class OrderAcceptControllerTest extends WebTestCase
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

    public function testDuplicateSubmitDoesNotCreateSecondOrder(): void
    {
        $storage = $this->findAvailableStorage('A2');
        $url = $this->acceptUrl($storage);
        $this->seedOrderFormSession($storage);

        // GET issues the one-time token.
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="submit_token"]')->attr('value');
        self::assertNotEmpty($token);

        // First POST creates the order and redirects to payment.
        $this->client->request('POST', $url, $this->validPostBody($token));
        self::assertResponseRedirects();
        self::assertStringContainsString('/platba', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->countOrdersForStorage($storage));

        // Re-POST with the same (now consumed) token must not create a second order, and must route
        // the customer to the already-created order's payment page (proving LAST_ORDER_ID_KEY was set).
        $this->client->request('POST', $url, $this->validPostBody($token));
        self::assertResponseRedirects();
        self::assertStringContainsString('/platba', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->countOrdersForStorage($storage));
    }

    public function testValidationFailureReissuesAFreshUsableToken(): void
    {
        $storage = $this->findAvailableStorage('A3');
        $url = $this->acceptUrl($storage);
        $this->seedOrderFormSession($storage);

        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $token1 = $crawler->filter('input[name="submit_token"]')->attr('value');

        // POST missing the VOP consent → validation fails, no order, page re-renders with a new token.
        $invalidBody = $this->validPostBody($token1);
        unset($invalidBody['accept_vop']);
        $crawler = $this->client->request('POST', $url, $invalidBody);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->countOrdersForStorage($storage));

        $token2 = $crawler->filter('input[name="submit_token"]')->attr('value');
        self::assertNotEmpty($token2);
        self::assertNotSame($token1, $token2);

        // The corrected resubmit with the reissued token succeeds.
        $this->client->request('POST', $url, $this->validPostBody($token2));
        self::assertResponseRedirects();
        self::assertSame(1, $this->countOrdersForStorage($storage));
    }

    public function testAdminOnlyStorageTypeReturns404(): void
    {
        // AO1 is a storage of the admin-only type — the accept route must reject it
        // (the guard fires before the session check, so no form session is needed).
        $storage = $this->findAvailableStorage('AO1');

        $this->client->request('GET', $this->acceptUrl($storage));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array<string, string>
     */
    private function validPostBody(string $token): array
    {
        return [
            'submit_token' => $token,
            'accept_contract' => '1',
            'accept_vop' => '1',
            'accept_consumer_notice' => '1',
            'accept_gdpr' => '1',
            'signature_consent' => '1',
            // Sent unconditionally; harmless when the place/dates don't require them.
            'accept_operating_rules' => '1',
            'accept_early_start_waiver' => '1',
            'signature_data' => self::signatureDataUrl(),
            'signing_method' => 'draw',
            'signing_place' => 'Praha',
        ];
    }

    /**
     * A real, byte-valid PNG so the downstream contract-PDF generator (TCPDF/libpng) can stamp it
     * without warnings.
     */
    private static function signatureDataUrl(): string
    {
        $image = imagecreatetruecolor(4, 4);
        \assert(false !== $image);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    private function seedOrderFormSession(Storage $storage): void
    {
        $formData = new OrderFormData();
        $formData->email = 'order-accept-test-'.$storage->number.'@example.com';
        $formData->firstName = 'Jan';
        $formData->lastName = 'Testovací';
        $formData->phone = '+420777123456';
        $formData->birthDate = new \DateTimeImmutable('1990-01-01');
        $formData->billingStreet = 'Testovací 1';
        $formData->billingCity = 'Praha';
        $formData->billingPostalCode = '11000';
        // 14-day rental (< 31-day card threshold) paid by bank transfer derives ONE_TIME,
        // non-recurring: keeps the POST body minimal (no recurring-payment consent needed).
        // Start well beyond the 14-day withdrawal window so no early-start waiver is required.
        $formData->startDate = new \DateTimeImmutable('2025-07-15');
        $formData->endDate = new \DateTimeImmutable('2025-07-29');
        $formData->paymentMethod = PaymentMethod::BANK_TRANSFER;
        $formData->billingMode = BillingMode::ONE_TIME;
        $formData->selectionMode = 'auto';

        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set('order_form_data', $formData->toSessionArray());
        $session->save();

        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    private function acceptUrl(Storage $storage): string
    {
        return sprintf(
            '/objednavka/%s/%s/%s/prijmout',
            $storage->place->id->toRfc4122(),
            $storage->storageType->id->toRfc4122(),
            $storage->id->toRfc4122(),
        );
    }

    private function findAvailableStorage(string $number): Storage
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $storage = $em->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.number = :number')
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($storage instanceof Storage, sprintf('Fixture storage %s not found', $number));

        return $storage;
    }

    private function countOrdersForStorage(Storage $storage): int
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return (int) $em->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->where('o.storage = :storage')
            ->setParameter('storage', $storage)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
