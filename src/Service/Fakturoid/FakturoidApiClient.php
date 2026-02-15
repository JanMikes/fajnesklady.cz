<?php

declare(strict_types=1);

namespace App\Service\Fakturoid;

use App\Entity\Order;
use App\Entity\SelfBillingInvoice;
use App\Entity\User;
use App\Value\FakturoidInvoice;
use App\Value\FakturoidSubject;
use Fakturoid\Exception\RequestException;
use Fakturoid\FakturoidManager;
use Psr\Log\LoggerInterface;

final readonly class FakturoidApiClient implements FakturoidClient
{
    public function __construct(
        private FakturoidManager $manager,
        private LoggerInterface $logger,
        private int $vatRate,
    ) {
    }

    public function createSubject(User $user): FakturoidSubject
    {
        $data = [
            'name' => $user->companyName ?? $user->fullName,
            'email' => $user->email,
            'street' => $user->billingStreet,
            'city' => $user->billingCity,
            'zip' => $user->billingPostalCode,
            'registration_no' => $user->companyId,
            'vat_no' => $user->companyVatId,
        ];

        if (null !== $user->bankAccountNumber) {
            $data['bank_account'] = null !== $user->bankCode
                ? $user->bankAccountNumber.'/'.$user->bankCode
                : $user->bankAccountNumber;
        }

        $response = $this->manager->getSubjectsProvider()->create($data);

        /** @var \stdClass $body */
        $body = $response->getBody();

        return new FakturoidSubject(
            id: (int) $body->id,
            name: (string) $body->name,
        );
    }

    public function updateSubject(int $subjectId, User $user): void
    {
        $data = [
            'name' => $user->companyName ?? $user->fullName,
            'email' => $user->email,
            'street' => $user->billingStreet,
            'city' => $user->billingCity,
            'zip' => $user->billingPostalCode,
            'registration_no' => $user->companyId,
            'vat_no' => $user->companyVatId,
            'bank_account' => null !== $user->bankAccountNumber
                ? (null !== $user->bankCode
                    ? $user->bankAccountNumber.'/'.$user->bankCode
                    : $user->bankAccountNumber)
                : null,
        ];

        $this->manager->getSubjectsProvider()->update($subjectId, $data);
    }

    public function createInvoice(int $subjectId, Order $order): FakturoidInvoice
    {
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        try {
            $response = $this->manager->getInvoicesProvider()->create([
                'subject_id' => $subjectId,
                'lines' => [
                    [
                        'name' => sprintf(
                            'Pronájem skladového boxu %s - %s (%s)',
                            $storage->number,
                            $storageType->name,
                            $place->name,
                        ),
                        'quantity' => 1,
                        'unit_price' => $order->getTotalPriceInCzk(),
                        'vat_rate' => $this->vatRate,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $context = [
                'subject_id' => $subjectId,
                'order_id' => $order->id->toRfc4122(),
                'error' => $e->getMessage(),
            ];

            if ($e instanceof RequestException) {
                $body = $e->getResponse()->getBody();
                $body->rewind();
                $context['status'] = $e->getResponse()->getStatusCode();
                $context['body'] = $body->getContents();
            }

            $this->logger->error('Fakturoid invoice creation failed', $context);

            throw $e;
        }

        /** @var \stdClass $body */
        $body = $response->getBody();

        return new FakturoidInvoice(
            id: (int) $body->id,
            number: (string) $body->number,
            total: (int) round((float) $body->total * 100),
        );
    }

    public function markInvoiceAsPaid(int $invoiceId, \DateTimeImmutable $paidAt): void
    {
        try {
            $this->manager->getInvoicesProvider()->createPayment($invoiceId, [
                'paid_on' => $paidAt->format('Y-m-d'),
                'currency' => 'CZK',
                'payment_method' => 'card',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to mark Fakturoid invoice as paid', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function downloadInvoicePdf(int $invoiceId): string
    {
        // Fakturoid returns 204 (No Content) when PDF is still being generated.
        // Retry up to 3 times with 2s delay.
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $response = $this->manager->getInvoicesProvider()->getPdf($invoiceId);
            $body = $response->getBody();

            if (\is_string($body) && '' !== $body) {
                return $body;
            }

            if ($attempt < 3) {
                $this->logger->info('Fakturoid PDF not ready, retrying', [
                    'invoice_id' => $invoiceId,
                    'attempt' => $attempt,
                ]);
                sleep(2);
            }
        }

        throw new \RuntimeException(sprintf('Fakturoid PDF not available after 3 attempts for invoice %d', $invoiceId));
    }

    public function createSelfBillingInvoice(int $subjectId, SelfBillingInvoice $invoice): FakturoidInvoice
    {
        $landlord = $invoice->landlord;

        $response = $this->manager->getInvoicesProvider()->create([
            'subject_id' => $subjectId,
            'number' => $invoice->invoiceNumber,
            'document_type' => 'partial_proforma', // Self-billing uses proforma until payment
            'lines' => [
                [
                    'name' => sprintf(
                        'Provize za pronájem skladů - %02d/%d',
                        $invoice->month,
                        $invoice->year,
                    ),
                    'quantity' => 1,
                    'unit_price' => $invoice->getNetAmountInCzk(),
                    'vat_rate' => $this->vatRate,
                ],
            ],
            'note' => sprintf(
                'Samofakturace za období %02d/%d. Hrubá částka: %s Kč, provize: %s%%.',
                $invoice->month,
                $invoice->year,
                number_format($invoice->getGrossAmountInCzk(), 2, ',', ' '),
                // Cast through float to ensure numeric-string for bcmul
                bcmul((string) (float) $invoice->commissionRate, '100', 0),
            ),
        ]);

        /** @var \stdClass $body */
        $body = $response->getBody();

        return new FakturoidInvoice(
            id: (int) $body->id,
            number: (string) $body->number,
            total: (int) round((float) $body->total * 100),
        );
    }
}
