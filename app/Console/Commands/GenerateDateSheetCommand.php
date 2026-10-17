<?php

namespace App\Console\Commands;

use App\Services\Scheduler\DateSheetGenerationService;
use Illuminate\Console\Command;

class GenerateDateSheetCommand extends Command
{
    protected $signature = 'scheduler:generate-datesheet {--rule=1} {--clear-existing=1} {--source=generated_datesheet}';

    protected $description = 'Generate a complete datesheet for one exam session from imported registrations and scheduling rules.';

    public function handle(DateSheetGenerationService $service): int
    {
        $result = $service->generate(
            (int) $this->option('rule'),
            (bool) ((int) $this->option('clear-existing')),
            (string) $this->option('source')
        );

        $this->info('Date sheet generation completed.');
        $this->line('Scheduled exams: ' . $result['scheduled_exams']);
        $this->line('Scheduled clusters: ' . $result['scheduled_clusters']);
        $this->line('Unscheduled clusters: ' . count($result['unscheduled_clusters']));

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
