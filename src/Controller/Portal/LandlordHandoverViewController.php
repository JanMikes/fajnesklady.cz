<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\AddHandoverPhotoCommand;
use App\Command\CompleteLandlordHandoverCommand;
use App\Form\LandlordHandoverFormData;
use App\Form\LandlordHandoverFormType;
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

#[Route('/portal/pronajimatel/predavaci-protokol/{id}', name: 'portal_landlord_handover_view')]
#[IsGranted('ROLE_LANDLORD')]
final class LandlordHandoverViewController extends AbstractController
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

        $formData = new LandlordHandoverFormData();
        $form = $this->createForm(LandlordHandoverFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $protocol->needsLandlordCompletion()) {
            $this->denyAccessUnlessGranted(HandoverProtocolVoter::COMPLETE_LANDLORD, $protocol);

            // Upload photos
            $photos = $request->files->get('photos', []);
            foreach ($photos as $photo) {
                $this->commandBus->dispatch(new AddHandoverPhotoCommand(
                    handoverProtocolId: $protocol->id,
                    file: $photo,
                    uploadedBy: 'landlord',
                ));
            }

            $this->commandBus->dispatch(new CompleteLandlordHandoverCommand(
                handoverProtocolId: $protocol->id,
                comment: $formData->comment,
                newLockCode: $formData->newLockCode,
            ));

            $this->addFlash('success', 'Předávací protokol byl úspěšně vyplněn.');

            return $this->redirectToRoute('portal_landlord_handover_view', ['id' => $id]);
        }

        return $this->render('portal/landlord/handover/view.html.twig', [
            'protocol' => $protocol,
            'contract' => $contract,
            'storage' => $storage,
            'place' => $place,
            'form' => $form,
            'canComplete' => $this->isGranted(HandoverProtocolVoter::COMPLETE_LANDLORD, $protocol),
        ]);
    }
}
