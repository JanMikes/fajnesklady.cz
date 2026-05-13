<?php

declare(strict_types=1);

namespace App\Controller\Portal\User;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\Security\OrderVoter;
use App\Service\Vop\VopDocumentGenerator;
use App\Service\Vop\VopPdfStamper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/objednavky/{id}/vop.pdf', name: 'portal_user_order_vop_pdf', requirements: ['id' => '[0-9a-f-]{36}'])]
#[IsGranted('ROLE_USER')]
final class VopPdfController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly VopDocumentGenerator $vopGenerator,
        private readonly VopPdfStamper $vopStamper,
        #[Autowire('%kernel.project_dir%/templates/documents/vop_template.docx')]
        private readonly string $vopTemplatePath,
        #[Autowire('%kernel.project_dir%/var/vop')]
        private readonly string $vopDirectory,
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

        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

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
            // Late-generate for orders predating this feature. Substitution is
            // deterministic and depends only on the order's place at request
            // time — safe to regenerate.
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
