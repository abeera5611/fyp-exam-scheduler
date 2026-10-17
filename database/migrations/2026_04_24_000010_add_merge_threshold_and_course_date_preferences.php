<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scheduler_datesheetrule')) {
            Schema::table('scheduler_datesheetrule', function (Blueprint $table) {
                if (!Schema::hasColumn('scheduler_datesheetrule', 'merge_same_course_strength_threshold')) {
                    $table->unsignedInteger('merge_same_course_strength_threshold')
                        ->default(0)
                        ->after('merge_same_course_program_instructor');
                }

                if (!Schema::hasColumn('scheduler_datesheetrule', 'course_date_preferences')) {
                    $table->text('course_date_preferences')
                        ->nullable()
                        ->after('course_slot_preferences');
                }

                if (!Schema::hasColumn('scheduler_datesheetrule', 'enforce_course_date_preferences')) {
                    $table->boolean('enforce_course_date_preferences')
                        ->default(true)
                        ->after('course_date_preferences');
                }
            });
        }

        if (Schema::hasTable('scheduler_exam') && !Schema::hasColumn('scheduler_exam', 'merged_class_labels')) {
            Schema::table('scheduler_exam', function (Blueprint $table) {
                $table->text('merged_class_labels')->nullable()->after('class_label');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduler_exam') && Schema::hasColumn('scheduler_exam', 'merged_class_labels')) {
            Schema::table('scheduler_exam', function (Blueprint $table) {
                $table->dropColumn('merged_class_labels');
            });
        }

        if (Schema::hasTable('scheduler_datesheetrule')) {
            Schema::table('scheduler_datesheetrule', function (Blueprint $table) {
                if (Schema::hasColumn('scheduler_datesheetrule', 'enforce_course_date_preferences')) {
                    $table->dropColumn('enforce_course_date_preferences');
                }

                if (Schema::hasColumn('scheduler_datesheetrule', 'course_date_preferences')) {
                    $table->dropColumn('course_date_preferences');
                }

                if (Schema::hasColumn('scheduler_datesheetrule', 'merge_same_course_strength_threshold')) {
                    $table->dropColumn('merge_same_course_strength_threshold');
                }
            });
        }
    }
};
