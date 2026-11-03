<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\EmailTemplateLabelExtension;
use PHPUnit\Framework\TestCase;

/**
 * Static guards for the admin order page's e-mail visibility. Both regressions
 * found on order 01a0cd43 (2026-11) were silent: handover e-mails were sent
 * but never linked to the order (no X-Order-Id), and had no label either.
 */
class EmailTemplateLabelExtensionTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    /**
     * Mail handlers whose e-mails are not about one specific order (account,
     * place access, portfolio digests, admin summaries).
     */
    private const array NOT_ORDER_SPECIFIC = [
        'SendOverdueDigestEmailHandler.php',
        'SendPlaceAccessRequestEmailHandler.php',
        'SendPlaceAccessApprovedEmailHandler.php',
        'SendPlaceAccessDeniedEmailHandler.php',
        'SendPlaceProposedEmailHandler.php',
        'SendPasswordResetEmailHandler.php',
        'SendPasswordChangedByAdminEmailHandler.php',
        'SendWelcomeEmailHandler.php',
        'SendVerificationEmailHandler.php',
        'ResendVerificationEmailHandler.php',
    ];

    public function testEveryEmailTemplateHasALabel(): void
    {
        $files = glob(self::ROOT.'/templates/email/*.html.twig');
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $name = basename($file, '.html.twig');
            if (str_starts_with($name, '_')) {
                continue; // partial, never sent on its own
            }

            self::assertArrayHasKey('email/'.$name, EmailTemplateLabelExtension::LABELS, sprintf('Add a label for email/%s to %s.', $name, EmailTemplateLabelExtension::class));
        }
    }

    public function testUnknownTemplateFallsBackToItsName(): void
    {
        $extension = new EmailTemplateLabelExtension();

        self::assertSame('Předávací protokol — připomínka nájemci', $extension->label('email/handover_reminder_tenant'));
        self::assertSame('email/something_new', $extension->label('email/something_new'));
        self::assertSame('—', $extension->label(null));
    }

    /**
     * Every e-mail about an order/contract/fine must set X-Order-Id, or
     * EmailLogger stores it with order_id NULL and the admin order page never
     * shows it.
     */
    public function testOrderRelatedEmailsCarryTheOrderIdHeader(): void
    {
        $files = array_merge(
            glob(self::ROOT.'/src/Event/*.php') ?: [],
            glob(self::ROOT.'/src/Command/*.php') ?: [],
        );

        $missing = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'new TemplatedEmail()')) {
                continue;
            }
            if (in_array(basename($file), self::NOT_ORDER_SPECIFIC, true)) {
                continue;
            }
            if (!preg_match('/\$(contract|order|fine)->/', $source)) {
                continue;
            }
            if (!str_contains($source, "'X-Order-Id'")) {
                $missing[] = basename($file);
            }
        }

        self::assertSame([], $missing, 'These mail handlers send order-related e-mails without X-Order-Id.');
    }
}
