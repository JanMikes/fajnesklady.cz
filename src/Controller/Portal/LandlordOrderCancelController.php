<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\CancelOrderCommand;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Service\Security\OrderVoter;
use App\Service\Security\PasswordConfirmation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/landlord/orders/{id}/cancel', name: 'portal_landlord_order_cancel', methods: ['POST'])]
#[IsGranted('ROLE_LANDLORD')]
final class LandlordOrderCancelController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly PasswordConfirmation $passwordConfirmation,
    ) {
    }

    public function __invoke(Request $request, string $id, #[CurrentUser] User $user): Response
    {
        $order = $this->orderRepository->get(Uuid::fromString($id));

        $this->denyAccessUnlessGranted(OrderVoter::CANCEL, $order);

        if (!$this->passwordConfirmation->isValid($user, $request->request->getString('password'))) {
            $this->addFlash('error', 'Zadané heslo není správné. Akce nebyla provedena.');

            return $this->redirectToRoute('portal_landlord_order_detail', ['id' => $id]);
        }

        if (!$order->canBeCancelled()) {
            $this->addFlash('error', $order->cancellationBlockedReason() ?? 'Objednávku nelze zrušit.');

            return $this->redirectToRoute('portal_landlord_order_detail', ['id' => $id]);
        }

        $this->commandBus->dispatch(new CancelOrderCommand($order));

        $this->addFlash('success', 'Objednávka byla úspěšně zrušena.');

        return $this->redirectToRoute('portal_landlord_order_detail', ['id' => $id]);
    }
}
