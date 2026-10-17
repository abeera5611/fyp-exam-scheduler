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
            if (!Schema::hasColumn('scheduler_datesheetrule', 'same_day_student_conflict_limit')) {
                $table->unsignedInteger('same_day_student_conflict_limit')
                    ->default(0)
                    ->after('allow_same_day_student_conflicts');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('scheduler_datesheetrule') || !Schema::hasColumn('scheduler_datesheetrule', 'same_day_student_conflict_limit')) {
            return;
        }

        Schema::table('scheduler_datesheetrule', function (Blueprint $table) {
            $table->dropColumn('same_day_student_conflict_limit');
        });
    }
};
