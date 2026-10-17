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
            if (!Schema::hasColumn('scheduler_datesheetrule', 'ignored_course_offerings')) {
                $table->text('ignored_course_offerings')
                    ->nullable()
                    ->after('manual_course_merge_groups');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('scheduler_datesheetrule') || !Schema::hasColumn('scheduler_datesheetrule', 'ignored_course_offerings')) {
            return;
        }

        Schema::table('scheduler_datesheetrule', function (Blueprint $table) {
            $table->dropColumn('ignored_course_offerings');
        });
    }
};
