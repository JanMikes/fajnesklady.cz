<?php

declare(strict_types=1);

namespace App\Controller;

use App\Command\UpdateBillingInfoCommand;
use App\Entity\User;
use App\Form\BillingInfoFormData;
use App\Form\BillingInfoFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile/billing', name: 'app_profile_billing')]
#[IsGranted('ROLE_USER')]
final class BillingInfoController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $formData = BillingInfoFormData::fromUser($user);
        $form = $this->createForm(BillingInfoFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->commandBus->dispatch(new UpdateBillingInfoCommand(
                userId: $user->id,
                companyName: $formData->companyName,
                companyId: $formData->companyId,
                companyVatId: $formData->companyVatId,
                billingStreet: $formData->billingStreet,
                billingCity: $formData->billingCity,
                billingPostalCode: $formData->billingPostalCode,
            ));

            $this->addFlash('success', 'Fakturační údaje byly úspěšně uloženy.');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('user/billing_info.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }
}
