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
            if (!Schema::hasColumn('scheduler_datesheetrule', 'single_duty_per_faculty_per_day')) {
                $table->boolean('single_duty_per_faculty_per_day')
                    ->default(false)
                    ->after('prevent_cross_building_for_same_class');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('scheduler_datesheetrule')) {
            return;
        }

        Schema::table('scheduler_datesheetrule', function (Blueprint $table) {
            if (Schema::hasColumn('scheduler_datesheetrule', 'single_duty_per_faculty_per_day')) {
                $table->dropColumn('single_duty_per_faculty_per_day');
            }
        });
    }
};
