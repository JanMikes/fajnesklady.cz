<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Entity\User;
use App\Repository\ContractRepository;
use App\Service\ContractService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/smlouvy/{id}', name: 'portal_user_contract_detail')]
#[IsGranted('ROLE_USER')]
final class ContractDetailController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly ContractService $contractService,
    ) {
    }

    public function __invoke(string $id): Response
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

        $now = new \DateTimeImmutable();
        $daysRemaining = $this->contractService->getDaysRemaining($contract, $now);

        return $this->render('portal/user/contract/detail.html.twig', [
            'contract' => $contract,
            'storage' => $contract->storage,
            'storageType' => $contract->storage->storageType,
            'place' => $contract->storage->getPlace(),
            'daysRemaining' => $daysRemaining,
            'canTerminate' => $contract->isUnlimited() && $this->contractService->canTerminate($contract),
        ]);
    }
}
