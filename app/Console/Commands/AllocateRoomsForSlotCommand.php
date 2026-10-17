<?php

namespace App\Console\Commands;

use App\Services\Scheduler\RoomAllocationService;
use Illuminate\Console\Command;

class AllocateRoomsForSlotCommand extends Command
{
    protected $signature = 'scheduler:allocate-rooms {rule} {date} {slot}';

    protected $description = 'Allocate rooms for one exam slot inside a specific exam session.';

    public function handle(RoomAllocationService $service): int
    {
        [$count, $warnings] = $service->allocateForSlot(
            (int) $this->argument('rule'),
            (string) $this->argument('date'),
            (string) $this->argument('slot')
        );

        $this->info("Room allocations created: {$count}");
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
