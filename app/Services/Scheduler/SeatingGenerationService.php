<?php

namespace App\Services\Scheduler;

use App\Models\Scheduler\Exam;
use App\Models\Scheduler\Room;
use App\Models\Scheduler\RoomAllocation;
use App\Models\Scheduler\SeatingAssignment;
use Illuminate\Support\Facades\DB;

class SeatingGenerationService
{
    public function __construct(
        private readonly ExamStudentResolver $resolver,
    ) {
    }

    /**
     * Generate seating plans for all scheduled slots in a session. Room
     * allocation remains a separate prerequisite; slots without room allocation
     * are reported and skipped by generateForSlot().
     */
    public function generateForRule(int $ruleId): array
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

        $totalSeats = 0;
        $slotsProcessed = 0;
        $warnings = [];

        foreach ($pairs as $pair) {
            $date = $pair->date?->format('Y-m-d') ?: (string) $pair->date;
            $slot = (string) $pair->time_slot;

            [$count, $slotWarnings] = $this->generateForSlot($ruleId, $date, $slot);
            $totalSeats += $count;
            $slotsProcessed++;

            foreach ($slotWarnings as $warning) {
                $warnings[] = $date . ' ' . $slot . ': ' . $warning;
            }
        }

        return [$totalSeats, $slotsProcessed, array_values(array_unique($warnings))];
    }

    public function generateForSlot(int $ruleId, string $date, string $slot): array
    {
        $slotExams = Exam::query()
            ->with('course')
            ->where('rule_id', $ruleId)
            ->whereDate('date', $date)
            ->where('time_slot', $slot)
            ->get();

        if ($slotExams->isEmpty()) {
            return [0, ['No exams exist for the selected slot.']];
        }

        DB::beginTransaction();

        $warnings = [];
        $placed = 0;

        try {
            $examIds = $slotExams->pluck('id')->all();

            $allSlotAllocations = RoomAllocation::query()
                ->with(['room', 'exam.course'])
                ->whereIn('exam_id', $examIds)
                ->get();

            SeatingAssignment::query()
                ->whereIn('allocation_id', $allSlotAllocations->pluck('id')->all())
                ->delete();

            $waitingAllocationIds = $allSlotAllocations
                ->filter(fn (RoomAllocation $allocation) => $allocation->room && $allocation->room->looksLikeWaitingRoom() && !$allocation->is_duty_only)
                ->pluck('id')
                ->all();

            if ($waitingAllocationIds !== []) {
                RoomAllocation::query()->whereIn('id', $waitingAllocationIds)->delete();
            }

            $normalAllocations = RoomAllocation::query()
                ->with(['room', 'exam.course'])
                ->where('is_duty_only', false)
                ->whereIn('exam_id', $examIds)
                ->get()
                ->filter(fn (RoomAllocation $allocation) => $allocation->room && !$allocation->room->looksLikeWaitingRoom())
                ->values();

            if ($normalAllocations->isEmpty()) {
                DB::commit();

                return [0, ['No normal room allocations exist for this slot. Please run room allocation first; seating is now kept separate from allocation.']];
            }

            $studentsByExam = [];
            $examCursor = [];
            $overflowByExam = [];

            foreach ($slotExams as $exam) {
                $studentsByExam[(int) $exam->id] = $this->resolver->studentsForExam($exam)->values()->all();
                $examCursor[(int) $exam->id] = 0;
                $overflowByExam[(int) $exam->id] = [];
            }

            $footprints = [];
            foreach ($normalAllocations as $allocation) {
                $examId = (int) $allocation->exam_id;
                $students = $studentsByExam[$examId] ?? [];
                $cursor = $examCursor[$examId] ?? 0;
                $target = max(0, (int) $allocation->assigned_strength);
                if ($target <= 0) {
                    $target = count($this->positionsForRoom($allocation->room, $allocation->subslot_label));
                }

                $slice = array_slice($students, $cursor, $target);
                $examCursor[$examId] = $cursor + count($slice);

                if ($slice === []) {
                    continue;
                }

                $footprint = $this->footprintKey((int) $allocation->room_id, $allocation->subslot_label);
                $footprints[$footprint] = $footprints[$footprint] ?? [
                    'room' => $allocation->room,
                    'subslot_label' => $allocation->subslot_label,
                    'queues' => [],
                ];

                $footprints[$footprint]['queues'][] = [
                    'allocation' => $allocation,
                    'exam' => $allocation->exam,
                    'course_key' => $this->courseKey($allocation),
                    'students' => $slice,
                    'cursor' => 0,
                ];
            }

            foreach ($studentsByExam as $examId => $students) {
                $cursor = $examCursor[$examId] ?? 0;
                if ($cursor < count($students)) {
                    $overflowByExam[$examId] = array_slice($students, $cursor);
                }
            }

            foreach ($footprints as $footprint) {
                $room = $footprint['room'];
                if (!$room instanceof Room) {
                    continue;
                }

                $positionsByRow = $this->positionsByRow($room, $footprint['subslot_label']);
                $allPositions = $this->positionsForRoom($room, $footprint['subslot_label']);
                $queues = array_values($footprint['queues']);
                $previousRowCourseKeys = [];
                $usedPositions = [];

                // First pass: Django-style row-aware placement. Try not to put
                // the same course on adjacent occupied rows.
                foreach ($positionsByRow as $rowNo => $positions) {
                    if ($this->totalQueueRemaining($queues) <= 0) {
                        break;
                    }

                    $rowCourseKeys = [];
                    $seatIndex = 0;

                    while ($seatIndex < count($positions) && $this->totalQueueRemaining($queues) > 0) {
                        $queueIndex = $this->pickQueueIndex($queues, $previousRowCourseKeys, $rowCourseKeys);

                        if ($queueIndex === null) {
                            break;
                        }

                        $queue = &$queues[$queueIndex];
                        $student = $queue['students'][$queue['cursor']] ?? null;

                        if ($student === null) {
                            $queue['cursor'] = count($queue['students']);
                            unset($queue);
                            continue;
                        }

                        $position = $positions[$seatIndex];
                        $positionKey = $position['row'] . '|' . $position['col'];

                        if (isset($usedPositions[$positionKey])) {
                            $seatIndex++;
                            unset($queue);
                            continue;
                        }

                        SeatingAssignment::query()->create([
                            'allocation_id' => $queue['allocation']->id,
                            'student_id' => $student->id,
                            'seat_row' => $position['row'],
                            'seat_col' => $position['col'],
                        ]);

                        $usedPositions[$positionKey] = true;
                        $queue['cursor']++;
                        $rowCourseKeys[$queue['course_key']] = true;
                        $placed++;
                        $seatIndex++;
                        unset($queue);
                    }

                    if ($rowCourseKeys !== []) {
                        $previousRowCourseKeys = $rowCourseKeys;
                    } else {
                        // A blank row is treated as a buffer row, so the next
                        // occupied row may again use a course that appeared
                        // before the blank row.
                        $previousRowCourseKeys = [];
                    }
                }

                // Second pass: if students are still left but physical seats
                // are available, relax the non-adjacent-row rule. This prevents
                // unnecessary waiting-room placement when only a few seats are
                // left, e.g. 55 students in 5x11 + 5x7 rooms.
                $relaxedPlaced = 0;
                $freePositions = array_values(array_filter($allPositions, function (array $position) use ($usedPositions) {
                    return !isset($usedPositions[$position['row'] . '|' . $position['col']]);
                }));
                $freeCursor = 0;

                while ($freeCursor < count($freePositions) && $this->totalQueueRemaining($queues) > 0) {
                    $queueIndex = $this->pickQueueIndexRelaxed($queues);
                    if ($queueIndex === null) {
                        break;
                    }

                    $queue = &$queues[$queueIndex];
                    $student = $queue['students'][$queue['cursor']] ?? null;
                    if ($student === null) {
                        $queue['cursor'] = count($queue['students']);
                        unset($queue);
                        continue;
                    }

                    $position = $freePositions[$freeCursor++];

                    SeatingAssignment::query()->create([
                        'allocation_id' => $queue['allocation']->id,
                        'student_id' => $student->id,
                        'seat_row' => $position['row'],
                        'seat_col' => $position['col'],
                    ]);

                    $queue['cursor']++;
                    $placed++;
                    $relaxedPlaced++;
                    unset($queue);
                }

                if ($relaxedPlaced > 0) {
                    $warnings[] = 'Room ' . $room->room_id . ' used ' . $relaxedPlaced . ' relaxed adjacent-row seat(s) because strict spacing did not have enough room.';
                }

                foreach ($queues as $queue) {
                    $remaining = array_slice($queue['students'], (int) $queue['cursor']);
                    if ($remaining !== []) {
                        $examId = (int) $queue['allocation']->exam_id;
                        $overflowByExam[$examId] = array_merge($overflowByExam[$examId] ?? [], $remaining);
                        $warnings[] = 'Room ' . $room->room_id . ' could not seat all students for ' . ($queue['exam']->course?->code ?: ('exam ' . $examId)) . ' after all physical seats were filled.';
                    }
                }
            }

            $waitingPlaced = $this->placeOverflowInWaitingRoom($slotExams, $overflowByExam, $warnings);
            $placed += $waitingPlaced;

            $this->syncAssignedStrengthsForSlot($examIds);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [$placed, array_values(array_unique($warnings))];
    }

    private function placeOverflowInWaitingRoom($slotExams, array $overflowByExam, array &$warnings): int
    {
        $overflowByExam = array_filter($overflowByExam, fn (array $students) => count($students) > 0);
        if ($overflowByExam === []) {
            return 0;
        }

        $waitingRoom = Room::query()
            ->get()
            ->first(fn (Room $room) => $room->looksLikeWaitingRoom());

        if (!$waitingRoom) {
            $waitingRoom = Room::query()->create([
                'room_id' => 'WaitingRoom',
                'room_name' => 'Waiting Room',
                'capacity' => max(1000, array_sum(array_map('count', $overflowByExam))),
                'rows' => 100,
                'cols' => 10,
                'invigilators_required' => 0,
                'building' => 'TEMP',
                'floor' => 'TEMP',
                'contingency_only' => true,
                'allow_split' => false,
                'is_waiting_room' => true,
            ]);
            $warnings[] = 'Waiting room did not exist, so a temporary WaitingRoom was created for overflow students.';
        }

        $positions = $this->positionsForRoom($waitingRoom, null);
        if ($positions === []) {
            $positions = [['row' => 1, 'col' => 1]];
        }

        $examById = $slotExams->keyBy('id');
        $used = [];
        $positionCursor = 0;
        $placed = 0;

        foreach ($overflowByExam as $examId => $students) {
            $exam = $examById[(int) $examId] ?? null;
            if (!$exam || $students === []) {
                continue;
            }

            $allocation = RoomAllocation::query()->create([
                'exam_id' => (int) $examId,
                'room_id' => $waitingRoom->id,
                'invigilator_id' => null,
                'assigned_strength' => count($students),
                'subslot_label' => null,
                'is_duty_only' => false,
            ]);

            foreach ($students as $student) {
                while (isset($positions[$positionCursor]) && isset($used[$positions[$positionCursor]['row'] . '|' . $positions[$positionCursor]['col']])) {
                    $positionCursor++;
                }

                if (!isset($positions[$positionCursor])) {
                    $warnings[] = 'Waiting room capacity finished before all overflow students could be seated.';
                    break 2;
                }

                $position = $positions[$positionCursor];
                $used[$position['row'] . '|' . $position['col']] = true;

                SeatingAssignment::query()->create([
                    'allocation_id' => $allocation->id,
                    'student_id' => $student->id,
                    'seat_row' => $position['row'],
                    'seat_col' => $position['col'],
                ]);

                $positionCursor++;
                $placed++;
            }
        }

        if ($placed > 0) {
            $warnings[] = $placed . ' student(s) were placed in the waiting room because allocated exam rooms could not hold them.';
        }

        return $placed;
    }

    private function syncAssignedStrengthsForSlot(array $examIds): void
    {
        $allocations = RoomAllocation::query()
            ->where('is_duty_only', false)
            ->whereIn('exam_id', $examIds)
            ->get();

        foreach ($allocations as $allocation) {
            $allocation->assigned_strength = SeatingAssignment::query()
                ->where('allocation_id', $allocation->id)
                ->count();
            $allocation->save();
        }
    }

    private function totalQueueRemaining(array $queues): int
    {
        $total = 0;
        foreach ($queues as $queue) {
            $total += max(0, count($queue['students']) - (int) $queue['cursor']);
        }

        return $total;
    }

    private function pickQueueIndex(array $queues, array $previousRowCourseKeys, array $currentRowCourseKeys): ?int
    {
        $candidates = [];

        foreach ($queues as $index => $queue) {
            $remaining = count($queue['students']) - (int) $queue['cursor'];
            if ($remaining <= 0) {
                continue;
            }

            $courseKey = (string) $queue['course_key'];

            if (isset($previousRowCourseKeys[$courseKey])) {
                continue;
            }

            $alreadyInCurrentRow = isset($currentRowCourseKeys[$courseKey]) ? 0 : 1;

            $candidates[] = [
                'index' => $index,
                'sort' => [$alreadyInCurrentRow, -$remaining, $courseKey],
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn ($left, $right) => $left['sort'] <=> $right['sort']);

        return (int) $candidates[0]['index'];
    }


    private function pickQueueIndexRelaxed(array $queues): ?int
    {
        $candidates = [];

        foreach ($queues as $index => $queue) {
            $remaining = count($queue['students']) - (int) $queue['cursor'];
            if ($remaining <= 0) {
                continue;
            }

            $candidates[] = [
                'index' => $index,
                'sort' => [-$remaining, (string) $queue['course_key']],
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn ($left, $right) => $left['sort'] <=> $right['sort']);

        return (int) $candidates[0]['index'];
    }

    private function footprintKey(int $roomId, ?string $subslotLabel): string
    {
        return $roomId . '|' . ($subslotLabel ?: 'FULL');
    }

    private function courseKey(RoomAllocation $allocation): string
    {
        return (string) ($allocation->exam?->course?->code ?: ('EXAM-' . $allocation->exam_id));
    }

    private function positionsByRow(Room $room, ?string $subslotLabel): array
    {
        $positions = $this->positionsForRoom($room, $subslotLabel);
        $byRow = [];

        foreach ($positions as $position) {
            $byRow[$position['row']] = $byRow[$position['row']] ?? [];
            $byRow[$position['row']][] = $position;
        }

        ksort($byRow);
        foreach ($byRow as &$row) {
            usort($row, fn ($left, $right) => $left['col'] <=> $right['col']);
        }
        unset($row);

        return $byRow;
    }

    private function positionsForRoom(Room $room, ?string $subslotLabel): array
    {
        $capacity = max(0, (int) $room->effectiveCapacity());
        $rows = max(1, (int) ($room->rows ?: 0));
        $cols = max(1, (int) ($room->cols ?: 0));

        if ((int) $room->rows <= 0 && (int) $room->cols <= 0) {
            $rows = 1;
            $cols = max(1, $capacity);
        } elseif ((int) $room->rows <= 0) {
            $rows = (int) ceil(max(1, $capacity) / $cols);
        } elseif ((int) $room->cols <= 0) {
            $cols = (int) ceil(max(1, $capacity) / $rows);
        }

        $startCol = 1;
        $endCol = $cols;

        if ($subslotLabel === 'A' && $cols > 1) {
            $endCol = (int) ceil($cols / 2);
        } elseif ($subslotLabel === 'B' && $cols > 1) {
            $startCol = (int) ceil($cols / 2) + 1;
        }

        $positions = [];
        for ($row = 1; $row <= $rows; $row++) {
            for ($col = $startCol; $col <= $endCol; $col++) {
                if ($capacity > 0 && count($positions) >= $capacity && $subslotLabel === null) {
                    break 2;
                }
                $positions[] = ['row' => $row, 'col' => $col];
            }
        }

        return $positions;
    }
}
