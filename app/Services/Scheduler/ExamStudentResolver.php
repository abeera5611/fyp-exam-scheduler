<?php

namespace App\Services\Scheduler;

use App\Models\Scheduler\CourseRegistration;
use App\Models\Scheduler\Exam;
use App\Models\Scheduler\ExamStudent;
use App\Models\Scheduler\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ExamStudentResolver
{
    public function studentsForExam(Exam $exam): Collection
    {
        $membershipStudents = $this->studentsFromExamMembership($exam);
        if ($membershipStudents !== null) {
            return $membershipStudents;
        }

        if (!$exam->course_id) {
            return collect();
        }

        $registrationQuery = CourseRegistration::query()
            ->where('rule_id', $exam->rule_id)
            ->where('course_id', $exam->course_id);

        $rule = $exam->rule;
        $mergedClassLabels = $this->mergedClassLabelsForExam($exam);
        $isMergedCourseProgramInstructor = $rule
            && $rule->shouldMergeSameCourseProgramInstructor()
            && $mergedClassLabels !== [];

        if ($isMergedCourseProgramInstructor) {
            // Merged offerings intentionally keep the largest class label for
            // display. Use the saved merged class-label set so threshold-based
            // merges do not accidentally pull in other large sections that
            // remained separate.
            $registrationQuery->whereIn('class_label', $mergedClassLabels);
            if ((string) $exam->program_code !== '') {
                $registrationQuery->where('program_code', (string) $exam->program_code);
            }
        } elseif ((string) $exam->class_label !== '') {
            $registrationQuery->where('class_label', (string) $exam->class_label);
        } elseif ((string) $exam->program_code !== '') {
            $registrationQuery->where('program_code', (string) $exam->program_code);
        }

        if ((string) $exam->instructor !== '') {
            $registrationQuery->where('instructor_name', (string) $exam->instructor);
        }

        $studentIds = $registrationQuery
            ->orderBy('student_id')
            ->pluck('student_id')
            ->unique()
            ->values();

        if ($studentIds->isNotEmpty()) {
            $query = Student::query()
                ->whereIn('id', $studentIds->all())
                ->orderBy('registration_id');

            $students = $query->get();

            if ($exam->strength !== null && $students->count() > (int) $exam->strength) {
                return $students->take((int) $exam->strength)->values();
            }

            return $students->values();
        }

        $fallback = $exam->course
            ? $exam->course->students()->orderBy('registration_id')->get()
            : collect();

        if ($exam->strength !== null && $fallback->count() > (int) $exam->strength) {
            return $fallback->take((int) $exam->strength)->values();
        }

        return $fallback->values();
    }

    private function studentsFromExamMembership(Exam $exam): ?Collection
    {
        if (!$exam->exists || !Schema::hasTable('scheduler_exam_student')) {
            return null;
        }

        $studentIds = ExamStudent::query()
            ->where('exam_id', (int) $exam->id)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($studentIds === []) {
            return null;
        }

        return Student::query()
            ->whereIn('id', $studentIds)
            ->orderBy('registration_id')
            ->get()
            ->values();
    }

    private function mergedClassLabelsForExam(Exam $exam): array
    {
        $raw = trim((string) ($exam->merged_class_labels ?? ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($label) => trim((string) $label),
            preg_split('/[|;,]+/', $raw) ?: []
        )));
    }

    public function strengthForExam(Exam $exam): int
    {
        if ($exam->strength !== null && (int) $exam->strength > 0) {
            return (int) $exam->strength;
        }

        return $this->studentsForExam($exam)->count();
    }
}
