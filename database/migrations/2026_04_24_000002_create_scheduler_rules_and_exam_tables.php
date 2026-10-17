<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduler_datesheetrule', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('session_code', 20)->default('');
            $table->string('session_label', 50)->default('');
            $table->string('exam_title', 200)->default('');
            $table->string('exam_type', 10)->default('FIN');
            $table->unsignedInteger('midterm_default_minutes')->default(90);
            $table->unsignedInteger('terminal_default_minutes')->default(180);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('off_days_overall')->nullable();
            $table->text('off_days_per_class')->nullable();
            $table->time('day_start_time')->default('08:30:00');
            $table->time('day_end_time')->default('16:30:00');
            $table->json('slots')->nullable();
            $table->unsignedInteger('slots_per_day')->default(0);
            $table->unsignedInteger('slot_break_minutes')->default(0);
            $table->json('slot_off_matrix')->nullable();
            $table->json('faculty_slot_matrix')->nullable();
            $table->boolean('schedule_same_course_code_together')->default(true);
            $table->boolean('allow_same_day_student_conflicts')->default(false);
            $table->unsignedInteger('same_day_student_conflict_limit')->default(0);
            $table->boolean('balance_daily_strength')->default(true);
            $table->boolean('exclude_weekends')->default(false);
            $table->json('weekend_days')->nullable();
            $table->boolean('split_rooms')->default(true);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('scheduler_exam', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('rule_id');
            $table->date('date');
            $table->string('time_slot', 50);
            $table->unsignedInteger('strength')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('class_label', 100)->default('');
            $table->string('program_code', 50)->default('');
            $table->unsignedInteger('semester_no')->nullable();
            $table->string('instructor', 200)->default('');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('cluster_key', 120)->default('');
            $table->string('source_tag', 100)->default('');

            $table->foreign('rule_id')->references('id')->on('scheduler_datesheetrule')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('scheduler_course')->nullOnDelete();
            $table->index(['rule_id', 'date', 'time_slot'], 'scheduler_exam_slot_idx');
            $table->index(['rule_id', 'course_id', 'class_label'], 'scheduler_exam_course_class_idx');
        });

        Schema::create('scheduler_roomallocation', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('exam_id');
            $table->unsignedBigInteger('invigilator_id')->nullable();
            $table->unsignedBigInteger('room_id');
            $table->unsignedInteger('assigned_strength')->default(0);
            $table->string('subslot_label', 10)->nullable();
            $table->boolean('is_duty_only')->default(false);

            $table->foreign('exam_id')->references('id')->on('scheduler_exam')->cascadeOnDelete();
            $table->foreign('invigilator_id')->references('id')->on('scheduler_faculty')->nullOnDelete();
            $table->foreign('room_id')->references('id')->on('scheduler_room')->cascadeOnDelete();
            $table->index(['exam_id', 'room_id', 'subslot_label'], 'scheduler_roomallocation_exam_room_idx');
        });

        Schema::create('scheduler_seatingassignment', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('seat_row');
            $table->unsignedInteger('seat_col');
            $table->unsignedBigInteger('allocation_id');
            $table->unsignedBigInteger('student_id');

            $table->foreign('allocation_id')->references('id')->on('scheduler_roomallocation')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('scheduler_student')->cascadeOnDelete();
            $table->unique(['allocation_id', 'seat_row', 'seat_col'], 'scheduler_seating_unique_position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_seatingassignment');
        Schema::dropIfExists('scheduler_roomallocation');
        Schema::dropIfExists('scheduler_exam');
        Schema::dropIfExists('scheduler_datesheetrule');
    }
};
