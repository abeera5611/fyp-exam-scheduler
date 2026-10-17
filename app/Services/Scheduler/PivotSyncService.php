<?php

namespace App\Services\Scheduler;

use Illuminate\Support\Facades\DB;

class PivotSyncService
{
    public function syncCourseStudentsFromRegistrations(): void
    {
        DB::statement('DELETE FROM scheduler_course_students');

        DB::table('scheduler_course_students')->insertUsing(
            ['course_id', 'student_id'],
            DB::table('scheduler_course_registration')
                ->select('course_id', 'student_id')
                ->distinct()
        );
    }
}
