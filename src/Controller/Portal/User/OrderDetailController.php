<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Entity\User;
use App\Repository\ContractRepository;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Service\ContractService;
use App\Service\Security\ContractVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/objednavky/{id}', name: 'portal_user_order_detail')]
#[IsGranted('ROLE_USER')]
final class OrderDetailController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ContractService $contractService,
        private readonly ClockInterface $clock,
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

        /** @var User $user */
        $user = $this->getUser();

        if (!$order->user->id->equals($user->id)) {
            throw new AccessDeniedHttpException('Nemáte přístup k této objednávce.');
        }

        $contract = $this->contractRepository->findByOrder($order);
        $invoice = $this->invoiceRepository->findByOrder($order);

        $daysRemaining = null;
        $canTerminate = false;

        if (null !== $contract) {
            $now = $this->clock->now();
            $daysRemaining = $this->contractService->getDaysRemaining($contract, $now);
            $canTerminate = $this->isGranted(ContractVoter::TERMINATE, $contract);
        }

        return $this->render('portal/user/order/detail.html.twig', [
            'order' => $order,
            'contract' => $contract,
            'invoice' => $invoice,
            'storage' => $order->storage,
            'storageType' => $order->storage->storageType,
            'place' => $order->storage->getPlace(),
            'daysRemaining' => $daysRemaining,
            'canTerminate' => $canTerminate,
        ]);
    }
}
