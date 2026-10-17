<?php

namespace App\Console\Commands;

use App\Services\Scheduler\AttendanceWorkbookImportService;
use Illuminate\Console\Command;

class ImportAttendanceWorkbookCommand extends Command
{
    protected $signature = 'scheduler:import-attendance-workbook {rule} {path} {--source=attendance_workbook}';

    protected $description = 'Import the student-course workbook into a specific exam session and create registrations plus teacher-course mappings.';

    public function handle(AttendanceWorkbookImportService $service): int
    {
        $stats = $service->import(
            (int) $this->argument('rule'),
            (string) $this->argument('path'),
            (string) $this->option('source')
        );

        $this->info('Student-course workbook import completed.');
        foreach ($stats as $label => $value) {
            $this->line(" - {$label}: {$value}");
        }

        return self::SUCCESS;
    }
}
