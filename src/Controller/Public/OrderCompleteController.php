<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\User;
use App\Enum\OrderStatus;
use App\Repository\ContractRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/dokonceno', name: 'public_order_complete')]
final class OrderCompleteController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly InvoiceRepository $invoiceRepository,
    ) {
    }

    public function __invoke(string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));

        if (null === $order) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        // DB is the source of truth: COMPLETED can be reached via GoPay (webhook
        // already verified the payment with GoPay before flipping status), via
        // admin manual completion, or via the onboarding flow — none of which
        // need re-verification at view time.
        if (OrderStatus::COMPLETED !== $order->status) {
            $this->addFlash('error', 'Tato objednávka nebyla dokončena.');

            return $this->redirectToRoute($this->getUser() ? 'portal_browse_places' : 'app_home');
        }

        // Logged-in owners belong on the portal page where they get the full
        // navigation context. Anonymous viewers (and admins/landlords looking
        // at someone else's order) stay on the public success page.
        $user = $this->getUser();
        if ($user instanceof User && $order->user->id->equals($user->id)) {
            return $this->redirectToRoute('portal_user_order_detail', [
                'id' => $order->id->toRfc4122(),
                '_fragment' => 'dokumenty',
            ]);
        }

        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $contract = $this->contractRepository->findByOrder($order);
        $invoices = $this->invoiceRepository->findAllByOrder($order);

        return $this->render('public/order_complete.html.twig', [
            'order' => $order,
            'contract' => $contract,
            'invoices' => $invoices,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
        ]);
    }
}
