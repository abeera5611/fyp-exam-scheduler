<?php

namespace App\Services\Scheduler;

use App\Models\Scheduler\CourseFacultyAssignment;
use App\Models\Scheduler\CourseRegistration;
use App\Models\Scheduler\Exam;
use App\Models\Scheduler\ExamStudent;
use App\Models\Scheduler\RoomAllocation;
use App\Models\Scheduler\SeatingAssignment;
use App\Models\Scheduler\Student;
use Illuminate\Support\Facades\DB;

class SchedulerDataResetService
{
    public function clearGeneratedForRule(int $ruleId): array
    {
        $examIds = Exam::query()->where('rule_id', $ruleId)->pluck('id')->all();
        $allocationIds = [];
        $deletedSeats = 0;
        $deletedAllocations = 0;
        $deletedExamStudents = 0;
        $deletedExams = 0;

        if ($examIds !== []) {
            $allocationIds = RoomAllocation::query()->whereIn('exam_id', $examIds)->pluck('id')->all();

            if ($allocationIds !== []) {
                $deletedSeats = SeatingAssignment::query()->whereIn('allocation_id', $allocationIds)->delete();
            }

            $deletedAllocations = RoomAllocation::query()->whereIn('exam_id', $examIds)->delete();
            $deletedExamStudents = ExamStudent::query()->whereIn('exam_id', $examIds)->delete();
            $deletedExams = Exam::query()->whereIn('id', $examIds)->delete();
        }

        return [
            'seats_deleted' => $deletedSeats,
            'room_allocations_deleted' => $deletedAllocations,
            'exam_students_deleted' => $deletedExamStudents,
            'exams_deleted' => $deletedExams,
        ];
    }

    public function clearStudentDataForRule(int $ruleId, bool $deleteOrphanStudents = true): array
    {
        return DB::transaction(function () use ($ruleId, $deleteOrphanStudents) {
            $stats = $this->clearGeneratedForRule($ruleId);

            $studentIds = CourseRegistration::query()
                ->where('rule_id', $ruleId)
                ->pluck('student_id')
                ->unique()
                ->values()
                ->all();

            $stats['registrations_deleted'] = CourseRegistration::query()->where('rule_id', $ruleId)->delete();
            $stats['faculty_assignments_deleted'] = CourseFacultyAssignment::query()->where('rule_id', $ruleId)->delete();
            $stats['students_deleted'] = 0;

            app(PivotSyncService::class)->syncCourseStudentsFromRegistrations();

            if ($deleteOrphanStudents && $studentIds !== []) {
                $stats['students_deleted'] = Student::query()
                    ->whereIn('id', $studentIds)
                    ->whereDoesntHave('registrations')
                    ->delete();
            }

            return $stats;
        });
    }

    public function clearAllStudentData(): array
    {
        return DB::transaction(function () {
            $stats = [
                'seats_deleted' => SeatingAssignment::query()->delete(),
                'room_allocations_deleted' => RoomAllocation::query()->delete(),
                'exam_students_deleted' => ExamStudent::query()->delete(),
                'exams_deleted' => Exam::query()->delete(),
                'registrations_deleted' => CourseRegistration::query()->delete(),
                'faculty_assignments_deleted' => CourseFacultyAssignment::query()->delete(),
            ];

            DB::table('scheduler_course_students')->delete();
            $stats['students_deleted'] = Student::query()->delete();

            return $stats;
        });
    }
}
