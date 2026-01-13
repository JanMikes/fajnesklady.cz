<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/landlord/orders/{id}', name: 'portal_landlord_order_detail')]
#[IsGranted('ROLE_LANDLORD')]
final class LandlordOrderDetailController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
    ) {
    }

    public function __invoke(string $id): Response
    {
        /** @var User $landlord */
        $landlord = $this->getUser();

        $order = $this->orderRepository->get(Uuid::fromString($id));

        // Check if this order belongs to landlord's storage
        $storage = $order->storage;
        $place = $storage->storageType->place;

        if (!$place->owner->id->equals($landlord->id)) {
            throw $this->createAccessDeniedException('You do not have access to this order.');
        }

        $storageType = $storage->storageType;
        $contract = $this->contractRepository->findByOrder($order);

        return $this->render('portal/landlord/order/detail.html.twig', [
            'order' => $order,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
            'contract' => $contract,
        ]);
    }
}
