<?php

namespace App\Services\Scheduler;

use App\Models\Scheduler\DateSheetRule;
use App\Models\Scheduler\CourseRegistration;
use App\Models\Scheduler\Exam;
use App\Models\Scheduler\Faculty;
use App\Models\Scheduler\RoomAllocation;
use App\Models\Scheduler\SeatingAssignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

class OperationalExportService
{
    public function __construct(
        private readonly PlanningBoardService $planningBoardService,
    ) {
    }

    public function export(int $ruleId, string $type, string $outputPath, ?string $date = null, ?string $slot = null, array $filters = []): string
    {
        $rule = DateSheetRule::query()->findOrFail($ruleId);
        if ($type === 'seat_plan_all') {
            return $this->seatPlanZipForAllSlots($rule, $outputPath);
        }

        $spreadsheet = match ($type) {
            'datesheet_board' => $this->datesheetBoardWorkbook($rule),
            'datesheet_final' => $this->finalDateSheetWorkbook($rule),
            'room_allocation' => $this->roomAllocationWorkbook($rule, $date, $slot),
            'seat_plan' => $this->seatPlanWorkbook($rule, $date, $slot),
            'duty_roster' => $this->dutyRosterWorkbook($rule, $filters),
            'room_grid' => $this->roomGridWorkbook(
                $rule,
                $this->slotPairsFromKeys((array) ($filters['pairs'] ?? [])),
                filter_var($filters['missing_duties_only'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ),
            'faculty_availability' => $this->facultyAvailabilityWorkbook($rule, $date, $filters),
            'faculty_list' => $this->facultyListWorkbook($rule),
            default => throw new \InvalidArgumentException('Unsupported export type.'),
        };

        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        (new Xlsx($spreadsheet))->save($outputPath);

        return $outputPath;
    }

    private function finalDateSheetWorkbook(DateSheetRule $rule): Spreadsheet
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
                $sheet->setCellValue($this->cell(1, $rowNo), $serial++);
                $sheet->setCellValue($this->cell(2, $rowNo), $exam->date?->format('Y-m-d'));
                $sheet->setCellValue($this->cell(3, $rowNo), (string) $exam->time_slot);
                $sheet->setCellValue($this->cell(4, $rowNo), (string) ($exam->course?->code ?: ''));
                $sheet->setCellValue($this->cell(5, $rowNo), (string) ($exam->course?->title ?: $exam->course?->code ?: ''));
                $sheet->setCellValue($this->cell(6, $rowNo), $exportRow['class_label']);
                $sheet->setCellValue($this->cell(7, $rowNo), (int) $exportRow['strength']);
                $sheet->setCellValue($this->cell(8, $rowNo), (string) $exam->instructor);
                $sheet->setCellValue($this->cell(9, $rowNo), $this->roomString($exam, $exportRow['student_ids'], $exportRow['allocation_ids']));
                $sheet->setCellValue($this->cell(10, $rowNo), $this->invigilatorString($exam, $exportRow['student_ids'], $exportRow['allocation_ids']));
                if ($this->examHasClassDayConflictForClass($exam, $classDayConflictMap, $exportRow['class_label'])) {
                    $sheet->getStyle($this->range(1, $rowNo, 10, $rowNo))
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFFFEDD5');
                }
                $rowNo++;
            }
        }

