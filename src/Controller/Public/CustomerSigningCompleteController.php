<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\OrderRepository;
use App\Service\Order\CompletionPageViewModel;
use App\Service\Order\CustomerBillingSituation;
use App\Service\OrderStatusUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/podpis/dokonceno/{id}', name: 'public_customer_signing_complete', requirements: ['id' => '[0-9a-f-]{36}'])]
final class CustomerSigningCompleteController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly OrderStatusUrlGenerator $statusUrlGenerator,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $order = $this->orderRepository->find(Uuid::fromString($id));
        if (null === $order) {
            throw new NotFoundHttpException();
        }

        $situation = CustomerBillingSituation::fromOrder($order);
        $statusUrl = $this->statusUrlGenerator->generate($order);

        $viewModel = match ($situation) {
            CustomerBillingSituation::GOPAY_FIRST_CHARGE => new CompletionPageViewModel(
                situation: $situation,
                headline: 'Smlouva podepsána, čekáme na platbu',
                body: 'Pro dokončení prosím dokončete platbu. Po úspěšné platbě je pronájem aktivní.',
                statusUrl: $statusUrl,
                ctaLabel: 'Zaplatit nyní',
            ),
            CustomerBillingSituation::EXTERNALLY_PREPAID => new CompletionPageViewModel(
                situation: $situation,
                headline: sprintf(
                    'Vše vyřízeno — pronájem je předplacen do %s',
                    $order->paidThroughDate?->format('d.m.Y') ?? '',
                ),
                body: 'Žádná další akce není potřeba. Detail pronájmu a všechny dokumenty najdete na následující stránce.',
                statusUrl: $statusUrl,
                ctaLabel: 'Zobrazit pronájem',
            ),
            CustomerBillingSituation::FREE => new CompletionPageViewModel(
                situation: $situation,
                headline: 'Vše vyřízeno — bezplatný pronájem aktivní',
                body: 'Žádná další akce není potřeba. Detail pronájmu a všechny dokumenty najdete na následující stránce.',
                statusUrl: $statusUrl,
                ctaLabel: 'Zobrazit pronájem',
            ),
        };

        return $this->render('public/customer_signing_complete.html.twig', [
            'viewModel' => $viewModel,
        ]);
    }
}
