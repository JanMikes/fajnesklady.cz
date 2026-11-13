<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Payment;

use App\Service\Payment\FioApiUnavailable;
use App\Service\Payment\FioClient;
use FioApi\Exceptions\TooGreedyException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class FioClientTest extends TestCase
{
    private const string STATEMENT = <<<'JSON'
        {"accountStatement":{"info":{"accountId":"2603478520","bankId":"2010","currency":"CZK","iban":"CZ00","bic":"FIOBCZPPXXX","openingBalance":0,"closingBalance":3080,"dateStart":"2026-11-10+0100","dateEnd":"2026-11-13+0100","idFrom":1,"idTo":1,"idLastDownload":null},
        "transactionList":{"transaction":[{"column22":{"value":27749499743,"name":"ID pohybu","id":22},"column0":{"value":"2026-11-12+0100","name":"Datum","id":0},"column1":{"value":3080.0,"name":"Objem","id":1},"column14":{"value":"CZK","name":"Měna","id":14},"column2":{"value":"123456789","name":"Protiúčet","id":2},"column3":{"value":"0800","name":"Kód banky","id":3},"column5":{"value":"1246392364","name":"VS","id":5},"column10":{"value":"Pavel","name":"Název protiúčtu","id":10}}]}}}
        JSON;

    public function testValidStatementIsParsed(): void
    {
        $transactions = $this->client(new Response(200, [], self::STATEMENT))->downloadTransactions(new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-13'));

        self::assertCount(1, $transactions);
        self::assertSame(308000, $transactions[0]->amount);
        self::assertSame('1246392364', $transactions[0]->variableSymbol);
    }

    public function testEmptyMaintenanceResponseIsAReadableOutageNotAVendorCrash(): void
    {
        // Sunday-evening FIO maintenance: 200 with an empty / HTML body. The
        // library used to emit "Undefined property ::$accountStatement" and
        // then a TypeError (Sentry FAJNE-SKLADY-36/37).
        $this->expectException(FioApiUnavailable::class);
        $this->expectExceptionMessage('údržba na straně FIO');

        $this->client(new Response(200, [], ''))->downloadTransactions(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable());
    }

    public function testHtmlResponseIsAnOutage(): void
    {
        $this->expectException(FioApiUnavailable::class);
        $this->expectExceptionMessage('Probíhá údržba');

        $this->client(new Response(200, [], '<html><body>Probíhá údržba</body></html>'))->downloadTransactions(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable());
    }

    public function testUnreachableFioIsAnOutage(): void
    {
        $this->expectException(FioApiUnavailable::class);
        $this->expectExceptionMessage('FIO API je nedostupné');

        $this->client(new ConnectException('cURL error 28: Failed to connect', new Request('GET', 'https://fioapi.fio.cz')))->downloadTransactions(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable());
    }

    public function testRateLimitStillSurfacesAsTooGreedy(): void
    {
        // The command treats 409 as a benign "retry next run" — must not be
        // swallowed into FioApiUnavailable.
        $this->expectException(TooGreedyException::class);

        $this->client(new Response(409, [], ''))->downloadTransactions(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable());
    }

    private function client(Response|\Throwable $answer): FioClient
    {
        return new FioClient('test-token', new MockHandler([$answer]));
    }
}
