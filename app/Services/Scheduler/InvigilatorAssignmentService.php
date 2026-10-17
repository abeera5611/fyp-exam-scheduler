<?php

namespace App\Services\Scheduler;

use App\Models\Scheduler\DateSheetRule;
use App\Models\Scheduler\Faculty;
use App\Models\Scheduler\RoomAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvigilatorAssignmentService
{
    /**
     * Assign duties after room allocation. Duties are room/subslot based, not
     * exam-row based: each duty cell is one physical room lane for a slot.
     * The assignment order mirrors the old operational roster behavior:
     * 1) assign own-paper instructors first for the physical room,
     * 2) then balance remaining available faculty across rooms,
     * 3) keep same-slot and availability conflicts as hard constraints,
     * 4) before leaving TBD, use remaining quota with relaxed spacing and
     *    try swaps that preserve already-assigned own-course duties.
     */
    public function assignForSlot(int $ruleId, string $date, string $slot): array
    {
        return $this->assignScope($ruleId, $date, $slot);
    }

    /**
     * Regenerate the full roster in one pass. This is important because the
     * old Django logic balanced duties globally, not slot-by-slot in isolation.
     */
    public function assignForRule(int $ruleId): array
    {
        return $this->assignScope($ruleId, null, null);
    }

    private function assignScope(int $ruleId, ?string $date, ?string $slot): array
    {
        $rule = DateSheetRule::query()->findOrFail($ruleId);
        $singleDutyPerDay = $rule->singleInvigilationDutyPerDayEnabled();
        [$slotLookup, $slotOrder] = SlotHelper::buildSlotMaps(SlotHelper::buildSlotsFromRule($rule));

        $normalAllocations = $this->normalAllocations($ruleId, $date, $slot);
        if ($normalAllocations->isEmpty()) {
            return [0, 0, ['No duty-eligible room allocations exist for the selected scope.']];
        }

        $faculties = Faculty::query()->orderBy('name')->get();
        if ($faculties->isEmpty()) {
            return [0, 0, ['No faculty records are available.']];
        }

        $slotPairs = $this->allScheduledSlotPairs($ruleId);
        $daySlotIndex = $this->buildDaySlotIndex($slotPairs, $slotLookup, $slotOrder);
        $availabilityMatrix = is_array($rule->faculty_slot_matrix) ? $rule->faculty_slot_matrix : [];
        $facultyLookup = $this->facultyLookup($faculties);

        $warnings = [];
        $assigned = 0;
        $tbd = 0;

        DB::beginTransaction();

        try {
            $this->clearDutyAssignments($ruleId, $date, $slot);
            $state = $this->buildFacultyState($faculties, $ruleId, $daySlotIndex, $slotLookup, $slotOrder);
            $cells = $this->buildDutyCells($normalAllocations, $facultyLookup, $daySlotIndex, $slotLookup, $slotOrder);
            $plans = [];

            foreach ($cells as $cell) {
                $plans[$cell['key']] = [];
            }

            // Step 1: own-paper faculty is the first priority. Same-slot,
            // availability and quota remain hard blocks, so an instructor with
            // multiple papers at the same time is assigned to at most one duty.
            foreach ($cells as $cell) {
                while (count($plans[$cell['key']]) < $cell['required']) {
                    $faculty = $this->pickOwnFaculty($state, $cell, $availabilityMatrix, false, $plans[$cell['key']], $singleDutyPerDay);
                    if (!$faculty) {
                        break;
                    }

                    $plans[$cell['key']][] = $faculty->id;
                    $this->recordAssignment($state, $faculty->id, $cell['date'], $cell['slot_idx']);
                }

                $ownIds = array_map('intval', $cell['own_faculty_ids'] ?? []);
                if ($ownIds !== [] && count(array_intersect($ownIds, array_map('intval', $plans[$cell['key']]))) === 0) {
                    $warnings[] = "Own-paper instructor could not be assigned without violating availability/max-duty/same-slot/same-day/consecutive constraints for {$cell['room_label']} on {$cell['date']} {$cell['slot']}; another eligible invigilator will be tried.";
                }
            }

            // Step 2: fill remaining room-based duties with balanced available
            // faculty. This pass respects the configured same-day/consecutive
            // spacing rules.
            foreach ($cells as $cell) {
                while (count($plans[$cell['key']]) < $cell['required']) {
                    $faculty = $this->pickFaculty($state, $cell, $availabilityMatrix, false, false, $plans[$cell['key']], $singleDutyPerDay);
                    if (!$faculty) {
                        break;
                    }

                    $plans[$cell['key']][] = $faculty->id;
                    $this->recordAssignment($state, $faculty->id, $cell['date'], $cell['slot_idx']);
                }
            }

            // Step 3: before accepting TBDs, use any remaining quota that does
            // not violate availability or same-slot conflicts. If a direct fill
            // cannot work, try moving a non-own assigned invigilator into the
            // open duty and backfilling that source duty with an available
            // faculty member who still has quota.
            $finalWarnings = $this->resolveUnfilledDutyCells($cells, $plans, $state, $availabilityMatrix);
            $warnings = array_merge($warnings, $finalWarnings);

            foreach ($cells as $cell) {
                $missing = max(0, $cell['required'] - count($plans[$cell['key']]));
                if ($missing <= 0) {
                    continue;
                }

                $tbd += $missing;
                $warnings[] = "TBD invigilator(s) {$missing} for {$cell['room_label']} on {$cell['date']} {$cell['slot']} after checking remaining quota and swap options.";
            }

            foreach ($cells as $cell) {
                $created = $this->persistDutyPlan($cell, $plans[$cell['key']]);
                $assigned += $created;
            }

            $this->persistAssignedDutyCounts($state);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [$assigned, $tbd, array_values(array_unique($warnings))];
    }

    private function normalAllocations(int $ruleId, ?string $date, ?string $slot): Collection
    {
        return RoomAllocation::query()
            ->with(['room', 'exam.course'])
            ->where('is_duty_only', false)
            ->whereHas('exam', function ($query) use ($ruleId, $date, $slot) {
                $query->where('rule_id', $ruleId);
                if ($date !== null) {
                    $query->whereDate('date', $date);
                }
                if ($slot !== null) {
                    $query->where('time_slot', $slot);
                }
            })
            ->get()
            ->filter(fn ($allocation) => SlotHelper::isDutyEligibleRoom($allocation->room))
            ->sortBy(fn ($allocation) => [
                $allocation->exam?->date?->format('Y-m-d') ?: '',
                SlotHelper::normalizeSlotKey((string) ($allocation->exam?->time_slot ?? '')),
                (string) ($allocation->room?->building ?? ''),
                (string) ($allocation->room?->floor ?? ''),
                SlotHelper::physicalRoomLabel($allocation->room),
                (string) ($allocation->subslot_label ?? ''),
            ])
            ->values();
    }

    private function buildDutyCells(Collection $allocations, array $facultyLookup, array $daySlotIndex, array $slotLookup, array $slotOrder): array
    {
        // Duty is attached to the PHYSICAL room, not to split row groups.
        // Example: CR2 A + CR2 B are seating/allocation lanes, but the duty
        // roster must still create only one CR2 duty cell.
        $groups = $allocations->groupBy(function (RoomAllocation $allocation) {
            return implode('|', [
                $allocation->exam?->date?->format('Y-m-d') ?: '',
                (string) ($allocation->exam?->time_slot ?? ''),
                SlotHelper::physicalRoomKey($allocation->room),
            ]);
        });

        $cells = [];

        foreach ($groups as $key => $group) {
            $first = $group->first();
            if (!$first || !$first->exam || !$first->room) {
                continue;
            }

            $date = $first->exam->date?->format('Y-m-d') ?: '';
            $slot = (string) $first->exam->time_slot;
            $slotIdx = $this->getSlotIndex($date, $slot, $daySlotIndex, $slotLookup, $slotOrder);

            $required = max(1, (int) $group
                ->pluck('room.invigilators_required')
                ->map(fn ($value) => (int) $value)
                ->max());

            $instructorNorms = [];
            $ownFacultyIds = [];
            $ownFacultyWeights = [];

            foreach ($group as $allocation) {
                $names = SlotHelper::splitExamInstructors((string) ($allocation->exam?->instructor ?? ''));
                $weight = max(1, (int) ($allocation->assigned_strength ?: $allocation->exam?->strength ?: 0));
                foreach ($names as $name) {
                    $norm = SlotHelper::normalizePersonName($name);
                    if ($norm !== '') {
                        $instructorNorms[$norm] = true;
                    }

                    $faculty = $this->findFacultyForInstructor($name, $facultyLookup);
                    if ($faculty) {
                        $ownFacultyIds[$faculty->id] = true;
                        $ownFacultyWeights[$faculty->id] = (int) (($ownFacultyWeights[$faculty->id] ?? 0) + $weight);
                    }
                }
            }

            $normalAllocations = $group->where('is_duty_only', false)->values();
            $primary = $normalAllocations->first() ?: $first;
            $roomLabel = SlotHelper::physicalRoomLabel($first->room);

            $cells[] = [
                'key' => $key,
                'date' => $date,
                'slot' => $slot,
                'slot_idx' => $slotIdx,
                'room_id' => (int) $primary->room_id,
                'room_label' => $roomLabel !== '' ? $roomLabel : (string) $first->room->room_id,
                'subslot_label' => null,
                'required' => $required,
                'primary_allocation' => $primary,
                'instructor_norms' => array_keys($instructorNorms),
                'own_faculty_ids' => array_keys($ownFacultyIds),
                'own_faculty_weights' => $ownFacultyWeights,
            ];
        }

        usort($cells, function (array $left, array $right) use ($slotLookup, $slotOrder) {
            return [
                $left['date'],
                SlotHelper::slotSortKey($left['slot'], $slotLookup, $slotOrder),
                $left['room_label'],
            ] <=> [
                $right['date'],
                SlotHelper::slotSortKey($right['slot'], $slotLookup, $slotOrder),
                $right['room_label'],
            ];
        });

        return $cells;
    }

    private function clearDutyAssignments(int $ruleId, ?string $date, ?string $slot): void
    {
        RoomAllocation::query()
            ->where('is_duty_only', true)
            ->whereHas('exam', function ($query) use ($ruleId, $date, $slot) {
                $query->where('rule_id', $ruleId);
                if ($date !== null) {
                    $query->whereDate('date', $date);
                }
                if ($slot !== null) {
                    $query->where('time_slot', $slot);
                }
            })
            ->delete();

        RoomAllocation::query()
            ->where('is_duty_only', false)
            ->whereHas('exam', function ($query) use ($ruleId, $date, $slot) {
                $query->where('rule_id', $ruleId);
                if ($date !== null) {
                    $query->whereDate('date', $date);
                }
                if ($slot !== null) {
                    $query->where('time_slot', $slot);
                }
            })
            ->update(['invigilator_id' => null]);
    }

    private function persistDutyPlan(array $cell, array $facultyIds): int
    {
        /** @var RoomAllocation $primary */
        $primary = $cell['primary_allocation'];
        $assigned = 0;

        foreach (array_values($facultyIds) as $index => $facultyId) {
            if ($index === 0) {
                // The allocation models were loaded before the bulk clear, so
                // their in-memory invigilator_id can be stale. Use a direct
                // update to guarantee the primary duty is written after clear.
                RoomAllocation::query()
                    ->whereKey($primary->id)
                    ->update(['invigilator_id' => $facultyId]);
                $primary->invigilator_id = $facultyId;
                $assigned++;
                continue;
            }

            RoomAllocation::query()->create([
                'exam_id' => $primary->exam_id,
                'room_id' => $primary->room_id,
                'invigilator_id' => $facultyId,
                'assigned_strength' => 0,
                'subslot_label' => $cell['subslot_label'],
                'is_duty_only' => true,
            ]);
            $assigned++;
        }

        return $assigned;
    }

    private function facultyLookup(Collection $faculties): array
    {
        $byNorm = [];
        foreach ($faculties as $faculty) {
            $norm = SlotHelper::normalizePersonName((string) $faculty->name);
            if ($norm !== '') {
                $byNorm[$norm] = $faculty;
            }
        }

        return $byNorm;
    }

    private function findFacultyForInstructor(string $name, array $facultyLookup): ?Faculty
    {
        $norm = SlotHelper::normalizePersonName($name);
        if ($norm === '') {
            return null;
        }

        if (isset($facultyLookup[$norm])) {
            return $facultyLookup[$norm];
        }

        foreach ($facultyLookup as $facultyNorm => $faculty) {
            if (strlen($facultyNorm) < 5 || strlen($norm) < 5) {
                continue;
            }

            if (str_contains($facultyNorm, $norm) || str_contains($norm, $facultyNorm)) {
                return $faculty;
            }
        }

        return null;
    }

    private function buildFacultyState(Collection $faculties, int $ruleId, array $daySlotIndex, array $slotLookup, array $slotOrder): array
    {
        $state = [];
        foreach ($faculties as $faculty) {
            $state[$faculty->id] = [
                'faculty' => $faculty,
                'count' => 0,
                'by_day' => [],
            ];
        }

        $existing = RoomAllocation::query()
            ->with(['exam', 'room'])
            ->whereNotNull('invigilator_id')
            ->whereHas('exam', function ($query) use ($ruleId) {
                $query->where('rule_id', $ruleId);
            })
            ->get();

        foreach ($existing as $allocation) {
            if (!SlotHelper::isDutyEligibleRoom($allocation->room) || !$allocation->exam || !$allocation->exam->date) {
                continue;
            }

            $facultyId = (int) $allocation->invigilator_id;
            if (!isset($state[$facultyId])) {
                continue;
            }

            $dayKey = $allocation->exam->date->format('Y-m-d');
            $idx = $this->getSlotIndex($dayKey, (string) $allocation->exam->time_slot, $daySlotIndex, $slotLookup, $slotOrder);
            $this->recordAssignment($state, $facultyId, $dayKey, $idx);
        }

        return $state;
    }

    private function pickOwnFaculty(array $state, array $cell, array $availabilityMatrix, bool $allowConsecutive, array $alreadyInCell, bool $singleDutyPerDay = false): ?Faculty
    {
        $already = array_map('intval', $alreadyInCell);
        $ownIds = array_map('intval', $cell['own_faculty_ids'] ?? []);
        $weights = $cell['own_faculty_weights'] ?? [];
        $candidates = [];

        foreach ($ownIds as $facultyId) {
            if (in_array($facultyId, $already, true)) {
                continue;
            }

            if (!$this->canAssignFaculty($facultyId, $state, $cell, $availabilityMatrix, $allowConsecutive, true, $singleDutyPerDay)) {
                continue;
            }

            /** @var Faculty $faculty */
            $faculty = $state[$facultyId]['faculty'];
            $ratio = (int) $faculty->max_duties > 0 ? ($state[$facultyId]['count'] / max(1, (int) $faculty->max_duties)) : 1.0;
            $weight = (int) ($weights[$facultyId] ?? 0);
            $candidates[] = [
                'faculty' => $faculty,
                'sort' => [-$weight, $ratio, $state[$facultyId]['count'], strtolower((string) $faculty->name)],
            ];
        }

        usort($candidates, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        return $candidates[0]['faculty'] ?? null;
    }

    private function pickFaculty(array $state, array $cell, array $availabilityMatrix, bool $allowConsecutive, bool $allowOwnPaper, array $alreadyInCell, bool $singleDutyPerDay = false): ?Faculty
    {
        $candidates = [];

        foreach ($state as $facultyId => $info) {
            if (in_array((int) $facultyId, array_map('intval', $alreadyInCell), true)) {
                continue;
            }

            if (!$this->canAssignFaculty((int) $facultyId, $state, $cell, $availabilityMatrix, $allowConsecutive, $allowOwnPaper, $singleDutyPerDay)) {
                continue;
            }

            /** @var Faculty $faculty */
            $faculty = $info['faculty'];
            $ratio = (int) $faculty->max_duties > 0 ? ($info['count'] / max(1, (int) $faculty->max_duties)) : 1.0;
            $candidates[] = [
                'faculty' => $faculty,
                'sort' => [$ratio, $info['count'], strtolower((string) $faculty->name)],
            ];
        }

        usort($candidates, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        return $candidates[0]['faculty'] ?? null;
    }

    private function canAssignFaculty(int $facultyId, array $state, array $cell, array $availabilityMatrix, bool $allowConsecutive, bool $allowOwnPaper, bool $singleDutyPerDay = false): bool
    {
        if (!isset($state[$facultyId])) {
            return false;
        }

        /** @var Faculty $faculty */
        $faculty = $state[$facultyId]['faculty'];

        if ((int) $state[$facultyId]['count'] >= (int) $faculty->max_duties) {
            return false;
        }

        $facultyNorm = SlotHelper::normalizePersonName((string) $faculty->name);
        if (!$allowOwnPaper && $facultyNorm !== '' && in_array($facultyNorm, $cell['instructor_norms'], true)) {
            return false;
        }

        $daySlots = $state[$facultyId]['by_day'][$cell['date']] ?? [];
        if (($daySlots[$cell['slot_idx']] ?? false) === true) {
            return false;
        }

        if ($singleDutyPerDay && $this->hasAnyDutyOnDate($daySlots)) {
            return false;
        }

        if (!$allowConsecutive && (($daySlots[$cell['slot_idx'] - 1] ?? false) === true || ($daySlots[$cell['slot_idx'] + 1] ?? false) === true)) {
            return false;
        }

        return $this->isFacultyAvailable($facultyId, $cell['date'], $cell['slot'], $availabilityMatrix);
    }

    private function resolveUnfilledDutyCells(array $cells, array &$plans, array &$state, array $availabilityMatrix): array
    {
        $warnings = [];
        foreach ($cells as $cell) {
            while (count($plans[$cell['key']]) < $cell['required']) {
                $ownFaculty = $this->pickOwnFaculty($state, $cell, $availabilityMatrix, true, $plans[$cell['key']], false);
                if ($ownFaculty) {
                    $plans[$cell['key']][] = $ownFaculty->id;
                    $this->recordAssignment($state, $ownFaculty->id, $cell['date'], $cell['slot_idx']);
                    $warnings[] = "Final quota pass assigned own-course instructor {$ownFaculty->name} to {$cell['room_label']} on {$cell['date']} {$cell['slot']} after relaxing same-day/consecutive spacing.";
                    continue;
                }

                $faculty = $this->pickFaculty($state, $cell, $availabilityMatrix, true, false, $plans[$cell['key']], false);
                if (!$faculty) {
                    break;
                }

                $plans[$cell['key']][] = $faculty->id;
                $this->recordAssignment($state, $faculty->id, $cell['date'], $cell['slot_idx']);
                $warnings[] = "Final quota pass assigned {$faculty->name} to {$cell['room_label']} on {$cell['date']} {$cell['slot']} after relaxing same-day/consecutive spacing.";
            }
        }

        foreach ($cells as $targetCell) {
            while (count($plans[$targetCell['key']]) < $targetCell['required']) {
                if (!$this->trySwapForUnfilledCell($targetCell, $cells, $plans, $state, $availabilityMatrix, $warnings)) {
                    break;
                }
            }
        }

        return $warnings;
    }

    private function trySwapForUnfilledCell(array $targetCell, array $cells, array &$plans, array &$state, array $availabilityMatrix, array &$warnings): bool
    {
        foreach ($cells as $sourceCell) {
            if ($sourceCell['key'] === $targetCell['key']) {
                continue;
            }

            foreach (array_values($plans[$sourceCell['key']] ?? []) as $sourceFacultyId) {
                $sourceFacultyId = (int) $sourceFacultyId;
                if ($sourceFacultyId <= 0 || $this->isOwnFacultyForCell($sourceFacultyId, $sourceCell)) {
                    continue;
                }

                $targetAlready = array_map('intval', $plans[$targetCell['key']] ?? []);
                if (in_array($sourceFacultyId, $targetAlready, true)) {
                    continue;
                }

                $this->removeAssignment($state, $sourceFacultyId, $sourceCell['date'], $sourceCell['slot_idx']);
                $sourceCanMoveToTarget = $this->canAssignFaculty(
                    $sourceFacultyId,
                    $state,
                    $targetCell,
                    $availabilityMatrix,
                    true,
                    $this->isOwnFacultyForCell($sourceFacultyId, $targetCell),
                    false
                );

                if (!$sourceCanMoveToTarget) {
                    $this->recordAssignment($state, $sourceFacultyId, $sourceCell['date'], $sourceCell['slot_idx']);
                    continue;
                }

                $sourcePlanWithoutMoved = array_values(array_filter(
                    array_map('intval', $plans[$sourceCell['key']] ?? []),
                    fn (int $id) => $id !== $sourceFacultyId
                ));

                $replacement = $this->pickFaculty($state, $sourceCell, $availabilityMatrix, true, false, array_merge($sourcePlanWithoutMoved, [$sourceFacultyId]), false);
                if (!$replacement) {
                    $this->recordAssignment($state, $sourceFacultyId, $sourceCell['date'], $sourceCell['slot_idx']);
                    continue;
                }

                $plans[$sourceCell['key']] = $sourcePlanWithoutMoved;
                $plans[$sourceCell['key']][] = $replacement->id;
                $plans[$targetCell['key']][] = $sourceFacultyId;
                $this->recordAssignment($state, $sourceFacultyId, $targetCell['date'], $targetCell['slot_idx']);
                $this->recordAssignment($state, $replacement->id, $sourceCell['date'], $sourceCell['slot_idx']);

                $sourceFaculty = $state[$sourceFacultyId]['faculty'] ?? null;
                $warnings[] = "Swap pass moved " . ($sourceFaculty?->name ?? ('faculty #' . $sourceFacultyId))
                    . " to {$targetCell['room_label']} on {$targetCell['date']} {$targetCell['slot']} and assigned {$replacement->name}"
                    . " to {$sourceCell['room_label']} on {$sourceCell['date']} {$sourceCell['slot']}.";

                return true;
            }
        }

        return false;
    }

    private function isOwnFacultyForCell(int $facultyId, array $cell): bool
    {
        return in_array($facultyId, array_map('intval', $cell['own_faculty_ids'] ?? []), true);
    }


    private function hasAnyDutyOnDate(array $daySlots): bool
    {
        foreach ($daySlots as $assigned) {
            if ($assigned === true) {
                return true;
            }
        }

        return false;
    }

    private function isFacultyAvailable(int $facultyId, string $date, string $slot, array $availabilityMatrix): bool
    {
        $dayMap = $availabilityMatrix[$date] ?? [];
        if (!is_array($dayMap)) {
            return true;
        }

        $facultyMap = $dayMap[(string) $facultyId] ?? [];
        if (!is_array($facultyMap)) {
            return true;
        }

        if (($facultyMap['__all__'] ?? false) === true) {
            return false;
        }

        if (($facultyMap[$slot] ?? false) === true) {
            return false;
        }

        if (($facultyMap[SlotHelper::normalizeSlotKey($slot)] ?? false) === true) {
            return false;
        }

        return true;
    }

    private function recordAssignment(array &$state, int $facultyId, string $date, int $slotIdx): void
    {
        if (!isset($state[$facultyId])) {
            return;
        }

        $state[$facultyId]['count']++;
        $state[$facultyId]['by_day'][$date] = $state[$facultyId]['by_day'][$date] ?? [];
        $state[$facultyId]['by_day'][$date][$slotIdx] = true;
    }

    private function removeAssignment(array &$state, int $facultyId, string $date, int $slotIdx): void
    {
        if (!isset($state[$facultyId])) {
            return;
        }

        $state[$facultyId]['count'] = max(0, (int) $state[$facultyId]['count'] - 1);
        unset($state[$facultyId]['by_day'][$date][$slotIdx]);

        if (($state[$facultyId]['by_day'][$date] ?? []) === []) {
            unset($state[$facultyId]['by_day'][$date]);
        }
    }

    private function persistAssignedDutyCounts(array $state): void
    {
        foreach ($state as $facultyId => $info) {
            Faculty::query()->whereKey($facultyId)->update([
                'assigned_duties' => (int) $info['count'],
            ]);
        }
    }

    private function allScheduledSlotPairs(int $ruleId): array
    {
        return RoomAllocation::query()
            ->join('scheduler_exam', 'scheduler_exam.id', '=', 'scheduler_roomallocation.exam_id')
            ->where('scheduler_exam.rule_id', $ruleId)
            ->select('scheduler_exam.date', 'scheduler_exam.time_slot')
            ->distinct()
            ->get()
            ->map(fn ($row) => [(string) $row->date, (string) $row->time_slot])
            ->all();
    }

    private function buildDaySlotIndex(array $slotPairs, array $slotLookup, array $slotOrder): array
    {
        $byDay = [];
        foreach ($slotPairs as [$date, $slot]) {
            $byDay[$date] = $byDay[$date] ?? [];
            $byDay[$date][] = (string) $slot;
        }

        $indexed = [];
        foreach ($byDay as $date => $slots) {
            usort($slots, function ($a, $b) use ($slotLookup, $slotOrder) {
                return SlotHelper::slotSortKey((string) $a, $slotLookup, $slotOrder)
                    <=> SlotHelper::slotSortKey((string) $b, $slotLookup, $slotOrder);
            });
            foreach (array_values(array_unique($slots)) as $index => $slot) {
                $indexed[$date][(string) $slot] = $index;
                $indexed[$date][SlotHelper::normalizeSlotKey((string) $slot)] = $index;
            }
        }

        return $indexed;
    }

    private function getSlotIndex(string $date, string $slot, array $daySlotIndex, array $slotLookup, array $slotOrder): int
    {
        $normalized = SlotHelper::normalizeSlotKey($slot);

        if (isset($daySlotIndex[$date][$slot])) {
            return (int) $daySlotIndex[$date][$slot];
        }

        if (isset($daySlotIndex[$date][$normalized])) {
            return (int) $daySlotIndex[$date][$normalized];
        }

        $sortKey = SlotHelper::slotSortKey($slot, $slotLookup, $slotOrder);

        return (int) ($sortKey[1] ?? 0);
    }
}
