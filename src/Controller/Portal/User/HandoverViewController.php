<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Command\AddHandoverPhotoCommand;
use App\Command\CompleteTenantHandoverCommand;
use App\Form\TenantHandoverFormData;
use App\Form\TenantHandoverFormType;
use App\Repository\HandoverProtocolRepository;
use App\Service\Security\HandoverProtocolVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/predavaci-protokol/{id}', name: 'portal_user_handover_view')]
#[IsGranted('ROLE_USER')]
final class HandoverViewController extends AbstractController
{
    public function __construct(
        private readonly HandoverProtocolRepository $handoverProtocolRepository,
        #[Autowire(service: 'command.bus')]
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        $protocol = $this->handoverProtocolRepository->get(Uuid::fromString($id));
        $this->denyAccessUnlessGranted(HandoverProtocolVoter::VIEW, $protocol);

        $contract = $protocol->contract;
        $storage = $contract->storage;
        $place = $storage->getPlace();

        $formData = new TenantHandoverFormData();
        $form = $this->createForm(TenantHandoverFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $protocol->needsTenantCompletion()) {
            $this->denyAccessUnlessGranted(HandoverProtocolVoter::COMPLETE_TENANT, $protocol);

            // Upload photos
            $photos = $request->files->get('photos', []);
            foreach ($photos as $photo) {
                $this->commandBus->dispatch(new AddHandoverPhotoCommand(
                    handoverProtocolId: $protocol->id,
                    file: $photo,
                    uploadedBy: 'tenant',
                ));
            }

            $this->commandBus->dispatch(new CompleteTenantHandoverCommand(
                handoverProtocolId: $protocol->id,
                comment: $formData->comment,
            ));

            $this->addFlash('success', 'Předávací protokol byl úspěšně vyplněn.');

            return $this->redirectToRoute('portal_user_handover_view', ['id' => $id]);
        }

        return $this->render('portal/user/handover/view.html.twig', [
            'protocol' => $protocol,
            'contract' => $contract,
            'storage' => $storage,
            'place' => $place,
            'form' => $form,
            'canComplete' => $this->isGranted(HandoverProtocolVoter::COMPLETE_TENANT, $protocol),
        ]);
    }
}
