<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/landlord/orders', name: 'portal_landlord_orders')]
#[IsGranted('ROLE_LANDLORD')]
final class LandlordOrderListController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
    ) {
    }

    public function __invoke(): Response
    {
        /** @var User $landlord */
        $landlord = $this->getUser();

        $orders = $this->orderRepository->findByLandlord($landlord);

        return $this->render('portal/landlord/order/list.html.twig', [
            'orders' => $orders,
        ]);
    }
}
