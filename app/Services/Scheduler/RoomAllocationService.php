<?php

namespace App\Services\Scheduler;

use App\Models\Scheduler\DateSheetRule;
use App\Models\Scheduler\Exam;
use App\Models\Scheduler\Room;
use App\Models\Scheduler\RoomAllocation;
use App\Models\Scheduler\SeatingAssignment;
use Illuminate\Support\Facades\DB;

class RoomAllocationService
{
    private const ROOM_PLANNING_BUFFER = 5;

    public function __construct(
        private readonly ExamStudentResolver $resolver,
    ) {
    }

    /**
     * Allocate rooms for every scheduled date/slot in the selected exam session.
     * This is the UI bulk action behind the single “Allocate rooms for all
     * slots” button. Each slot still uses allocateForSlot(), so the same
     * building, waiting-room, and room-capacity+5 rules are preserved.
     */
    public function allocateForRule(int $ruleId): array
    {
        $pairs = Exam::query()
            ->where('rule_id', $ruleId)
            ->select('date', 'time_slot')
            ->distinct()
            ->orderBy('date')
            ->orderBy('time_slot')
            ->get();

        if ($pairs->isEmpty()) {
            return [0, 0, ['No datesheet slots exist. Please generate the datesheet first.']];
        }

        $totalAllocations = 0;
        $slotsProcessed = 0;
        $warnings = [];

        foreach ($pairs as $pair) {
            $date = $pair->date?->format('Y-m-d') ?: (string) $pair->date;
            $slot = (string) $pair->time_slot;

            [$count, $slotWarnings] = $this->allocateForSlot($ruleId, $date, $slot);
            $totalAllocations += $count;
            $slotsProcessed++;

            foreach ($slotWarnings as $warning) {
                $warnings[] = $date . ' ' . $slot . ': ' . $warning;
            }
        }

        return [$totalAllocations, $slotsProcessed, array_values(array_unique($warnings))];
    }

