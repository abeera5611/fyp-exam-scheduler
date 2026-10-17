<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduler_room', function (Blueprint $table) {
            if (!Schema::hasColumn('scheduler_room', 'is_waiting_room')) {
                $table->boolean('is_waiting_room')->default(false)->after('allow_split');
            }
        });

        Schema::table('scheduler_datesheetrule', function (Blueprint $table) {
            if (!Schema::hasColumn('scheduler_datesheetrule', 'course_slot_preferences')) {
                $table->text('course_slot_preferences')->nullable()->after('off_days_per_class');
            }
        });
    }

    public function down(): void
    {
        Schema::table('scheduler_datesheetrule', function (Blueprint $table) {
            if (Schema::hasColumn('scheduler_datesheetrule', 'course_slot_preferences')) {
                $table->dropColumn('course_slot_preferences');
            }
        });

        Schema::table('scheduler_room', function (Blueprint $table) {
            if (Schema::hasColumn('scheduler_room', 'is_waiting_room')) {
                $table->dropColumn('is_waiting_room');
            }
        });
    }
};
