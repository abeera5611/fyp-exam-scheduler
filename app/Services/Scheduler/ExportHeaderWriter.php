<?php

namespace App\Services\Scheduler;

use App\Models\Scheduler\DateSheetRule;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExportHeaderWriter
{
    public static function write(Worksheet $sheet, int $lastColumn, array $lines): int
    {
        $lastColumn = max(2, $lastColumn);
        $lastColumnLetter = Coordinate::stringFromColumnIndex($lastColumn);
        $textStartColumn = file_exists(self::logoPath()) ? 'B' : 'A';

        if (file_exists(self::logoPath())) {
            $drawing = new Drawing();
            $drawing->setName('COMSATS logo');
            $drawing->setDescription('COMSATS University Islamabad');
            $drawing->setPath(self::logoPath());
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(18);
            $drawing->setOffsetY(6);
            $drawing->setHeight(58);
            $drawing->setWorksheet($sheet);
        }

        foreach (array_values(array_filter($lines, fn ($line) => trim((string) $line) !== '')) as $index => $line) {
            $row = $index + 1;
            $range = $textStartColumn . $row . ':' . $lastColumnLetter . $row;
            $sheet->mergeCells($range);
            $sheet->setCellValue($textStartColumn . $row, (string) $line);
            $sheet->getStyle($range)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle($range)->getFont()
                ->setBold(true)
                ->setSize($row === 1 ? 18 : 14);
        }

        for ($row = 1; $row <= 4; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
        }
        $sheet->getRowDimension(5)->setRowHeight(8);

        return 6;
    }

    public static function lines(?DateSheetRule $rule = null, ?string $context = null): array
    {
        return [
            'COMSATS University Islamabad',
            'Attock Campus',
            config('scheduler.exports.exam_header', 'Terminal Exam SP26'),
            trim((string) $context),
        ];
    }

    public static function programName(string $program): string
    {
        $program = strtoupper(trim($program));
        $names = [
            'BCS' => 'Bachelor of Science in Computer Science',
            'BSE' => 'Bachelor of Science in Software Engineering',
            'BAI' => 'Bachelor of Science in Artificial Intelligence',
            'BBA' => 'Bachelor of Business Administration',
            'GENERAL' => 'General',
        ];

        return $names[$program] ?? $program;
    }

    private static function logoPath(): string
    {
        return (string) config('scheduler.exports.logo_path', public_path('images/comsats-logo.jpg'));
    }
}
