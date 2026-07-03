<?php

declare(strict_types=1);

namespace App\Service\Fakturoid;

use App\Entity\Contract;
use App\Entity\Fine;
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

        try {
            $response = $this->manager->getSubjectsProvider()->create($data);
        } catch (\Throwable $e) {
            $context = [
                'user_email' => $user->email,
                'exception' => $e,
            ];

            if ($e instanceof RequestException) {
                $body = $e->getResponse()->getBody();
                $body->rewind();
                $context['status'] = $e->getResponse()->getStatusCode();
                $context['body'] = $body->getContents();
            }

            $this->logger->error('Fakturoid subject creation failed', $context);

            throw $e;
        }

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

        try {
            $this->manager->getSubjectsProvider()->update($subjectId, $data);
        } catch (\Throwable $e) {
            $context = [
                'subject_id' => $subjectId,
                'user_email' => $user->email,
                'exception' => $e,
            ];

            if ($e instanceof RequestException) {
                $body = $e->getResponse()->getBody();
                $body->rewind();
                $context['status'] = $e->getResponse()->getStatusCode();
                $context['body'] = $body->getContents();
            }

            $this->logger->error('Fakturoid subject update failed', $context);

            throw $e;
        }
    }

    public function createInvoice(int $subjectId, Order $order): FakturoidInvoice
    {
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        try {
            $response = $this->manager->getInvoicesProvider()->create([
                'subject_id' => $subjectId,
                // Prices in our system are gross (vč. DPH); Fakturoid must back-calculate VAT, not add it on top.
                'vat_price_mode' => 'from_total_with_vat',
                'lines' => [
                    [
                        'name' => sprintf(
                            'Pronájem skladovací jednotky %s - %s (%s)',
                            $storage->number,
                            $storageType->name,
                            $place->name,
                        ),
                        'quantity' => 1,
                        'unit_price' => $order->getFirstPaymentPriceInCzk(),
                        'vat_rate' => $this->vatRate,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $context = [
                'subject_id' => $subjectId,
                'order_id' => $order->id->toRfc4122(),
                'exception' => $e,
            ];

            if ($e instanceof RequestException) {
                $body = $e->getResponse()->getBody();
                $body->rewind();
                $context['status'] = $e->getResponse()->getStatusCode();
                $context['body'] = $body->getContents();
            }

            if ($this->isStaleSubjectError($context)) {
                // Caller (InvoicingService) recreates the subject and retries —
                // log at info so the recovery doesn't pollute the error stream.
                $this->logger->info('Fakturoid subject no longer exists; caller will recreate', $context);

                throw new StaleFakturoidSubjectException($subjectId, $e);
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

    public function createDebtInvoice(int $subjectId, Order $order): FakturoidInvoice
    {
        $place = $order->storage->getPlace();

        try {
            $response = $this->manager->getInvoicesProvider()->create([
                'subject_id' => $subjectId,
                // The debt amount is gross (vč. DPH); Fakturoid must back-calculate VAT, not add it on top.
                'vat_price_mode' => 'from_total_with_vat',
                'lines' => [
                    [
                        // The debt is from a previous arrangement — name it generically, tagged by place for the books.
                        'name' => sprintf('Úhrada dluhu z předchozí smlouvy (%s)', $place->name),
                        'quantity' => 1,
                        'unit_price' => $order->getDebtAmountInCzk(),
                        'vat_rate' => $this->vatRate,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $context = [
                'subject_id' => $subjectId,
                'order_id' => $order->id->toRfc4122(),
                'exception' => $e,
            ];

            if ($e instanceof RequestException) {
                $body = $e->getResponse()->getBody();
                $body->rewind();
                $context['status'] = $e->getResponse()->getStatusCode();
                $context['body'] = $body->getContents();
            }

            if ($this->isStaleSubjectError($context)) {
                // Caller (InvoicingService) recreates the subject and retries —
                // log at info so the recovery doesn't pollute the error stream.
                $this->logger->info('Fakturoid subject no longer exists; caller will recreate', $context);

                throw new StaleFakturoidSubjectException($subjectId, $e);
            }

            $this->logger->error('Fakturoid debt invoice creation failed', $context);

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

    public function createFineInvoice(int $subjectId, Fine $fine): FakturoidInvoice
    {
        $place = $fine->contract->storage->getPlace();

        try {
            $response = $this->manager->getInvoicesProvider()->create([
                'subject_id' => $subjectId,
                // Kept for symmetry with the other invoices: were the rate ever
                // flipped to a non-zero one, the gross amount must stay gross.
                'vat_price_mode' => 'from_total_with_vat',
                'lines' => [
                    [
                        'name' => sprintf('Smluvní pokuta — %s (%s)', $fine->type->label(), $place->name),
                        'quantity' => 1,
                        'unit_price' => $fine->getAmountInCzk(),
                        // Smluvní pokuta není předmětem DPH (není úplatou za plnění) — 0 %, ne $this->vatRate.
                        'vat_rate' => 0,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $context = [
                'subject_id' => $subjectId,
                'fine_id' => $fine->id->toRfc4122(),
                'exception' => $e,
            ];

            if ($e instanceof RequestException) {
                $body = $e->getResponse()->getBody();
                $body->rewind();
                $context['status'] = $e->getResponse()->getStatusCode();
                $context['body'] = $body->getContents();
            }

            if ($this->isStaleSubjectError($context)) {
                // Caller (InvoicingService) recreates the subject and retries —
                // log at info so the recovery doesn't pollute the error stream.
                $this->logger->info('Fakturoid subject no longer exists; caller will recreate', $context);

                throw new StaleFakturoidSubjectException($subjectId, $e);
            }

            $this->logger->error('Fakturoid fine invoice creation failed', $context);

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

    public function createRecurringInvoice(int $subjectId, Contract $contract, int $amount, \DateTimeImmutable $billingDate): FakturoidInvoice
    {
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        try {
            $response = $this->manager->getInvoicesProvider()->create([
                'subject_id' => $subjectId,
                // Prices in our system are gross (vč. DPH); Fakturoid must back-calculate VAT, not add it on top.
                'vat_price_mode' => 'from_total_with_vat',
                'lines' => [
                    [
                        'name' => sprintf(
                            'Pravidelná platba - %s, box %s (%s) - %s',
                            $storageType->name,
                            $storage->number,
                            $place->name,
                            $billingDate->format('m/Y'),
                        ),
                        'quantity' => 1,
                        'unit_price' => $amount / 100,
                        'vat_rate' => $this->vatRate,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $context = [
                'subject_id' => $subjectId,
                'contract_id' => $contract->id->toRfc4122(),
                'exception' => $e,
            ];

            if ($e instanceof RequestException) {
                $body = $e->getResponse()->getBody();
                $body->rewind();
                $context['status'] = $e->getResponse()->getStatusCode();
                $context['body'] = $body->getContents();
            }

            if ($this->isStaleSubjectError($context)) {
                $this->logger->info('Fakturoid subject no longer exists; caller will recreate', $context);

                throw new StaleFakturoidSubjectException($subjectId, $e);
            }

            $this->logger->error('Fakturoid recurring invoice creation failed', $context);

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
                'exception' => $e,
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
            // Prices in our system are gross (vč. DPH); Fakturoid must back-calculate VAT, not add it on top.
            'vat_price_mode' => 'from_total_with_vat',
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

    /**
     * Detect Fakturoid's "subject_id refers to a contact that no longer
     * exists" 422 response. Body shape: {"errors":{"subject_id":["Kontakt
     * neexistuje."],"client_name":["je povinná položka"]}}.
     *
     * @param array<string, mixed> $context built in the catch block above —
     *                                      carries the parsed 'status' and 'body'
     */
    private function isStaleSubjectError(array $context): bool
    {
        if (422 !== ($context['status'] ?? null)) {
            return false;
        }

        $body = $context['body'] ?? null;
        if (!\is_string($body)) {
            return false;
        }

        $payload = json_decode($body, true);
        if (!\is_array($payload) || !isset($payload['errors']['subject_id']) || !\is_array($payload['errors']['subject_id'])) {
            return false;
        }

        foreach ($payload['errors']['subject_id'] as $message) {
            if (\is_string($message) && str_contains($message, 'Kontakt neexistuje')) {
                return true;
            }
        }

        return false;
    }
}
