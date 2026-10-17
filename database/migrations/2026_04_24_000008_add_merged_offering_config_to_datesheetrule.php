<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('scheduler_datesheetrule')) {
            return;
        }

        Schema::table('scheduler_datesheetrule', function (Blueprint $table) {
            if (!Schema::hasColumn('scheduler_datesheetrule', 'merge_same_course_program_instructor')) {
                $table->boolean('merge_same_course_program_instructor')
                    ->default(false)
                    ->after('schedule_same_course_code_together');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('scheduler_datesheetrule')) {
            return;
        }

        Schema::table('scheduler_datesheetrule', function (Blueprint $table) {
            if (Schema::hasColumn('scheduler_datesheetrule', 'merge_same_course_program_instructor')) {
                $table->dropColumn('merge_same_course_program_instructor');
            }
        });
    }
};
