<?php

declare(strict_types=1);

namespace App\Service\Excel;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ExcelExporter
{
    public const int MAX_ROWS = 50_000;

    public function stream(ExcelSheet $sheet): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($sheet): void {
            $this->writeXlsx($sheet, 'php://output');
        });

        $response->headers->set(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', $this->safeFilename($sheet->filename)),
        );
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Write the sheet to a path / stream wrapper. Used by ::stream() with
     * `php://output` and by the unit test with a tmpfile path.
     */
    public function writeXlsx(ExcelSheet $sheet, string $target): void
    {
        $options = new Options();
        $options->DEFAULT_COLUMN_WIDTH = 18.0;

        $writer = new Writer($options);
        $writer->openToFile($target);
        $writer->getCurrentSheet()->setName($this->safeSheetTitle($sheet->sheetTitle));

        $writer->addRow(Row::fromValues(
            array_values(array_map(static fn (ExcelColumn $c): string => $c->header, $sheet->columns)),
            (new Style())->setFontBold(),
        ));

        // Reusable styles — never instantiate per cell.
        $dateStyle = (new Style())->setFormat('dd.mm.yyyy');
        $dateTimeStyle = (new Style())->setFormat('dd.mm.yyyy hh:mm');
        $moneyStyle = (new Style())->setFormat('#,##0.00');
        $integerStyle = (new Style())->setFormat('0');
        $decimalStyle = (new Style())->setFormat('#,##0.00');

        $written = 0;
        foreach ($sheet->rows as $rawRow) {
            if ($written >= self::MAX_ROWS) {
                break;
            }
            $cells = [];
            foreach ($sheet->columns as $index => $column) {
                $cells[] = $this->buildCell(
                    $rawRow[$index] ?? null,
                    $column->type,
                    $dateStyle,
                    $dateTimeStyle,
                    $moneyStyle,
                    $integerStyle,
                    $decimalStyle,
                );
            }
            $writer->addRow(new Row($cells));
            ++$written;
        }

        $writer->close();
    }

    private function buildCell(
        bool|\DateTimeInterface|float|int|string|null $value,
        ExcelColumnType $type,
        Style $dateStyle,
        Style $dateTimeStyle,
        Style $moneyStyle,
        Style $integerStyle,
        Style $decimalStyle,
    ): Cell {
        if (null === $value || '' === $value) {
            return Cell::fromValue(null);
        }

        return match ($type) {
            ExcelColumnType::DATE => $value instanceof \DateTimeInterface
                ? Cell::fromValue($value, $dateStyle)
                : Cell::fromValue($this->stringify($value)),
            ExcelColumnType::DATETIME => $value instanceof \DateTimeInterface
                ? Cell::fromValue($value, $dateTimeStyle)
                : Cell::fromValue($this->stringify($value)),
            ExcelColumnType::MONEY_KC => Cell::fromValue($this->moneyToFloat($value), $moneyStyle),
            ExcelColumnType::INTEGER => Cell::fromValue($this->toInt($value), $integerStyle),
            ExcelColumnType::DECIMAL => Cell::fromValue($this->toFloat($value), $decimalStyle),
            ExcelColumnType::BOOLEAN => Cell::fromValue(
                ($value && true === (bool) $value) ? 'Ano' : 'Ne',
            ),
            ExcelColumnType::TEXT => Cell::fromValue($this->stringify($value)),
        };
    }

    private function stringify(bool|\DateTimeInterface|float|int|string $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private function moneyToFloat(bool|\DateTimeInterface|float|int|string $value): float
    {
        if (is_int($value)) {
            return $value / 100;
        }
        if (is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function toInt(bool|\DateTimeInterface|float|int|string $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || is_string($value) || is_bool($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function toFloat(bool|\DateTimeInterface|float|int|string $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) || is_bool($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function safeSheetTitle(string $title): string
    {
        $stripped = preg_replace('/[\/\\\\?\*\[\]]/u', ' ', $title) ?? $title;
        $stripped = trim($stripped);
        if (mb_strlen($stripped) > 31) {
            $stripped = mb_substr($stripped, 0, 31);
        }

        return '' === $stripped ? 'List' : $stripped;
    }

    private function safeFilename(string $filename): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; [:Nonspacing Mark:] Remove;', $filename);
        if (false === $ascii) {
            $ascii = $filename;
        }
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $ascii) ?? $ascii;
        $ascii = trim($ascii, '-_.');

        return '' === $ascii ? 'export.xlsx' : $ascii;
    }
}
