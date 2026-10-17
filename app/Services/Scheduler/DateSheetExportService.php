<?php

namespace App\Services\Scheduler;

use App\Models\Scheduler\DateSheetRule;
use App\Models\Scheduler\CourseRegistration;
use App\Models\Scheduler\Exam;
use App\Models\Scheduler\RoomAllocation;
use App\Models\Scheduler\SeatingAssignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DateSheetExportService
{
    public function export(int $ruleId, string $format, string $outputPath): string
    {
        $rule = DateSheetRule::query()->findOrFail($ruleId);
        $format = strtolower(trim($format));

        $spreadsheet = match ($format) {
            'with_rooms', 'rooms', 'final', 'manual', 'manual_with_rooms' => $this->buildWithRoomsWorkbook($rule),
            'terminal' => $this->buildTerminalWorkbook($rule),
            default => $this->buildMidtermWorkbook($rule),
        };

        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);

        return $outputPath;
    }

    private function buildWithRoomsWorkbook(DateSheetRule $rule): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Date Sheet');

        $exams = $this->finalDateSheetExams($rule);
        $this->writeFinalDateSheetSheet($sheet, $exams, 'Final Date Sheet');
        $this->appendProgramDateSheetSheets($spreadsheet, $exams);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildMidtermWorkbook(DateSheetRule $rule): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Midterm');

        $headerRow = ExportHeaderWriter::write($sheet, 9, ExportHeaderWriter::lines($rule, 'Date Sheet'));

        $headers = ['S No', 'Date', 'Time', 'Course', 'Instructor', 'Class', 'Invigilator', 'Strength', 'Hall / Room No'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue($this->cell($index + 1, $headerRow), $header);
        }
        $sheet->getStyle('A' . $headerRow . ':I' . $headerRow)->getFont()->setBold(true);

        $rowNo = $headerRow + 1;
        $serial = 1;

        $exams = Exam::query()
            ->with(['course', 'allocations.room', 'allocations.invigilator'])
            ->where('rule_id', $rule->id)
            ->orderBy('date')
            ->orderBy('time_slot')
            ->orderBy('class_label')
            ->get();

        $classDayConflictMap = $this->classDayConflictMap($exams);
        foreach ($exams as $exam) {
            foreach ($this->exportRowsForExam($exam) as $exportRow) {
                $sheet->setCellValue("A{$rowNo}", $serial++);
                $sheet->setCellValue("B{$rowNo}", $exam->date?->format('d/m/Y'));
                $sheet->setCellValue("C{$rowNo}", (string) $exam->time_slot);
                $sheet->setCellValue("D{$rowNo}", (string) ($exam->course?->title ?: $exam->course?->code ?: ''));
                $sheet->setCellValue("E{$rowNo}", (string) $exam->instructor);
                $sheet->setCellValue("F{$rowNo}", $exportRow['class_label']);
                $sheet->setCellValue("G{$rowNo}", $this->invigilatorString($exam, $exportRow['student_ids'], $exportRow['allocation_ids']));
                $sheet->setCellValue("H{$rowNo}", (int) $exportRow['strength']);
                $sheet->setCellValue("I{$rowNo}", $this->roomString($exam, true, $exportRow['student_ids'], $exportRow['allocation_ids']));
                if ($this->examHasClassDayConflictForClass($exam, $classDayConflictMap, $exportRow['class_label'])) {
                    $sheet->getStyle("A{$rowNo}:I{$rowNo}")
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFFFEDD5');
                }
                $rowNo++;
            }
        }

        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    private function buildTerminalWorkbook(DateSheetRule $rule): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $exams = Exam::query()
            ->with(['course', 'allocations.room', 'allocations.invigilator'])
            ->where('rule_id', $rule->id)
            ->orderBy('program_code')
            ->orderBy('semester_no')
            ->orderBy('class_label')
            ->orderBy('date')
            ->orderBy('time_slot')
            ->get()
            ->groupBy(function ($exam) {
                return $exam->program_code ?: $this->programFromClassLabel((string) $exam->class_label) ?: 'GENERAL';
            });

        if ($exams->isEmpty()) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Terminal');
            ExportHeaderWriter::write($sheet, 4, ExportHeaderWriter::lines($rule, 'Date Sheet'));
            $sheet->setCellValue('A6', 'No exams scheduled for the selected session.');
            return $spreadsheet;
        }

        foreach ($exams as $program => $programExams) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->safeSheetTitle((string) $program));

            $headerRow = ExportHeaderWriter::write($sheet, 8, ExportHeaderWriter::lines($rule, ExportHeaderWriter::programName((string) $program)));
            $sheet->setCellValue('A' . $headerRow, 'S No');
            $sheet->setCellValue('B' . $headerRow, 'Date');
            $sheet->setCellValue('C' . $headerRow, 'Time');
            $sheet->setCellValue('D' . $headerRow, 'Course');
            $sheet->setCellValue('E' . $headerRow, 'Instructor');
            $sheet->setCellValue('F' . $headerRow, 'Invigilator');
            $sheet->setCellValue('G' . $headerRow, 'Strength');
            $sheet->setCellValue('H' . $headerRow, 'Hall / Room No');
            $sheet->getStyle('A' . $headerRow . ':H' . $headerRow)->getFont()->setBold(true);

            $rowNo = $headerRow + 1;

            $expandedRows = $programExams
                ->flatMap(fn (Exam $exam) => collect($this->exportRowsForExam($exam))->map(fn (array $row) => $row + ['exam' => $exam]))
                ->sortBy([
                    fn (array $left, array $right) => strcmp((string) $left['class_label'], (string) $right['class_label']),
                    fn (array $left, array $right) => strcmp($left['exam']->date?->format('Y-m-d') ?: '', $right['exam']->date?->format('Y-m-d') ?: ''),
                    fn (array $left, array $right) => SlotHelper::slotSortKey((string) $left['exam']->time_slot, [], []) <=> SlotHelper::slotSortKey((string) $right['exam']->time_slot, [], []),
                ])
                ->values();
            $groupedByClass = $expandedRows->groupBy('class_label');
            $classDayConflictMap = $this->classDayConflictMap($programExams);

            foreach ($groupedByClass as $classLabel => $classRows) {
                $sheet->setCellValue("A{$rowNo}", $classLabel);
                $sheet->getStyle("A{$rowNo}:H{$rowNo}")->getFont()->setBold(true);
                $rowNo++;

                $serial = 1;
                foreach ($classRows as $classRow) {
                    $exam = $classRow['exam'];
                    $sheet->setCellValue("A{$rowNo}", $serial++);
                    $sheet->setCellValue("B{$rowNo}", $exam->date?->format('d/m/Y'));
                    $sheet->setCellValue("C{$rowNo}", (string) $exam->time_slot);
                    $sheet->setCellValue("D{$rowNo}", (string) ($exam->course?->title ?: $exam->course?->code ?: ''));
                    $sheet->setCellValue("E{$rowNo}", (string) $exam->instructor);
                    $sheet->setCellValue("F{$rowNo}", $this->invigilatorString($exam, $classRow['student_ids'], $classRow['allocation_ids']));
                    $sheet->setCellValue("G{$rowNo}", (int) $classRow['strength']);
                    $sheet->setCellValue("H{$rowNo}", $this->roomString($exam, true, $classRow['student_ids'], $classRow['allocation_ids']));
                    if ($this->examHasClassDayConflictForClass($exam, $classDayConflictMap, (string) $classLabel)) {
                        $sheet->getStyle("A{$rowNo}:H{$rowNo}")
                            ->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()
                            ->setARGB('FFFFEDD5');
                    }
                    $rowNo++;
                }

                $rowNo++;
            }

            foreach (range('A', 'H') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function finalDateSheetExams(DateSheetRule $rule)
    {
        return Exam::query()
            ->with(['course', 'allocations.room', 'allocations.invigilator'])
            ->where('rule_id', $rule->id)
            ->get()
            ->sort(function (Exam $left, Exam $right) {
                return [
                    $this->examProgram($left),
                    (int) ($left->semester_no ?? 0),
                    (string) ($left->class_label ?: ''),
                    $left->date?->format('Y-m-d') ?: '',
                    SlotHelper::slotSortKey((string) $left->time_slot, [], []),
                    (string) ($left->course?->code ?: ''),
                    (int) $left->id,
                ] <=> [
                    $this->examProgram($right),
                    (int) ($right->semester_no ?? 0),
                    (string) ($right->class_label ?: ''),
                    $right->date?->format('Y-m-d') ?: '',
                    SlotHelper::slotSortKey((string) $right->time_slot, [], []),
                    (string) ($right->course?->code ?: ''),
                    (int) $right->id,
                ];
            })
            ->values();
    }

    private function writeFinalDateSheetSheet(Worksheet $sheet, $exams, string $context = 'Final Date Sheet'): void
    {
        $headerRow = ExportHeaderWriter::write($sheet, 10, ExportHeaderWriter::lines(null, $context));
        $headers = ['S No', 'Date', 'Time', 'Course Code', 'Course Title', 'Class', 'Strength', 'Instructor', 'Hall/Room', 'Invigilators'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue($this->cell($index + 1, $headerRow), $header);
        }

        $classDayConflictMap = $this->classDayConflictMap($exams);
        $rowNo = $headerRow + 1;
        $serial = 1;
        foreach ($exams as $exam) {
            foreach ($this->exportRowsForExam($exam) as $exportRow) {
                $sheet->setCellValue("A{$rowNo}", $serial++);
                $sheet->setCellValue("B{$rowNo}", $exam->date?->format('Y-m-d'));
                $sheet->setCellValue("C{$rowNo}", (string) $exam->time_slot);
                $sheet->setCellValue("D{$rowNo}", (string) ($exam->course?->code ?: ''));
                $sheet->setCellValue("E{$rowNo}", (string) ($exam->course?->title ?: $exam->course?->code ?: ''));
                $sheet->setCellValue("F{$rowNo}", $exportRow['class_label']);
                $sheet->setCellValue("G{$rowNo}", (int) $exportRow['strength']);
                $sheet->setCellValue("H{$rowNo}", (string) $exam->instructor);
                $sheet->setCellValue("I{$rowNo}", $this->roomString($exam, true, $exportRow['student_ids'], $exportRow['allocation_ids']));
                $sheet->setCellValue("J{$rowNo}", $this->invigilatorString($exam, $exportRow['student_ids'], $exportRow['allocation_ids']));
                if ($this->examHasClassDayConflictForClass($exam, $classDayConflictMap, $exportRow['class_label'])) {
                    $sheet->getStyle("A{$rowNo}:J{$rowNo}")
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFFFEDD5');
                }
                $rowNo++;
            }
        }

        $this->styleFlatDateSheet($sheet, max($headerRow, $rowNo - 1), $headerRow);
    }

    private function appendProgramDateSheetSheets(Spreadsheet $spreadsheet, $exams): void
    {
        $usedTitles = [];
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $usedTitles[strtolower($worksheet->getTitle())] = true;
        }

        $groups = $exams->groupBy(function (Exam $exam) {
            return $this->examProgram($exam);
        });

        foreach ($groups as $program => $programExams) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->uniqueSheetTitle((string) $program, $usedTitles));
            $this->writeFinalDateSheetSheet($sheet, $programExams->values(), ExportHeaderWriter::programName((string) $program));
        }
    }

    private function uniqueSheetTitle(string $title, array &$usedTitles): string
    {
        $base = $this->safeSheetTitle($title !== '' ? $title : 'GENERAL');
        $candidate = $base;
        $suffix = 2;
        while (isset($usedTitles[strtolower($candidate)])) {
            $candidate = mb_substr($base, 0, 28) . ' ' . $suffix++;
        }
        $usedTitles[strtolower($candidate)] = true;

        return $candidate;
    }

    private function examProgram(Exam $exam): string
    {
        $program = strtoupper(trim((string) ($exam->program_code ?: $this->programFromClassLabel((string) $exam->class_label))));

        return $program !== '' ? $program : 'GENERAL';
    }

    private function classLabelText(Exam $exam): string
    {
        return implode(', ', $this->classLabels($exam));
    }

    private function classLabels(Exam $exam): array
    {
        $labels = [];
        $primary = trim((string) ($exam->class_label ?: $exam->program_code ?: ''));
        if ($primary !== '') {
            $labels[$primary] = true;
        }

        $raw = trim((string) ($exam->merged_class_labels ?? ''));
        if ($raw !== '') {
            foreach (preg_split('/[|;,]+/', $raw) ?: [] as $label) {
                $label = trim((string) $label);
                if ($label !== '') {
                    $labels[$label] = true;
                }
            }
        }

        return array_keys($labels);
    }

    private function exportRowsForExam(Exam $exam): array
    {
        $labels = $this->classLabels($exam);
        if ($labels === []) {
            return [[
                'class_label' => 'GENERAL',
                'student_ids' => [],
                'allocation_ids' => [],
                'strength' => (int) ($exam->strength ?? 0),
            ]];
        }

        $rows = [];
        $strengths = [];
        foreach ($labels as $index => $classLabel) {
            $studentIds = $this->studentIdsForExamClass($exam, $classLabel);
            $strength = $studentIds !== [] ? count($studentIds) : (count($labels) === 1 ? (int) ($exam->strength ?? 0) : 0);
            $strengths[$index] = $strength;
            $rows[] = [
                'class_label' => $classLabel,
                'student_ids' => $studentIds,
                'strength' => $strength,
            ];
        }

        foreach ($rows as $index => &$row) {
            $row['allocation_ids'] = $this->allocationIdsForExportRow($exam, $row['student_ids'], $index, $strengths);
        }
        unset($row);

        return $rows;
    }

    private function classDayConflictMap($exams): array
    {
        $studentCourses = [];

        foreach ($exams as $exam) {
            if (!$exam instanceof Exam || !$exam->date) {
                continue;
            }

            $date = $exam->date->format('Y-m-d');
            $courseCode = strtoupper(trim((string) ($exam->course?->code ?: 'COURSE-' . $exam->course_id)));
            if ($courseCode === '') {
                continue;
            }

            foreach ($this->classLabels($exam) as $classLabel) {
                foreach ($this->studentIdsForExamClass($exam, $classLabel) as $studentId) {
                    $studentCourses[$date][$classLabel][(int) $studentId][$courseCode] = true;
                }
            }
        }

        $conflicts = [];
        foreach ($studentCourses as $date => $classMap) {
            foreach ($classMap as $classLabel => $students) {
                foreach ($students as $courseSet) {
                    if (count($courseSet) > 1) {
                        $conflicts[$date][$classLabel] = true;
                        break;
                    }
                }
            }
        }

        return $conflicts;
    }

    private function examHasClassDayConflict(Exam $exam, array $classDayConflictMap): bool
    {
        if (!$exam->date) {
            return false;
        }

        $date = $exam->date->format('Y-m-d');
        foreach ($this->classLabels($exam) as $classLabel) {
            if (($classDayConflictMap[$date][$classLabel] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function examHasClassDayConflictForClass(Exam $exam, array $classDayConflictMap, string $classLabel): bool
    {
        if (!$exam->date) {
            return false;
        }

        return ($classDayConflictMap[$exam->date->format('Y-m-d')][$classLabel] ?? false) === true;
    }

    private function studentIdsForExamClass(Exam $exam, string $classLabel): array
    {
        if (!$exam->course_id) {
            return [];
        }

        return CourseRegistration::query()
            ->where('rule_id', (int) $exam->rule_id)
            ->where('course_id', (int) $exam->course_id)
            ->where('class_label', $classLabel)
            ->when((string) $exam->instructor !== '', fn ($query) => $query->where('instructor_name', (string) $exam->instructor))
            ->pluck('student_id')
            ->unique()
            ->values()
            ->all();
    }

    private function styleFlatDateSheet(Worksheet $sheet, int $lastRow, int $headerRow = 1): void
    {
        $lastRow = max($headerRow, $lastRow);
        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->setAutoFilter('A' . $headerRow . ':J' . $lastRow);
        $sheet->getStyle('A' . $headerRow . ':J' . $headerRow)->getFont()->setBold(true);
        $sheet->getStyle('A' . $headerRow . ':J' . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE9EEF7');
        $sheet->getStyle('A' . $headerRow . ':J' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFD6DEE8');
        $sheet->getStyle('A' . $headerRow . ':J' . $lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle('A' . $headerRow . ':J' . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A' . ($headerRow + 1) . ':C' . max($headerRow + 1, $lastRow))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('G' . ($headerRow + 1) . ':G' . max($headerRow + 1, $lastRow))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $widths = [8, 14, 15, 22, 36, 14, 10, 28, 24, 42];
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        for ($row = $headerRow; $row <= $lastRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight($row === $headerRow ? 22 : 18);
        }
    }

    private function cell(int $column, int $row): string
    {
        return Coordinate::stringFromColumnIndex($column) . $row;
    }

    private function safeSheetTitle(string $value): string
    {
        $title = trim((string) preg_replace('#[\\\\/\?\*\[\]\:]+#', ' ', $value));
        $title = $title !== '' ? $title : 'Sheet';

        return mb_substr($title, 0, 31);
    }

    private function roomString(Exam $exam, bool $physical = false, array $studentIds = [], array $allocationIds = []): string
    {
        $allocationIds = $allocationIds !== [] ? $allocationIds : $this->allocationIdsForStudentIds($exam, $studentIds);
        $rooms = $exam->allocations
            ->when($allocationIds !== [], fn ($allocations) => $allocations->whereIn('id', $allocationIds))
            ->filter(fn ($allocation) => !$allocation->is_duty_only && $allocation->room && !$allocation->room->looksLikeWaitingRoom())
            ->map(function ($allocation) use ($physical) {
                if ($physical) {
                    $label = SlotHelper::physicalRoomLabel($allocation->room);
                    return $label !== '' ? $label : (string) $allocation->room->room_id;
                }

                return trim((string) $allocation->room->room_id . ($allocation->subslot_label ? ' ' . $allocation->subslot_label : ''));
            })
            ->filter()
            ->unique()
            ->values();

        return $rooms->implode(', ');
    }

    private function invigilatorString(Exam $exam, array $studentIds = [], array $allocationIds = []): string
    {
        $allocationIds = $allocationIds !== [] ? $allocationIds : $this->allocationIdsForStudentIds($exam, $studentIds);
        $roomKeys = $exam->allocations
            ->when($allocationIds !== [], fn ($allocations) => $allocations->whereIn('id', $allocationIds))
            ->filter(fn ($allocation) => !$allocation->is_duty_only && $allocation->room && !$allocation->room->looksLikeWaitingRoom())
            ->map(fn ($allocation) => SlotHelper::physicalRoomKey($allocation->room))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($roomKeys === [] || !$exam->date) {
            return '';
        }

        $slotAllocations = RoomAllocation::query()
            ->with(['exam', 'room', 'invigilator'])
            ->whereNotNull('invigilator_id')
            ->whereHas('exam', function ($query) use ($exam) {
                $query->where('rule_id', $exam->rule_id)
                    ->whereDate('date', $exam->date->format('Y-m-d'))
                    ->where('time_slot', $exam->time_slot);
            })
            ->get()
            ->filter(fn ($allocation) => $allocation->room
                && !$allocation->room->looksLikeWaitingRoom()
                && in_array(SlotHelper::physicalRoomKey($allocation->room), $roomKeys, true));

        $parts = [];
        foreach ($slotAllocations->groupBy(fn ($allocation) => SlotHelper::physicalRoomKey($allocation->room)) as $group) {
            $names = $group->pluck('invigilator.name')->filter()->unique()->values();
            if ($names->isNotEmpty()) {
                $parts[] = $names->implode(', ');
            }
        }

        return implode('; ', array_values(array_unique($parts)));
    }

    private function allocationIdsForStudentIds(Exam $exam, array $studentIds): array
    {
        $examAllocationIds = $exam->allocations
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
        if ($examAllocationIds === [] || $studentIds === []) {
            return $examAllocationIds;
        }

        $matched = $this->matchedAllocationIdsForStudentIds($exam, $studentIds, $examAllocationIds);

        return $matched !== [] ? $matched : $examAllocationIds;
    }

    private function allocationIdsForExportRow(Exam $exam, array $studentIds, int $rowIndex, array $rowStrengths): array
    {
        $examAllocationIds = $exam->allocations
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if ($examAllocationIds === []) {
            return [];
        }

        $matched = $this->matchedAllocationIdsForStudentIds($exam, $studentIds, $examAllocationIds);
        if ($matched !== []) {
            return $matched;
        }

        return $this->fallbackAllocationIdsForExportRow($exam, $rowIndex, $rowStrengths) ?: $examAllocationIds;
    }

    private function matchedAllocationIdsForStudentIds(Exam $exam, array $studentIds, array $examAllocationIds): array
    {
        $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
        if ($examAllocationIds === [] || $studentIds === []) {
            return [];
        }

        return SeatingAssignment::query()
            ->whereIn('allocation_id', $examAllocationIds)
            ->whereIn('student_id', $studentIds)
            ->pluck('allocation_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function fallbackAllocationIdsForExportRow(Exam $exam, int $rowIndex, array $rowStrengths): array
    {
        $allocations = $exam->allocations
            ->filter(fn ($allocation) => !$allocation->is_duty_only && $allocation->room && !$allocation->room->looksLikeWaitingRoom())
            ->values();

        $rowStrength = max(0, (int) ($rowStrengths[$rowIndex] ?? 0));
        if ($allocations->isEmpty() || $rowStrength <= 0) {
            return [];
        }

        $start = 0;
        for ($i = 0; $i < $rowIndex; $i++) {
            $start += max(0, (int) ($rowStrengths[$i] ?? 0));
        }
        $end = $start + $rowStrength;
        $cursor = 0;
        $ids = [];

        foreach ($allocations as $allocation) {
            $capacity = max(0, (int) ($allocation->assigned_strength ?? 0));
            if ($capacity <= 0) {
                $capacity = max(1, (int) ($allocation->room?->effectiveCapacity() ?? 0));
            }

            $next = $cursor + $capacity;
            if ($start < $next && $end > $cursor) {
                $ids[] = (int) $allocation->id;
            }
            $cursor = $next;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function programFromClassLabel(string $classLabel): string
    {
        if (preg_match('/^[A-Za-z]+/', $classLabel, $matches)) {
            return strtoupper($matches[0]);
        }

        return '';
    }
}
