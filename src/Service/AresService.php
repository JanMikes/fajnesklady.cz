<?php

declare(strict_types=1);

namespace App\Service;

use h4kuna\Ares\Ares;
use h4kuna\Ares\AresFactory;
use h4kuna\Ares\Exception\IdentificationNumberNotFoundException;
use h4kuna\Ares\Exception\ServerResponseException;

final class AresService implements AresLookup
{
    private Ares $ares;

    public function __construct()
    {
        $this->ares = (new AresFactory())->create();
    }

    public function loadByCompanyId(string $companyId): ?AresResult
    {
        try {
            $data = $this->ares->loadBasic($companyId);

            $street = trim(sprintf('%s %s', $data->street ?? '', $data->house_number ?? ''));

            return new AresResult(
                companyName: $data->company ?? '',
                companyId: $data->in,
                companyVatId: $data->tin,
                street: $street,
                city: $data->city_post ?? $data->city ?? '',
                postalCode: $data->zip ?? '',
            );
        } catch (IdentificationNumberNotFoundException) {
            return null;
        } catch (ServerResponseException) {
            return null;
        }
    }
}
