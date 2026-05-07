<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Excel;

use App\Service\Excel\ExcelColumn;
use App\Service\Excel\ExcelColumnType;
use App\Service\Excel\ExcelExporter;
use App\Service\Excel\ExcelSheet;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;

final class ExcelExporterTest extends TestCase
{
    private ExcelExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new ExcelExporter();
    }

    public function testStreamReturnsXlsxResponseWithSafeFilename(): void
    {
        $sheet = new ExcelSheet(
            sheetTitle: 'Objednávky',
            filename: 'objednávky-2025-06-15.xlsx',
            columns: [new ExcelColumn('Číslo')],
            rows: [['1']],
        );

        $response = $this->exporter->stream($sheet);

        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment;', $disposition);
        // Diacritics must be transliterated.
        self::assertStringNotContainsString('á', $disposition);
        self::assertStringContainsString('objednavky-2025-06-15.xlsx', $disposition);
    }

    public function testWritesHeaderAndTypedCells(): void
    {
        $sheet = new ExcelSheet(
            sheetTitle: 'Test',
            filename: 'test.xlsx',
            columns: [
                new ExcelColumn('Text'),
                new ExcelColumn('Číslo', ExcelColumnType::INTEGER),
                new ExcelColumn('Cena (Kč)', ExcelColumnType::MONEY_KC),
                new ExcelColumn('Datum', ExcelColumnType::DATE),
                new ExcelColumn('Aktivní', ExcelColumnType::BOOLEAN),
            ],
            rows: [
                ['Alfa', 5, 1500_00, new \DateTimeImmutable('2025-06-15'), true],
                ['Beta', 0, 0, null, false],
            ],
        );

        $rows = $this->writeAndRead($sheet);

        self::assertSame(['Text', 'Číslo', 'Cena (Kč)', 'Datum', 'Aktivní'], $rows[0]);
        self::assertSame('Alfa', $rows[1][0]);
        self::assertSame(5, $rows[1][1]);
        self::assertEqualsWithDelta(1500.0, (float) $rows[1][2], 0.01);
        self::assertInstanceOf(\DateTimeInterface::class, $rows[1][3]);
        self::assertSame('2025-06-15', $rows[1][3]->format('Y-m-d'));
        self::assertSame('Ano', $rows[1][4]);

        // Beta row — null and zero handling.
        self::assertSame('Beta', $rows[2][0]);
        // Integer 0 reads back as text per openspout EmptyCell semantics — see ExcelExporter::buildCell.
        self::assertSame(0, $rows[2][1]);
        self::assertEqualsWithDelta(0.0, (float) $rows[2][2], 0.01);
        self::assertSame('', $rows[2][3]);
        self::assertSame('Ne', $rows[2][4]);
    }

    public function testTruncatesAt50000Rows(): void
    {
        $sheet = new ExcelSheet(
            sheetTitle: 'Big',
            filename: 'big.xlsx',
            columns: [new ExcelColumn('N', ExcelColumnType::INTEGER)],
            rows: $this->generateRows(50_100),
        );

        $rows = $this->writeAndRead($sheet);

        // Header + 50 000 data rows + 1 truncation marker = 50 002 total.
        self::assertCount(ExcelExporter::MAX_ROWS + 2, $rows);
    }

    public function testAddsTruncationMarkerWhenInputExceedsCap(): void
    {
        $sheet = new ExcelSheet(
            sheetTitle: 'Big',
            filename: 'big.xlsx',
            columns: [new ExcelColumn('N', ExcelColumnType::INTEGER)],
            rows: $this->generateRows(ExcelExporter::MAX_ROWS + 1),
        );

        $rows = $this->writeAndRead($sheet);

        self::assertNotSame([], $rows);
        $lastRow = $rows[count($rows) - 1];
        self::assertSame(ExcelExporter::TRUNCATION_MARKER, $lastRow[0]);
    }

    public function testNoTruncationMarkerWhenWithinCap(): void
    {
        $sheet = new ExcelSheet(
            sheetTitle: 'Small',
            filename: 'small.xlsx',
            columns: [new ExcelColumn('N', ExcelColumnType::INTEGER)],
            rows: $this->generateRows(3),
        );

        $rows = $this->writeAndRead($sheet);

        // Header + 3 data rows = 4 — no marker appended.
        self::assertCount(4, $rows);
        $lastRow = $rows[count($rows) - 1];
        self::assertNotSame(ExcelExporter::TRUNCATION_MARKER, $lastRow[0]);
    }

    /**
     * @return \Generator<int, array<int, int>>
     */
    private function generateRows(int $count): \Generator
    {
        for ($i = 1; $i <= $count; ++$i) {
            yield [$i];
        }
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function writeAndRead(ExcelSheet $sheet): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'excel-test-');
        self::assertNotFalse($tmp);
        $path = $tmp.'.xlsx';
        rename($tmp, $path);

        $this->exporter->writeXlsx($sheet, $path);

        $reader = new Reader();
        $reader->open($path);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheetReader) {
            foreach ($sheetReader->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }

            break;
        }
        $reader->close();
        unlink($path);

        return $rows;
    }
}
