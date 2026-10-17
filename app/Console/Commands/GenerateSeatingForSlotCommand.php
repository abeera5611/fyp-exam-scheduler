<?php

namespace App\Console\Commands;

use App\Services\Scheduler\SeatingGenerationService;
use Illuminate\Console\Command;

class GenerateSeatingForSlotCommand extends Command
{
    protected $signature = 'scheduler:generate-seating {rule} {date} {slot}';

    protected $description = 'Generate seat assignments for one exam slot inside a specific exam session.';

    public function handle(SeatingGenerationService $service): int
    {
        [$count, $warnings] = $service->generateForSlot(
            (int) $this->argument('rule'),
            (string) $this->argument('date'),
            (string) $this->argument('slot')
        );

        $this->info("Seating assignments created: {$count}");
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
