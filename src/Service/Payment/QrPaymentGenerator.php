<?php

declare(strict_types=1);

namespace App\Service\Payment;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Rikudou\CzQrPayment\QrPayment;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class QrPaymentGenerator
{
    private const string ACCOUNT_NUMBER = '2603478520';
    private const string BANK_CODE = '2010';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function generateDataUri(string $variableSymbol, int $amountInHaler, ?\DateTimeImmutable $dueDate = null): string
    {
        return $this->buildQrResult($variableSymbol, $amountInHaler, $dueDate)->getDataUri();
    }

    public function generatePng(string $variableSymbol, int $amountInHaler, ?\DateTimeImmutable $dueDate = null): string
    {
        return $this->buildQrResult($variableSymbol, $amountInHaler, $dueDate)->getString();
    }

    public function generateImageUrl(string $variableSymbol, int $amountInHaler): string
    {
        $url = $this->urlGenerator->generate(
            'public_qr_payment_image',
            ['variableSymbol' => $variableSymbol, 'amountInHaler' => $amountInHaler],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->uriSigner->sign($url);
    }

    public function getBankAccountFormatted(): string
    {
        return self::ACCOUNT_NUMBER.'/'.self::BANK_CODE;
    }

    private function buildQrResult(string $variableSymbol, int $amountInHaler, ?\DateTimeImmutable $dueDate = null): \Endroid\QrCode\Writer\Result\ResultInterface
    {
        $payment = QrPayment::fromAccountAndBankCode(self::ACCOUNT_NUMBER, self::BANK_CODE);
        $payment->setVariableSymbol((int) $variableSymbol);
        $payment->setAmount($amountInHaler / 100);
        $payment->setCurrency('CZK');

        if (null !== $dueDate) {
            $payment->setDueDate($dueDate);
        }

        $qrCode = new QrCode($payment->getQrString());
        $writer = new PngWriter();

        return $writer->write($qrCode);
    }
}
