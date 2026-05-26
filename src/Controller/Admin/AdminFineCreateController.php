<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\IssueFineCommand;
use App\Entity\Contract;
use App\Form\FineFormData;
use App\Form\FineFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/pokuty/vytvorit/{contractId}', name: 'admin_fine_create', requirements: ['contractId' => '[0-9a-f-]{36}'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminFineCreateController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $contractId): Response
    {
        $contract = $this->entityManager->find(Contract::class, Uuid::fromString($contractId));
        if (null === $contract) {
            throw new NotFoundHttpException('Smlouva nenalezena.');
        }

        $formData = new FineFormData();
        $form = $this->createForm(FineFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \App\Entity\User $admin */
            $admin = $this->getUser();

            assert(null !== $formData->type);
            assert(null !== $formData->amountInHaler);

            $this->commandBus->dispatch(new IssueFineCommand(
                contractId: $contract->id,
                type: $formData->type,
                amountInHaler: $formData->amountInHaler,
                description: $formData->description,
                issuedById: $admin->id,
            ));

            $this->addFlash('success', 'Pokuta vystavena');

            return $this->redirectToRoute('admin_order_detail', ['id' => $contract->order->id]);
        }

        return $this->render('admin/fine/create.html.twig', [
            'contract' => $contract,
            'order' => $contract->order,
            'form' => $form->createView(),
        ]);
    }
}
