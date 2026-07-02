<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\UpdatePlatformSettingsCommand;
use App\Form\PlatformSettingsFormData;
use App\Form\PlatformSettingsFormType;
use App\Repository\PlatformSettingsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/nastaveni', name: 'admin_settings')]
#[IsGranted('ROLE_ADMIN')]
final class AdminSettingsController extends AbstractController
{
    public function __construct(
        private readonly PlatformSettingsRepository $settingsRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $settings = $this->settingsRepository->getSettings();
        $formData = PlatformSettingsFormData::fromSettings($settings);

        $form = $this->createForm(PlatformSettingsFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->commandBus->dispatch(new UpdatePlatformSettingsCommand($formData->toHaler()));

            $this->addFlash('success', 'Nastavení bylo uloženo.');

            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'form' => $form,
        ]);
    }
}
