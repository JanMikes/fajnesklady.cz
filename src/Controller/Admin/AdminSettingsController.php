<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\PlatformSettingsFormData;
use App\Form\PlatformSettingsFormType;
use App\Repository\PlatformSettingsRepository;
use App\Service\AuditLogger;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/nastaveni', name: 'admin_settings')]
#[IsGranted('ROLE_ADMIN')]
final class AdminSettingsController extends AbstractController
{
    public function __construct(
        private readonly PlatformSettingsRepository $settingsRepository,
        private readonly AuditLogger $auditLogger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $settings = $this->settingsRepository->getSettings();
        $formData = PlatformSettingsFormData::fromSettings($settings);

        $form = $this->createForm(PlatformSettingsFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $oldValue = $settings->bankTransferSurchargeInHaler;
            $newValue = $formData->toHaler();

            $settings->updateSurcharge($newValue, $this->clock->now());
            $this->settingsRepository->save($settings);

            $user = $this->getUser();
            $this->auditLogger->log(
                entityType: 'platform_settings',
                entityId: $settings->id->toRfc4122(),
                eventType: 'surcharge_changed',
                payload: [
                    'old_value_haler' => $oldValue,
                    'new_value_haler' => $newValue,
                    'admin_id' => $user?->getUserIdentifier(),
                    'admin_email' => $user?->getUserIdentifier(),
                ],
            );

            $this->addFlash('success', 'Nastavení bylo uloženo.');

            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'form' => $form,
        ]);
    }
}
