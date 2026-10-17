<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduler_course_faculty_assignment', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('rule_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('faculty_id')->nullable();
            $table->string('instructor_name', 200);
            $table->string('class_label', 100)->default('');
            $table->string('program_code', 50)->default('');
            $table->unsignedInteger('semester_no')->nullable();
            $table->string('source_tag', 100)->default('');
            $table->timestamps();

            $table->foreign('rule_id')->references('id')->on('scheduler_datesheetrule')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('scheduler_course')->cascadeOnDelete();
            $table->foreign('faculty_id')->references('id')->on('scheduler_faculty')->nullOnDelete();
            $table->unique(
                ['rule_id', 'course_id', 'instructor_name', 'class_label', 'program_code'],
                'scheduler_course_faculty_assignment_unique'
            );
        });

        Schema::create('scheduler_course_registration', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('rule_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_id');
            $table->string('class_label', 100)->default('');
            $table->string('program_code', 50)->default('');
            $table->unsignedInteger('semester_no')->nullable();
            $table->string('instructor_name', 200)->default('');
            $table->string('source_tag', 100)->default('');
            $table->timestamps();

            $table->foreign('rule_id')->references('id')->on('scheduler_datesheetrule')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('scheduler_student')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('scheduler_course')->cascadeOnDelete();
            $table->index(['rule_id', 'course_id', 'class_label', 'program_code'], 'scheduler_course_registration_group_idx');
            $table->unique(
                ['rule_id', 'student_id', 'course_id', 'class_label', 'program_code', 'instructor_name'],
                'scheduler_course_registration_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_course_registration');
        Schema::dropIfExists('scheduler_course_faculty_assignment');
    }
};
