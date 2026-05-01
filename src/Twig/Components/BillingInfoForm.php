<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\User;
use App\Form\BillingInfoFormData;
use App\Form\BillingInfoFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class BillingInfoForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

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
}
