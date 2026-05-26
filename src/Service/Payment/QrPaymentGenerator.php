<?php

declare(strict_types=1);

namespace App\Service\Payment;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Rikudou\CzQrPayment\QrPayment;

final readonly class QrPaymentGenerator
{
    private const string ACCOUNT_NUMBER = '2603478520';
    private const string BANK_CODE = '2010';

    public function generateDataUri(string $variableSymbol, int $amountInHaler, ?\DateTimeImmutable $dueDate = null): string
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

        return $writer->write($qrCode)->getDataUri();
    }

    public function getBankAccountFormatted(): string
    {
        return self::ACCOUNT_NUMBER.'/'.self::BANK_CODE;
    }
}
