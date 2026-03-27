<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Event\OrderCompleted;
use App\Event\SendContractReadyEmailHandler;
use App\Repository\ContractRepository;
use App\Service\RecurringPaymentCancelUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class SendContractReadyEmailHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/contract_ready_email_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testHandlerSendsEmailWithCorrectRecipientAndSubject(): void
    {
        $contract = $this->createContract();
        $event = new OrderCompleted($contract->order->id, $contract->id, new \DateTimeImmutable());

        $handler = $this->createHandler($contract, $sentEmail);
        $handler($event);

        $this->assertNotNull($sentEmail);
        $to = $sentEmail->getTo();
        $this->assertCount(1, $to);
        $this->assertSame('tenant@example.com', $to[0]->getAddress());
        $this->assertNotNull($sentEmail->getSubject());
        $this->assertStringContainsString('Smlouva připravena', $sentEmail->getSubject());
    }

    public function testHandlerAttachesOperatingRulesWhenAvailable(): void
    {
        $rulesDir = $this->tempDir.'/places/test/operating-rules';
        mkdir($rulesDir, 0755, true);
        $rulesPath = 'places/test/operating-rules/provozni-rad.pdf';
        file_put_contents($this->tempDir.'/'.$rulesPath, '%PDF-1.4 test content');

        $contract = $this->createContract(operatingRulesPath: $rulesPath);
        $event = new OrderCompleted($contract->order->id, $contract->id, new \DateTimeImmutable());

        $handler = $this->createHandler($contract, $sentEmail);
        $handler($event);

        $this->assertNotNull($sentEmail);
        $attachments = $sentEmail->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('provozni_rad.pdf', $attachments[0]->getFilename());
    }

    public function testHandlerDoesNotAttachOperatingRulesWhenNotAvailable(): void
    {
        $contract = $this->createContract();
        $event = new OrderCompleted($contract->order->id, $contract->id, new \DateTimeImmutable());

        $handler = $this->createHandler($contract, $sentEmail);
        $handler($event);

        $this->assertNotNull($sentEmail);
        $attachments = $sentEmail->getAttachments();
        $this->assertCount(0, $attachments);
    }

    public function testHandlerAttachesBothContractDocumentAndOperatingRules(): void
    {
        // Create contract document
        $contractDocPath = $this->tempDir.'/contract.docx';
        file_put_contents($contractDocPath, 'PK test docx content');

        // Create operating rules
        $rulesDir = $this->tempDir.'/places/test/operating-rules';
        mkdir($rulesDir, 0755, true);
        $rulesPath = 'places/test/operating-rules/provozni-rad.docx';
        file_put_contents($this->tempDir.'/'.$rulesPath, 'PK test docx content');

        $contract = $this->createContract(operatingRulesPath: $rulesPath);
        $contract->attachDocument($contractDocPath, new \DateTimeImmutable());

        $event = new OrderCompleted($contract->order->id, $contract->id, new \DateTimeImmutable());

        $handler = $this->createHandler($contract, $sentEmail);
        $handler($event);

        $this->assertNotNull($sentEmail);
        $attachments = $sentEmail->getAttachments();
        $this->assertCount(2, $attachments);
    }

    /**
     * @param Email|null $sentEmail Captured email reference
     */
    private function createHandler(Contract $contract, ?Email &$sentEmail): SendContractReadyEmailHandler
    {
        $contractRepository = $this->createStub(ContractRepository::class);
        $contractRepository->method('get')->willReturn($contract);

        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail) {
            $sentEmail = $email;
        });

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/portal');

        $uriSigner = new \Symfony\Component\HttpFoundation\UriSigner('test-secret');
        $cancelUrlGenerator = new RecurringPaymentCancelUrlGenerator($urlGenerator, $uriSigner);

        return new SendContractReadyEmailHandler(
            $contractRepository,
            $mailer,
            $urlGenerator,
            $cancelUrlGenerator,
            $this->tempDir,
        );
    }

    private function createContract(?string $operatingRulesPath = null): Contract
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Warehouse',
            address: 'Testovaci 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );

        if (null !== $operatingRulesPath) {
            $place->updateOperatingRules($operatingRulesPath, new \DateTimeImmutable());
        }

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 200,
            innerLength: 150,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: new \DateTimeImmutable(),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $user = new User(
            Uuid::v7(),
            'tenant@example.com',
            'password',
            'Jan',
            'Novak',
            new \DateTimeImmutable(),
        );

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-20'),
            endDate: new \DateTimeImmutable('2025-07-20'),
            totalPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            startDate: new \DateTimeImmutable('2025-06-20'),
            endDate: new \DateTimeImmutable('2025-07-20'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );
    }
}
