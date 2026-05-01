<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\EmailLog;
use App\Enum\EmailLogStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class EmailLogFixtures extends Fixture
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        $samples = [
            [
                'minutesAgo' => 5,
                'status' => EmailLogStatus::SENT,
                'subject' => 'Vítejte na Fajnesklady.cz',
                'to' => [['email' => 'user@example.com', 'name' => 'Jan Novák']],
                'template' => 'email/welcome.html.twig',
                'attachments' => null,
                'errorMessage' => null,
            ],
            [
                'minutesAgo' => 30,
                'status' => EmailLogStatus::SENT,
                'subject' => 'Potvrzení objednávky #2025-0001',
                'to' => [['email' => 'tenant@example.com', 'name' => 'Eva Nájemce']],
                'template' => 'email/order_confirmation.html.twig',
                'attachments' => [
                    ['name' => 'smlouva.pdf', 'sizeBytes' => 184_321, 'mimeType' => 'application/pdf'],
                    ['name' => 'mapa.png', 'sizeBytes' => 24_500, 'mimeType' => 'image/png'],
                ],
                'errorMessage' => null,
            ],
            [
                'minutesAgo' => 90,
                'status' => EmailLogStatus::SENT,
                'subject' => 'Faktura #FA-2025-0001',
                'to' => [['email' => 'tenant@example.com', 'name' => 'Eva Nájemce']],
                'template' => 'email/invoice_issued.html.twig',
                'attachments' => [
                    ['name' => 'faktura.pdf', 'sizeBytes' => 92_100, 'mimeType' => 'application/pdf'],
                ],
                'errorMessage' => null,
            ],
            [
                'minutesAgo' => 120,
                'status' => EmailLogStatus::SENT,
                'subject' => 'Vaše smlouva je připravena k podpisu',
                'to' => [['email' => 'landlord@example.com', 'name' => 'Marie Skladová']],
                'template' => 'email/contract_ready.html.twig',
                'attachments' => null,
                'errorMessage' => null,
            ],
            [
                'minutesAgo' => 240,
                'status' => EmailLogStatus::FAILED,
                'subject' => 'Připomenutí: nezaplacená faktura',
                'to' => [['email' => 'unknown-mailbox@example.invalid', 'name' => null]],
                'template' => 'email/payment_reminder.html.twig',
                'attachments' => null,
                'errorMessage' => 'SMTP error: 550 Mailbox not found.',
            ],
            [
                'minutesAgo' => 360,
                'status' => EmailLogStatus::SENT,
                'subject' => 'Připomenutí předání protokolu',
                'to' => [['email' => 'admin@example.com', 'name' => 'Admin System']],
                'template' => 'email/handover_reminder.html.twig',
                'attachments' => null,
                'errorMessage' => null,
            ],
        ];

        foreach ($samples as $sample) {
            $attemptedAt = $now->modify(sprintf('-%d minutes', $sample['minutesAgo']));

            $log = new EmailLog(
                id: Uuid::v7(),
                attemptedAt: $attemptedAt,
                status: $sample['status'],
                errorMessage: $sample['errorMessage'],
                fromEmail: 'noreply@fajnesklady.cz',
                fromName: 'Fajnesklady.cz',
                toAddresses: $sample['to'],
                ccAddresses: null,
                bccAddresses: null,
                replyToAddresses: null,
                subject: $sample['subject'],
                htmlBody: '<p>Ukázkový obsah pro <strong>'.htmlspecialchars($sample['subject']).'</strong>.</p>',
                textBody: 'Ukázkový obsah pro '.$sample['subject'].'.',
                templateName: $sample['template'],
                attachments: $sample['attachments'],
                messageId: '<'.bin2hex(random_bytes(8)).'@fajnesklady.cz>',
            );

            $manager->persist($log);
        }

        $manager->flush();
    }
}
