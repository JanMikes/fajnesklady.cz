<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Entity\User;
use App\Repository\ContractRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/smlouvy', name: 'portal_user_contracts')]
#[IsGranted('ROLE_USER')]
final class ContractListController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
    ) {
    }

    public function __invoke(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $contracts = $this->contractRepository->findByUser($user);

        return $this->render('portal/user/contract/list.html.twig', [
            'contracts' => $contracts,
        ]);
    }
}
