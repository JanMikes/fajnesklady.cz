<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use App\Service\Vop\VopDocumentGenerator;
use App\Service\Vop\VopPdfStamper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/dokumenty/vop.pdf', name: 'public_order_vop_download', requirements: ['id' => '[0-9a-f-]{36}'])]
final class OrderVopDownloadController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly VopDocumentGenerator $vopGenerator,
        private readonly VopPdfStamper $vopStamper,
        private readonly UriSigner $uriSigner,
        #[Autowire('%kernel.project_dir%/templates/documents/vop_template.docx')]
        private readonly string $vopTemplatePath,
        #[Autowire('%kernel.project_dir%/var/vop')]
        private readonly string $vopDirectory,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException('Neplatný nebo expirovaný odkaz.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));
        // VOP is relevant from order placement onwards (the confirmation e-mail
        // ships with it pre-payment); only cancelled orders lose access.
        if (null === $order || OrderStatus::CANCELLED === $order->status) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $docxPath = $this->ensureVopExists($order);
        $pdfBytes = $this->vopStamper->stampSignedPdfBytes($docxPath, $order->signaturePath);
        if (null === $pdfBytes) {
            throw new NotFoundHttpException('VOP PDF se nepodařilo vygenerovat.');
        }

        return new Response(
            $pdfBytes,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf(
                    'attachment; filename="vop-%s.pdf"',
                    substr($order->id->toRfc4122(), 0, 8),
                ),
            ],
        );
    }

    private function ensureVopExists(Order $order): string
    {
        $path = $this->vopGenerator->pathFor($order);
        if (!file_exists($path)) {
            return $this->vopGenerator->generate($order, $this->vopTemplatePath);
        }

        $realDir = realpath($this->vopDirectory);
        $real = realpath($path);
        if (false === $realDir || false === $real || !str_starts_with($real, $realDir.'/')) {
            throw new NotFoundHttpException('VOP nenalezeno.');
        }

        return $path;
    }
}
