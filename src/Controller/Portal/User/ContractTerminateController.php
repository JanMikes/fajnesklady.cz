<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Entity\User;
use App\Repository\ContractRepository;
use App\Service\ContractService;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/smlouvy/{id}/ukoncit', name: 'portal_user_contract_terminate', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
final class ContractTerminateController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly ContractService $contractService,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
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
            throw new AccessDeniedHttpException('Nemáte přístup k této smlouvě.');
        }

        if (!$contract->isUnlimited()) {
            $this->addFlash('error', 'Smlouvu na dobu určitou nelze předčasně ukončit.');

            return $this->redirectToRoute('portal_user_contract_detail', ['id' => $id]);
        }

        if (!$this->contractService->canTerminate($contract)) {
            $this->addFlash('error', 'Tuto smlouvu nelze ukončit.');

            return $this->redirectToRoute('portal_user_contract_detail', ['id' => $id]);
        }

        $this->contractService->terminateContract($contract, $this->clock->now());

        $this->addFlash('success', 'Smlouva byla úspěšně ukončena.');

        return $this->redirectToRoute('portal_user_contracts');
    }
}
