<?php

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Cell-by-cell worksheet helpers for the exports that reproduce the farm's own workbooks
 * (PointageExport, the payment sheets). The using class sets $this->ws before calling them.
 */
trait WritesSheetCells
{
    /** Escapes a value for use inside a formula's "..." string literal. */
    private function quote(string $value): string
    {
        return str_replace('"', '""', $value);
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private function text(string $cell, string $value): void
    {
        $this->ws->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function formula(string $cell, string $formula): void
    {
        $this->ws->setCellValue($cell, $formula);
    }

    private function col(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    private function cell(int $col, int $row): string
    {
        return $this->col($col).$row;
    }

    private function range(int $col1, int $row1, int $col2, int $row2): string
    {
        return $this->cell($col1, $row1).':'.$this->cell($col2, $row2);
    }

    /** Applies style parts merged recursively (two parts may both set e.g. 'borders'). */
    private function style(string $range, array ...$parts): void
    {
        $this->ws->getStyle($range)->applyFromArray(array_replace_recursive(...$parts));
    }

    private function font(string $name, float $size, bool $bold = false, ?string $color = null, bool $underline = false): array
    {
        return ['font' => array_filter([
            'name' => $name, 'size' => $size, 'bold' => $bold,
            'underline' => $underline ? 'single' : null,
            'color' => $color ? ['rgb' => $color] : null,
        ], fn ($v) => $v !== null)];
    }

    private function fill(string $rgb): array
    {
        return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rgb]]];
    }

    private function center(): array
    {
        return ['alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true]];
    }

    private function borders(string $style): array
    {
        return ['borders' => ['allBorders' => ['borderStyle' => $style]]];
    }

    private function outline(string $style): array
    {
        return ['borders' => ['outline' => ['borderStyle' => $style]]];
    }

    /** A sheet name Excel accepts (≤ 31 chars, none of \ / ? * [ ] :), as written in a reference. */
    private function sheetRef(string $title): string
    {
        return "'".str_replace("'", "''", $title)."'!";
    }
}
