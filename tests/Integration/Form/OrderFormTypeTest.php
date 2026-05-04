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
}
