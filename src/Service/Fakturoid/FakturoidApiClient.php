<?php

declare(strict_types=1);

namespace App\Service\Fakturoid;

use App\Entity\Order;
use App\Entity\User;
use App\Value\FakturoidInvoice;
use App\Value\FakturoidSubject;
use Fakturoid\FakturoidManager;

final readonly class FakturoidApiClient implements FakturoidClient
{
    public function __construct(
        private FakturoidManager $manager,
    ) {
    }

    public function createSubject(User $user): FakturoidSubject
    {
        $response = $this->manager->getSubjectsProvider()->create([
            'name' => $user->companyName ?? $user->fullName,
            'email' => $user->email,
            'street' => $user->billingStreet,
            'city' => $user->billingCity,
            'zip' => $user->billingPostalCode,
            'registration_no' => $user->companyId,
            'vat_no' => $user->companyVatId,
        ]);

        /** @var \stdClass $body */
        $body = $response->getBody();

        return new FakturoidSubject(
            id: (int) $body->id,
            name: (string) $body->name,
        );
    }

    public function createInvoice(int $subjectId, Order $order): FakturoidInvoice
    {
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

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
                    'vat_rate' => 21,
                ],
            ],
        ]);

        /** @var \stdClass $body */
        $body = $response->getBody();

        return new FakturoidInvoice(
            id: (int) $body->id,
            number: (string) $body->number,
            total: (int) round((float) $body->total * 100),
        );
    }

    public function downloadInvoicePdf(int $invoiceId): string
    {
        $response = $this->manager->getInvoicesProvider()->getPdf($invoiceId);

        /** @var string $body */
        $body = $response->getBody();

        return $body;
    }
}
