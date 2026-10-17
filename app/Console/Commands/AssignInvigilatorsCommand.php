<?php

namespace App\Console\Commands;

use App\Services\Scheduler\DutyRosterService;
use App\Services\Scheduler\InvigilatorAssignmentService;
use Illuminate\Console\Command;

class AssignInvigilatorsCommand extends Command
{
    protected $signature = 'scheduler:assign-invigilators {date?} {slot?} {--rule=1} {--all=0}';

    protected $description = 'Assign invigilators for one slot or regenerate the full duty roster for one exam session.';

    public function handle(InvigilatorAssignmentService $slotService, DutyRosterService $dutyRosterService): int
    {
        $ruleId = (int) $this->option('rule');

        if ((int) $this->option('all') === 1) {
            [$assigned, $tbd, $warnings] = $dutyRosterService->regenerate($ruleId);
            $this->info("Duty roster regenerated. Assigned duties: {$assigned}. TBD slots: {$tbd}.");
            foreach ($warnings as $warning) {
                $this->warn($warning);
            }

            return self::SUCCESS;
        }

        $date = (string) $this->argument('date');
        $slot = (string) $this->argument('slot');

        if ($date === '' || $slot === '') {
            $this->error('date and slot are required unless --all=1 is used.');

            return self::FAILURE;
        }

        [$assigned, $tbd, $warnings] = $slotService->assignForSlot($ruleId, $date, $slot);

        $this->info("Invigilator assignments created: {$assigned}. TBD slots: {$tbd}.");
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
