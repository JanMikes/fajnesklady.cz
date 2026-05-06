<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use App\Service\StorageMapImageGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/dokumenty/mapa', name: 'public_order_map_download')]
final class OrderMapDownloadController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly StorageMapImageGenerator $mapImageGenerator,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));

        if (null === $order || OrderStatus::COMPLETED !== $order->status) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $pngBytes = $this->mapImageGenerator->generate($order->storage);

        if (null === $pngBytes) {
            throw new NotFoundHttpException('Mapa není k dispozici.');
        }

        $disposition = $request->query->getBoolean('download')
            ? HeaderUtils::DISPOSITION_ATTACHMENT
            : HeaderUtils::DISPOSITION_INLINE;

        $filename = sprintf('mapa-pobocka-%s.png', $order->storage->getPlace()->id->toBase32());

        $response = new Response($pngBytes);
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition($disposition, $filename),
        );

        return $response;
    }
}
