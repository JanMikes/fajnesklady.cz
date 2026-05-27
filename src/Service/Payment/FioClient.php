<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Value\FioBankTransaction;
use FioApi\Downloader;
use FioApi\Exceptions\TooGreedyException;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

final readonly class FioClient
{
    public function __construct(
        private string $fioApiToken,
    ) {
    }

    /**
     * @return FioBankTransaction[]
     *
     * @throws TooGreedyException
     */
    public function downloadTransactions(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ('' === $this->fioApiToken) {
            return [];
        }

        $httpClient = new Client([
            RequestOptions::VERIFY => true,
            'curl' => [\CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_2TLS],
        ]);
        $downloader = new Downloader($this->fioApiToken, $httpClient);
        $transactionList = $downloader->downloadFromTo($from, $to);

        $result = [];
        foreach ($transactionList->getTransactions() as $t) {
            $result[] = new FioBankTransaction(
                id: (string) $t->getId(),
                amount: (int) round($t->getAmount() * 100),
                currency: $t->getCurrency(),
                variableSymbol: $t->getVariableSymbol(),
                senderAccountNumber: $this->formatAccount($t->getSenderAccountNumber(), $t->getSenderBankCode()),
                senderName: $t->getSenderName(),
                date: $t->getDate(),
                comment: $t->getComment(),
            );
        }

        return $result;
    }

    private function formatAccount(?string $accountNumber, ?string $bankCode): ?string
    {
        if (null === $accountNumber || '' === $accountNumber) {
            return null;
        }

        if (null === $bankCode || '' === $bankCode) {
            return $accountNumber;
        }

        return $accountNumber.'/'.$bankCode;
    }
}
