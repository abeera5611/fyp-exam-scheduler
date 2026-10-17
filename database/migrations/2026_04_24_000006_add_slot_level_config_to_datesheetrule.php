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
            if (!Schema::hasColumn('scheduler_datesheetrule', 'pg_course_codes')) {
                $table->text('pg_course_codes')->nullable()->after('same_course_together_codes');
            }
            if (!Schema::hasColumn('scheduler_datesheetrule', 'pg_slot_preference')) {
                $table->string('pg_slot_preference', 50)->default('EVENING')->after('pg_course_codes');
            }
            if (!Schema::hasColumn('scheduler_datesheetrule', 'schedule_pg_courses_in_preferred_slot')) {
                $table->boolean('schedule_pg_courses_in_preferred_slot')->default(true)->after('pg_slot_preference');
            }
            if (!Schema::hasColumn('scheduler_datesheetrule', 'exclude_pg_preferred_slot_for_bs')) {
                $table->boolean('exclude_pg_preferred_slot_for_bs')->default(true)->after('schedule_pg_courses_in_preferred_slot');
            }
            if (!Schema::hasColumn('scheduler_datesheetrule', 'pg_program_prefixes')) {
                $table->string('pg_program_prefixes', 100)->default('P,R')->after('exclude_pg_preferred_slot_for_bs');
            }
            if (!Schema::hasColumn('scheduler_datesheetrule', 'pg_course_level_min')) {
                $table->unsignedInteger('pg_course_level_min')->default(700)->after('pg_program_prefixes');
            }
            if (!Schema::hasColumn('scheduler_datesheetrule', 'prevent_cross_building_for_same_class')) {
                $table->boolean('prevent_cross_building_for_same_class')->default(true)->after('pg_course_level_min');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('scheduler_datesheetrule')) {
            return;
        }

        Schema::table('scheduler_datesheetrule', function (Blueprint $table) {
            foreach ([
                'prevent_cross_building_for_same_class',
                'pg_course_level_min',
                'pg_program_prefixes',
                'exclude_pg_preferred_slot_for_bs',
                'schedule_pg_courses_in_preferred_slot',
                'pg_slot_preference',
                'pg_course_codes',
            ] as $column) {
                if (Schema::hasColumn('scheduler_datesheetrule', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
