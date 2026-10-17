<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduler_department', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 200)->unique();
        });

        Schema::create('scheduler_designation', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 200)->unique();
        });

        Schema::create('scheduler_student', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 200)->default('');
            $table->string('registration_id', 100)->unique();
            $table->string('session', 20)->default('');
            $table->string('intake_code', 20)->default('');
            $table->string('program_code', 20)->default('');
            $table->string('campus_code', 20)->default('');
        });

        Schema::create('scheduler_faculty', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 200);
            $table->string('department', 200)->default('');
            $table->string('designation', 200)->default('');
            $table->unsignedInteger('max_duties')->default(5);
            $table->unsignedInteger('assigned_duties')->default(0);
        });

        Schema::create('scheduler_room', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('room_id', 50)->unique();
            $table->string('room_name', 100);
            $table->unsignedInteger('capacity')->default(0);
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedInteger('cols')->default(0);
            $table->unsignedInteger('invigilators_required')->default(1);
            $table->string('building', 100)->default('');
            $table->boolean('contingency_only')->default(false);
            $table->string('floor', 50)->default('');
            $table->boolean('allow_split')->default(true);
        });

        Schema::create('scheduler_course', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->unique();
            $table->string('title', 200);
        });

        Schema::create('scheduler_course_students', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('student_id');

            $table->foreign('course_id')->references('id')->on('scheduler_course')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('scheduler_student')->cascadeOnDelete();
            $table->unique(['course_id', 'student_id'], 'scheduler_course_students_unique_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_course_students');
        Schema::dropIfExists('scheduler_course');
        Schema::dropIfExists('scheduler_room');
        Schema::dropIfExists('scheduler_faculty');
        Schema::dropIfExists('scheduler_student');
        Schema::dropIfExists('scheduler_designation');
        Schema::dropIfExists('scheduler_department');
    }
};
