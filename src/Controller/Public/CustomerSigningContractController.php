<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\OrderRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the admin-uploaded contract document for preview on the onboarding
 * signing page (scenario A). The signing token is the authorization — the
 * customer is passwordless — so this mirrors the gate on CustomerSigningController.
 * Once the order is signed the token is cleared, so this route stops serving.
 */
#[Route('/podpis/{token}/smlouva', name: 'public_customer_signing_contract', requirements: ['token' => '[a-f0-9]{64}'])]
final class CustomerSigningContractController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.project_dir%/var/contracts')]
        private readonly string $contractsDirectory,
    ) {
    }

    public function __invoke(string $token): BinaryFileResponse
    {
        $order = $this->orderRepository->findBySigningToken($token);

        if (null === $order || $order->isExpired($this->clock->now()) || !$order->hasUploadedContract()) {
            throw new NotFoundHttpException('Smlouva není k dispozici.');
        }

        $documentPath = $order->uploadedContractDocumentPath;
        \assert(null !== $documentPath);

        // Confine the served file to the contracts directory (mirror OrderContractDownloadController).
        $realPath = realpath($documentPath);
        $realContractsDir = realpath($this->contractsDirectory);

        if (false === $realPath || false === $realContractsDir || !str_starts_with($realPath, $realContractsDir.'/')) {
            throw new NotFoundHttpException('Smlouva nebyla nalezena.');
        }

        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/pdf',
        };

        $response = new BinaryFileResponse($realPath);
        $response->headers->set('Content-Type', $contentType);
        $response->setContentDisposition(HeaderUtils::DISPOSITION_INLINE, 'smlouva.'.$extension);

        return $response;
    }
}
