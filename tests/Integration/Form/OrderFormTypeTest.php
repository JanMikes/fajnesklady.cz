<?php

declare(strict_types=1);

namespace App\Tests\Integration\Form;

use App\Form\OrderFormData;
use App\Form\OrderFormType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class OrderFormTypeTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->formFactory = static::getContainer()->get('test.form.factory');
    }

    public function testSubmittingPartialFormDoesNotCrashOnEmptyRequiredStrings(): void
    {
        // This regression covers the per-field live-validation flow where the form is
        // re-submitted on every blur — including while required fields are still empty.
        // Without `empty_data: ''` on the non-nullable string fields, Symfony Forms binds
        // `null` and PropertyAccess throws a TypeError on `OrderFormData::$email` etc.
        $form = $this->formFactory->create(OrderFormType::class, new OrderFormData());

        $form->submit([
            'firstName' => 'Jan',
            // email, lastName, and the rest deliberately omitted
        ]);

        /** @var OrderFormData $data */
        $data = $form->getData();

        self::assertSame('Jan', $data->firstName);
        self::assertSame('', $data->email);
        self::assertSame('', $data->lastName);
    }

    public function testCompanyFieldsCarryNativeHints(): void
    {
        // Spec 062: IČO gets a numeric keypad with no autofill token (none exists for IČO),
        // company name gets the standard `organization` autofill, DIČ suppresses autofill noise.
        $view = $this->formFactory->create(OrderFormType::class, new OrderFormData())->createView();

        $companyId = $view->children['companyId']->vars['attr'];
        self::assertSame('numeric', $companyId['inputmode']);
        self::assertSame('off', $companyId['autocomplete']);
        self::assertSame(8, $companyId['maxlength'], 'maxlength must be preserved.');
        self::assertSame('12345678', $companyId['placeholder'], 'placeholder must be preserved.');

        self::assertSame('organization', $view->children['companyName']->vars['attr']['autocomplete']);
        self::assertSame('off', $view->children['companyVatId']->vars['attr']['autocomplete']);
    }
}