    /**
     * Allocate rooms for one exam slot only. This remains separate from seat
     * generation. Planning capacity may overbook each physical room by +5 so
     * the room board can stay compact; the seat generator later uses real
     * rows/columns and sends only true overflow to the waiting room.
     */
    public function allocateForSlot(int $ruleId, string $date, string $slot): array
    {
        $rule = DateSheetRule::query()->findOrFail($ruleId);
        $exams = Exam::query()
            ->with('course')
            ->where('rule_id', $ruleId)
            ->whereDate('date', $date)
            ->where('time_slot', $slot)
            ->get();

        if ($exams->isEmpty()) {
            return [0, ['No exams were found for the selected slot.']];
        }

        $allRooms = Room::query()
            ->orderBy('contingency_only')
            ->orderByDesc('capacity')
            ->orderBy('building')
            ->orderBy('floor')
            ->orderBy('room_id')
            ->get();

        if ($allRooms->isEmpty()) {
            return [0, ['No rooms are available.']];
        }

        $usableRooms = $allRooms->filter(fn (Room $room) => $room->isAllocatableExamRoom())
            ->sortByDesc(fn (Room $room) => $room->effectiveCapacity())
            ->values();
        $warnings = [];

        if ($usableRooms->isEmpty()) {
            $usableRooms = $allRooms->filter(fn (Room $room) => $room->isUsableNonWaitingRoom())
                ->sortByDesc(fn (Room $room) => $room->effectiveCapacity())
                ->values();
            if ($usableRooms->isNotEmpty()) {
                $warnings[] = 'No normal exam rooms were available, so contingency rooms were considered. Waiting rooms remained excluded.';
            }
        } elseif ($allRooms->count() !== $usableRooms->count()) {
            $warnings[] = 'Waiting rooms and contingency-only rooms were excluded from exam room allocation.';
        }

        if ($usableRooms->isEmpty()) {
            return [0, ['No usable non-waiting room capacity is available.']];
        }

        $subslots = $this->buildSubslots($usableRooms->all(), (bool) $rule->split_rooms);
        if ($subslots === []) {
            return [0, ['No usable room capacity is available.']];
        }

        $allocationCount = 0;
        $globalUsedRoomIds = [];

        DB::beginTransaction();

        try {
            $examIds = $exams->pluck('id')->all();

            SeatingAssignment::query()->whereIn('allocation_id', function ($query) use ($examIds) {
                $query->select('id')->from('scheduler_roomallocation')->whereIn('exam_id', $examIds);
            })->delete();

            RoomAllocation::query()->whereIn('exam_id', $examIds)->delete();

            $clusterGroups = $this->buildClusterGroups($exams->all(), $rule);

            foreach ($clusterGroups as $group) {
                $groupStrength = (int) $group['total_strength'];
                if ($groupStrength <= 0) {
                    continue;
                }

                $buildingPlan = $this->pickBestBuildingPlan($subslots, $groupStrength);
                if ($buildingPlan === null) {
                    $warnings[] = 'Cluster ' . $group['label'] . ' could not be packed into one building, even with +'
                        . self::ROOM_PLANNING_BUFFER . ' planning buffer per room. It was left unallocated for manual handling.';
                    continue;
                }

                $allowedRoomIds = array_flip($buildingPlan['room_ids']);
                $groupUsedRoomIds = [];

                foreach ($group['exams'] as $exam) {
                    $remaining = $this->resolver->strengthForExam($exam);
                    if ($remaining <= 0) {
                        $warnings[] = 'Exam ' . $exam->id . ' has no students to allocate.';
                        continue;
                    }

                    while ($remaining > 0) {
                        $candidateIndexes = [];
                        foreach ($subslots as $index => $subslot) {
                            if (($subslot['remaining'] ?? 0) <= 0) {
                                continue;
                            }
                            if (!isset($allowedRoomIds[$subslot['room_id']])) {
                                continue;
                            }
                            $candidateIndexes[] = $index;
                        }

                        if ($candidateIndexes === []) {
                            $warnings[] = 'Cluster ' . $group['label'] . ' exhausted the selected building plan. ' . $remaining . ' seat(s) remain uncovered.';
                            break;
                        }

                        usort($candidateIndexes, function ($left, $right) use ($subslots, $groupUsedRoomIds, $remaining) {
                            $a = $subslots[$left];
                            $b = $subslots[$right];

                            return $this->roomPickSort($a, $remaining, $groupUsedRoomIds)
                                <=> $this->roomPickSort($b, $remaining, $groupUsedRoomIds);
                        });

                        $pickedIndex = $candidateIndexes[0];
                        $picked = &$subslots[$pickedIndex];
                        $assigned = min($remaining, (int) $picked['remaining']);

                        RoomAllocation::query()->create([
                            'exam_id' => $exam->id,
                            'room_id' => $picked['room_id'],
                            'invigilator_id' => null,
                            'assigned_strength' => $assigned,
                            'subslot_label' => $picked['subslot_label'],
                            'is_duty_only' => false,
                        ]);

                        $picked['remaining'] = max(0, (int) $picked['remaining'] - $assigned);
                        $picked['seat_remaining'] = max(0, (int) ($picked['seat_remaining'] ?? 0) - min($assigned, (int) ($picked['seat_remaining'] ?? 0)));
                        $picked['used'] = (int) ($picked['used'] ?? 0) + $assigned;
                        $picked['used_strength'] = (int) ($picked['used_strength'] ?? 0) + $assigned;
                        $remaining -= $assigned;
                        $allocationCount++;
                        $groupUsedRoomIds[$picked['room_id']] = true;
                        $globalUsedRoomIds[$picked['room_id']] = true;
                        unset($picked);
                    }
                }

                if (($buildingPlan['uses_buffer'] ?? false) === true) {
                    $warnings[] = 'Cluster ' . $group['label'] . ' used compact room planning with +'
                        . self::ROOM_PLANNING_BUFFER . ' buffer. Seat generation will use real seats and overflow only if required.';
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [$allocationCount, array_values(array_unique($warnings))];
    }

    private function buildClusterGroups(array $exams, DateSheetRule $rule): array
    {
        $groups = [];

        foreach ($exams as $exam) {
            if (!$exam instanceof Exam) {
                continue;
            }

            if ((bool) ($rule->prevent_cross_building_for_same_class ?? true)) {
                $courseCode = (string) ($exam->course?->code ?? ('COURSE-' . $exam->course_id));
                $clusterKey = implode('|', [
                    $courseCode,
                    (string) $exam->class_label,
                    (string) $exam->program_code,
                    (string) $exam->semester_no,
                ]);
                $label = trim($courseCode . ' ' . (string) $exam->class_label) ?: ('EXAM-' . $exam->id);
            } else {
                $clusterKey = trim((string) $exam->cluster_key) !== '' ? (string) $exam->cluster_key : ('EXAM-' . $exam->id);
                $label = $clusterKey;
            }

            if (!isset($groups[$clusterKey])) {
                $groups[$clusterKey] = [
                    'key' => $clusterKey,
                    'label' => $label,
                    'total_strength' => 0,
                    'exams' => [],
                ];
            }

            $strength = $this->resolver->strengthForExam($exam);
            $groups[$clusterKey]['total_strength'] += $strength;
            $groups[$clusterKey]['exams'][] = $exam;
        }

        $groups = array_values($groups);
        usort($groups, function (array $left, array $right) {
            return ($right['total_strength'] <=> $left['total_strength'])
                ?: (count($right['exams']) <=> count($left['exams']))
                ?: strcmp((string) $left['key'], (string) $right['key']);
        });

        foreach ($groups as &$group) {
            usort($group['exams'], function (Exam $left, Exam $right) {
                return ($this->resolver->strengthForExam($right) <=> $this->resolver->strengthForExam($left))
                    ?: ($left->id <=> $right->id);
            });
        }
        unset($group);

        return $groups;
    }

    /**
     * Pick a same-building room plan for one room-allocation cluster.
     *
     * Patch v30 changes this from “open the largest remaining room for every
     * small paper” to “compact first”. The planner now prefers a single room
     * whenever possible, fills already-open rooms before opening another room,
     * and only splits a class/paper across multiple rooms when one physical
     * room cannot hold it. When it does need to open a new room, it still starts
     * from the largest capacity rooms, as requested.
     */
    private function pickBestBuildingPlan(array $subslots, int $requiredStrength): ?array
    {
        $candidates = [];
        $buildings = [];

        foreach ($subslots as $subslot) {
            if (($subslot['remaining'] ?? 0) <= 0) {
                continue;
            }

            $buildingKey = trim((string) ($subslot['building'] ?? ''));
            $buildingKey = $buildingKey !== '' ? $buildingKey : '[NO BUILDING]';
            $roomId = (int) $subslot['room_id'];

            if (!isset($buildings[$buildingKey]['rooms'][$roomId])) {
                $buildings[$buildingKey]['rooms'][$roomId] = [
                    'room_id' => $roomId,
                    'room_key' => (string) ($subslot['room_key'] ?? $roomId),
                    'planning_remaining' => 0,
                    'seat_remaining' => 0,
                    'planning_capacity' => 0,
                    'seat_capacity' => 0,
                    'used' => false,
                ];
            }

            $planningCapacity = (int) ($subslot['planning_capacity'] ?? $subslot['remaining'] ?? 0);
            $seatCapacity = (int) ($subslot['seat_capacity'] ?? $subslot['seat_remaining'] ?? 0);
            $used = (bool) ($subslot['used'] ?? false) || ((int) ($subslot['remaining'] ?? 0) < $planningCapacity);

            $buildings[$buildingKey]['rooms'][$roomId]['planning_remaining'] += (int) $subslot['remaining'];
            $buildings[$buildingKey]['rooms'][$roomId]['seat_remaining'] += (int) ($subslot['seat_remaining'] ?? 0);
            $buildings[$buildingKey]['rooms'][$roomId]['planning_capacity'] += $planningCapacity;
            $buildings[$buildingKey]['rooms'][$roomId]['seat_capacity'] += $seatCapacity;
            $buildings[$buildingKey]['rooms'][$roomId]['used'] = $buildings[$buildingKey]['rooms'][$roomId]['used'] || $used;
        }

        foreach ($buildings as $buildingKey => $payload) {
            $rooms = array_values($payload['rooms'] ?? []);
            if ($rooms === []) {
                continue;
            }

            // One-room candidates first. This prevents a class/paper being split
            // when a single physical room can hold it.
            foreach ($rooms as $room) {
                if ((int) $room['planning_remaining'] < $requiredStrength) {
                    continue;
                }

                $candidates[] = $this->buildingPlanCandidate(
                    $buildingKey,
                    [$room],
                    $requiredStrength,
                    'single-room'
                );
            }

            // Multi-room candidate. Use already-open rooms first to avoid the
            // screenshot problem where 19-student papers open CR2, CR3, CR4,
            // etc. instead of filling a room that already has free capacity.
            $usedRooms = array_values(array_filter($rooms, fn (array $room) => (bool) $room['used'] && (int) $room['planning_remaining'] > 0));
            $newRooms = array_values(array_filter($rooms, fn (array $room) => !(bool) $room['used'] && (int) $room['planning_remaining'] > 0));

            usort($usedRooms, function (array $left, array $right) {
                return ((int) $right['planning_remaining'] <=> (int) $left['planning_remaining'])
                    ?: strcmp((string) $left['room_key'], (string) $right['room_key']);
            });

            usort($newRooms, function (array $left, array $right) {
                return ((int) $right['planning_capacity'] <=> (int) $left['planning_capacity'])
                    ?: strcmp((string) $left['room_key'], (string) $right['room_key']);
            });

            $picked = [];
            $covered = 0;
            foreach (array_merge($usedRooms, $newRooms) as $room) {
                $picked[] = $room;
                $covered += (int) $room['planning_remaining'];
                if ($covered >= $requiredStrength) {
                    break;
                }
            }

            if ($covered >= $requiredStrength && count($picked) > 1) {
                $candidates[] = $this->buildingPlanCandidate(
                    $buildingKey,
                    $picked,
                    $requiredStrength,
                    'compact-multi-room'
                );
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $left, array $right) {
            return $left['sort'] <=> $right['sort'];
        });

        $best = $candidates[0];
        unset($best['sort']);

        return $best;
    }

    private function buildingPlanCandidate(string $buildingKey, array $rooms, int $requiredStrength, string $mode): array
    {
        $planningCovered = array_sum(array_map(fn (array $room) => (int) $room['planning_remaining'], $rooms));
        $realCovered = array_sum(array_map(fn (array $room) => (int) $room['seat_remaining'], $rooms));
        $roomIds = array_values(array_unique(array_map(fn (array $room) => (int) $room['room_id'], $rooms)));
        $newRoomCount = count(array_filter($rooms, fn (array $room) => !(bool) ($room['used'] ?? false)));
        $largestCapacity = max(array_map(fn (array $room) => (int) $room['planning_capacity'], $rooms));
        $largestNewCapacity = 0;
        foreach ($rooms as $room) {
            if (!(bool) ($room['used'] ?? false)) {
                $largestNewCapacity = max($largestNewCapacity, (int) $room['planning_capacity']);
            }
        }

        $slack = $planningCovered - $requiredStrength;
        $usesBuffer = $realCovered < $requiredStrength;

        // Sort objective:
        // 1) one physical room if possible,
        // 2) then fewer newly-opened rooms,
        // 3) then avoid +5 buffer when another option has real seats,
        // 4) then, if opening a room, start from largest capacity; otherwise
        //    best-fit into the already-open room.
        $allRoomsAreNew = $newRoomCount === count($rooms);
        $capacityPreference = $allRoomsAreNew ? -$largestNewCapacity : $slack;

        return [
            'building' => $buildingKey,
            'room_ids' => $roomIds,
            'room_count' => count($roomIds),
            'new_room_count' => $newRoomCount,
            'planning_capacity' => $planningCovered,
            'seat_capacity' => $realCovered,
            'largest_room_capacity' => $largestCapacity,
            'slack' => $slack,
            'uses_buffer' => $usesBuffer,
            'mode' => $mode,
            'sort' => [
                count($roomIds),
                $usesBuffer ? 1 : 0,
                $newRoomCount,
                $capacityPreference,
                $slack,
                $buildingKey,
            ],
        ];
    }


    private function roomPickSort(array $room, int $examRemaining, array $groupUsedRoomIds): array
    {
        $remaining = (int) ($room['remaining'] ?? 0);
        $seatRemaining = (int) ($room['seat_remaining'] ?? 0);
        $planningCapacity = (int) ($room['planning_capacity'] ?? $remaining);
        $used = (int) ($room['used'] ?? 0);

        $fullFit = $remaining >= $examRemaining ? 0 : 1;
        $usesBuffer = $seatRemaining >= min($examRemaining, $remaining) ? 0 : 1;
        $groupAlreadyUsed = isset($groupUsedRoomIds[$room['room_id']]) ? 0 : 1;
        $globallyAlreadyUsed = $used > 0 ? 0 : 1;

        // For a full fit, existing rooms use best-fit, while unopened rooms use
        // largest-first so the first room opened in a slot has useful capacity.
        // For a partial fit, largest remaining capacity reduces fragmentation.
        if ($fullFit === 0) {
            $fitScore = $globallyAlreadyUsed === 0 ? $remaining : -$planningCapacity;
        } else {
            $fitScore = -$remaining;
        }

        return [
            $fullFit,
            $usesBuffer,
            $groupAlreadyUsed,
            $globallyAlreadyUsed,
            $fitScore,
            -$used,
            (string) ($room['room_key'] ?? ''),
            (string) ($room['subslot_label'] ?? ''),
        ];
    }

    private function buildSubslots(array $rooms, bool $splitRooms): array
    {
        $subslots = [];

        foreach ($rooms as $room) {
            if (!$room instanceof Room || $room->looksLikeWaitingRoom()) {
                continue;
            }

            $seatCapacity = $room->effectiveCapacity();
            if ($seatCapacity <= 0) {
                continue;
            }

            $planningCapacity = $seatCapacity + self::ROOM_PLANNING_BUFFER;

            // A/B are only row groups inside a physical room. For room
            // allocation, every room is treated as one physical room globally,
            // so invigilation and allocation counts never double CR2 A/CR2 B.
            $subslots[] = $this->subslotRow($room, null, $planningCapacity, $seatCapacity);
        }

        usort($subslots, function (array $left, array $right) {
            return ((int) $right['remaining'] <=> (int) $left['remaining'])
                ?: strcmp((string) $left['building'], (string) $right['building'])
                ?: strcmp((string) $left['room_key'], (string) $right['room_key']);
        });

        return $subslots;
    }

    private function subslotRow(Room $room, ?string $label, int $planningCapacity, int $seatCapacity): array
    {
        return [
            'room_id' => $room->id,
            'room_key' => (string) $room->room_id,
            'building' => (string) $room->building,
            'floor' => (string) $room->floor,
            'contingency_only' => (bool) $room->contingency_only,
            'subslot_label' => $label,
            'planning_capacity' => max(0, $planningCapacity),
            'seat_capacity' => max(0, $seatCapacity),
            'remaining' => max(0, $planningCapacity),
            'seat_remaining' => max(0, $seatCapacity),
            'used' => false,
            'used_strength' => 0,
        ];
    }
}
