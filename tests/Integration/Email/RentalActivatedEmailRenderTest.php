<?php

declare(strict_types=1);

namespace App\Tests\Integration\Email;

use App\Command\CompleteOrderCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\Order\RentalActivatedEmailContent;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Twig\Environment;

/**
 * Renders the real rental-activated template for the 2026-11-24 incident
 * shape: a yearly rental paid by bank transfer a week before it starts. The
 * customer received "Pronájem zahájen … předplacen externě do 24.11.2027. Od
 * 24.11.2027 činí nájemné …".
 */
class RentalActivatedEmailRenderTest extends KernelTestCase
{
    public function testYearlyBankTransferPaidBeforeStart(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $clock = $container->get(ClockInterface::class);
        $orderService = $container->get(OrderService::class);

        /** @var User $tenant */
        $tenant = $em->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $em->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $em->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $clock->now(); // 2025-06-15 12:00
        $order = $orderService->createOrder(
            user: $tenant,
            storageType: $storageType,
            place: $place,
            startDate: new \DateTimeImmutable('2025-06-22'),
            endDate: new \DateTimeImmutable('2026-06-22'),
            now: $now,
            paymentFrequency: PaymentFrequency::YEARLY,
        );
        $order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->reserve($now);
        $orderService->processPayment($order);
        $orderService->confirmPayment($order, $now);

        $envelope = $container->get('test.command.bus')->dispatch(new CompleteOrderCommand($order));
        $contract = $envelope->last(HandledStamp::class)?->getResult();
        \assert($contract instanceof Contract);

        $content = RentalActivatedEmailContent::fromContract($contract, $now);
        $subject = $content->subject;

        /** @var Environment $twig */
        $twig = $container->get('test.twig');
        $html = $twig->render('email/rental_activated.html.twig', [
            'name' => 'Jan Novák',
            'content' => $content,
            'contractNumber' => '2025-0615-TEST',
            'placeName' => $place->name,
            'placeAddress' => 'Testovací 1',
            'placeNavigationUrl' => null,
            'storageType' => $storageType->name,
            'storageNumber' => 'A1',
            'startDate' => '22.06.2025',
            'endDate' => '22.06.2026',
            'statusUrl' => 'https://example.com/status',
            'isRecurring' => false,
            'monthlyAmount' => null,
            'nextBillingDate' => null,
            'cancelUrl' => null,
            'hasMapAttachment' => false,
            'hasOperatingRulesAttachment' => false,
            'hasInstructionsAttachment' => false,
            'lockCode' => null,
            'hasInvoiceAttachment' => false,
            'invoiceNumber' => null,
        ]);

        $this->assertStringStartsWith('Pronájem začne 22.06.2025 — platba přijata — ', $subject);
        $this->assertStringContainsString('<h1>✓ Pronájem začne 22.06.2025</h1>', $html);
        $this->assertStringContainsString('Nájemné je uhrazeno do konce smlouvy (22.06.2026) — žádné další platby Vás nečekají.', $html);
        $this->assertStringNotContainsString('externě', $html);
        $this->assertStringNotContainsString('Pronájem zahájen', $html);
        $this->assertStringNotContainsString('QR kódem', $html, 'nothing is left to pay');
    }
}
