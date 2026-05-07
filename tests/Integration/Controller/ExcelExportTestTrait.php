<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Helpers shared across the spec-028 export controller tests.
 */
trait ExcelExportTestTrait
{
    private function findUserByEmail(EntityManagerInterface $entityManager, string $email): User
    {
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found', $email));

        return $user;
    }

    private function assertXlsxResponse(KernelBrowser $client): string
    {
        $response = $client->getResponse();
        \assert($response instanceof \Symfony\Component\HttpFoundation\Response);

        \PHPUnit\Framework\Assert::assertSame(200, $response->getStatusCode());
        \PHPUnit\Framework\Assert::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );

        // StreamedResponse::getContent() returns false; the buffered body is on
        // the BrowserKit-wrapped DomResponse instead.
        $body = $client->getInternalResponse()->getContent();
        \PHPUnit\Framework\Assert::assertNotEmpty($body);

        return $body;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readXlsxRows(string $body): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx-export-test-');
        \PHPUnit\Framework\Assert::assertNotFalse($tmp);
        $path = $tmp.'.xlsx';
        rename($tmp, $path);
        file_put_contents($path, $body);

        $reader = new Reader();
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }

            break;
        }
        $reader->close();
        unlink($path);

        return $rows;
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function rowsContainCellValue(array $rows, string $needle): bool
    {
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if (is_string($cell) && str_contains($cell, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
