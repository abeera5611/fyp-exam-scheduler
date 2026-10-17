<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('scheduler_department')) {
            Schema::create('scheduler_department', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 200)->unique();
            });
        }

        if (!Schema::hasTable('scheduler_designation')) {
            Schema::create('scheduler_designation', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 200)->unique();
            });
        }

        if (Schema::hasTable('scheduler_faculty')) {
            $departments = DB::table('scheduler_faculty')
                ->whereNotNull('department')
                ->where('department', '<>', '')
                ->distinct()
                ->pluck('department')
                ->all();

            foreach ($departments as $department) {
                DB::table('scheduler_department')->updateOrInsert(['name' => trim((string) $department)]);
            }

            $designations = DB::table('scheduler_faculty')
                ->whereNotNull('designation')
                ->where('designation', '<>', '')
                ->distinct()
                ->pluck('designation')
                ->all();

            foreach ($designations as $designation) {
                DB::table('scheduler_designation')->updateOrInsert(['name' => trim((string) $designation)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_designation');
    }
};
