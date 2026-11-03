<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Human label for an email_log.template_name. Single source of truth for the
 * admin order page's e-mail table AND its activity timeline — two inline maps
 * used to drift (handover e-mails had no label in either).
 *
 * EmailTemplateLabelExtensionTest asserts every templates/email/*.html.twig
 * has an entry, so a new e-mail cannot ship unlabelled.
 */
final class EmailTemplateLabelExtension extends AbstractExtension
{
    public const array LABELS = [
        'email/signing_link' => 'Odkaz k podpisu',
        'email/order_placed' => 'Potvrzení objednávky',
        'email/order_cancelled' => 'Zrušení objednávky',
        'email/rental_activated' => 'Aktivace pronájmu',
        'email/invoice' => 'Faktura',
        'email/onboarding_payment_reminder' => 'Připomínka platby (onboarding)',
        'email/manual_billing_payment_initial' => 'Výzva k platbě (7 dní)',
        'email/manual_billing_payment_reminder' => 'Připomínka platby (2 dny)',
        'email/manual_billing_payment_due_today' => 'Platba splatná dnes',
        'email/manual_billing_payment_overdue_first' => 'Upomínka — platba po splatnosti',
        'email/manual_billing_payment_overdue_final' => 'Poslední upomínka',
        'email/manual_billing_overdue_admin' => 'Upomínka (admin)',
        'email/recurring_payment_established' => 'Nastavení opakované platby',
        'email/recurring_payment_failed' => 'Selhání opakované platby',
        'email/recurring_payment_failed_admin' => 'Selhání platby (admin)',
        'email/recurring_payment_advance_notice' => 'Předběžné oznámení platby',
        'email/recurring_payment_cancelled' => 'Zrušení opakované platby',
        'email/recurring_payment_cancelled_admin' => 'Zrušení platby (admin)',
        'email/contract_expiring' => 'Blížící se konec smlouvy',
        'email/contract_prolonged' => 'Prodloužení smlouvy',
        'email/contract_terminated' => 'Ukončení smlouvy',
        'email/termination_notice' => 'Výpověď',
        'email/termination_notice_admin' => 'Výpověď (admin)',
        'email/payment_default_tenant' => 'Oznámení o prodlení',
        'email/payment_default_admin' => 'Prodlení (admin)',
        'email/payment_demand_tenant' => 'Výzva k úhradě',
        'email/payment_demand_admin' => 'Výzva k úhradě (admin)',
        'email/external_prepayment_ending_soon' => 'Končící externí předplatné',
        'email/external_prepayment_ending_soon_admin' => 'Končící předplatné (admin)',
        'email/debt_payment_request' => 'Výzva k úhradě dluhu',
        'email/debt_paid' => 'Potvrzení úhrady dluhu',
        'email/debt_paid_admin' => 'Úhrada dluhu (admin)',
        'email/fine_issued' => 'Vystavení pokuty',
        'email/fine_payment_reminder' => 'Připomínka pokuty',
        'email/fine_paid' => 'Potvrzení úhrady pokuty',
        'email/payment_amount_mismatch' => 'Nesouhlasí částka platby',
        'email/bank_transfer_amount_mismatch_admin' => 'Neshoda převodu (admin)',
        'email/bank_transfer_amount_mismatch_customer' => 'Neshoda převodu (zákazník)',
        'email/unaccounted_paid_payment' => 'Platba bez uplatnění (admin)',
        'email/handover_request_tenant' => 'Předávací protokol — výzva nájemci',
        'email/handover_request_landlord' => 'Předávací protokol — výzva pronajímateli',
        'email/handover_reminder_tenant' => 'Předávací protokol — připomínka nájemci',
        'email/handover_reminder_landlord' => 'Předávací protokol — připomínka pronajímateli',
        'email/handover_completed' => 'Předávací protokol dokončen',
        'email/handover_expired_admin' => 'Předávací protokol nevyplněn — sklad uvolněn (admin)',
        'email/storage_availability_warning' => 'Upozornění na dostupnost skladu',
        'email/storage_availability_warning_admin' => 'Upozornění na dostupnost skladu (admin)',
        'email/overdue_digest' => 'Přehled plateb po splatnosti (admin)',
        'email/welcome' => 'Uvítací e-mail',
        'email/verification' => 'Ověření e-mailu',
        'email/password_changed_by_admin' => 'Heslo změněno administrátorem',
        'email/place_access_requested' => 'Žádost o přístup k pobočce',
        'email/place_access_approved' => 'Přístup k pobočce schválen',
        'email/place_access_denied' => 'Přístup k pobočce zamítnut',
        'email/place_proposed' => 'Návrh nové pobočky',
        'user/email/reset_password' => 'Obnovení hesla',
    ];

    public function getFilters(): array
    {
        return [
            new TwigFilter('email_template_label', $this->label(...)),
        ];
    }

    public function label(?string $templateName): string
    {
        if (null === $templateName) {
            return '—';
        }

        return self::LABELS[$templateName] ?? $templateName;
    }
}
