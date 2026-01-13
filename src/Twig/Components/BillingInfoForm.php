<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\User;
use App\Form\BillingInfoFormData;
use App\Form\BillingInfoFormType;
use App\Service\AresLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class BillingInfoForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public ?string $aresError = null;

    #[LiveProp]
    public ?string $aresSuccess = null;

    public function __construct(
        private readonly AresLookup $aresLookup,
    ) {
    }

    /**
     * @return FormInterface<BillingInfoFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        $user = $this->getUser();
        $initialData = $user instanceof User
            ? BillingInfoFormData::fromUser($user)
            : new BillingInfoFormData();

        return $this->createForm(BillingInfoFormType::class, $initialData);
    }

    #[LiveAction]
    public function loadFromAres(): void
    {
        $this->aresError = null;
        $this->aresSuccess = null;
        $companyId = $this->formValues['companyId'] ?? null;

        if (null === $companyId || '' === $companyId) {
            $this->aresError = 'Zadejte IČO pro načtení údajů z ARES.';

            return;
        }

        if (!\is_string($companyId) || !preg_match('/^\d{8}$/', $companyId)) {
            $this->aresError = 'IČO musí mít přesně 8 číslic.';

            return;
        }

        $result = $this->aresLookup->loadByCompanyId($companyId);

        if (null === $result) {
            $this->aresError = 'Společnost s tímto IČO nebyla nalezena v registru ARES.';

            return;
        }

        $this->formValues['companyName'] = $result->companyName;
        $this->formValues['companyVatId'] = $result->companyVatId ?? '';
        $this->formValues['billingStreet'] = $result->street;
        $this->formValues['billingCity'] = $result->city;
        $this->formValues['billingPostalCode'] = $result->postalCode;

        $this->aresSuccess = 'Údaje byly úspěšně načteny z ARES.';
    }
}
