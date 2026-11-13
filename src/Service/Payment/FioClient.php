<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Value\FioBankTransaction;
use FioApi\Downloader;
use FioApi\Exceptions\TooGreedyException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

final readonly class FioClient
{
    public function __construct(
        private string $fioApiToken,
        // Test seam: a Guzzle handler (e.g. MockHandler) instead of real HTTP.
        private mixed $httpHandler = null,
    ) {
    }

    /**
     * @return FioBankTransaction[]
     *
     * @throws TooGreedyException
     * @throws FioApiUnavailable  FIO unreachable or not returning a statement
     */
    public function downloadTransactions(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ('' === $this->fioApiToken) {
            return [];
        }

        $stack = HandlerStack::create(\is_callable($this->httpHandler) ? $this->httpHandler : null);
        // Validate the statement BEFORE the FIO library parses it: on an empty
        // or maintenance response the library raises a PHP warning and then a
        // TypeError from vendor code (Sentry FAJNE-SKLADY-36/37).
        $stack->push(Middleware::mapResponse(static function (ResponseInterface $response): ResponseInterface {
            // Error statuses keep their library handling (409 → TooGreedy
            // "retry next run", 500 → InternalError).
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return $response;
            }

            $body = (string) $response->getBody();
            $decoded = json_decode($body);

            if (!$decoded instanceof \stdClass || !isset($decoded->accountStatement) || !$decoded->accountStatement instanceof \stdClass) {
                throw FioApiUnavailable::invalidResponse($response->getStatusCode(), $body);
            }

            return $response->withBody(Utils::streamFor($body));
        }));

        $httpClient = new Client([
            'handler' => $stack,
            RequestOptions::VERIFY => true,
            // Fail an unreachable FIO in seconds, not after the ~130 s OS default.
            RequestOptions::CONNECT_TIMEOUT => 20,
            RequestOptions::TIMEOUT => 60,
            'curl' => [\CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_2TLS],
        ]);
        $downloader = new Downloader($this->fioApiToken, $httpClient);

        try {
            $transactionList = $downloader->downloadFromTo($from, $to);
        } catch (ConnectException $e) {
            throw FioApiUnavailable::unreachable($e);
        }

        $result = [];
        foreach ($transactionList->getTransactions() as $t) {
            $result[] = new FioBankTransaction(
                id: (string) $t->getId(),
                amount: (int) round($t->getAmount() * 100),
                currency: $t->getCurrency(),
                // Store the canonical form so the bank_transaction row, admin table,
                // audit payloads and mismatch e-mails all show what the bank sent (spec 090).
                variableSymbol: VariableSymbolGenerator::normalize($t->getVariableSymbol()),
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
