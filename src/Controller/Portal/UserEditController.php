<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\AdminUpdateUserCommand;
use App\Form\AdminUserFormData;
use App\Form\AdminUserFormType;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/users/{id}/edit', name: 'portal_users_edit')]
#[IsGranted('ROLE_ADMIN')]
final class UserEditController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $user = $this->userRepository->get(Uuid::fromString($id));

        $formData = AdminUserFormData::fromUser($user);
        $form = $this->createForm(AdminUserFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Convert percentage to decimal for commission rate
            $commissionRate = null !== $formData->commissionRate
                ? bcdiv((string) $formData->commissionRate, '100', 2)
                : null;

            $this->commandBus->dispatch(new AdminUpdateUserCommand(
                userId: $user->id,
                firstName: $formData->firstName,
                lastName: $formData->lastName,
                phone: $formData->phone,
                companyName: $formData->companyName,
                companyId: $formData->companyId,
                companyVatId: $formData->companyVatId,
                billingStreet: $formData->billingStreet,
                billingCity: $formData->billingCity,
                billingPostalCode: $formData->billingPostalCode,
                role: $formData->role,
                commissionRate: $commissionRate,
                selfBillingPrefix: $formData->selfBillingPrefix,
            ));

            $this->addFlash('success', 'Údaje uživatele byly aktualizovány.');

            return $this->redirectToRoute('portal_users_view', ['id' => $id]);
        }

        return $this->render('portal/user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }
}
