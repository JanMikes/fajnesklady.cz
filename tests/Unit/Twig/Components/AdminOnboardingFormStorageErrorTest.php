<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components;

use App\Twig\Components\AdminOnboardingForm;
use PHPUnit\Framework\TestCase;

/**
 * The onboarding submit button is no longer hard-disabled until a storage is picked (spec 070);
 * instead submit() surfaces a `storageError` rendered as a scroll anchor. These guard the wiring —
 * the runtime scroll behaviour is covered by the manual QA checklist (no Live Component test harness).
 */
final class AdminOnboardingFormStorageErrorTest extends TestCase
{
    public function testComponentExposesNullableStorageErrorProp(): void
    {
        $property = new \ReflectionProperty(AdminOnboardingForm::class, 'storageError');
        $type = $property->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertTrue($type->allowsNull());
    }

    public function testTemplateRendersStorageErrorAnchorAndDropsTheDisabledGate(): void
    {
        $template = file_get_contents(
            \dirname(__DIR__, 4).'/templates/components/AdminOnboardingForm.html.twig',
        );
        \assert(is_string($template));

        self::assertStringContainsString('this.storageError', $template);
        self::assertStringContainsString('data-live-error', $template);
        self::assertStringContainsString('live-form-scroll#markSubmit', $template);
        // The blanket "no storage → disabled" gate must be gone.
        self::assertStringNotContainsString('if not this.storageId %}disabled', $template);
    }
}
