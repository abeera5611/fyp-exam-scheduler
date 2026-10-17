<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scheduler_datesheetrule') && !Schema::hasColumn('scheduler_datesheetrule', 'same_course_together_codes')) {
            Schema::table('scheduler_datesheetrule', function (Blueprint $table) {
                $table->text('same_course_together_codes')->nullable()->after('course_slot_preferences');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduler_datesheetrule') && Schema::hasColumn('scheduler_datesheetrule', 'same_course_together_codes')) {
            Schema::table('scheduler_datesheetrule', function (Blueprint $table) {
                $table->dropColumn('same_course_together_codes');
            });
        }
    }
};
