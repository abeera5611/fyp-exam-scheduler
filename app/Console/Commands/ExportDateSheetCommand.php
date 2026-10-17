<?php

namespace App\Console\Commands;

use App\Services\Scheduler\DateSheetExportService;
use Illuminate\Console\Command;

class ExportDateSheetCommand extends Command
{
    protected $signature = 'scheduler:export-datesheet {--rule=1} {--format=terminal} {--output=}';

    protected $description = 'Export the current datesheet of one exam session to an XLSX file.';

    public function handle(DateSheetExportService $service): int
    {
        $ruleId = (int) $this->option('rule');
        $format = (string) $this->option('format');
        $output = (string) $this->option('output');

        if ($output === '') {
            $output = storage_path('app/scheduler/exports/' . strtolower($format) . '_datesheet_rule_' . $ruleId . '.xlsx');
        }

        $path = $service->export($ruleId, $format, $output);

        $this->info("Date sheet exported to {$path}");

        return self::SUCCESS;
    }
}
