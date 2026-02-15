<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\AcceptOrderTermsCommand;
use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/prijmout', name: 'public_order_accept')]
final class OrderAcceptController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));

        if (null === $order) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        // Already completed - show completion page
        if (OrderStatus::COMPLETED === $order->status) {
            return $this->redirectToRoute('public_order_complete', ['id' => $order->id]);
        }

        // Terms already accepted - go to payment
        if ($order->hasAcceptedTerms()) {
            return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
        }

        // Only reserved orders can accept terms
        if (OrderStatus::RESERVED !== $order->status) {
            $this->addFlash('error', 'Tuto objednávku nelze dokončit.');

            return $this->redirectToRoute($this->getUser() ? 'portal_browse_places' : 'app_home');
        }

        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        // Handle contract acceptance
        if ($request->isMethod('POST')) {
            $accepted = $request->request->getBoolean('accept_contract');

            if (!$accepted) {
                $this->addFlash('error', 'Pro pokračování k platbě je nutné souhlasit se smluvními podmínkami.');

                return $this->render('public/order_accept.html.twig', [
                    'order' => $order,
                    'storage' => $storage,
                    'storageType' => $storageType,
                    'place' => $place,
                ]);
            }

            $this->commandBus->dispatch(new AcceptOrderTermsCommand($order));

            $this->addFlash('success', 'Smluvní podmínky byly přijaty. Pokračujte k platbě.');

            return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
        }

        return $this->render('public/order_accept.html.twig', [
            'order' => $order,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
        ]);
    }
}