        $lastRow = max($headerRow, $rowNo - 1);
        $this->styleGrid($sheet, $headerRow, 1, $lastRow, 10);
        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->setAutoFilter('A' . $headerRow . ':J' . $lastRow);
        $sheet->getStyle('A' . $headerRow . ':J' . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE9EEF7');
        $sheet->getStyle('A' . $headerRow . ':J' . $headerRow)->getFont()->setBold(true);
        $sheet->getStyle('A' . $headerRow . ':J' . $lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle('A' . $headerRow . ':J' . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $widths = [8, 14, 15, 22, 36, 14, 10, 28, 24, 42];
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
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

    private function programFromClassLabel(string $classLabel): string
    {
        if (preg_match('/^[A-Za-z]+/', $classLabel, $matches)) {
            return strtoupper($matches[0]);
        }

        return '';
    }

    private function datesheetBoardWorkbook(DateSheetRule $rule): Spreadsheet
    {
        $board = $this->planningBoardService->datesheetBoard($rule);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Datesheet Board');

        $headerRow = ExportHeaderWriter::write($sheet, max(2, count($board['slots']) + 1), ExportHeaderWriter::lines($rule, 'Datesheet Board'));
        $sheet->setCellValue('A' . $headerRow, 'Date');
        $column = 2;
        foreach ($board['slots'] as $slot) {
            $sheet->setCellValue($this->cell($column++, $headerRow), $slot['label']);
        }

        $row = $headerRow + 1;
        foreach ($board['days'] as $day) {
            $sheet->setCellValue($this->cell(1, $row), $day);
            $column = 2;
            foreach ($board['slots'] as $slot) {
                $cellKey = $day . '|' . $slot['label'];
                $cards = $board['cells'][$cellKey] ?? [];
                $lines = [];
                $hasClassConflict = false;
                foreach ($cards as $card) {
                    $cardHasConflict = (bool) ($card['has_class_day_conflict'] ?? false);
                    $hasClassConflict = $hasClassConflict || $cardHasConflict;
                    $classesText = $this->boardCardClassesText($card);
                    $lines[] = trim(($cardHasConflict ? '[CLASS SAME-DAY CONFLICT] ' : '')
                        . ($card['course_code'] ?: 'Course')
                        . ($card['course_title'] ? ' - ' . $card['course_title'] : '')
                        . "\nClasses:\n" . $classesText
                        . "\nStrength: " . ($card['strength'] ?? 0)
                        . (($card['class_day_conflict_text'] ?? '') ? "\nWarning: " . $card['class_day_conflict_text'] : '')
                        . (($card['instructors_text'] ?? '') ? "\nInstructor: " . $card['instructors_text'] : ''));
                }
                $cell = $this->cell($column, $row);
                $sheet->setCellValue($cell, implode("\n\n", $lines));
                if ($hasClassConflict) {
                    $sheet->getStyle($cell)
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFFFEDD5');
                }
                $column++;
            }
            $row++;
        }

        $this->styleGrid($sheet, $headerRow, 1, $row - 1, max(1, count($board['slots']) + 1));
        $sheet->getColumnDimension('A')->setWidth(14);
        foreach (range(2, max(2, count($board['slots']) + 1)) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth(34);
        }

        return $spreadsheet;
    }

    private function boardCardClassesText(array $card): string
    {
        $classes = array_values(array_filter(array_map(
            fn ($classLabel) => trim((string) $classLabel),
            (array) ($card['classes'] ?? [])
        )));

        if ($classes === []) {
            return (string) ($card['classes_text'] ?? '');
        }

        return implode("\n", array_map(fn (string $classLabel) => '- ' . $classLabel, $classes));
    }

    private function roomAllocationWorkbook(DateSheetRule $rule, ?string $date, ?string $slot): Spreadsheet
    {
        [$date, $slot] = $this->dateSlotOrFirst($rule, $date, $slot);
        $board = $this->planningBoardService->roomBoard((int) $rule->id, $date, $slot);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Room Allocation');

        $headerRow = ExportHeaderWriter::write($sheet, 7, ExportHeaderWriter::lines($rule, 'Room Allocation - ' . $date . ' - ' . $slot));

        $headers = ['Building', 'Floor', 'Physical Room', 'Capacity', 'Planning Capacity', 'Assigned', 'Paper Segments'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue($this->cell($i + 1, $headerRow), $header);
        }

        $excelRow = $headerRow + 1;
        foreach (($board['lanes'] ?? []) as $lane) {
            $papers = collect($lane['cards'] ?? [])->map(function ($card) {
                return trim(($card['course_code'] ?? '')
                    . (($card['class_label'] ?? '') !== '' ? ' [' . $card['class_label'] . ']' : '')
                    . ' (' . (int) ($card['assigned_strength'] ?? 0) . ')');
            })->filter()->implode("\n");

            $sheet->setCellValue($this->cell(1, $excelRow), $lane['building'] ?? '');
            $sheet->setCellValue($this->cell(2, $excelRow), $lane['floor'] ?? '');
            $sheet->setCellValue($this->cell(3, $excelRow), $lane['room_id'] ?? '');
            $sheet->setCellValue($this->cell(4, $excelRow), (int) ($lane['capacity'] ?? 0));
            $sheet->setCellValue($this->cell(5, $excelRow), (int) ($lane['planning_capacity'] ?? $lane['capacity'] ?? 0));
            $sheet->setCellValue($this->cell(6, $excelRow), (int) ($lane['assigned_total'] ?? 0));
            $sheet->setCellValue($this->cell(7, $excelRow), $papers ?: 'Empty');
            $excelRow++;
        }

        if ($excelRow === $headerRow + 1) {
            $sheet->setCellValue('A' . ($headerRow + 1), 'No room allocation exists for the selected slot.');
            $excelRow = $headerRow + 2;
        }

        $this->styleGrid($sheet, $headerRow, 1, max($headerRow, $excelRow - 1), 7);
        foreach (range('A', 'G') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    private function seatPlanWorkbook(DateSheetRule $rule, ?string $date, ?string $slot): Spreadsheet
    {
        [$date, $slot] = $this->dateSlotOrFirst($rule, $date, $slot);
        $board = $this->planningBoardService->seatPlanBoard((int) $rule->id, $date, $slot);
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        if (($board['rooms'] ?? []) === []) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Seat Plan');
            ExportHeaderWriter::write($sheet, 4, ExportHeaderWriter::lines($rule, 'Seat Plan - ' . $date . ' - ' . $slot));
            $sheet->setCellValue('A6', 'No seating assignments found for ' . $date . ' ' . $slot);
            return $spreadsheet;
        }

        $rooms = collect($board['rooms'])
            ->filter(fn (array $room) => (int) ($room['occupied_count'] ?? 0) > 0 || !empty($room['overflow']))
            ->values()
            ->all();

        if ($rooms === []) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Seat Plan');
            ExportHeaderWriter::write($sheet, 4, ExportHeaderWriter::lines($rule, 'Seat Plan - ' . $date . ' - ' . $slot));
            $sheet->setCellValue('A6', 'No occupied rooms found for ' . $date . ' ' . $slot);
            return $spreadsheet;
        }

        foreach ($rooms as $room) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->safeSheetTitle((string) $room['room_id']));
            $lastColumn = max(2, (int) $room['rows'] + 1);
            $headerRow = ExportHeaderWriter::write($sheet, $lastColumn, ExportHeaderWriter::lines($rule, 'Seat Plan - ' . $date . ' - ' . $slot . ' - ' . $room['room_id']));

            $sheet->setCellValue('A' . $headerRow, 'Col / Row');
            for ($rowNo = 1; $rowNo <= (int) $room['rows']; $rowNo++) {
                $sheet->setCellValue($this->cell($rowNo + 1, $headerRow), 'Row ' . $rowNo);
            }

            $excelRow = $headerRow + 1;
            for ($colNo = 1; $colNo <= (int) $room['cols']; $colNo++) {
                $sheet->setCellValue($this->cell(1, $excelRow), 'Col ' . $colNo);
                foreach ($room['matrix'] as $rowNo => $seats) {
                    $seat = $seats[$colNo] ?? null;
                    $assignment = $seat['assignment'] ?? null;
                    $value = $assignment
                        ? trim($this->seatPlanRegistrationForExport((string) ($assignment['registration_id'] ?? '')) . "\n" . ($assignment['student_name'] ?? ''))
                        : '';
                    $sheet->setCellValue($this->cell(((int) $rowNo) + 1, $excelRow), $value);
                }
                $excelRow++;
            }

            if (!empty($room['overflow'])) {
                $excelRow += 2;
                $sheet->setCellValue($this->cell(1, $excelRow++), 'Overflow / duplicate position records');
                foreach ($room['overflow'] as $item) {
                    $sheet->setCellValue($this->cell(1, $excelRow), $this->seatPlanRegistrationForExport((string) ($item['registration_id'] ?? '')));
                    $sheet->setCellValue($this->cell(2, $excelRow), $item['student_name'] ?? '');
                    $excelRow++;
                }
            }

            $this->styleGrid($sheet, $headerRow, 1, max($headerRow, $excelRow - 1), $lastColumn);
            foreach (range(1, $lastColumn) as $index) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth($index === 1 ? 10 : 20);
            }
            $sheet->freezePane('B' . ($headerRow + 1));
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function seatPlanRegistrationForExport(string $registrationId): string
    {
        return trim(str_replace(['CIIT/', '/ATK'], '', $registrationId));
    }

    private function seatPlanZipForAllSlots(DateSheetRule $rule, string $outputPath): string
    {
        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $tempDirectory = $directory . DIRECTORY_SEPARATOR . 'seat_plan_zip_' . uniqid('', true);
        if (!is_dir($tempDirectory)) {
            mkdir($tempDirectory, 0777, true);
        }

        $zipStream = fopen($outputPath, 'w+b');
        if ($zipStream === false) {
            throw new \RuntimeException('Unable to create export zip file.');
        }

        try {
            $zip = new ZipStream(
                outputStream: $zipStream,
                defaultCompressionMethod: CompressionMethod::STORE,
                sendHttpHeaders: false,
            );

            $pairs = $this->scheduledSlotPairsForRule($rule);
            if ($pairs === []) {
                $zip->addFile(
                    fileName: 'README.txt',
                    data: 'No datesheet slots exist for this exam session.',
                    compressionMethod: CompressionMethod::STORE,
                );
            }

            foreach ($pairs as $pair) {
                $slotFolder = $this->safeArchivePathPart($pair['date'] . '_' . $pair['slot'], 'slot');
                $fileBase = $this->safeArchivePathPart('seat_plan_' . $pair['date'] . '_' . $pair['slot'], 'seat_plan');
                $tempFile = $tempDirectory . DIRECTORY_SEPARATOR . $fileBase . '.xlsx';

                (new Xlsx($this->seatPlanWorkbook($rule, $pair['date'], $pair['slot'])))->save($tempFile);

                $zip->addFileFromPath(
                    fileName: $slotFolder . '/' . $fileBase . '.xlsx',
                    path: $tempFile,
                    compressionMethod: CompressionMethod::STORE,
                );
            }

            $zip->finish();
        } finally {
            fclose($zipStream);
            $this->deleteDirectory($tempDirectory);
        }

        return $outputPath;
    }

    private function facultyListWorkbook(DateSheetRule $rule): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Faculty');

        $headerRow = ExportHeaderWriter::write($sheet, 6, ExportHeaderWriter::lines($rule, 'Faculty List'));
        $headers = ['S No', 'Name', 'Department', 'Designation', 'Max Duties', 'Assigned Duties'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue($this->cell($i + 1, $headerRow), $header);
        }

        $row = $headerRow + 1;
        $serial = 1;
        foreach (Faculty::query()->orderBy('department')->orderBy('name')->get() as $faculty) {
            $sheet->setCellValue($this->cell(1, $row), $serial++);
            $sheet->setCellValue($this->cell(2, $row), (string) $faculty->name);
            $sheet->setCellValue($this->cell(3, $row), (string) $faculty->department);
            $sheet->setCellValue($this->cell(4, $row), (string) $faculty->designation);
            $sheet->setCellValue($this->cell(5, $row), (int) $faculty->max_duties);
            $sheet->setCellValue($this->cell(6, $row), (int) $faculty->assigned_duties);
            $row++;
        }

        $lastRow = max($headerRow, $row - 1);
        $this->styleGrid($sheet, $headerRow, 1, $lastRow, 6);
        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->setAutoFilter('A' . $headerRow . ':F' . $lastRow);
        $sheet->getStyle('A' . $headerRow . ':F' . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE9EEF7');
        $sheet->getStyle('A' . $headerRow . ':F' . $headerRow)->getFont()->setBold(true);
        $widths = [8, 32, 28, 26, 12, 14];
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        return $spreadsheet;
    }

    private function dutyRosterWorkbook(DateSheetRule $rule, array $filters = []): Spreadsheet
    {
        $matrix = $this->filterDutyMatrixForExport($this->planningBoardService->facultyDutyMatrix((int) $rule->id), $filters);
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $sheetNames = [];
        $groups = array_merge(
            [['department' => 'Sheet', 'faculties' => $matrix['faculties'] ?? []]],
            $matrix['departments'] ?? []
        );

        foreach ($groups as $group) {
            $baseTitle = $group['department'] === 'Sheet' ? 'Sheet' : $this->safeSheetTitle((string) $group['department']);
            $title = $baseTitle;
            $suffix = 2;
            while (isset($sheetNames[strtolower($title)])) {
                $title = mb_substr($baseTitle, 0, 28) . ' ' . $suffix++;
            }
            $sheetNames[strtolower($title)] = true;

            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $this->populateFacultyDutySheet($sheet, $matrix, $group['faculties'] ?? [], $rule);
        }

        if ($spreadsheet->getSheetCount() === 0) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Sheet');
            ExportHeaderWriter::write($sheet, 4, ExportHeaderWriter::lines($rule, 'Duty Roster'));
            $sheet->setCellValue('A6', 'No duty roster has been generated yet.');
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function roomGridWorkbook(DateSheetRule $rule, array $slotPairs = [], bool $missingDutiesOnly = false): Spreadsheet
    {
        $grid = $this->planningBoardService->roomCourseGrid((int) $rule->id, $slotPairs, $missingDutiesOnly);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Room Course Grid');

        $maxInvigilators = collect($grid['rows'] ?? [])
            ->map(fn ($row) => count($row['invigilators'] ?? []))
            ->max() ?: 1;
        $headerRow = ExportHeaderWriter::write($sheet, max(8, 7 + $maxInvigilators), ExportHeaderWriter::lines($rule, 'Room Course Grid'));

        $headers = ['Date', 'Slot', 'Building', 'Floor', 'Room', 'Course(s)'];
        for ($i = 1; $i <= $maxInvigilators; $i++) {
            $headers[] = 'Invigilator ' . $i;
        }
        $headers[] = 'All Invigilators';

        foreach ($headers as $i => $header) {
            $sheet->setCellValue($this->cell($i + 1, $headerRow), $header);
        }

        $excelRow = $headerRow + 1;
        foreach ($grid['rows'] as $row) {
            $sheet->setCellValue($this->cell(1, $excelRow), $row['date']);
            $sheet->setCellValue($this->cell(2, $excelRow), $row['slot']);
            $sheet->setCellValue($this->cell(3, $excelRow), $row['building']);
            $sheet->setCellValue($this->cell(4, $excelRow), $row['floor']);
            $sheet->setCellValue($this->cell(5, $excelRow), $row['room']);
            $sheet->setCellValue($this->cell(6, $excelRow), $row['papers_text']);

            $names = collect($row['invigilators'] ?? [])
                ->pluck('name')
                ->filter()
                ->values();

            for ($i = 0; $i < $maxInvigilators; $i++) {
                $sheet->setCellValue($this->cell(7 + $i, $excelRow), (string) ($names[$i] ?? ''));
            }

            $sheet->setCellValue($this->cell(7 + $maxInvigilators, $excelRow), $names->isNotEmpty() ? $names->implode(', ') : 'TBD');
            $excelRow++;
        }

        $lastColumn = 7 + $maxInvigilators;
        $this->styleGrid($sheet, $headerRow, 1, max($headerRow, $excelRow - 1), $lastColumn);
        for ($column = 1; $column <= $lastColumn; $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }

        return $spreadsheet;
    }

    private function slotPairsFromKeys(array $keys): array
    {
        return collect($keys)
            ->map(function ($key) {
                [$date, $slot] = array_pad(explode('|', (string) $key, 2), 2, '');

                return [
                    'date' => trim($date),
                    'slot' => trim($slot),
                ];
            })
            ->filter(fn (array $pair) => $pair['date'] !== '' && $pair['slot'] !== '')
            ->unique(fn (array $pair) => $pair['date'] . '|' . $pair['slot'])
            ->values()
            ->all();
    }

    private function facultyAvailabilityWorkbook(DateSheetRule $rule, ?string $date, array $filters = []): Spreadsheet
    {
        $slots = SlotHelper::buildSlotsFromRule($rule);
        $matrix = is_array($rule->faculty_slot_matrix) ? $rule->faculty_slot_matrix : [];
        $slotFilter = trim((string) ($filters['slot_filter'] ?? $filters['slot'] ?? ''));
        if ($slotFilter !== '') {
            $slotKey = SlotHelper::normalizeSlotKey($slotFilter);
            $slots = array_values(array_filter($slots, fn (array $slot) => SlotHelper::normalizeSlotKey((string) ($slot['label'] ?? '')) === $slotKey));
        }
        $faculties = $this->filteredFacultyForAvailabilityExport($filters, (string) ($date ?? ''), $slots, $matrix);
        $dates = $date ? [$date] : Exam::query()
            ->where('rule_id', (int) $rule->id)
            ->select('date')
            ->distinct()
            ->orderBy('date')
            ->get()
            ->map(fn (Exam $exam) => $exam->date?->format('Y-m-d') ?: (string) $exam->date)
            ->values()
            ->all();
        if ($dates === []) {
            $dates = array_keys($matrix);
        }
        if ($dates === []) {
            $dates = [$rule->start_date?->format('Y-m-d') ?: date('Y-m-d')];
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($dates as $day) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->safeSheetTitle($day));
            $headerRow = ExportHeaderWriter::write($sheet, max(2, count($slots) + 1), ExportHeaderWriter::lines($rule, 'Faculty Availability - ' . $day));
            $sheet->setCellValue($this->cell(1, $headerRow), 'Faculty');
            foreach (array_values($slots) as $index => $slot) {
                $sheet->setCellValue($this->cell($index + 2, $headerRow), $slot['label']);
            }

            $row = $headerRow + 1;
            foreach ($faculties as $faculty) {
                $sheet->setCellValue($this->cell(1, $row), $faculty->name);
                foreach (array_values($slots) as $index => $slot) {
                    $unavailable = (bool) (($matrix[$day][(string) $faculty->id][$slot['label']] ?? false)
                        || ($matrix[$day][(string) $faculty->id][SlotHelper::normalizeSlotKey($slot['label'])] ?? false));
                    $sheet->setCellValue($this->cell($index + 2, $row), $unavailable ? 'Unavailable' : 'Available');
                }
                $row++;
            }

            $this->styleGrid($sheet, $headerRow, 1, max($headerRow, $row - 1), count($slots) + 1);
            foreach (range(1, count($slots) + 1) as $index) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function filterDutyMatrixForExport(array $matrix, array $filters): array
    {
        $columns = collect($matrix['columns'] ?? []);

        if (trim((string) ($filters['date'] ?? '')) !== '') {
            $columns = $columns->filter(fn (array $column) => (string) ($column['date'] ?? '') === (string) $filters['date']);
        }

        if (trim((string) ($filters['slot'] ?? '')) !== '') {
            $slotKey = SlotHelper::normalizeSlotKey((string) $filters['slot']);
            $columns = $columns->filter(fn (array $column) => SlotHelper::normalizeSlotKey((string) ($column['slot'] ?? '')) === $slotKey);
        }

        $columns = $columns->values();
        $columnKeys = $columns->pluck('key')->filter()->mapWithKeys(fn ($key) => [(string) $key => true])->all();

        $faculties = collect($matrix['faculties'] ?? [])
            ->map(function (array $faculty) use ($columns, $columnKeys) {
                $cells = [];
                $dailyCounts = [];
                foreach ($columns as $column) {
                    $key = (string) ($column['key'] ?? '');
                    if ($key === '' || !isset($columnKeys[$key])) {
                        continue;
                    }
                    $value = (string) (($faculty['cells'][$key] ?? '') ?: '');
                    $cells[$key] = $value;
                    if (trim($value) !== '') {
                        $date = (string) ($column['date'] ?? '');
                        if ($date !== '') {
                            $dailyCounts[$date] = ($dailyCounts[$date] ?? 0) + 1;
                        }
                    }
                }

                $multiDutyDates = collect($dailyCounts)->filter(fn ($count) => (int) $count > 1)->keys()->values()->all();
                $faculty['cells'] = $cells;
                $faculty['visible_duty_count'] = collect($cells)->filter(fn ($value) => trim((string) $value) !== '')->count();
                $faculty['multi_duty_dates'] = $multiDutyDates;
                $faculty['has_multi_duty_day'] = count($multiDutyDates) > 0;

                return $faculty;
            })
            ->filter(function (array $faculty) use ($filters) {
                $needle = mb_strtolower(trim((string) ($filters['q'] ?? '')));
                if ($needle !== '') {
                    $haystack = mb_strtolower(implode(' ', [
                        (string) ($faculty['name'] ?? ''),
                        (string) ($faculty['department'] ?? ''),
                        (string) ($faculty['designation'] ?? ''),
                    ]));
                    if (!str_contains($haystack, $needle)) {
                        return false;
                    }
                }

                if (trim((string) ($filters['department'] ?? '')) !== '' && (string) ($faculty['department'] ?? '') !== (string) $filters['department']) {
                    return false;
                }

                if (trim((string) ($filters['designation'] ?? '')) !== '' && (string) ($faculty['designation'] ?? '') !== (string) $filters['designation']) {
                    return false;
                }

                return match ((string) ($filters['duty_status'] ?? '')) {
                    'assigned', 'has_duty' => (int) ($faculty['visible_duty_count'] ?? 0) > 0,
                    'not_assigned', 'no_duty' => (int) ($faculty['visible_duty_count'] ?? 0) === 0,
                    'multi_same_day' => !empty($faculty['has_multi_duty_day']),
                    'no_multi_same_day' => empty($faculty['has_multi_duty_day']),
                    'excluded' => (int) ($faculty['max_duties'] ?? 0) === 0,
                    'active' => (int) ($faculty['max_duties'] ?? 0) > 0,
                    default => true,
                };
            })
            ->values();

        $departments = $faculties
            ->groupBy(fn (array $faculty) => trim((string) ($faculty['department'] ?? '')) !== '' ? trim((string) $faculty['department']) : 'Unassigned')
            ->map(fn ($items, $department) => [
                'department' => (string) $department,
                'faculties' => $items->values()->all(),
            ])
            ->values()
            ->all();

        $matrix['columns'] = $columns->all();
        $matrix['faculties'] = $faculties->all();
        $matrix['departments'] = $departments;

        return $matrix;
    }

    private function filteredFacultyForAvailabilityExport(array $filters, string $date, array $slots, array $matrix)
    {
        $query = Faculty::query();

        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $needle = '%' . trim((string) $filters['q']) . '%';
            $query->where(function ($builder) use ($needle) {
                $builder->where('name', 'like', $needle)
                    ->orWhere('department', 'like', $needle)
                    ->orWhere('designation', 'like', $needle);
            });
        }

        if (trim((string) ($filters['department'] ?? '')) !== '') {
            $query->where('department', (string) $filters['department']);
        }

        if (trim((string) ($filters['designation'] ?? '')) !== '') {
            $query->where('designation', (string) $filters['designation']);
        }

        match ((string) ($filters['duty_status'] ?? '')) {
            'excluded' => $query->where('max_duties', 0),
            'active' => $query->where('max_duties', '>', 0),
            'quota_available' => $query->whereColumn('assigned_duties', '<', 'max_duties')->where('max_duties', '>', 0),
            'quota_full' => $query->whereColumn('assigned_duties', '>=', 'max_duties')->where('max_duties', '>', 0),
            default => null,
        };

        $faculties = $query->orderBy('department')->orderBy('name')->get();
        $status = trim((string) ($filters['availability_status'] ?? ''));
        if ($status === '' || $date === '' || $slots === []) {
            return $faculties;
        }

        return $faculties->filter(function (Faculty $faculty) use ($status, $date, $slots, $matrix) {
            $states = [];
            foreach ($slots as $slot) {
                $label = (string) ($slot['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                $states[] = (bool) (($matrix[$date][(string) $faculty->id][$label] ?? false)
                    || ($matrix[$date][(string) $faculty->id][SlotHelper::normalizeSlotKey($label)] ?? false));
            }

            if ($states === []) {
                return true;
            }

            $unavailableCount = collect($states)->filter()->count();

            return match ($status) {
                'unavailable_any' => $unavailableCount > 0,
                'unavailable_all' => $unavailableCount === count($states),
                'available_all' => $unavailableCount === 0,
                default => true,
            };
        })->values();
    }
    private function populateFacultyDutySheet(Worksheet $sheet, array $matrix, array $faculties, DateSheetRule $rule): void
    {
        $columns = $matrix['columns'] ?? [];
        $columnCount = max(1, count($columns) + 1);
        $headerRow = ExportHeaderWriter::write($sheet, $columnCount, ExportHeaderWriter::lines($rule, 'Duty Roster'));
        $dateRow = $headerRow;
        $slotRow = $headerRow + 1;
        $firstDataRow = $headerRow + 2;

        $sheet->setCellValue($this->cell(1, $dateRow), 'Faculty');
        $sheet->setCellValue($this->cell(1, $slotRow), '');

        $col = 2;
        $dateGroups = collect($columns)->groupBy('date');
        foreach ($dateGroups as $date => $items) {
            $startCol = $col;
            foreach ($items as $item) {
                $sheet->setCellValue($this->cell($col, $slotRow), $item['slot']);
                $col++;
            }
            $endCol = $col - 1;
            if ($endCol >= $startCol) {
                $sheet->mergeCells($this->range($startCol, $dateRow, $endCol, $dateRow));
                $sheet->setCellValue($this->cell($startCol, $dateRow), $date);
            }
        }

        $row = $firstDataRow;
        foreach ($faculties as $faculty) {
            $facultyCell = $this->cell(1, $row);
            $sheet->setCellValue($facultyCell, trim(($faculty['name'] ?? '') . ' (' . (int) ($faculty['duty_count'] ?? 0) . ')'));
            $sheet->getStyle($facultyCell)->getFont()->setBold(true);
            $col = 2;
            foreach ($columns as $column) {
                $text = (string) (($faculty['cells'][$column['key']] ?? '') ?: '');
                $sheet->setCellValue($this->cell($col, $row), $this->dutyCellRichText($text));
                $col++;
            }

            if (!empty($faculty['has_multi_duty_day'])) {
                $sheet->getStyle($this->range(1, $row, $columnCount, $row))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFE1E1');
            }

            $row++;
        }

        $lastRow = max($slotRow, $row - 1);
        $this->styleGrid($sheet, $dateRow, 1, $lastRow, $columnCount);
        $sheet->freezePane('B' . $firstDataRow);
        $sheet->getStyle($this->range(1, $dateRow, $columnCount, $slotRow))->getFont()->setBold(true);
        $sheet->getStyle($this->range(1, $dateRow, $columnCount, $slotRow))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle($this->range(1, $dateRow, $columnCount, $slotRow))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE9EEF7');
        $sheet->getStyle($this->range(1, $firstDataRow, $columnCount, $lastRow))->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getColumnDimension('A')->setWidth(24);
        for ($i = 2; $i <= $columnCount; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(18);
        }
        $sheet->getRowDimension($dateRow)->setRowHeight(18);
        $sheet->getRowDimension($slotRow)->setRowHeight(18);
        for ($r = $firstDataRow; $r <= $lastRow; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(58);
        }
    }

    private function dutyCellRichText(string $text): string|RichText
    {
        if (trim($text) === '') {
            return '';
        }

        $richText = new RichText();
        $blocks = preg_split("/(\r\n|\n|\r){2,}/", $text) ?: [$text];

        foreach ($blocks as $blockIndex => $block) {
            if ($blockIndex > 0) {
                $richText->createText("\n\n");
            }

            $lines = preg_split("/\r\n|\n|\r/", (string) $block) ?: [];
            if ($lines === []) {
                continue;
            }

            $room = $richText->createTextRun((string) array_shift($lines));
            $room->getFont()->setBold(true);
            if ($lines !== []) {
                $richText->createText("\n" . implode("\n", $lines));
            }
        }

        return $richText;
    }

    private function roomString(Exam $exam, array $studentIds = [], array $allocationIds = []): string
    {
        $allocationIds = $allocationIds !== [] ? $allocationIds : $this->allocationIdsForStudentIds($exam, $studentIds);

        return $exam->allocations
            ->when($allocationIds !== [], fn ($allocations) => $allocations->whereIn('id', $allocationIds))
            ->filter(fn ($allocation) => !$allocation->is_duty_only && $allocation->room && !$allocation->room->looksLikeWaitingRoom())
            ->map(function ($allocation) {
                $label = SlotHelper::physicalRoomLabel($allocation->room);
                return $label !== '' ? $label : (string) $allocation->room->room_id;
            })
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');
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

    private function cell(int $column, int $row): string
    {
        return Coordinate::stringFromColumnIndex($column) . $row;
    }

    private function range(int $startCol, int $startRow, int $endCol, int $endRow): string
    {
        return $this->cell($startCol, $startRow) . ':' . $this->cell($endCol, $endRow);
    }

    private function dateSlotOrFirst(DateSheetRule $rule, ?string $date, ?string $slot): array
    {
        if ($date && $slot) {
            return [$date, $slot];
        }

        $pair = $rule->exams()
            ->select('date', 'time_slot')
            ->distinct()
            ->orderBy('date')
            ->orderBy('time_slot')
            ->first();

        return [
            $date ?: ($pair?->date?->format('Y-m-d') ?: ''),
            $slot ?: ((string) ($pair?->time_slot ?? '')),
        ];
    }

    private function scheduledSlotPairsForRule(DateSheetRule $rule): array
    {
        return Exam::query()
            ->where('rule_id', (int) $rule->id)
            ->select('date', 'time_slot')
            ->distinct()
            ->orderBy('date')
            ->orderBy('time_slot')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date?->format('Y-m-d') ?: (string) $row->date,
                'slot' => (string) $row->time_slot,
            ])
            ->filter(fn (array $pair) => $pair['date'] !== '' && $pair['slot'] !== '')
            ->values()
            ->all();
    }

    private function safeArchivePathPart(string $value, string $fallback): string
    {
        $safe = trim((string) preg_replace('/[^0-9A-Za-z._-]+/', '_', $value), '._-');

        return mb_substr($safe !== '' ? $safe : $fallback, 0, 120);
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    private function styleGrid(Worksheet $sheet, int $startRow, int $startCol, int $endRow, int $endCol): void
    {
        if ($endRow < $startRow || $endCol < $startCol) {
            return;
        }

        $range = $this->range($startCol, $startRow, $endCol, $endRow);
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($range)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle($this->range($startCol, $startRow, $endCol, $startRow))->getFont()->setBold(true);
    }

    private function safeSheetTitle(string $value): string
    {
        $title = trim((string) preg_replace('#[\\\\/\?\*\[\]\:]+#', ' ', $value));
        $title = $title !== '' ? $title : 'Sheet';

        return mb_substr($title, 0, 31);
    }
}
