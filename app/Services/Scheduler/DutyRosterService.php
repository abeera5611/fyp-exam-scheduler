<?php

namespace App\Services\Scheduler;

class DutyRosterService
{
    public function __construct(
        private readonly InvigilatorAssignmentService $assignmentService,
    ) {
    }

    public function regenerate(int $ruleId): array
    {
        return $this->assignmentService->assignForRule($ruleId);
    }
}
