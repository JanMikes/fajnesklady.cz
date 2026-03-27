<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Event\OrderCreated;
use App\Event\SendOrderConfirmationEmailHandler;
use App\Repository\OrderRepository;
use App\Repository\StorageRepository;
use App\Service\PublicFilesystem;
use App\Service\StorageMapImageGenerator;
use Intervention\Image\ImageManager;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class SendOrderConfirmationEmailHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/order_email_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir.'/public/documents', 0755, true);
        mkdir($this->tempDir.'/public/uploads/places', 0755, true);

        // Create static document files
        file_put_contents($this->tempDir.'/public/documents/vop.pdf', '%PDF-vop');
        file_put_contents($this->tempDir.'/public/documents/pouceni-spotrebitele.pdf', '%PDF-consumer');
        file_put_contents($this->tempDir.'/public/documents/podminky-opakovanych-plateb.pdf', '%PDF-recurring');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testAttachesVopAndConsumerNoticeForLimitedRental(): void
    {
        $order = $this->createOrder(endDate: new \DateTimeImmutable('+30 days'));
        $sentEmail = $this->sendEmail($order);

        $attachments = $sentEmail->getAttachments();
        $names = array_map(fn ($a) => $a->getFilename(), $attachments);

        $this->assertContains('vop.pdf', $names);
        $this->assertContains('pouceni-spotrebitele.pdf', $names);
        $this->assertNotContains('podminky-opakovanych-plateb.pdf', $names);
    }

    public function testAttachesRecurringPaymentsTermsForUnlimitedRental(): void
    {
        $order = $this->createOrder(endDate: null);
        $sentEmail = $this->sendEmail($order);

        $attachments = $sentEmail->getAttachments();
        $names = array_map(fn ($a) => $a->getFilename(), $attachments);

        $this->assertContains('vop.pdf', $names);
        $this->assertContains('pouceni-spotrebitele.pdf', $names);
        $this->assertContains('podminky-opakovanych-plateb.pdf', $names);
    }

    public function testAttachesOperatingRulesWhenPlaceHasOne(): void
    {
        $order = $this->createOrder(endDate: new \DateTimeImmutable('+30 days'));

        // Create operating rules file
        $rulesDir = $this->tempDir.'/public/uploads/places/test';
        mkdir($rulesDir, 0755, true);
        file_put_contents($rulesDir.'/provozni-rad.pdf', '%PDF-rules');
        $order->storage->getPlace()->updateOperatingRules('places/test/provozni-rad.pdf', new \DateTimeImmutable());

        $sentEmail = $this->sendEmail($order);

        $attachments = $sentEmail->getAttachments();
        $names = array_map(fn ($a) => $a->getFilename(), $attachments);

        $this->assertContains('provozni-rad.pdf', $names);
    }

    public function testDoesNotAttachOperatingRulesWhenPlaceHasNone(): void
    {
        $order = $this->createOrder(endDate: new \DateTimeImmutable('+30 days'));
        $sentEmail = $this->sendEmail($order);

        $attachments = $sentEmail->getAttachments();
        $names = array_map(fn ($a) => $a->getFilename(), $attachments);

        // Should not contain any provozni-rad attachment
        foreach ($names as $name) {
            $this->assertStringNotContainsString('provozni-rad', (string) $name);
        }
    }

    private function sendEmail(Order $order): Email
    {
        $event = new OrderCreated(
            $order->id,
            $order->user->id,
            $order->storage->id,
            $order->totalPrice,
            new \DateTimeImmutable(),
        );

        $orderRepository = $this->createStub(OrderRepository::class);
        $orderRepository->method('get')->willReturn($order);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/order');

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByPlace')->willReturn([]);
        $filesystemOperator = $this->createStub(FilesystemOperator::class);
        $filesystem = new PublicFilesystem($filesystemOperator);
        $mapImageGenerator = new StorageMapImageGenerator($storageRepository, $filesystem, ImageManager::gd(), new NullLogger());

        $sentEmail = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sentEmail) {
            $sentEmail = $email;
        });

        $handler = new SendOrderConfirmationEmailHandler(
            $orderRepository,
            $mailer,
            $urlGenerator,
            $mapImageGenerator,
            $this->tempDir,
        );
        $handler($event);

        $this->assertNotNull($sentEmail);

        return $sentEmail;
    }

    private function createOrder(?\DateTimeImmutable $endDate): Order
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );

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

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: null === $endDate ? RentalType::UNLIMITED : RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable(),
            endDate: $endDate,
            totalPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
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
}
