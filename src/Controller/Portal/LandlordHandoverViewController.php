<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\AddHandoverPhotoCommand;
use App\Command\CompleteLandlordHandoverCommand;
use App\Command\IssueFineCommand;
use App\Exception\InvalidStorageCode;
use App\Exception\StorageCodeRangeExhausted;
use App\Form\LandlordHandoverFormData;
use App\Form\LandlordHandoverFormType;
use App\Repository\HandoverProtocolRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Service\Security\HandoverProtocolVoter;
use App\Service\StorageCodeGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
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
        private readonly StorageCodeGenerator $codeGenerator,
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
        if ($place->storageCodesEnabled && $protocol->needsLandlordCompletion() && !$request->isMethod('POST')) {
            try {
                $formData->newLockCode = $this->codeGenerator->propose($place);
            } catch (StorageCodeRangeExhausted $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        }

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

            try {
                $this->commandBus->dispatch(new CompleteLandlordHandoverCommand(
                    handoverProtocolId: $protocol->id,
                    comment: $formData->comment,
                    newLockCode: $formData->newLockCode,
                ));
            } catch (\Throwable $rawException) {
                $exception = HandlerFailureUnwrap::unwrap($rawException);
                if ($exception instanceof InvalidStorageCode) {
                    $form->get('newLockCode')->addError(new FormError($exception->getMessage()));

                    return $this->render('portal/landlord/handover/view.html.twig', [
                        'protocol' => $protocol,
                        'contract' => $contract,
                        'storage' => $storage,
                        'place' => $place,
                        'form' => $form,
                        'canComplete' => $this->isGranted(HandoverProtocolVoter::COMPLETE_LANDLORD, $protocol),
                    ]);
                }

                throw $rawException;
            }

            $this->addFlash('success', 'Předávací protokol byl úspěšně vyplněn.');

            // Completion-first ordering: if InvalidStorageCode threw above, no
            // fine is issued and the re-rendered form preserves the fine values.
            if ($formData->issueFine) {
                /** @var \App\Entity\User $user */
                $user = $this->getUser();
                assert(null !== $formData->fineType);
                assert(null !== $formData->fineAmountInCzk);

                $this->commandBus->dispatch(new IssueFineCommand(
                    contractId: $contract->id,
                    type: $formData->fineType,
                    amountInHaler: (int) round($formData->fineAmountInCzk * 100),
                    description: $formData->fineDescription,
                    issuedById: $user->id,
                ));
                $this->addFlash('success', 'Pokuta vystavena');
            }

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
