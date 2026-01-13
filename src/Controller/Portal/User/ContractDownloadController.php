<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Entity\User;
use App\Repository\ContractRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/smlouvy/{id}/stahnout', name: 'portal_user_contract_download')]
#[IsGranted('ROLE_USER')]
final class ContractDownloadController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(string $id): BinaryFileResponse
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Smlouva nenalezena.');
        }

        try {
            $contract = $this->contractRepository->get(Uuid::fromString($id));
        } catch (\Exception) {
            throw new NotFoundHttpException('Smlouva nenalezena.');
        }

        /** @var User $user */
        $user = $this->getUser();

        if (!$contract->user->id->equals($user->id)) {
            throw new AccessDeniedHttpException('Nemate pristup k teto smlouve.');
        }

        if (!$contract->hasDocument()) {
            throw new NotFoundHttpException('Dokument smlouvy neni k dispozici.');
        }

        $filePath = $this->projectDir . '/var/contracts/' . $contract->documentPath;

        if (!file_exists($filePath)) {
            throw new NotFoundHttpException('Dokument smlouvy nebyl nalezen.');
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'smlouva-' . $contract->id->toBase32() . '.docx'
        );

        return $response;
    }
}
