<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\FineRepository;
use App\Service\Payment\QrPaymentGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/pokuta/{id}/platba', name: 'public_fine_payment', requirements: ['id' => '[0-9a-f-]{36}'])]
final class FinePaymentController extends AbstractController
{
    public function __construct(
        private readonly FineRepository $fineRepository,
        private readonly QrPaymentGenerator $qrPaymentGenerator,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Pokuta nenalezena.');
        }

        $fine = $this->fineRepository->findById(Uuid::fromString($id));
        if (null === $fine) {
            throw new NotFoundHttpException('Pokuta nenalezena.');
        }

        if (!$fine->isPayable()) {
            throw new NotFoundHttpException('Pokuta nenalezena.');
        }

        $contract = $fine->contract;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $qrCodeDataUri = null;
        if (null !== $fine->variableSymbol) {
            $qrCodeDataUri = $this->qrPaymentGenerator->generateDataUri(
                $fine->variableSymbol,
                $fine->amountInHaler,
            );
        }

        return $this->render('public/fine_payment.html.twig', [
            'fine' => $fine,
            'contract' => $contract,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
            'amountCzk' => $fine->getAmountInCzk(),
            'bankAccount' => $this->qrPaymentGenerator->getBankAccountFormatted(),
            'qrCodeDataUri' => $qrCodeDataUri,
        ]);
    }
}
