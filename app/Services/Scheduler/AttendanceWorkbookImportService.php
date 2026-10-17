<?php

namespace App\Services\Scheduler;

use App\Models\Scheduler\Course;
use App\Models\Scheduler\CourseFacultyAssignment;
use App\Models\Scheduler\CourseRegistration;
use App\Models\Scheduler\DateSheetRule;
use App\Models\Scheduler\Faculty;
use App\Models\Scheduler\Student;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AttendanceWorkbookImportService
{
    public function import(int $ruleId, string $path, string $sourceTag = 'attendance_workbook', bool $replaceExisting = false): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Workbook not found: {$path}");
        }

        $rule = DateSheetRule::query()->findOrFail($ruleId);
        $spreadsheet = IOFactory::load($path);
        $facultyMap = Faculty::query()->get()->mapWithKeys(function ($faculty) {
            return [SlotHelper::normalizePersonName($faculty->name) => $faculty->id];
        })->all();

        $stats = [
            'cleared_students' => 0,
            'cleared_registrations' => 0,
            'cleared_generated_exams' => 0,
            'students_created' => 0,
            'students_updated' => 0,
            'courses_created' => 0,
            'registrations_inserted' => 0,
            'faculty_assignments_inserted' => 0,
        ];

        DB::beginTransaction();

        try {
            if ($replaceExisting) {
                $clearStats = app(SchedulerDataResetService::class)->clearStudentDataForRule($ruleId, true);
                $stats['cleared_students'] = (int) ($clearStats['students_deleted'] ?? 0);
                $stats['cleared_registrations'] = (int) ($clearStats['registrations_deleted'] ?? 0);
                $stats['cleared_generated_exams'] = (int) ($clearStats['exams_deleted'] ?? 0);
            }

            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                $currentStudent = null;

                foreach ($sheet->toArray(null, false, false, false) as $row) {
                    $colA = trim((string) ($row[0] ?? ''));
                    $colB = trim((string) ($row[1] ?? ''));
                    $colC = trim((string) ($row[2] ?? ''));
                    $colH = trim((string) ($row[7] ?? ''));

                    if ($this->isBlankRow($row)) {
                        continue;
                    }

                    if ($this->isStudentHeaderRow($colB)) {
                        [$student, $created, $updated] = $this->upsertStudent($colB, $colC);
                        $currentStudent = $student;
                        $stats['students_created'] += $created ? 1 : 0;
                        $stats['students_updated'] += $updated ? 1 : 0;
                        continue;
                    }

                    if ($currentStudent === null || $this->isSeparatorRow($colA, $colB)) {
                        continue;
                    }

                    if ($colB === '' || !$this->looksLikeCourseRow($colB, $colC)) {
                        continue;
                    }

                    [$code, $title] = $this->parseCourseTokens($colB);
                    if ($code === '') {
                        continue;
                    }

                    $course = Course::query()->where('code', $code)->first();
                    if (!$course) {
                        $course = Course::query()->create([
                            'code' => $code,
                            'title' => $title !== '' ? $title : $colB,
                        ]);
                        $stats['courses_created']++;
                    } elseif ($title !== '' && mb_strlen((string) $title) > mb_strlen((string) $course->title)) {
                        $course->title = $title;
                        $course->save();
                    }

                    $programCode = $colH !== '' ? strtoupper($colH) : (string) ($currentStudent->program_code ?: SlotHelper::parseProgramFromRegistration($currentStudent->registration_id));
                    $intakeCode = (string) ($currentStudent->intake_code ?: SlotHelper::parseRegistrationSession($currentStudent->registration_id));
                    $semesterNo = SlotHelper::semesterNumber($intakeCode, (string) $rule->session_code);
                    $classLabel = SlotHelper::classLabel($programCode, $semesterNo);
                    $instructorName = $colC;

                    $registration = CourseRegistration::query()->firstOrCreate([
                        'rule_id' => $rule->id,
                        'student_id' => $currentStudent->id,
                        'course_id' => $course->id,
                        'class_label' => $classLabel,
                        'program_code' => $programCode,
                        'semester_no' => $semesterNo,
                        'instructor_name' => $instructorName,
                    ], [
                        'source_tag' => $sourceTag,
                    ]);

                    if ($registration->wasRecentlyCreated) {
                        $stats['registrations_inserted']++;
                    }

                    if ($instructorName !== '') {
                        $facultyId = $facultyMap[SlotHelper::normalizePersonName($instructorName)] ?? null;

                        $assignment = CourseFacultyAssignment::query()->firstOrCreate([
                            'rule_id' => $rule->id,
                            'course_id' => $course->id,
                            'instructor_name' => $instructorName,
                            'class_label' => $classLabel,
                            'program_code' => $programCode,
                            'semester_no' => $semesterNo,
                        ], [
                            'faculty_id' => $facultyId,
                            'source_tag' => $sourceTag,
                        ]);

                        if ($assignment->wasRecentlyCreated) {
                            $stats['faculty_assignments_inserted']++;
                        }
                    }
                }
            }

            app(PivotSyncService::class)->syncCourseStudentsFromRegistrations();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $stats;
    }

    private function upsertStudent(string $registrationId, string $name): array
    {
        $parts = SlotHelper::parseRegistrationParts($registrationId);

        $student = Student::query()->where('registration_id', $registrationId)->first();
        $created = false;
        $updated = false;

        if (!$student) {
            $student = Student::query()->create([
                'registration_id' => $registrationId,
                'name' => $name,
                'session' => SlotHelper::parseRegistrationSession($registrationId),
                'intake_code' => $parts['intake_code'],
                'program_code' => $parts['program_code'],
                'campus_code' => $parts['campus_code'],
            ]);
            $created = true;
        } else {
            $changes = [];
            if ($name !== '' && trim((string) $student->name) === '') {
                $changes['name'] = $name;
            }
            if (($parts['intake_code'] ?? '') !== '' && trim((string) $student->intake_code) === '') {
                $changes['intake_code'] = $parts['intake_code'];
            }
            if (($parts['program_code'] ?? '') !== '' && trim((string) $student->program_code) === '') {
                $changes['program_code'] = $parts['program_code'];
            }
            if (($parts['campus_code'] ?? '') !== '' && trim((string) $student->campus_code) === '') {
                $changes['campus_code'] = $parts['campus_code'];
            }
            $session = SlotHelper::parseRegistrationSession($registrationId);
            if ($session !== '' && trim((string) $student->session) === '') {
                $changes['session'] = $session;
            }
            if ($changes !== []) {
                $student->fill($changes)->save();
                $updated = true;
            }
        }

        return [$student, $created, $updated];
    }

    private function isStudentHeaderRow(string $value): bool
    {
        return (bool) preg_match('/^(?:CIIT|COMSATS)\/.+\/[A-Z]+$/i', $value);
    }

    private function looksLikeCourseRow(string $courseToken, string $teacher): bool
    {
        if ($teacher === '') {
            return false;
        }

        if (str_starts_with($courseToken, 'Date:')) {
            return false;
        }

        return (bool) preg_match('/^[A-Z]{2,}\d{2,}/i', $courseToken);
    }

    private function isSeparatorRow(string $colA, string $colB): bool
    {
        return str_contains($colA, '____') || str_contains($colB, '____');
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function parseCourseTokens(string $raw): array
    {
        $raw = trim($raw);

        if (!preg_match('/^([A-Z]{2,}\d{2,}[A-Z0-9]*)\s*(.*)$/i', $raw, $matches)) {
            return ['', $raw];
        }

        $code = strtoupper(trim($matches[1]));
        $title = trim($matches[2]);

        return [$code, $title];
    }
}
