<?php

declare(strict_types=1);

namespace App\Tests\Integration\Form;

use App\Form\AdminOnboardingFormData;
use App\Form\AdminOnboardingFormType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Locks the server-side binding/validation contract of the admin onboarding form
 * as exercised through real form submission (the Live Component submits the raw
 * `formValues` strings exactly like this). Guards the two reported bugs:
 *  - birthDate must bind a valid date and only be required for non-company tenants;
 *  - "Předplaceno do" must accept *today* (date-only, no off-by-one).
 */
final class AdminOnboardingFormTypeTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->formFactory = static::getContainer()->get('test.form.factory');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        return array_replace([
            'email' => 'zak@example.com',
            'firstName' => 'Jan',
            'lastName' => 'Novak',
            'birthDate' => '1990-01-01',
            'invoiceToCompany' => false,
            'billingStreet' => 'Hlavni 1',
            'billingCity' => 'Praha',
            'billingPostalCode' => '11000',
            'addressOverride' => true,
            'startDate' => $today,
            'endDate' => (new \DateTimeImmutable('today'))->modify('+12 months')->format('Y-m-d'),
            'paymentMethod' => 'external',
            'paymentFrequency' => 'monthly',
            'monthlyPriceMode' => 'standard',
            'isExternallyPrepaid' => true,
            'paidThroughDate' => $today,
        ], $overrides);
    }

    /**
     * @return list<string>
     */
    private function errorPaths(\Symfony\Component\Form\FormInterface $form): array
    {
        $paths = [];
        foreach ($form->getErrors(true) as $error) {
            $origin = $error->getOrigin();
            $paths[] = ($origin?->getName() ?? '?').': '.$error->getMessage();
        }

        return $paths;
    }

    public function testValidIndividualWithTodayPrepaidBindsAndPasses(): void
    {
        $form = $this->formFactory->create(AdminOnboardingFormType::class, new AdminOnboardingFormData());
        $form->submit($this->payload());

        self::assertTrue($form->isValid(), 'Expected valid form, got: '.implode(' | ', $this->errorPaths($form)));

        /** @var AdminOnboardingFormData $data */
        $data = $form->getData();
        self::assertInstanceOf(\DateTimeImmutable::class, $data->birthDate);
        self::assertSame('1990-01-01', $data->birthDate->format('Y-m-d'));
        self::assertInstanceOf(\DateTimeImmutable::class, $data->paidThroughDate);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $data->paidThroughDate->format('Y-m-d'));
    }

    public function testTodayPrepaidIsAcceptedNotRejected(): void
    {
        // Bug 2 regression: "Předplaceno do" = today must NOT be flagged as past.
        $form = $this->formFactory->create(AdminOnboardingFormType::class, new AdminOnboardingFormData());
        $form->submit($this->payload(['paidThroughDate' => (new \DateTimeImmutable('today'))->format('Y-m-d')]));

        self::assertFalse(
            $form->get('paidThroughDate')->isSubmitted() && \count($form->get('paidThroughDate')->getErrors()) > 0,
            'Today must be a valid paidThroughDate.',
        );
        self::assertTrue($form->isValid(), implode(' | ', $this->errorPaths($form)));
    }

    public function testYesterdayPrepaidIsRejected(): void
    {
        $form = $this->formFactory->create(AdminOnboardingFormType::class, new AdminOnboardingFormData());
        $form->submit($this->payload(['paidThroughDate' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d')]));

        self::assertFalse($form->isValid());
    }

    public function testMissingBirthDateForIndividualIsRequired(): void
    {
        // Bug 1 regression: an empty birthDate for a non-company tenant must produce
        // exactly the required violation (and the form stays invalid).
        $form = $this->formFactory->create(AdminOnboardingFormType::class, new AdminOnboardingFormData());
        $form->submit($this->payload(['birthDate' => '']));

        self::assertFalse($form->isValid());
        self::assertStringContainsString('Zadejte datum narození', implode(' | ', $this->errorPaths($form)));
    }

    public function testCompanyDoesNotRequireBirthDate(): void
    {
        // Bug 1 regression: invoiceToCompany switches off the birthDate requirement.
        $form = $this->formFactory->create(AdminOnboardingFormType::class, new AdminOnboardingFormData());
        $form->submit($this->payload([
            'birthDate' => '',
            'invoiceToCompany' => true,
            'companyId' => '12345678',
            'companyName' => 'Firma s.r.o.',
        ]));

        self::assertTrue($form->isValid(), 'Company onboarding must not require birthDate: '.implode(' | ', $this->errorPaths($form)));
        self::assertStringNotContainsString('Zadejte datum narození', implode(' | ', $this->errorPaths($form)));
    }

    public function testValidBirthDateForIndividualPasses(): void
    {
        // Bug 1 regression: a valid date must NOT be flagged invalid.
        $form = $this->formFactory->create(AdminOnboardingFormType::class, new AdminOnboardingFormData());
        $form->submit($this->payload(['birthDate' => '1985-07-20']));

        self::assertCount(0, $form->get('birthDate')->getErrors());
        self::assertTrue($form->isValid(), implode(' | ', $this->errorPaths($form)));
    }
}
