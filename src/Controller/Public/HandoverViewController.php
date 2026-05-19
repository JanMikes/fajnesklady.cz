<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\AddHandoverPhotoCommand;
use App\Command\CompleteTenantHandoverCommand;
use App\Form\TenantHandoverFormData;
use App\Form\TenantHandoverFormType;
use App\Repository\HandoverProtocolRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/predavaci-protokol/{id}', name: 'public_handover_view', requirements: ['id' => '[0-9a-f-]{36}'])]
final class HandoverViewController extends AbstractController
{
    public function __construct(
        private readonly HandoverProtocolRepository $handoverProtocolRepository,
        private readonly UriSigner $uriSigner,
        #[Autowire(service: 'command.bus')]
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if (!$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException('Neplatný nebo expirovaný odkaz.');
        }

        $protocol = $this->handoverProtocolRepository->get(Uuid::fromString($id));

        $contract = $protocol->contract;
        $storage = $contract->storage;
        $place = $storage->getPlace();

        $formData = new TenantHandoverFormData();
        $form = $this->createForm(TenantHandoverFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $protocol->needsTenantCompletion()) {
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

            // Re-mint the signed URL — the redirect target must carry a fresh _hash
            // because the bare path won't validate against UriSigner.
            $signedUrl = $this->uriSigner->sign(
                $this->generateUrl('public_handover_view', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL),
            );

            return $this->redirect($signedUrl);
        }

        return $this->render('public/handover_view.html.twig', [
            'protocol' => $protocol,
            'contract' => $contract,
            'storage' => $storage,
            'place' => $place,
            'form' => $form,
            'canComplete' => $protocol->needsTenantCompletion(),
        ]);
    }
}
