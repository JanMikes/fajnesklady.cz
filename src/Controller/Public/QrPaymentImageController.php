<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Service\Payment\QrPaymentGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/qr-platba/{variableSymbol}/{amountInHaler}', name: 'public_qr_payment_image', requirements: ['variableSymbol' => '\d+', 'amountInHaler' => '\d+'])]
final class QrPaymentImageController extends AbstractController
{
    public function __construct(
        private readonly QrPaymentGenerator $qrPaymentGenerator,
        private readonly UriSigner $uriSigner,
    ) {
    }

    public function __invoke(Request $request, string $variableSymbol, int $amountInHaler): Response
    {
        if (!$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException();
        }

        $pngContent = $this->qrPaymentGenerator->generatePng($variableSymbol, $amountInHaler);

        return new Response($pngContent, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
