<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scheduler_exam_student')) {
            return;
        }

        Schema::create('scheduler_exam_student', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('exam_id');
            $table->unsignedBigInteger('student_id');
            $table->string('source_class_label', 100)->default('');

            $table->foreign('exam_id')->references('id')->on('scheduler_exam')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('scheduler_student')->cascadeOnDelete();
            $table->unique(['exam_id', 'student_id'], 'scheduler_exam_student_unique_pair');
            $table->index(['student_id', 'exam_id'], 'scheduler_exam_student_student_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_exam_student');
    }
};
