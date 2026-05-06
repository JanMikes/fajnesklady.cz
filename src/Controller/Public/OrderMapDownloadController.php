<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/dokumenty/mapa', name: 'public_order_map_download')]
final class OrderMapDownloadController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        #[Autowire('%kernel.project_dir%/public/uploads')]
        private readonly string $uploadsDirectory,
    ) {
    }

    public function __invoke(string $id): BinaryFileResponse
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));

        if (null === $order || OrderStatus::COMPLETED !== $order->status) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $place = $order->storage->getPlace();
        $mapImagePath = $place->mapImagePath;

        if (null === $mapImagePath) {
            throw new NotFoundHttpException('Mapa není k dispozici.');
        }

        $filePath = $this->uploadsDirectory.'/'.$mapImagePath;
        $realPath = realpath($filePath);

        if (false === $realPath || !str_starts_with($realPath, realpath($this->uploadsDirectory).'/')) {
            throw new NotFoundHttpException('Mapa nebyla nalezena.');
        }

        $extension = pathinfo($realPath, PATHINFO_EXTENSION) ?: 'png';

        $response = new BinaryFileResponse($realPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('mapa-pobocka-%s.%s', $place->id->toBase32(), $extension),
        );

        return $response;
    }
}
