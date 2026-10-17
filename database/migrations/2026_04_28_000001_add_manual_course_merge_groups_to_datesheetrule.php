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
            if (!Schema::hasColumn('scheduler_datesheetrule', 'manual_course_merge_groups')) {
                $table->text('manual_course_merge_groups')
                    ->nullable()
                    ->after('same_course_together_codes');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('scheduler_datesheetrule') || !Schema::hasColumn('scheduler_datesheetrule', 'manual_course_merge_groups')) {
            return;
        }

        Schema::table('scheduler_datesheetrule', function (Blueprint $table) {
            $table->dropColumn('manual_course_merge_groups');
        });
    }
};
