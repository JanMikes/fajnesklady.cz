<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\ExtendPaymentDeadlineCommand;
use App\Repository\ContractRepository;
use App\Service\Security\OrderVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/contracts/{id}/extend-deadline', name: 'admin_contract_extend_deadline', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminContractExtendDeadlineController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $contract = $this->contractRepository->get(Uuid::fromString($id));
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $contract->order);

        $redirect = $this->redirectToRoute('admin_order_detail', ['id' => $contract->order->id]);

        $raw = trim($request->request->getString('newDeadline'));
        $newDeadline = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw) ?: null;

        if (null === $newDeadline) {
            $this->addFlash('error', 'Zadejte platné datum.');

            return $redirect;
        }

        $now = $this->clock->now();
        if ($contract->isTerminated() || null === $contract->nextBillingDate) {
            $this->addFlash('error', 'U této smlouvy nelze splatnost prodloužit.');

            return $redirect;
        }

        $anchor = $contract->effectiveDunningAnchor();
        if ($newDeadline->setTime(0, 0, 0) <= $now->setTime(0, 0, 0)
            || (null !== $anchor && $newDeadline->setTime(0, 0, 0) <= $anchor->setTime(0, 0, 0))
        ) {
            $this->addFlash('error', 'Nové datum splatnosti musí být po aktuálním datu splatnosti.');

            return $redirect;
        }

        $this->commandBus->dispatch(new ExtendPaymentDeadlineCommand($contract, $newDeadline));

        $this->addFlash('success', sprintf('Splatnost byla prodloužena do %s.', $newDeadline->format('j. n. Y')));

        return $redirect;
    }
}
