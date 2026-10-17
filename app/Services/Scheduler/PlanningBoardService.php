<?php

namespace App\Services\Scheduler;

use App\Models\Scheduler\DateSheetRule;
use App\Models\Scheduler\CourseRegistration;
use App\Models\Scheduler\Exam;
use App\Models\Scheduler\Faculty;
use App\Models\Scheduler\Room;
use App\Models\Scheduler\RoomAllocation;
use App\Models\Scheduler\SeatingAssignment;
use App\Models\Scheduler\Student;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlanningBoardService
{
    private const MAX_AUTO_EXTENSION_CALENDAR_DAYS = 8;
    private const HIGH_STUDENT_CLASH_THRESHOLD = 10;

    public function __construct(
        private readonly RoomAllocationService $roomAllocationService,
        private readonly SeatingGenerationService $seatingGenerationService,
        private readonly InvigilatorAssignmentService $invigilatorAssignmentService,
        private readonly ExamStudentResolver $studentResolver,
    ) {
    }

    public function datesheetBoard(DateSheetRule $rule): array
    {
        $slots = SlotHelper::buildSlotsFromRule($rule);

        $exams = Exam::query()
            ->with('course')
            ->where('rule_id', $rule->id)
            ->orderBy('date')
            ->orderBy('time_slot')
            ->orderBy('cluster_key')
            ->orderBy('class_label')
            ->get();

        $scheduledDays = $exams->pluck('date')
            ->filter()
            ->map(fn ($date) => $date->format('Y-m-d'))
            ->unique()
            ->values()
            ->all();

        $displayDays = $this->buildDisplayDays($rule, $scheduledDays);

        $cells = [];
        foreach ($displayDays as $day) {
            foreach ($slots as $slot) {
                $cells[$day . '|' . $slot['label']] = [];
            }
        }

        $clusterGroups = $exams->groupBy(function (Exam $exam) {
            return $exam->date?->format('Y-m-d') . '|' . $exam->time_slot . '|' . ($exam->cluster_key ?: ('EXAM-' . $exam->id));
        });

        foreach ($clusterGroups as $group) {
            $first = $group->first();
            if (!$first || !$first->date) {
                continue;
            }

            $cellKey = $first->date->format('Y-m-d') . '|' . $first->time_slot;
            $courseCodes = $group->map(fn (Exam $exam) => (string) ($exam->course?->code ?: ''))->filter()->unique()->values();
            $courseTitles = $group->map(fn (Exam $exam) => (string) ($exam->course?->title ?: ''))->filter()->unique()->values();
            $classLabels = $group->flatMap(fn (Exam $exam) => $this->classLabelsForExam($exam))->filter()->unique()->values();
            $programCodes = $group->map(fn (Exam $exam) => (string) ($exam->program_code ?: ''))->filter()->unique()->values();
            $instructors = $group->map(fn (Exam $exam) => (string) $exam->instructor)->filter()->unique()->values();
            $level = $this->clusterAcademicLevel($rule, $group);

            $cells[$cellKey] = $cells[$cellKey] ?? [];
            $cells[$cellKey][] = [
                'cluster_key' => (string) ($first->cluster_key ?: ('EXAM-' . $first->id)),
                'exam_ids' => $group->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'date' => $first->date->format('Y-m-d'),
                'slot' => (string) $first->time_slot,
                'course_code' => $courseCodes->implode(', '),
                'course_codes' => $courseCodes->all(),
                'course_title' => $courseTitles->implode(' / '),
                'classes' => $classLabels->all(),
                'classes_text' => $classLabels->take(4)->implode(', ') . ($classLabels->count() > 4 ? ' +' . ($classLabels->count() - 4) . ' more' : ''),
                'programs' => $programCodes->all(),
                'programs_text' => $programCodes->implode(', '),
                'academic_level' => $level,
                'instructors_text' => $instructors->take(3)->implode(', ') . ($instructors->count() > 3 ? ' +' . ($instructors->count() - 3) . ' more' : ''),
                'strength' => (int) $group->sum(fn (Exam $exam) => (int) ($exam->strength ?? 0)),
                'exam_count' => $group->count(),
                'display_title' => trim(($courseCodes->first() ?: 'Cluster') . (($courseTitles->first() ?: '') !== '' ? ' - ' . $courseTitles->first() : '')),
            ];
        }

        $classDayConflictMap = $this->classDayStudentConflictMap($exams);
        $this->markDateSheetClassDayConflicts($cells, $classDayConflictMap);

        foreach ($cells as &$cards) {
            usort($cards, function (array $left, array $right) {
                if ((bool) ($left['has_high_class_day_conflict'] ?? false) !== (bool) ($right['has_high_class_day_conflict'] ?? false)) {
                    return (bool) ($left['has_high_class_day_conflict'] ?? false) ? -1 : 1;
                }

                if ((bool) ($left['has_class_day_conflict'] ?? false) !== (bool) ($right['has_class_day_conflict'] ?? false)) {
                    return (bool) ($left['has_class_day_conflict'] ?? false) ? -1 : 1;
                }

                return [$right['strength'], $left['course_code'], $left['cluster_key']] <=> [$left['strength'], $right['course_code'], $right['cluster_key']];
            });
        }
        unset($cards);

        return [
            'days' => $displayDays,
            'slots' => $slots,
            'cells' => $cells,
            'scheduled_days' => $scheduledDays,
        ];
    }

    private function markDateSheetClassDayConflicts(array &$cells, array $classDayConflictMap): void
    {
        foreach ($cells as &$cards) {
            foreach ($cards as &$card) {
                $date = (string) ($card['date'] ?? '');
                $conflictClasses = [];
                $conflictLabels = [];
                $maxConflictCount = 0;

                foreach ((array) ($card['classes'] ?? []) as $classLabel) {
                    $classLabel = trim((string) $classLabel);
                    $studentCount = (int) ($classDayConflictMap[$date][$classLabel] ?? 0);
                    if ($classLabel !== '' && $studentCount > 0) {
                        $conflictClasses[] = $classLabel;
                        $conflictLabels[] = $classLabel . ' (' . $studentCount . ')';
                        $maxConflictCount = max($maxConflictCount, $studentCount);
                    }
                }

                $card['has_class_day_conflict'] = $conflictClasses !== [];
                $card['has_high_class_day_conflict'] = $maxConflictCount > self::HIGH_STUDENT_CLASH_THRESHOLD;
                $card['max_class_day_conflict_count'] = $maxConflictCount;
                $card['class_day_conflict_classes'] = $conflictClasses;
                $card['class_day_conflict_text'] = $conflictClasses !== []
                    ? 'Student clash on this date: ' . implode(', ', $conflictLabels)
                    : '';
            }
            unset($card);
        }
        unset($cards);
    }

    private function classDayStudentConflictMap(Collection $exams): array
    {
        $studentCourses = [];

        foreach ($exams as $exam) {
            if (!$exam instanceof Exam || !$exam->date) {
                continue;
            }

            $date = $exam->date->format('Y-m-d');
            $courseKey = strtoupper(trim((string) ($exam->course?->code ?: 'COURSE-' . $exam->course_id)));
            if ($courseKey === '') {
                continue;
            }

            foreach ($this->classLabelsForExam($exam) as $classLabel) {
                foreach ($this->studentIdsForExamClass($exam, $classLabel) as $studentId) {
                    $studentCourses[$date][$classLabel][(int) $studentId][$courseKey] = true;
                }
            }
        }

        $conflicts = [];
        foreach ($studentCourses as $date => $classMap) {
            foreach ($classMap as $classLabel => $students) {
                foreach ($students as $courseSet) {
                    if (count($courseSet) > 1) {
                        $conflicts[$date][$classLabel] = ((int) ($conflicts[$date][$classLabel] ?? 0)) + 1;
                    }
                }
            }
        }

        return $conflicts;
    }

    private function clusterAcademicLevel(DateSheetRule $rule, Collection $exams): string
    {
        $hasPostgraduate = false;
        $hasUndergraduate = false;

        foreach ($exams as $exam) {
            if (!$exam instanceof Exam) {
                continue;
            }

            $offering = [
                'course_code' => (string) ($exam->course?->code ?? ''),
                'program_code' => (string) ($exam->program_code ?? ''),
                'class_label' => (string) ($exam->class_label ?? ''),
            ];

            if ($rule->isPostgraduateOffering($offering)) {
                $hasPostgraduate = true;
            }

            if ($rule->isUndergraduateOffering($offering)) {
                $hasUndergraduate = true;
            }
        }

        if ($hasPostgraduate && $hasUndergraduate) {
            return 'mixed';
        }

        if ($hasPostgraduate) {
            return 'postgraduate';
        }

        if ($hasUndergraduate) {
            return 'undergraduate';
        }

        return 'other';
    }

    public function seatPlanBoard(int $ruleId, string $date, string $slot): array
    {
        $rooms = Room::query()
            ->orderBy('is_waiting_room')
            ->orderBy('building')
            ->orderBy('floor')
            ->orderBy('room_id')
            ->get();

        $assignments = SeatingAssignment::query()
            ->with(['student', 'allocation.exam.course', 'allocation.room'])
            ->whereHas('allocation.exam', function ($query) use ($ruleId, $date, $slot) {
                $query->where('rule_id', $ruleId)->whereDate('date', $date)->where('time_slot', $slot);
            })
            ->get();

        $assignmentsByRoom = [];
        foreach ($assignments as $assignment) {
            $roomId = (int) ($assignment->allocation?->room_id ?? 0);
            if ($roomId <= 0) {
                continue;
            }

            $assignmentsByRoom[$roomId][] = $assignment;
        }

        $roomCards = [];
        foreach ($rooms as $room) {
            $dimensions = $this->roomDimensions($room);
            $matrix = [];
            for ($row = 1; $row <= $dimensions['rows']; $row++) {
                $matrix[$row] = [];
                for ($col = 1; $col <= $dimensions['cols']; $col++) {
                    $matrix[$row][$col] = [
                        'row' => $row,
                        'col' => $col,
                        'occupied' => false,
                        'assignment' => null,
                    ];
                }
            }

            $overflow = [];
            $rowCourseMap = [];

            foreach ($assignmentsByRoom[(int) $room->id] ?? [] as $assignment) {
                $row = (int) $assignment->seat_row;
                $col = (int) $assignment->seat_col;
                $exam = $assignment->allocation?->exam;
                $courseCode = (string) ($exam?->course?->code ?: '');
                $payload = [
                    'seat_assignment_id' => (int) $assignment->id,
                    'allocation_id' => (int) $assignment->allocation_id,
                    'student_id' => (int) $assignment->student_id,
                    'student_name' => (string) ($assignment->student?->name ?: ''),
                    'registration_id' => (string) ($assignment->student?->registration_id ?: ''),
                    'exam_id' => (int) ($exam?->id ?? 0),
                    'course_code' => $courseCode,
                    'course_title' => (string) ($exam?->course?->title ?: ''),
                    'class_label' => $exam ? $this->classLabelTextForExam($exam) : '',
                    'row' => $row,
                    'col' => $col,
                    'is_waiting_room' => (bool) $room->looksLikeWaitingRoom(),
                ];

                if (isset($matrix[$row][$col]) && !$matrix[$row][$col]['occupied']) {
                    $matrix[$row][$col]['occupied'] = true;
                    $matrix[$row][$col]['assignment'] = $payload;
                    if ($courseCode !== '') {
                        $rowCourseMap[$row][$courseCode] = true;
                    }
                } else {
                    $overflow[] = $payload;
                }
            }

            $rowSummaries = [];
            foreach ($rowCourseMap as $row => $courses) {
                $rowSummaries[(int) $row] = implode(', ', array_keys($courses));
            }

            $occupiedCount = 0;
            foreach ($matrix as $row) {
                foreach ($row as $seat) {
                    if ($seat['occupied']) {
                        $occupiedCount++;
                    }
                }
            }

            $roomCards[] = [
                'room_pk' => (int) $room->id,
                'room_id' => (string) $room->room_id,
                'room_name' => (string) $room->room_name,
                'building' => (string) $room->building,
                'floor' => (string) $room->floor,
                'rows' => $dimensions['rows'],
                'cols' => $dimensions['cols'],
                'capacity' => $dimensions['rows'] * $dimensions['cols'],
                'occupied_count' => $occupiedCount,
                'free_count' => max(0, ($dimensions['rows'] * $dimensions['cols']) - $occupiedCount),
                'is_waiting_room' => (bool) $room->looksLikeWaitingRoom(),
                'matrix' => $matrix,
                'overflow' => $overflow,
                'row_summaries' => $rowSummaries,
            ];
        }

        $warnings = [];
        foreach ($roomCards as $roomCard) {
            $warnings = array_merge($warnings, $this->adjacentCourseWarningsFromMatrix($roomCard));
        }

        return [
            'rule_id' => $ruleId,
            'date' => $date,
            'slot' => $slot,
            'rooms' => $roomCards,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function roomBoard(int $ruleId, string $date, string $slot): array
    {
        $rule = DateSheetRule::query()->findOrFail($ruleId);

        $rooms = Room::query()
            ->orderBy('building')
            ->orderBy('floor')
            ->orderBy('room_id')
            ->get();

        // IMPORTANT: the manual room-allocation board is now physical-room
        // based. A/B are only internal row/column groups used by seating; CR3
        // must appear as one lane in the UI and exports, not CR3 A + CR3 B.
        $lanes = $this->baseRoomLanes($rule, $rooms);
        $laneMap = [];

        foreach ($lanes as $index => $lane) {
            $laneMap[$lane['lane_key']] = $index;
        }

        $allocations = RoomAllocation::query()
            ->with(['room', 'exam.course'])
            ->where('is_duty_only', false)
            ->whereHas('exam', function ($query) use ($ruleId, $date, $slot) {
                $query->where('rule_id', $ruleId)
                    ->whereDate('date', $date)
                    ->where('time_slot', $slot);
            })
            ->get()
            ->sortBy(fn (RoomAllocation $allocation) => [
                (string) ($allocation->room->building ?? ''),
                (string) ($allocation->room->floor ?? ''),
                SlotHelper::physicalRoomLabel($allocation->room),
                (string) ($allocation->subslot_label ?? ''),
                -((int) $allocation->assigned_strength),
            ])
            ->values();

        foreach ($allocations as $allocation) {
            $room = $allocation->room;
            if (!$room || $room->looksLikeWaitingRoom()) {
                continue;
            }

            // Always place cards into the physical room lane. Keep the source
            // lane only as card metadata, so users can understand whether the
            // system originally used A/B internally.
            $laneKey = $this->laneKey((int) $room->id, null);
            if (!isset($laneMap[$laneKey])) {
                $lanes[] = $this->makeLane($rule, $room, null);
                $laneMap[$laneKey] = count($lanes) - 1;
            }

            $index = $laneMap[$laneKey];
            $physicalLabel = SlotHelper::physicalRoomLabel($room);
            $sourceLane = trim((string) ($allocation->subslot_label ?? ''));
            $lanes[$index]['cards'][] = [
                'allocation_id' => (int) $allocation->id,
                'exam_id' => (int) $allocation->exam_id,
                'cluster_key' => (string) ($allocation->exam->cluster_key ?: ''),
                'course_code' => (string) ($allocation->exam->course?->code ?: ''),
                'course_title' => (string) ($allocation->exam->course?->title ?: ''),
                'class_label' => $this->classLabelTextForExam($allocation->exam),
                'instructor' => (string) $allocation->exam->instructor,
                'assigned_strength' => (int) $allocation->assigned_strength,
                'room_label' => $physicalLabel !== '' ? $physicalLabel : (string) $room->room_id,
                'source_lane' => $sourceLane,
            ];
            $lanes[$index]['assigned_total'] += (int) $allocation->assigned_strength;
        }

        $allocationTotals = $allocations
            ->groupBy(fn (RoomAllocation $allocation) => (int) $allocation->exam_id)
            ->map(fn (Collection $items) => (int) $items->sum(fn (RoomAllocation $allocation) => (int) $allocation->assigned_strength));

        $unallocatedExams = Exam::query()
            ->with('course')
            ->where('rule_id', $ruleId)
            ->whereDate('date', $date)
            ->where('time_slot', $slot)
            ->orderBy('cluster_key')
            ->orderBy('class_label')
            ->get()
            ->map(function (Exam $exam) use ($allocationTotals) {
                $requiredStrength = $this->studentResolver->strengthForExam($exam);
                $allocatedStrength = (int) ($allocationTotals->get((int) $exam->id, 0));

                return [
                    'exam_id' => (int) $exam->id,
                    'cluster_key' => (string) ($exam->cluster_key ?: ''),
                    'course_code' => (string) ($exam->course?->code ?: ''),
                    'course_title' => (string) ($exam->course?->title ?: ''),
                    'class_label' => $this->classLabelTextForExam($exam),
                    'instructor' => (string) $exam->instructor,
                    'required_strength' => $requiredStrength,
                    'allocated_strength' => $allocatedStrength,
                    'missing_strength' => max(0, $requiredStrength - $allocatedStrength),
                    'status' => $allocatedStrength <= 0 ? 'unallocated' : 'partial',
                ];
            })
            ->filter(fn (array $row) => (int) $row['missing_strength'] > 0)
            ->values()
            ->all();

        usort($lanes, function (array $left, array $right) {
            return [
                (string) $left['building'],
                (string) $left['floor'],
                (string) $left['room_id'],
            ] <=> [
                (string) $right['building'],
                (string) $right['floor'],
                (string) $right['room_id'],
            ];
        });

        $buildings = [];
        foreach ($lanes as $lane) {
            $buildingKey = $lane['building'] !== '' ? $lane['building'] : '[No building]';
            $buildings[$buildingKey] = $buildings[$buildingKey] ?? [];
            $buildings[$buildingKey][] = $lane;
        }

        return [
            'date' => $date,
            'slot' => $slot,
            'buildings' => array_map(fn ($name, $items) => ['building' => $name, 'lanes' => array_values($items)], array_keys($buildings), $buildings),
            'lanes' => array_values($lanes),
            'unallocated_exams' => $unallocatedExams,
        ];
    }

    public function unallocatedRoomSummary(int $ruleId): array
    {
        $allocationTotals = RoomAllocation::query()
            ->where('is_duty_only', false)
            ->whereHas('exam', fn ($query) => $query->where('rule_id', $ruleId))
            ->select('exam_id', DB::raw('SUM(assigned_strength) as allocated_strength'))
            ->groupBy('exam_id')
            ->pluck('allocated_strength', 'exam_id');

        $exams = Exam::query()
            ->with('course')
            ->where('rule_id', $ruleId)
            ->whereNotNull('date')
            ->orderBy('date')
            ->orderBy('time_slot')
            ->orderBy('cluster_key')
            ->orderBy('class_label')
            ->get()
            ->map(function (Exam $exam) use ($allocationTotals) {
                $requiredStrength = $this->studentResolver->strengthForExam($exam);
                $allocatedStrength = (int) ($allocationTotals->get((int) $exam->id, 0));
                $missingStrength = max(0, $requiredStrength - $allocatedStrength);

                if ($missingStrength <= 0) {
                    return null;
                }

                return [
                    'exam_id' => (int) $exam->id,
                    'date' => $exam->date?->format('Y-m-d') ?: '',
                    'slot' => (string) $exam->time_slot,
                    'cluster_key' => (string) ($exam->cluster_key ?: ''),
                    'course_code' => (string) ($exam->course?->code ?: ''),
                    'course_title' => (string) ($exam->course?->title ?: ''),
                    'class_label' => $this->classLabelTextForExam($exam),
                    'instructor' => (string) $exam->instructor,
                    'required_strength' => $requiredStrength,
                    'allocated_strength' => $allocatedStrength,
                    'missing_strength' => $missingStrength,
                    'status' => $allocatedStrength <= 0 ? 'unallocated' : 'partial',
                ];
            })
            ->filter()
            ->values();

        return $exams
            ->groupBy(fn (array $exam) => $exam['date'] . '|' . $exam['slot'])
            ->map(function (Collection $slotExams, string $key) {
                [$date, $slot] = array_pad(explode('|', $key, 2), 2, '');

                return [
                    'date' => $date,
                    'slot' => $slot,
                    'missing_strength' => (int) $slotExams->sum(fn (array $exam) => (int) $exam['missing_strength']),
                    'exams' => $slotExams->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    public function dutyRoster(int $ruleId): array
    {
        $rows = RoomAllocation::query()
            ->with(['exam.course', 'room', 'invigilator'])
            ->whereHas('exam', function ($query) use ($ruleId) {
                $query->where('rule_id', $ruleId);
            })
            ->get()
            ->filter(fn (RoomAllocation $allocation) => $allocation->room && !$allocation->room->looksLikeWaitingRoom())
            ->groupBy(function (RoomAllocation $allocation) {
                $exam = $allocation->exam;
                $date = $exam?->date?->format('Y-m-d') ?: '';
                $slot = (string) ($exam?->time_slot ?? '');

                return implode('|', [$date, $slot, SlotHelper::physicalRoomKey($allocation->room)]);
            });

        $result = [];

        foreach ($rows as $group) {
            $first = $group->first();
            if (!$first || !$first->exam || !$first->room) {
                continue;
            }

            $papers = $group
                ->where('is_duty_only', false)
                ->map(function (RoomAllocation $allocation) {
                    $exam = $allocation->exam;
                    $courseCode = (string) ($exam?->course?->code ?: '');
                    $classLabel = $exam ? $this->classLabelTextForExam($exam) : '';
                    $strength = (int) ($allocation->assigned_strength ?? 0);
                    $lane = trim((string) ($allocation->subslot_label ?? ''));
                    $laneText = $lane !== '' ? ' ' . $lane : '';

                    return trim($courseCode . ($classLabel !== '' ? ' [' . $classLabel . ']' : '') . ($strength > 0 ? ' (' . $strength . $laneText . ')' : ''));
                })
                ->filter()
                ->unique()
                ->values();

            $requiredInvigilators = $this->requiredInvigilatorCount($group);
            $invigilators = $this->invigilatorNamesWithTbd($group, $requiredInvigilators);
            $missingInvigilators = max(0, $requiredInvigilators - $group->pluck('invigilator_id')->filter()->unique()->count());

            $roomLabel = SlotHelper::physicalRoomLabel($first->room);

            $result[] = [
                'date' => $first->exam->date?->format('Y-m-d'),
                'slot' => (string) $first->exam->time_slot,
                'room' => $roomLabel !== '' ? $roomLabel : (string) $first->room->room_id,
                'building' => (string) ($first->room->building ?? ''),
                'floor' => (string) ($first->room->floor ?? ''),
                'papers' => $papers->all(),
                'papers_text' => $papers->implode(', '),
                'invigilators' => $invigilators->all(),
                'invigilators_text' => $invigilators->implode(', '),
                'required_invigilators' => $requiredInvigilators,
                'missing_invigilators' => $missingInvigilators,
                'strength' => (int) $group->where('is_duty_only', false)->sum('assigned_strength'),
            ];
        }

        usort($result, function (array $left, array $right) {
            return [
                (string) $left['date'],
                SlotHelper::normalizeSlotKey((string) $left['slot']),
                (string) $left['room'],
            ] <=> [
                (string) $right['date'],
                SlotHelper::normalizeSlotKey((string) $right['slot']),
                (string) $right['room'],
            ];
        });

        return $result;
    }

    private function requiredInvigilatorCount(Collection $roomGroup): int
    {
        return max(1, (int) $roomGroup
            ->pluck('room.invigilators_required')
            ->map(fn ($value) => (int) $value)
            ->max());
    }

    private function invigilatorNamesWithTbd(Collection $roomGroup, int $requiredInvigilators): Collection
    {
        $names = $roomGroup
            ->pluck('invigilator.name')
            ->filter()
            ->unique()
            ->values();

        $missing = max(0, $requiredInvigilators - $names->count());
        for ($i = 0; $i < $missing; $i++) {
            $names->push('TBD');
        }

        return $names;
    }

    public function facultyDutyMatrix(int $ruleId): array
    {
        $allocations = RoomAllocation::query()
            ->with(['exam.course', 'room', 'invigilator'])
            ->whereHas('exam', function ($query) use ($ruleId) {
                $query->where('rule_id', $ruleId);
            })
            ->get()
            ->filter(fn (RoomAllocation $allocation) => $allocation->room && !$allocation->room->looksLikeWaitingRoom());

        $slotPairs = $allocations
            ->map(function (RoomAllocation $allocation) {
                return [
                    'date' => $allocation->exam?->date?->format('Y-m-d') ?: '',
                    'slot' => (string) ($allocation->exam?->time_slot ?? ''),
                ];
            })
            ->filter(fn (array $pair) => $pair['date'] !== '' && $pair['slot'] !== '')
            ->unique(fn (array $pair) => $pair['date'] . '|' . $pair['slot'])
            ->sortBy(fn (array $pair) => [$pair['date'], SlotHelper::normalizeSlotKey($pair['slot'])])
            ->values()
            ->all();

        $columns = array_map(function (array $pair) {
            return [
                'date' => $pair['date'],
                'slot' => $pair['slot'],
                'key' => $pair['date'] . '|' . $pair['slot'],
            ];
        }, $slotPairs);

        $dates = collect($columns)->pluck('date')->unique()->values()->all();
        $slotsByDate = collect($columns)->groupBy('date')->map(fn ($items) => $items->pluck('slot')->values()->all())->all();

        $faculties = Faculty::query()->orderBy('department')->orderBy('name')->get();

        $cells = [];
        $counts = [];
        $dailyCounts = [];
        $tbdFacultyId = 0;
        $tbdCells = [];
        $tbdCount = 0;
        $tbdDailyCounts = [];
        $totalDutiesRequired = 0;
        foreach ($faculties as $faculty) {
            $facultyId = (int) $faculty->id;
            $cells[$facultyId] = [];
            $counts[$facultyId] = 0;
            $dailyCounts[$facultyId] = [];
        }

        $roomGroups = $allocations->groupBy(function (RoomAllocation $allocation) {
            return implode('|', [
                $allocation->exam?->date?->format('Y-m-d') ?: '',
                (string) ($allocation->exam?->time_slot ?? ''),
                SlotHelper::physicalRoomKey($allocation->room),
            ]);
        });

        foreach ($roomGroups as $group) {
            $first = $group->first();
            if (!$first || !$first->exam || !$first->room) {
                continue;
            }

            $date = $first->exam->date?->format('Y-m-d') ?: '';
            $slot = (string) $first->exam->time_slot;
            if ($date === '' || $slot === '') {
                continue;
            }

            $roomLabel = SlotHelper::physicalRoomLabel($first->room);
            if ($roomLabel === '') {
                $roomLabel = (string) $first->room->room_id;
            }

            $papers = $group
                ->where('is_duty_only', false)
                ->map(function (RoomAllocation $allocation) {
                    $exam = $allocation->exam;
                    $courseTitle = trim((string) ($exam?->course?->title ?: ''));
                    $courseCode = trim((string) ($exam?->course?->code ?: ''));
                    $classLabel = $exam ? $this->classLabelTextForExam($exam) : '';
                    $label = $courseTitle !== '' ? $courseTitle : $courseCode;
                    if ($classLabel !== '') {
                        $label .= '-' . $classLabel;
                    }
                    return $label;
                })
                ->filter()
                ->unique()
                ->values();

            $papersText = $papers->implode(', ');
            $cellText = trim($roomLabel . ($papersText !== '' ? "\n" . $papersText : ''));

            $invigilatorIds = $group->pluck('invigilator_id')->filter()->unique()->values();
            foreach ($invigilatorIds as $facultyId) {
                $facultyId = (int) $facultyId;
                if (!isset($cells[$facultyId])) {
                    $cells[$facultyId] = [];
                    $counts[$facultyId] = 0;
                    $dailyCounts[$facultyId] = [];
                }
                $slotKey = $date . '|' . $slot;
                $existing = $cells[$facultyId][$slotKey] ?? '';
                $cells[$facultyId][$slotKey] = trim($existing . ($existing !== '' ? "\n\n" : '') . $cellText);
                $counts[$facultyId] = ($counts[$facultyId] ?? 0) + 1;
                $dailyCounts[$facultyId][$date] = ($dailyCounts[$facultyId][$date] ?? 0) + 1;
            }

            $requiredInvigilators = $this->requiredInvigilatorCount($group);
            $totalDutiesRequired += $requiredInvigilators;
            $missingInvigilators = max(0, $requiredInvigilators - $invigilatorIds->count());
            if ($missingInvigilators > 0) {
                $slotKey = $date . '|' . $slot;
                $tbdText = trim($cellText . "\n" . ($missingInvigilators === 1 ? 'TBD' : 'TBD x ' . $missingInvigilators));
                $existing = $tbdCells[$slotKey] ?? '';
                $tbdCells[$slotKey] = trim($existing . ($existing !== '' ? "\n\n" : '') . $tbdText);
                $tbdCount += $missingInvigilators;
                $tbdDailyCounts[$date] = ($tbdDailyCounts[$date] ?? 0) + $missingInvigilators;
            }
        }

        $facultyRows = $faculties->map(function (Faculty $faculty) use ($counts, $dailyCounts, $cells) {
            $facultyId = (int) $faculty->id;
            $dayCounts = $dailyCounts[$facultyId] ?? [];
            $multiDutyDates = collect($dayCounts)
                ->filter(fn ($count) => (int) $count > 1)
                ->keys()
                ->values()
                ->all();

            return [
                'id' => $facultyId,
                'name' => (string) $faculty->name,
                'department' => trim((string) ($faculty->department ?: 'Unassigned')),
                'designation' => (string) ($faculty->designation ?: ''),
                'max_duties' => (int) ($faculty->max_duties ?: 0),
                'duty_count' => (int) ($counts[$facultyId] ?? 0),
                'daily_duty_counts' => $dayCounts,
                'multi_duty_dates' => $multiDutyDates,
                'has_multi_duty_day' => count($multiDutyDates) > 0,
                'cells' => $cells[$facultyId] ?? [],
            ];
        })->values();

        if ($tbdCells !== []) {
            $multiDutyDates = collect($tbdDailyCounts)
                ->filter(fn ($count) => (int) $count > 1)
                ->keys()
                ->values()
                ->all();

            $facultyRows->push([
                'id' => $tbdFacultyId,
                'name' => 'TBD / Unfilled Duties',
                'department' => 'TBD',
                'designation' => 'Unfilled invigilator positions',
                'max_duties' => 0,
                'duty_count' => $tbdCount,
                'daily_duty_counts' => $tbdDailyCounts,
                'multi_duty_dates' => $multiDutyDates,
                'has_multi_duty_day' => count($multiDutyDates) > 0,
                'cells' => $tbdCells,
                'is_tbd_row' => true,
            ]);
        }

        $facultyRows = $facultyRows->values()->all();
        $maximumAssignableDuties = collect($facultyRows)
            ->reject(fn (array $faculty) => (bool) ($faculty['is_tbd_row'] ?? false))
            ->sum(fn (array $faculty) => (int) ($faculty['max_duties'] ?? 0));

        $departments = collect($facultyRows)
            ->groupBy(fn (array $faculty) => $faculty['department'] !== '' ? $faculty['department'] : 'Unassigned')
            ->map(fn ($items, $department) => [
                'department' => (string) $department,
                'faculties' => $items->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'columns' => $columns,
            'dates' => $dates,
            'slots_by_date' => $slotsByDate,
            'faculties' => $facultyRows,
            'departments' => $departments,
            'cells' => $cells,
            'counts' => $counts,
            'summary' => [
                'maximum_assignable_duties' => (int) $maximumAssignableDuties,
                'total_duties_required' => (int) $totalDutiesRequired,
                'assigned_duties' => (int) collect($facultyRows)
                    ->reject(fn (array $faculty) => (bool) ($faculty['is_tbd_row'] ?? false))
                    ->sum(fn (array $faculty) => (int) ($faculty['duty_count'] ?? 0)),
                'unfilled_duties' => (int) $tbdCount,
            ],
        ];
    }

    public function roomCourseGrid(int $ruleId, array $slotPairs = [], bool $missingDutiesOnly = false): array
    {
        $rule = DateSheetRule::query()->findOrFail($ruleId);
        $faculties = Faculty::query()->orderBy('name')->get();
        $slotPairs = collect($slotPairs)
            ->filter(fn ($pair) => is_array($pair) && !empty($pair['date']) && !empty($pair['slot']))
            ->map(fn (array $pair) => [
                'date' => (string) $pair['date'],
                'slot' => (string) $pair['slot'],
            ])
            ->values()
            ->all();

        $allocations = RoomAllocation::query()
            ->with(['exam.course', 'room', 'invigilator'])
            ->whereHas('exam', function ($query) use ($ruleId, $slotPairs) {
                $query->where('rule_id', $ruleId);

                if ($slotPairs !== []) {
                    $query->where(function ($pairQuery) use ($slotPairs) {
                        foreach ($slotPairs as $pair) {
                            $pairQuery->orWhere(function ($query) use ($pair) {
                                $query
                                    ->whereDate('date', $pair['date'])
                                    ->where('time_slot', $pair['slot']);
                            });
                        }
                    });
                }
            })
            ->get()
            ->filter(fn (RoomAllocation $allocation) => $allocation->room && !$allocation->room->looksLikeWaitingRoom())
            ->groupBy(function (RoomAllocation $allocation) {
                return implode('|', [
                    $allocation->exam?->date?->format('Y-m-d') ?: '',
                    (string) ($allocation->exam?->time_slot ?? ''),
                    SlotHelper::physicalRoomKey($allocation->room),
                ]);
            });

        $rows = [];

        foreach ($allocations as $group) {
            $first = $group->first();
            if (!$first || !$first->exam || !$first->room) {
                continue;
            }

            $normal = $group->where('is_duty_only', false)->values();
            $primary = $normal->first() ?: $first;

            $instructorNorms = [];
            foreach ($normal as $allocation) {
                foreach (SlotHelper::splitExamInstructors((string) ($allocation->exam?->instructor ?? '')) as $name) {
                    $norm = SlotHelper::normalizePersonName($name);
                    if ($norm !== '') {
                        $instructorNorms[$norm] = true;
                    }
                }
            }

            $papers = $normal->map(function (RoomAllocation $allocation) {
                $exam = $allocation->exam;
                $lane = trim((string) ($allocation->subslot_label ?? ''));
                return [
                    'allocation_id' => (int) $allocation->id,
                    'course_code' => (string) ($exam?->course?->code ?: ''),
                    'course_title' => (string) ($exam?->course?->title ?: ''),
                    'class_label' => $exam ? $this->classLabelTextForExam($exam) : '',
                    'strength' => (int) $allocation->assigned_strength,
                    'lane' => $lane,
                ];
            })->values()->all();

            $requiredInvigilators = $this->requiredInvigilatorCount($group);
            $assignedInvigilatorCount = $group->pluck('invigilator_id')->filter()->unique()->count();
            $missingInvigilators = max(0, $requiredInvigilators - $assignedInvigilatorCount);

            if ($missingDutiesOnly && $missingInvigilators <= 0) {
                continue;
            }

            $invigilators = $group
                ->filter(fn (RoomAllocation $allocation) => $allocation->invigilator_id !== null)
                ->map(function (RoomAllocation $allocation) use ($instructorNorms) {
                    $facultyName = (string) ($allocation->invigilator?->name ?: '');
                    $facultyNorm = SlotHelper::normalizePersonName($facultyName);
                    $isCourseInstructor = $facultyNorm !== '' && isset($instructorNorms[$facultyNorm]);

                    return [
                        'allocation_id' => (int) $allocation->id,
                        'faculty_id' => (int) $allocation->invigilator_id,
                        'name' => $facultyName,
                        'is_duty_only' => (bool) $allocation->is_duty_only,
                        'is_course_instructor' => $isCourseInstructor,
                        'is_tbd' => false,
                    ];
                })
                ->values()
                ->all();

            for ($i = 0; $i < $missingInvigilators; $i++) {
                $invigilators[] = [
                    'allocation_id' => null,
                    'faculty_id' => null,
                    'name' => 'TBD',
                    'is_duty_only' => true,
                    'is_course_instructor' => false,
                    'is_tbd' => true,
                ];
            }

            $roomLabel = SlotHelper::physicalRoomLabel($first->room);
            $eligible = $this->eligibleFacultyOptionsForDuty($primary, $faculties, $rule);
            $primaryLocked = $primary->invigilator_id !== null && $this->isOwnPaperInvigilator($primary, (int) $primary->invigilator_id);

            $rows[] = [
                'key' => $first->exam->date?->format('Y-m-d') . '|' . $first->exam->time_slot . '|' . SlotHelper::physicalRoomKey($first->room),
                'date' => $first->exam->date?->format('Y-m-d'),
                'slot' => (string) $first->exam->time_slot,
                'room' => $roomLabel !== '' ? $roomLabel : (string) $first->room->room_id,
                'room_id' => (int) $primary->room_id,
                'building' => (string) $first->room->building,
                'floor' => (string) $first->room->floor,
                'subslot_label' => null,
                'primary_allocation_id' => (int) $primary->id,
                'primary_locked' => $primaryLocked,
                'papers' => $papers,
                'papers_text' => collect($papers)->map(function ($paper) {
                    $lane = $paper['lane'] ? ' '.$paper['lane'] : '';
                    return trim($paper['course_code'] . ' ' . ($paper['class_label'] ? '[' . $paper['class_label'] . ']' : '') . ' (' . $paper['strength'] . $lane . ')');
                })->implode(', '),
                'invigilators' => $invigilators,
                'invigilators_text' => collect($invigilators)->pluck('name')->filter()->implode(', ') ?: 'TBD',
                'required_invigilators' => $requiredInvigilators,
                'missing_invigilators' => $missingInvigilators,
                'eligible_faculties' => $eligible,
                'eligible_count' => count($eligible),
            ];
        }

        usort($rows, function (array $left, array $right) {
            return [
                (string) $left['date'],
                SlotHelper::normalizeSlotKey((string) $left['slot']),
                (string) $left['building'],
                (string) $left['room'],
            ] <=> [
                (string) $right['date'],
                SlotHelper::normalizeSlotKey((string) $right['slot']),
                (string) $right['building'],
                (string) $right['room'],
            ];
        });

        return ['rows' => $rows];
    }

    public function clusterDetails(int $ruleId, string $clusterKey, ?array $examIds = null): array
    {
        $rule = DateSheetRule::query()->findOrFail($ruleId);
        $allExams = $this->clusterExams($ruleId, $clusterKey);
        $exams = $this->selectedClusterExams($allExams, $examIds);
        $first = $exams->first();
        $slots = SlotHelper::buildSlotsFromRule($rule);
        $scheduledDays = Exam::query()
            ->where('rule_id', $ruleId)
            ->pluck('date')
            ->filter()
            ->map(fn ($date) => $date instanceof \Carbon\CarbonInterface ? $date->format('Y-m-d') : Carbon::parse((string) $date)->format('Y-m-d'))
            ->unique()
            ->values()
            ->all();

        $courseCodes = $exams->map(fn (Exam $exam) => (string) ($exam->course?->code ?: ''))->filter()->unique()->values();
        $courseTitles = $exams->map(fn (Exam $exam) => (string) ($exam->course?->title ?: ''))->filter()->unique()->values();
        $classLabels = $exams->flatMap(fn (Exam $exam) => $this->classLabelsForExam($exam))->filter()->unique()->values();
        $instructors = $exams->map(fn (Exam $exam) => (string) $exam->instructor)->filter()->unique()->values();
        $sameCourseGroupingKeys = $exams->map(fn (Exam $exam) => $rule->sameCourseGroupingKeyForExam($exam))->filter()->unique()->values();
        $sameCourseGroupingDescriptions = $sameCourseGroupingKeys
            ->map(fn ($key) => $rule->sameCourseGroupingDescriptionForKey((string) $key))
            ->filter()
            ->values();

        $constraints = [];
        $isPartialSelection = $exams->count() < $allExams->count();
        $constraints[] = [
            'level' => $isPartialSelection ? 'warning' : ($sameCourseGroupingKeys->isNotEmpty() ? 'hard' : 'info'),
            'title' => 'Rows affected',
            'message' => $isPartialSelection
                ? 'This save updates only the selected rows: ' . $exams->count() . ' of ' . $allExams->count() . ' linked exam row(s), ' . $classLabels->count() . ' selected class(es), selected strength ' . (int) $exams->sum('strength') . '. Unselected rows stay in their current slot.'
                : 'This modal updates the complete generated cluster: ' . $exams->count() . ' exam row(s), ' . $classLabels->count() . ' class(es), total strength ' . (int) $exams->sum('strength') . '.',
        ];

        if ($sameCourseGroupingKeys->isNotEmpty()) {
            $constraints[] = [
                'level' => $isPartialSelection ? 'warning' : 'hard',
                'title' => 'Same course together',
                'message' => $isPartialSelection
                    ? 'Same-course scheduling is active for ' . $sameCourseGroupingDescriptions->implode(', ') . '. You selected a subset, so saving can split this generated group. The selected classes are: ' . $classLabels->implode(', ') . '.'
                    : 'Same-course scheduling is active for ' . $sameCourseGroupingDescriptions->implode(', ') . '. The following classes move together: ' . $classLabels->implode(', ') . '.',
            ];
        }

        $sameDayConflictLimit = $rule->sameDayStudentConflictLimit();
        $constraints[] = [
            'level' => $rule->allow_same_day_student_conflicts || $sameDayConflictLimit > 0 ? 'warning' : 'hard',
            'title' => 'Student clash rule',
            'message' => $sameDayConflictLimit === PHP_INT_MAX
                ? 'Same-day student conflicts are allowed without a numeric cap, but same-slot student conflicts are still checked.'
                : ($sameDayConflictLimit > 0
                    ? 'Same-day student conflicts are allowed up to ' . $sameDayConflictLimit . ' student(s) for this paper. Same-slot student conflicts are still blocked.'
                    : 'Students should not have more than one paper on the same day.'),
        ];

        $preferredSlots = $this->clusterPreferredSlotLabelsForManualCheck($exams, $rule, $slots);
        if ($preferredSlots !== []) {
            $constraints[] = [
                'level' => 'warning',
                'title' => 'Slot preference',
                'message' => 'Configured slot preference for this cluster: ' . implode(', ', $preferredSlots) . '.',
            ];
        }

        $preferredDates = $this->clusterPreferredDatesForManualCheck($exams, $rule);
        if ($preferredDates !== []) {
            $constraints[] = [
                'level' => $rule->enforceCourseDatePreferences() ? 'hard' : 'warning',
                'title' => 'Preferred date',
                'message' => 'Configured date preference for this cluster: ' . implode(', ', $preferredDates) . ($rule->enforceCourseDatePreferences() ? ' (enforced).' : ' (soft preference).'),
            ];
        }

        $slotCapacity = $this->normalRoomSlotCapacity();
        if ($slotCapacity > 0) {
            $constraints[] = [
                'level' => 'info',
                'title' => 'Planning capacity',
                'message' => 'Normal-room planning capacity per slot is approximately ' . $slotCapacity . ' students. Room allocation is still a separate step.',
            ];
        }

        $validMoveTargets = $this->validClusterMoveTargets(
            $rule,
            $exams,
            $clusterKey,
            $scheduledDays,
            $slots,
            $preferredSlots,
            $preferredDates,
            !$isPartialSelection
        );

        return [
            'cluster_key' => $clusterKey,
            'current_date' => $first?->date?->format('Y-m-d'),
            'current_slot' => (string) ($first?->time_slot ?? ''),
            'course_codes' => $courseCodes->all(),
            'course_titles' => $courseTitles->all(),
            'classes' => $classLabels->all(),
            'instructors' => $instructors->all(),
            'strength' => (int) $exams->sum(fn (Exam $exam) => (int) ($exam->strength ?? 0)),
            'total_strength' => (int) $allExams->sum(fn (Exam $exam) => (int) ($exam->strength ?? 0)),
            'exam_count' => $exams->count(),
            'total_exam_count' => $allExams->count(),
            'selected_exam_ids' => $exams->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'is_partial_selection' => $isPartialSelection,
            'same_course_together' => $sameCourseGroupingKeys->isNotEmpty(),
            'grouped_course_codes' => $sameCourseGroupingDescriptions->all(),
            'preferred_slots' => $preferredSlots,
            'preferred_dates' => $preferredDates,
            'available_dates' => $validMoveTargets['dates'],
            'available_slots' => $validMoveTargets['slots'],
            'valid_move_targets' => $validMoveTargets['targets'],
            'target_slots_by_date' => $validMoveTargets['slots_by_date'],
            'exams' => $allExams->map(fn (Exam $exam) => [
                'id' => (int) $exam->id,
                'course_code' => (string) ($exam->course?->code ?: ''),
                'course_title' => (string) ($exam->course?->title ?: ''),
                'class_label' => $this->classLabelTextForExam($exam),
                'class_labels' => $this->classLabelsForExam($exam),
                'program_code' => (string) ($exam->program_code ?: ''),
                'semester_no' => $exam->semester_no !== null ? (int) $exam->semester_no : null,
                'instructor' => (string) $exam->instructor,
                'strength' => (int) ($exam->strength ?? 0),
            ])->values()->all(),
            'constraints' => $constraints,
        ];
    }

    private function validClusterMoveTargets(
        DateSheetRule $rule,
        Collection $clusterExams,
        string $clusterKey,
        array $scheduledDays,
        array $slots,
        array $preferredSlots,
        array $preferredDates,
        bool $enforceSameCourseGrouping = true
    ): array
    {
        $targets = [];
        $slotsByDate = [];
        $slotMap = [];
        $ruleId = (int) $rule->id;
        $clusterExamIds = $clusterExams->pluck('id')->map(fn ($id) => (int) $id)->all();
        $clusterStudentIds = $this->studentIdsForExamCollection($clusterExams);
        $clusterStudentSet = array_fill_keys($clusterStudentIds, true);
        $clusterStrength = (int) $clusterExams->sum(fn (Exam $exam) => (int) ($exam->strength ?? 0));
        $slotCapacity = $this->normalRoomSlotCapacity();
        $sameDayConflictLimit = $rule->sameDayStudentConflictLimit();
        $sameDayConflictsAreUnlimited = $sameDayConflictLimit === PHP_INT_MAX;
        $overallOffDays = array_flip($rule->offDaysOverallList());
        $weekendDays = $rule->weekend_days ?: [0, 6];
        $classOffDays = $rule->offDaysPerClassMap();
        $slotOffMatrix = is_array($rule->slot_off_matrix) ? $rule->slot_off_matrix : [];
        $enforcePreferredDates = $rule->enforceCourseDatePreferences();
        $currentDate = $clusterExams->first()?->date?->format('Y-m-d');
        $currentSlot = (string) ($clusterExams->first()?->time_slot ?? '');

        $groupKeys = $clusterExams
            ->map(fn (Exam $exam) => $rule->sameCourseGroupingKeyForExam($exam))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $otherSameGroupExams = collect();
        if ($enforceSameCourseGrouping && $groupKeys !== []) {
            $otherSameGroupExams = Exam::query()
                ->with('course')
                ->where('rule_id', $ruleId)
                ->whereNotIn('id', $clusterExamIds)
                ->get()
                ->filter(fn (Exam $exam) => in_array($rule->sameCourseGroupingKeyForExam($exam), $groupKeys, true))
                ->values();
        }

        $displayDays = $this->buildDisplayDays($rule, $scheduledDays);
        $allDisplayExams = Exam::query()
            ->with('course')
            ->where('rule_id', $ruleId)
            ->whereNotIn('id', $clusterExamIds)
            ->whereIn(DB::raw('DATE(date)'), $displayDays)
            ->get();

        $slotLoads = [];
        $otherStudentIdsByDate = [];
        $otherStudentIdsBySlot = [];
        foreach ($allDisplayExams as $exam) {
            $date = $exam->date?->format('Y-m-d');
            $slot = (string) $exam->time_slot;
            if (!$date || $slot === '') {
                continue;
            }

            $slotKey = $date . '|' . $slot;
            $slotLoads[$slotKey] = ((int) ($slotLoads[$slotKey] ?? 0)) + (int) ($exam->strength ?? 0);
            foreach ($this->studentIdsForExamCollection(collect([$exam])) as $studentId) {
                $studentId = (int) $studentId;
                $otherStudentIdsByDate[$date][$studentId] = true;
                $otherStudentIdsBySlot[$slotKey][$studentId] = true;
            }
        }

        foreach ($slots as $slot) {
            $label = (string) ($slot['label'] ?? $slot['id'] ?? '');
            if ($label === '') {
                continue;
            }

            $slotMap[$label] = [
                'label' => $label,
                'type' => (string) ($slot['type'] ?? ''),
                'start' => (string) ($slot['start'] ?? ''),
                'end' => (string) ($slot['end'] ?? ''),
            ];
        }

        foreach ($displayDays as $day) {
            if (isset($overallOffDays[$day])) {
                continue;
            }

            if ((bool) $rule->exclude_weekends && in_array(Carbon::parse($day)->dayOfWeek, $weekendDays, true)) {
                continue;
            }

            if ($preferredDates !== [] && $enforcePreferredDates && !in_array($day, $preferredDates, true)) {
                continue;
            }

            $dayBlocked = false;
            foreach ($clusterExams as $exam) {
                foreach ($this->groupKeysForExam($exam) as $groupKey) {
                    if (in_array($day, $classOffDays[$groupKey] ?? [], true)) {
                        $dayBlocked = true;
                        break 2;
                    }
                }
            }
            if ($dayBlocked) {
                continue;
            }

            foreach ($slotMap as $slotLabel => $slotData) {
                if ($preferredSlots !== [] && !in_array($slotLabel, $preferredSlots, true)) {
                    continue;
                }

                $slotBlocked = false;
                foreach ($clusterExams as $exam) {
                    foreach ($this->groupKeysForExam($exam) as $groupKey) {
                        $blocked = $slotOffMatrix[$day][$groupKey][$slotLabel]
                            ?? $slotOffMatrix[$day][$groupKey][SlotHelper::normalizeSlotKey($slotLabel)]
                            ?? false;
                        if ($blocked) {
                            $slotBlocked = true;
                            break 2;
                        }
                    }
                }
                if ($slotBlocked) {
                    continue;
                }

                $sameCourseSplitWarning = null;
                if ($enforceSameCourseGrouping && $otherSameGroupExams->isNotEmpty()) {
                    $separate = $otherSameGroupExams->contains(function (Exam $exam) use ($day, $slotLabel) {
                        return $exam->date?->format('Y-m-d') !== $day || (string) $exam->time_slot !== $slotLabel;
                    });
                    if ($separate) {
                        $sameCourseSplitWarning = 'Same-course grouping warning.';
                    }
                }

                $slotKey = $day . '|' . $slotLabel;
                if ($slotCapacity > 0 && (((int) ($slotLoads[$slotKey] ?? 0)) + $clusterStrength) > $slotCapacity) {
                    continue;
                }

                $sameSlotConflict = false;
                foreach (array_keys((array) ($otherStudentIdsBySlot[$slotKey] ?? [])) as $studentId) {
                    if (isset($clusterStudentSet[(int) $studentId])) {
                        $sameSlotConflict = true;
                        break;
                    }
                }
                if ($sameSlotConflict) {
                    continue;
                }

                $sameDayConflictCount = 0;
                if (!$sameDayConflictsAreUnlimited) {
                    foreach (array_keys((array) ($otherStudentIdsByDate[$day] ?? [])) as $studentId) {
                        if (isset($clusterStudentSet[(int) $studentId])) {
                            $sameDayConflictCount++;
                            if ($sameDayConflictCount > $sameDayConflictLimit) {
                                break;
                            }
                        }
                    }

                    if ($sameDayConflictCount > $sameDayConflictLimit) {
                        continue;
                    }
                }

                $warnings = [];
                if ($sameCourseSplitWarning !== null) {
                    $warnings[] = $sameCourseSplitWarning;
                }
                if ($preferredDates !== [] && !$enforcePreferredDates && !in_array($day, $preferredDates, true)) {
                    $warnings[] = 'Date preference mismatch.';
                }
                if (!$sameDayConflictsAreUnlimited && $sameDayConflictCount > 0) {
                    $warnings[] = 'Same-day student clashes: ' . $sameDayConflictCount . '.';
                }

                $target = [
                    'date' => $day,
                    'slot' => $slotLabel,
                    'slot_type' => $slotData['type'],
                    'warning_count' => count($warnings),
                    'warnings' => $warnings,
                    'same_position' => $currentDate === $day && $currentSlot === $slotLabel,
                    'same_day_student_conflicts' => $sameDayConflictCount,
                ];

                $targets[] = $target;
                $slotsByDate[$day][] = array_merge($slotData, [
                    'warning_count' => $target['warning_count'],
                    'same_position' => $target['same_position'],
                    'same_day_student_conflicts' => $target['same_day_student_conflicts'],
                ]);
            }
        }

        $dates = array_keys($slotsByDate);
        sort($dates);

        return [
            'dates' => $dates,
            'slots' => array_values($slotMap),
            'targets' => $targets,
            'slots_by_date' => $slotsByDate,
        ];
    }

    public function classDayClashReport(int $ruleId, string $date, array $classes): array
    {
        $date = Carbon::parse($date)->format('Y-m-d');
        $classes = array_values(array_unique(array_filter(array_map(
            fn ($classLabel) => trim((string) $classLabel),
            $classes
        ))));

        $exams = Exam::query()
            ->with('course')
            ->where('rule_id', $ruleId)
            ->whereDate('date', $date)
            ->orderBy('time_slot')
            ->orderBy('class_label')
            ->get();

        $reports = [];

        foreach ($classes as $classLabel) {
            $studentPapers = [];
            $matchingExams = $exams->filter(fn (Exam $exam) => in_array($classLabel, $this->classLabelsForExam($exam), true))->values();

            foreach ($matchingExams as $exam) {
                $courseCode = (string) ($exam->course?->code ?: ('COURSE-' . $exam->course_id));
                $courseTitle = (string) ($exam->course?->title ?: '');
                $courseKey = strtoupper(trim($courseCode)) ?: ('EXAM-' . $exam->id);

                foreach ($this->studentsForExamClass($exam, $classLabel) as $student) {
                    $studentId = (int) $student->id;
                    $studentPapers[$studentId] = $studentPapers[$studentId] ?? [
                        'student_id' => $studentId,
                        'registration_id' => (string) $student->registration_id,
                        'student_name' => (string) $student->name,
                        'papers' => [],
                        'course_keys' => [],
                    ];

                    $studentPapers[$studentId]['course_keys'][$courseKey] = true;
                    $studentPapers[$studentId]['papers'][] = [
                        'exam_id' => (int) $exam->id,
                        'course_code' => $courseCode,
                        'course_title' => $courseTitle,
                        'slot' => (string) $exam->time_slot,
                        'instructor' => (string) $exam->instructor,
                    ];
                }
            }

            $students = [];
            foreach ($studentPapers as $student) {
                if (count($student['course_keys']) <= 1) {
                    continue;
                }

                usort($student['papers'], fn (array $left, array $right) => [
                    (string) ($left['slot'] ?? ''),
                    (string) ($left['course_code'] ?? ''),
                ] <=> [
                    (string) ($right['slot'] ?? ''),
                    (string) ($right['course_code'] ?? ''),
                ]);

                $student['paper_count'] = count($student['course_keys']);
                $student['papers_text'] = collect($student['papers'])
                    ->map(fn (array $paper) => trim(($paper['course_code'] ?? '') . ' ' . (($paper['slot'] ?? '') !== '' ? '(' . $paper['slot'] . ')' : '')))
                    ->implode(', ');
                unset($student['course_keys']);
                $students[] = $student;
            }

            usort($students, fn (array $left, array $right) => [
                (string) ($left['registration_id'] ?? ''),
                (string) ($left['student_name'] ?? ''),
            ] <=> [
                (string) ($right['registration_id'] ?? ''),
                (string) ($right['student_name'] ?? ''),
            ]);

            $reports[] = [
                'class_label' => $classLabel,
                'student_count' => count($students),
                'exam_count' => $matchingExams->count(),
                'students' => $students,
            ];
        }

        return [
            'rule_id' => $ruleId,
            'date' => $date,
            'classes' => $reports,
            'total_students' => collect($reports)->sum('student_count'),
        ];
    }

    public function validateClusterMove(int $ruleId, string $clusterKey, string $targetDate, string $targetSlot, ?array $examIds = null, ?array $ignoredExamIds = null): array
    {
        $rule = DateSheetRule::query()->findOrFail($ruleId);
        $allExams = $this->clusterExams($ruleId, $clusterKey);
        $exams = $this->selectedClusterExams($allExams, $examIds);
        $examIds = $exams->pluck('id')->all();
        $excludedExamIds = array_values(array_unique(array_merge(
            array_map('intval', $examIds),
            array_map('intval', $ignoredExamIds ?? [])
        )));
        $slots = SlotHelper::buildSlotsFromRule($rule);
        $slotLabels = $this->manualSlotLabels($slots);
        $hardWarnings = [];
        $warnings = [];
        $notices = [];
        $studentConflicts = [];
        $sameDayConflictLimit = $rule->sameDayStudentConflictLimit();
        $sameDayConflictsAreUnlimited = $sameDayConflictLimit === PHP_INT_MAX;
        $sameDayConflictStudentIds = [];
        $sameDayConflictMessages = [];

        $targetDate = Carbon::parse($targetDate)->format('Y-m-d');
        $targetSlot = trim($targetSlot);
        $first = $exams->first();
        $currentDate = $first?->date?->format('Y-m-d');
        $currentSlot = (string) ($first?->time_slot ?? '');
        $isPartialSelection = $exams->count() < $allExams->count();

        if ($isPartialSelection) {
            $notices[] = 'Only ' . $exams->count() . ' of ' . $allExams->count() . ' linked exam row(s) are selected. Constraints are checked for the selected row(s) only; unselected rows will remain in their current slot.';
        }

        if (!in_array($targetSlot, $slotLabels, true)) {
            $hardWarnings[] = 'The selected target slot is not configured for this exam session.';
        }

        $overallOffDays = array_flip($rule->offDaysOverallList());
        if (isset($overallOffDays[$targetDate])) {
            $hardWarnings[] = 'The selected date is marked as an overall off day.';
        }

        $weekendDays = $rule->weekend_days ?: [0, 6];
        if ((bool) $rule->exclude_weekends && in_array(Carbon::parse($targetDate)->dayOfWeek, $weekendDays, true)) {
            $hardWarnings[] = 'The selected date is excluded by the weekend/off-day configuration.';
        }

        $classOffDays = $rule->offDaysPerClassMap();
        foreach ($exams as $exam) {
            foreach ($this->groupKeysForExam($exam) as $groupKey) {
                if (in_array($targetDate, $classOffDays[$groupKey] ?? [], true)) {
                    $hardWarnings[] = 'Class/program ' . $groupKey . ' is marked off on ' . $targetDate . '.';
                }
            }
        }

        $slotOffMatrix = is_array($rule->slot_off_matrix) ? $rule->slot_off_matrix : [];
        foreach ($exams as $exam) {
            foreach ($this->groupKeysForExam($exam) as $groupKey) {
                $blocked = $slotOffMatrix[$targetDate][$groupKey][$targetSlot]
                    ?? $slotOffMatrix[$targetDate][$groupKey][SlotHelper::normalizeSlotKey($targetSlot)]
                    ?? false;
                if ($blocked) {
                    $hardWarnings[] = 'Class/program ' . $groupKey . ' is blocked for slot ' . $targetSlot . ' on ' . $targetDate . '.';
                }
            }
        }

        $preferredSlots = $this->clusterPreferredSlotLabelsForManualCheck($exams, $rule, $slots);
        if ($preferredSlots !== [] && !in_array($targetSlot, $preferredSlots, true)) {
            $warnings[] = 'This paper has a configured slot preference: ' . implode(', ', $preferredSlots) . '. Target slot is ' . $targetSlot . '.';
        }

        $preferredDates = $this->clusterPreferredDatesForManualCheck($exams, $rule);
        if ($preferredDates !== [] && !in_array($targetDate, $preferredDates, true)) {
            $message = 'This paper has a configured date preference: ' . implode(', ', $preferredDates) . '. Target date is ' . $targetDate . '.';
            if ($rule->enforceCourseDatePreferences()) {
                $hardWarnings[] = $message;
            } else {
                $warnings[] = $message;
            }
        }

        if (!$isPartialSelection) {
            $groupWarnings = $this->sameCourseGroupingWarnings($rule, $exams, $excludedExamIds, $targetDate, $targetSlot);
            foreach ($groupWarnings as $message) {
                $hardWarnings[] = $message;
            }
        }

        $clusterStudentIds = $this->studentIdsForExamCollection($exams);
        $otherExamQuery = Exam::query()
            ->with('course')
            ->where('rule_id', $ruleId)
            ->whereNotIn('id', $excludedExamIds)
            ->whereDate('date', $targetDate);

        if ($sameDayConflictsAreUnlimited) {
            $otherExamQuery->where('time_slot', $targetSlot);
        }

        $otherExams = $otherExamQuery->get();
        foreach ($otherExams as $otherExam) {
            $otherStudentIds = $this->studentIdsForExamCollection(collect([$otherExam]));
            $commonIds = array_values(array_intersect($clusterStudentIds, $otherStudentIds));
            if ($commonIds === []) {
                continue;
            }

            $samples = Student::query()
                ->whereIn('id', array_slice($commonIds, 0, 5))
                ->orderBy('registration_id')
                ->get(['id', 'registration_id', 'name'])
                ->map(fn (Student $student) => trim((string) ($student->registration_id ?: $student->name)))
                ->filter()
                ->values()
                ->all();

            $message = count($commonIds) . ' student(s) clash with '
                . trim((string) ($otherExam->course?->code ?: 'another paper') . ' ' . ($this->classLabelTextForExam($otherExam) !== '' ? '[' . $this->classLabelTextForExam($otherExam) . ']' : ''))
                . ' in ' . $otherExam->time_slot
                . ($samples !== [] ? '. Sample: ' . implode(', ', $samples) : '.');

            $studentConflicts[] = [
                'exam_id' => (int) $otherExam->id,
                'course_code' => (string) ($otherExam->course?->code ?: ''),
                'class_label' => $this->classLabelTextForExam($otherExam),
                'slot' => (string) $otherExam->time_slot,
                'count' => count($commonIds),
                'sample_students' => $samples,
                'message' => $message,
                'severity' => (string) $otherExam->time_slot === $targetSlot ? 'hard' : 'same_day',
            ];

            if ((string) $otherExam->time_slot === $targetSlot) {
                $hardWarnings[] = 'Same-slot student clash: ' . $message;
                continue;
            }

            if ($sameDayConflictsAreUnlimited) {
                continue;
            }

            if ($sameDayConflictLimit > 0) {
                foreach ($commonIds as $studentId) {
                    $sameDayConflictStudentIds[(int) $studentId] = true;
                }
                $sameDayConflictMessages[] = $message;
                continue;
            }

            $hardWarnings[] = $message;
        }

        if (!$sameDayConflictsAreUnlimited && $sameDayConflictLimit > 0 && $sameDayConflictStudentIds !== []) {
            $sameDayConflictCount = count($sameDayConflictStudentIds);
            $message = 'Same-day student clash count is ' . $sameDayConflictCount . ' student(s); configured limit is ' . $sameDayConflictLimit . '.';
            if ($sameDayConflictCount > $sameDayConflictLimit) {
                $hardWarnings[] = $message;
                foreach ($sameDayConflictMessages as $conflictMessage) {
                    $hardWarnings[] = $conflictMessage;
                }
            } else {
                $warnings[] = $message . ' This move is allowed by the configured limit.';
            }
        }

        $slotCapacity = $this->normalRoomSlotCapacity();
        $clusterStrength = (int) $exams->sum(fn (Exam $exam) => (int) ($exam->strength ?? 0));
        if ($slotCapacity > 0) {
            $existingStrength = (int) Exam::query()
                ->where('rule_id', $ruleId)
                ->whereNotIn('id', $excludedExamIds)
                ->whereDate('date', $targetDate)
                ->where('time_slot', $targetSlot)
                ->sum('strength');
            if (($existingStrength + $clusterStrength) > $slotCapacity) {
                $hardWarnings[] = 'Target slot capacity warning: existing strength ' . $existingStrength . ' + this cluster strength ' . $clusterStrength . ' exceeds normal-room planning capacity ' . $slotCapacity . '.';
            }
        }

        $hardWarnings = array_values(array_unique($hardWarnings));
        $warnings = array_values(array_unique($warnings));

        return [
            'ok' => $hardWarnings === [],
            'same_position' => $currentDate === $targetDate && $currentSlot === $targetSlot,
            'cluster_key' => $clusterKey,
            'selected_exam_ids' => $exams->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'is_partial_selection' => $isPartialSelection,
            'target_date' => $targetDate,
            'target_slot' => $targetSlot,
            'current_date' => $currentDate,
            'current_slot' => $currentSlot,
            'hard_warnings' => $hardWarnings,
            'warnings' => $warnings,
            'notices' => $notices,
            'student_conflicts' => $studentConflicts,
            'summary' => [
                'exam_rows' => $exams->count(),
                'strength' => $clusterStrength,
                'classes' => $exams->flatMap(fn (Exam $exam) => $this->classLabelsForExam($exam))->filter()->unique()->values()->all(),
                'course_codes' => $exams->map(fn (Exam $exam) => (string) ($exam->course?->code ?: ''))->filter()->unique()->values()->all(),
                'preferred_slots' => $preferredSlots,
                'preferred_dates' => $preferredDates,
                'slot_capacity' => $slotCapacity,
                'same_day_student_conflict_limit' => $sameDayConflictLimit === PHP_INT_MAX ? null : $sameDayConflictLimit,
                'same_day_student_conflicts' => count($sameDayConflictStudentIds),
            ],
        ];
    }

    public function validateClusterSwap(int $ruleId, string $leftClusterKey, string $rightClusterKey, ?array $leftExamIds = null, ?array $rightExamIds = null): array
    {
        $leftAllExams = $this->clusterExams($ruleId, $leftClusterKey);
        $rightAllExams = $this->clusterExams($ruleId, $rightClusterKey);
        $leftExams = $this->selectedClusterExams($leftAllExams, $leftExamIds);
        $rightExams = $this->selectedClusterExams($rightAllExams, $rightExamIds);

        $leftIds = $leftExams->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $rightIds = $rightExams->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        if (array_intersect($leftIds, $rightIds) !== []) {
            abort(422, 'The same exam row cannot be selected on both sides of a swap.');
        }

        $leftFirst = $leftExams->first();
        $rightFirst = $rightExams->first();
        if (!$leftFirst?->date || !$rightFirst?->date) {
            abort(422, 'Both swap sides must be attached to dated exam slots.');
        }

        $leftDate = $leftFirst->date->format('Y-m-d');
        $leftSlot = (string) $leftFirst->time_slot;
        $rightDate = $rightFirst->date->format('Y-m-d');
        $rightSlot = (string) $rightFirst->time_slot;

        $hardWarnings = [];
        if ($leftDate === $rightDate && $leftSlot === $rightSlot) {
            $hardWarnings[] = 'Both selected paper groups are already in the same date/slot.';
        }

        $leftValidation = $this->validateClusterMove($ruleId, $leftClusterKey, $rightDate, $rightSlot, $leftIds, $rightIds);
        $rightValidation = $this->validateClusterMove($ruleId, $rightClusterKey, $leftDate, $leftSlot, $rightIds, $leftIds);

        $hardWarnings = array_values(array_unique(array_merge(
            $hardWarnings,
            array_map(fn ($message) => 'First paper: ' . $message, $leftValidation['hard_warnings'] ?? []),
            array_map(fn ($message) => 'Second paper: ' . $message, $rightValidation['hard_warnings'] ?? [])
        )));

        return [
            'ok' => $hardWarnings === [],
            'hard_warnings' => $hardWarnings,
            'warnings' => array_values(array_unique(array_merge(
                array_map(fn ($message) => 'First paper: ' . $message, $leftValidation['warnings'] ?? []),
                array_map(fn ($message) => 'Second paper: ' . $message, $rightValidation['warnings'] ?? [])
            ))),
            'notices' => array_values(array_unique(array_merge(
                array_map(fn ($message) => 'First paper: ' . $message, $leftValidation['notices'] ?? []),
                array_map(fn ($message) => 'Second paper: ' . $message, $rightValidation['notices'] ?? [])
            ))),
            'left' => [
                'cluster_key' => $leftClusterKey,
                'selected_exam_ids' => $leftIds,
                'current_date' => $leftDate,
                'current_slot' => $leftSlot,
                'target_date' => $rightDate,
                'target_slot' => $rightSlot,
                'validation' => $leftValidation,
            ],
            'right' => [
                'cluster_key' => $rightClusterKey,
                'selected_exam_ids' => $rightIds,
                'current_date' => $rightDate,
                'current_slot' => $rightSlot,
                'target_date' => $leftDate,
                'target_slot' => $leftSlot,
                'validation' => $rightValidation,
            ],
            'student_conflicts' => [
                'left' => $leftValidation['student_conflicts'] ?? [],
                'right' => $rightValidation['student_conflicts'] ?? [],
            ],
        ];
    }

    public function swapClusters(int $ruleId, string $leftClusterKey, string $rightClusterKey, bool $force = false, ?array $leftExamIds = null, ?array $rightExamIds = null): array
    {
        $validation = $this->validateClusterSwap($ruleId, $leftClusterKey, $rightClusterKey, $leftExamIds, $rightExamIds);
        if (!$force && ($validation['hard_warnings'] ?? []) !== []) {
            return [
                'ok' => false,
                'needs_confirmation' => true,
                'message' => 'Swap has rule warnings. Review and confirm before saving.',
                'validation' => $validation,
            ];
        }

        $leftIds = $validation['left']['selected_exam_ids'] ?? [];
        $rightIds = $validation['right']['selected_exam_ids'] ?? [];
        $leftTargetDate = (string) ($validation['left']['target_date'] ?? '');
        $leftTargetSlot = (string) ($validation['left']['target_slot'] ?? '');
        $rightTargetDate = (string) ($validation['right']['target_date'] ?? '');
        $rightTargetSlot = (string) ($validation['right']['target_slot'] ?? '');
        $affectedPairs = collect([
            ['date' => $validation['left']['current_date'] ?? '', 'slot' => $validation['left']['current_slot'] ?? ''],
            ['date' => $validation['right']['current_date'] ?? '', 'slot' => $validation['right']['current_slot'] ?? ''],
            ['date' => $leftTargetDate, 'slot' => $leftTargetSlot],
            ['date' => $rightTargetDate, 'slot' => $rightTargetSlot],
        ])->filter(fn (array $pair) => (string) $pair['date'] !== '' && (string) $pair['slot'] !== '')
            ->unique(fn (array $pair) => $pair['date'] . '|' . $pair['slot'])
            ->values();

        DB::transaction(function () use ($leftIds, $rightIds, $leftTargetDate, $leftTargetSlot, $rightTargetDate, $rightTargetSlot) {
            $allIds = array_values(array_unique(array_merge($leftIds, $rightIds)));

            SeatingAssignment::query()->whereIn('allocation_id', function ($query) use ($allIds) {
                $query->select('id')->from('scheduler_roomallocation')->whereIn('exam_id', $allIds);
            })->delete();

            RoomAllocation::query()->whereIn('exam_id', $allIds)->delete();

            Exam::query()->whereIn('id', $leftIds)->update([
                'date' => $leftTargetDate,
                'time_slot' => $leftTargetSlot,
            ]);

            Exam::query()->whereIn('id', $rightIds)->update([
                'date' => $rightTargetDate,
                'time_slot' => $rightTargetSlot,
            ]);
        });

        foreach ($affectedPairs as $pair) {
            $this->clearDutyOnlyRowsForSlot($ruleId, (string) $pair['date'], (string) $pair['slot']);
        }

        return [
            'ok' => true,
            'validation' => $validation,
            'warnings' => array_merge(
                $validation['warnings'] ?? [],
                $validation['hard_warnings'] ?? [],
                ['Papers swapped. Room allocation, seating, and invigilation for affected papers were cleared. Run room allocation/seating/roster again for affected slots.']
            ),
        ];
    }

    public function rescheduleCluster(int $ruleId, string $clusterKey, string $targetDate, string $targetSlot, bool $force = false, ?array $selectedExamIds = null): array
    {
        $validation = $this->validateClusterMove($ruleId, $clusterKey, $targetDate, $targetSlot, $selectedExamIds);
        if (!$force && ($validation['hard_warnings'] ?? []) !== []) {
            return [
                'ok' => false,
                'needs_confirmation' => true,
                'message' => 'Reschedule has rule warnings. Review and confirm before saving.',
                'validation' => $validation,
            ];
        }

        $allExams = $this->clusterExams($ruleId, $clusterKey);
        $exams = $this->selectedClusterExams($allExams, $selectedExamIds);
        $isPartialSelection = $exams->count() < $allExams->count();
        $sourcePairs = $exams
            ->map(fn (Exam $exam) => [
                'date' => $exam->date?->format('Y-m-d'),
                'slot' => (string) $exam->time_slot,
            ])
            ->unique(fn ($pair) => $pair['date'] . '|' . $pair['slot'])
            ->values()
            ->all();

        $examIds = $exams->pluck('id')->all();
        $targetDate = Carbon::parse($targetDate)->format('Y-m-d');
        $targetSlot = trim($targetSlot);

        DB::transaction(function () use ($exams, $examIds, $targetDate, $targetSlot) {
            SeatingAssignment::query()->whereIn('allocation_id', function ($query) use ($examIds) {
                $query->select('id')->from('scheduler_roomallocation')->whereIn('exam_id', $examIds);
            })->delete();

            RoomAllocation::query()->whereIn('exam_id', $examIds)->delete();

            foreach ($exams as $exam) {
                $exam->date = $targetDate;
                $exam->time_slot = $targetSlot;
                $exam->save();
            }
        });

        $affectedPairs = $sourcePairs;
        $affectedPairs[] = ['date' => $targetDate, 'slot' => $targetSlot];
        $affectedPairs = collect($affectedPairs)->unique(fn ($pair) => $pair['date'] . '|' . $pair['slot'])->values();

        foreach ($affectedPairs as $pair) {
            $date = (string) ($pair['date'] ?? '');
            $slot = (string) ($pair['slot'] ?? '');
            if ($date !== '' && $slot !== '') {
                $this->clearDutyOnlyRowsForSlot($ruleId, $date, $slot);
            }
        }

        $rule = DateSheetRule::query()->findOrFail($ruleId);
        $board = $this->datesheetBoard($rule);

        return [
            'ok' => true,
            'cluster_key' => $clusterKey,
            'selected_exam_ids' => $exams->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'is_partial_selection' => $isPartialSelection,
            'target_date' => $targetDate,
            'target_slot' => $targetSlot,
            'validation' => $validation,
            'warnings' => array_merge(
                $validation['warnings'] ?? [],
                $validation['hard_warnings'] ?? [],
                ['Paper rescheduled. Room allocation, seating, and invigilation for the moved paper were cleared. Run room allocation/seating/roster again for affected slots.']
            ),
            'board_days' => $board['days'],
        ];
    }

    private function clusterExams(int $ruleId, string $clusterKey): Collection
    {
        $exams = Exam::query()
            ->with('course')
            ->where('rule_id', $ruleId)
            ->where('cluster_key', $clusterKey)
            ->orderBy('class_label')
            ->orderBy('program_code')
            ->get();

        if ($exams->isEmpty() && str_starts_with($clusterKey, 'EXAM-')) {
            $examId = (int) substr($clusterKey, 5);
            $exams = Exam::query()
                ->with('course')
                ->where('rule_id', $ruleId)
                ->where('id', $examId)
                ->get();
        }

        if ($exams->isEmpty()) {
            abort(404, 'Cluster not found for the selected session.');
        }

        return $exams;
    }

    private function selectedClusterExams(Collection $clusterExams, ?array $examIds): Collection
    {
        $ids = collect($examIds ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            if ($examIds !== null) {
                abort(422, 'Select at least one exam row to reschedule.');
            }

            return $clusterExams->values();
        }

        $idSet = array_fill_keys($ids->all(), true);
        $selected = $clusterExams
            ->filter(fn (Exam $exam) => isset($idSet[(int) $exam->id]))
            ->values();

        if ($selected->count() !== $ids->count()) {
            abort(422, 'One or more selected exam rows no longer belong to this paper group.');
        }

        return $selected;
    }

    private function classLabelTextForExam(Exam $exam): string
    {
        return implode(', ', $this->classLabelsForExam($exam));
    }

    private function classLabelsForExam(Exam $exam): array
    {
        $labels = [];

        foreach ([(string) ($exam->class_label ?: $exam->program_code ?: '')] as $label) {
            $label = trim($label);
            if ($label !== '') {
                $labels[$label] = true;
            }
        }

        $raw = trim((string) ($exam->merged_class_labels ?? ''));
        if ($raw !== '') {
            foreach (preg_split('/[|;,]+/', $raw) ?: [] as $label) {
                $label = trim((string) $label);
                if ($label !== '') {
                    $labels[$label] = true;
                }
            }
        }

        return array_keys($labels);
    }

    private function groupKeysForExam(Exam $exam): array
    {
        $keys = [];

        foreach ($this->classLabelsForExam($exam) as $classLabel) {
            $keys[$classLabel] = true;
        }

        $programCode = trim((string) $exam->program_code);
        if ($programCode !== '') {
            $keys[$programCode] = true;
        }

        return array_keys($keys);
    }

    private function studentsForExamClass(Exam $exam, string $classLabel): Collection
    {
        if (!$exam->course_id) {
            return collect();
        }

        $studentIds = $this->studentIdsForExamClass($exam, $classLabel);

        if ($studentIds === []) {
            return collect();
        }

        return Student::query()
            ->whereIn('id', $studentIds)
            ->orderBy('registration_id')
            ->get(['id', 'registration_id', 'name']);
    }

    private function studentIdsForExamClass(Exam $exam, string $classLabel): array
    {
        if (!$exam->course_id) {
            return [];
        }

        return CourseRegistration::query()
            ->where('rule_id', (int) $exam->rule_id)
            ->where('course_id', (int) $exam->course_id)
            ->where('class_label', $classLabel)
            ->when((string) $exam->instructor !== '', fn ($query) => $query->where('instructor_name', (string) $exam->instructor))
            ->pluck('student_id')
            ->unique()
            ->values()
            ->all();
    }

    private function studentIdsForExamCollection(Collection $exams): array
    {
        $ids = [];
        foreach ($exams as $exam) {
            if (!$exam instanceof Exam) {
                continue;
            }

            foreach ($this->studentResolver->studentsForExam($exam)->pluck('id')->all() as $studentId) {
                $ids[(int) $studentId] = true;
            }
        }

        return array_keys($ids);
    }

    private function sameCourseGroupingWarnings(DateSheetRule $rule, Collection $exams, array $examIds, string $targetDate, string $targetSlot): array
    {
        $warnings = [];
        $groupKeys = $exams
            ->map(fn (Exam $exam) => $rule->sameCourseGroupingKeyForExam($exam))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($groupKeys === []) {
            return [];
        }

        $allOtherExams = Exam::query()
            ->with('course')
            ->where('rule_id', (int) $rule->id)
            ->whereNotIn('id', $examIds)
            ->get();

        foreach ($groupKeys as $groupKey) {
            $others = $allOtherExams->filter(fn (Exam $exam) => $rule->sameCourseGroupingKeyForExam($exam) === $groupKey);

            $separate = $others->filter(function (Exam $exam) use ($targetDate, $targetSlot) {
                return $exam->date?->format('Y-m-d') !== $targetDate || (string) $exam->time_slot !== $targetSlot;
            });

            if ($separate->isNotEmpty()) {
                $warnings[] = 'Same-course grouping is active for ' . $rule->sameCourseGroupingDescriptionForKey((string) $groupKey) . ', but ' . $separate->count() . ' other generated row(s) in this configured group are not in the selected target slot.';
            }
        }

        return $warnings;
    }

    private function normalRoomSlotCapacity(): int
    {
        $rooms = Room::query()->get();
        $usable = $rooms->filter(fn (Room $room) => $room->isAllocatableExamRoom());
        if ($usable->isEmpty()) {
            $usable = $rooms->filter(fn (Room $room) => $room->isUsableNonWaitingRoom());
        }

        return (int) $usable->sum(fn (Room $room) => (int) $room->effectiveCapacity());
    }

    private function clusterPreferredDatesForManualCheck(Collection $exams, DateSheetRule $rule): array
    {
        $offerings = $exams->map(fn (Exam $exam) => [
            'course_code' => (string) ($exam->course?->code ?: ''),
            'program_code' => (string) ($exam->program_code ?: ''),
            'class_label' => (string) ($exam->class_label ?: ''),
        ])->values()->all();

        return $rule->preferredDatesForOfferings($offerings);
    }

    private function clusterPreferredSlotLabelsForManualCheck(Collection $exams, DateSheetRule $rule, array $slots): array
    {
        if ($slots === []) {
            return [];
        }

        $offerings = $exams->map(fn (Exam $exam) => [
            'course_code' => (string) ($exam->course?->code ?: ''),
            'program_code' => (string) ($exam->program_code ?: ''),
            'class_label' => (string) ($exam->class_label ?: ''),
        ])->values()->all();

        $coursePreferences = $rule->preferredSlotPreferencesForOfferings($offerings);
        $labels = [];

        foreach ($coursePreferences as $preference) {
            foreach ($this->resolveManualSlotPreferenceLabels((string) $preference, $slots) as $label) {
                $labels[$label] = true;
            }
        }

        if ($labels !== []) {
            return array_keys($labels);
        }

        $hasPostgraduate = false;
        $hasUndergraduate = false;
        foreach ($exams as $exam) {
            $offering = [
                'course_code' => (string) ($exam->course?->code ?: ''),
                'program_code' => (string) $exam->program_code,
                'class_label' => (string) $exam->class_label,
            ];
            if ($rule->isPostgraduateOffering($offering)) {
                $hasPostgraduate = true;
            } elseif ($rule->isUndergraduateOffering($offering)) {
                $hasUndergraduate = true;
            }
        }

        if ($hasPostgraduate && (bool) ($rule->schedule_pg_courses_in_preferred_slot ?? true)) {
            return $this->resolveManualSlotPreferenceLabels((string) ($rule->pg_slot_preference ?? 'EVENING'), $slots);
        }

        if (!$hasPostgraduate && $hasUndergraduate && (bool) ($rule->exclude_pg_preferred_slot_for_bs ?? true)) {
            $pgLabels = $this->resolveManualSlotPreferenceLabels((string) ($rule->pg_slot_preference ?? 'EVENING'), $slots);
            if ($pgLabels === []) {
                return $this->resolveManualSlotPreferenceLabels('NOT_EVENING', $slots);
            }

            $blocked = array_flip($pgLabels);
            $allowed = [];
            foreach ($slots as $slot) {
                $label = (string) ($slot['label'] ?? $slot['id'] ?? '');
                if ($label !== '' && !isset($blocked[$label])) {
                    $allowed[] = $label;
                }
            }

            return $allowed !== [] ? $allowed : [];
        }

        return [];
    }

    private function manualSlotLabels(array $slots): array
    {
        return array_values(array_filter(array_map(fn ($slot) => (string) ($slot['label'] ?? $slot['id'] ?? ''), $slots)));
    }

    private function resolveManualSlotPreferenceLabels(string $preference, array $slots): array
    {
        $preferences = preg_split('/\s*[;|]\s*/', trim($preference)) ?: [];
        $labels = [];

        foreach ($preferences as $item) {
            foreach ($this->resolveManualSingleSlotPreferenceLabels((string) $item, $slots) as $label) {
                if ($label !== '') {
                    $labels[$label] = true;
                }
            }
        }

        return array_keys($labels);
    }

    private function resolveManualSingleSlotPreferenceLabels(string $preference, array $slots): array
    {
        $preference = trim($preference);
        if ($preference === '' || $slots === []) {
            return [];
        }

        $upper = strtoupper($preference);
        $slotLabels = $this->manualSlotLabels($slots);

        if (in_array($upper, ['ALL', 'ANY', '*'], true)) {
            return $slotLabels;
        }

        if (in_array($upper, ['LAST', 'EVENING', 'PG', 'POSTGRAD', 'POSTGRADUATE'], true)) {
            $typed = $this->labelsByManualSlotType($slots, 'EVENING');
            if ($typed !== []) {
                return $typed;
            }
            $last = end($slots);
            return is_array($last) ? [(string) ($last['label'] ?? $last['id'] ?? '')] : [];
        }

        if (in_array($upper, ['NOT_EVENING', 'NON_EVENING', 'OTHER', 'REGULAR', 'BS_ONLY', 'UG'], true)) {
            $allowed = [];
            foreach ($slots as $slot) {
                $label = (string) ($slot['label'] ?? $slot['id'] ?? '');
                $type = strtoupper((string) ($slot['type'] ?? ''));
                if ($label !== '' && $type !== 'EVENING') {
                    $allowed[] = $label;
                }
            }
            return $allowed !== [] ? $allowed : (count($slotLabels) > 1 ? array_slice($slotLabels, 0, -1) : []);
        }

        if (in_array($upper, ['FIRST', 'MORNING'], true)) {
            $typed = $this->labelsByManualSlotType($slots, 'MORNING');
            if ($typed !== []) {
                return $typed;
            }
            return isset($slots[0]) ? [(string) ($slots[0]['label'] ?? $slots[0]['id'] ?? '')] : [];
        }

        if ($upper === 'AFTERNOON') {
            $typed = $this->labelsByManualSlotType($slots, 'AFTERNOON');
            if ($typed !== []) {
                return $typed;
            }
            return count($slotLabels) > 2 ? array_slice($slotLabels, 1, -1) : [];
        }

        if (preg_match('/^(?:SLOT|S)?\s*(\d+)$/i', $preference, $matches)) {
            $index = max(0, ((int) $matches[1]) - 1);
            return isset($slots[$index]) ? [(string) ($slots[$index]['label'] ?? $slots[$index]['id'] ?? '')] : [];
        }

        $normalized = SlotHelper::normalizeSlotKey($preference);
        foreach ($slots as $slot) {
            $label = (string) ($slot['label'] ?? '');
            $id = (string) ($slot['id'] ?? '');
            if ($normalized !== '' && (SlotHelper::normalizeSlotKey($label) === $normalized || SlotHelper::normalizeSlotKey($id) === $normalized)) {
                return [$label !== '' ? $label : $id];
            }
        }

        return [];
    }

    private function labelsByManualSlotType(array $slots, string $type): array
    {
        $labels = [];
        foreach ($slots as $slot) {
            if (strtoupper((string) ($slot['type'] ?? '')) !== $type) {
                continue;
            }
            $label = (string) ($slot['label'] ?? $slot['id'] ?? '');
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    public function moveCluster(int $ruleId, string $clusterKey, string $targetDate, string $targetSlot): array
    {
        // Kept for backward compatibility with older UI/API callers. The v22
        // datesheet board uses rescheduleCluster() after a modal preflight check.
        return $this->rescheduleCluster($ruleId, $clusterKey, $targetDate, $targetSlot, true);
    }

    public function moveAllocation(int $allocationId, int $targetRoomId, ?string $targetSubslotLabel): array
    {
        return $this->moveAllocations([$allocationId], $targetRoomId, $targetSubslotLabel);
    }

    public function moveAllocations(array $allocationIds, int $targetRoomId, ?string $targetSubslotLabel): array
    {
        $allocationIds = array_values(array_unique(array_filter(array_map('intval', $allocationIds))));
        if ($allocationIds === []) {
            abort(422, 'Select at least one paper segment to move.');
        }

        $allocations = RoomAllocation::query()
            ->with(['exam', 'room'])
            ->whereIn('id', $allocationIds)
            ->where('is_duty_only', false)
            ->get();

        if ($allocations->count() !== count($allocationIds)) {
            abort(404, 'One or more selected paper segments were not found.');
        }

        $allocation = $allocations->first();
        $exam = $allocation?->exam;
        if (!$exam || !$exam->date) {
            abort(422, 'The selected allocation is not attached to a dated exam slot.');
        }

        $date = $exam->date->format('Y-m-d');
        $slot = (string) $exam->time_slot;
        foreach ($allocations as $selectedAllocation) {
            $selectedExam = $selectedAllocation->exam;
            if (
                !$selectedExam
                || !$selectedExam->date
                || (int) $selectedExam->rule_id !== (int) $exam->rule_id
                || $selectedExam->date->format('Y-m-d') !== $date
                || (string) $selectedExam->time_slot !== $slot
            ) {
                abort(422, 'All selected paper segments must belong to the same dated exam slot.');
            }
        }

        $targetRoom = Room::query()->findOrFail($targetRoomId);
        if ($targetRoom->looksLikeWaitingRoom()) {
            abort(422, 'Waiting rooms cannot be used as exam destinations in the manual room allocation board. Waiting rooms are filled only by seat-plan overflow.');
        }

        $rule = DateSheetRule::query()->findOrFail((int) $exam->rule_id);
        $targetSubslotLabel = $this->normalizeSubslotLabel($targetSubslotLabel);

        $laneCapacity = $this->lanePlanningCapacity($rule, $targetRoom, $targetSubslotLabel);
        $occupiedQuery = RoomAllocation::query()
            ->where('is_duty_only', false)
            ->where('room_id', $targetRoomId)
            ->whereNotIn('id', $allocationIds)
            ->whereHas('exam', function ($query) use ($exam) {
                $query->where('rule_id', $exam->rule_id)
                    ->whereDate('date', $exam->date->format('Y-m-d'))
                    ->where('time_slot', $exam->time_slot);
            });

        if ($targetSubslotLabel !== null) {
            $occupiedQuery->where(function ($query) use ($targetSubslotLabel) {
                $this->whereSubslot($query, $targetSubslotLabel);
            });
        }

        $occupied = (int) $occupiedQuery->sum('assigned_strength');
        $movingStrength = (int) $allocations->sum(fn (RoomAllocation $item) => (int) $item->assigned_strength);

        if (($occupied + $movingStrength) > $laneCapacity) {
            abort(422, 'Target room lane does not have enough remaining planning capacity for the selected paper segment(s). Planning capacity allows room capacity +5 only.');
        }

        $targetBuilding = trim((string) $targetRoom->building);
        foreach ($allocations as $selectedAllocation) {
            $selectedExam = $selectedAllocation->exam;
            $otherClusterBuildings = RoomAllocation::query()
                ->with(['exam', 'room'])
                ->whereNotIn('id', $allocationIds)
                ->where('is_duty_only', false)
                ->whereHas('exam', function ($query) use ($selectedExam) {
                    $query->where('rule_id', $selectedExam->rule_id)
                        ->whereDate('date', $selectedExam->date->format('Y-m-d'))
                        ->where('time_slot', $selectedExam->time_slot)
                        ->where('cluster_key', $selectedExam->cluster_key);
                })
                ->get()
                ->pluck('room.building')
                ->filter(fn ($building) => trim((string) $building) !== '')
                ->unique()
                ->values();

            if ($otherClusterBuildings->isNotEmpty() && $targetBuilding !== '' && !$otherClusterBuildings->contains($targetBuilding)) {
                abort(422, 'This move would split the same paper across different buildings.');
            }
        }

        DB::transaction(function () use ($allocationIds, $targetRoomId, $targetSubslotLabel, $exam, $date, $slot) {
            RoomAllocation::query()
                ->whereIn('id', $allocationIds)
                ->update([
                    'room_id' => $targetRoomId,
                    'subslot_label' => $targetSubslotLabel,
                ]);

            // Room allocation has changed. Seating and invigilation are room
            // dependent, so clear them and let the user run the next steps.
            $slotExamIds = Exam::query()
                ->where('rule_id', $exam->rule_id)
                ->whereDate('date', $date)
                ->where('time_slot', $slot)
                ->pluck('id')
                ->all();

            SeatingAssignment::query()->whereIn('allocation_id', function ($query) use ($slotExamIds) {
                $query->select('id')->from('scheduler_roomallocation')->whereIn('exam_id', $slotExamIds);
            })->delete();

            RoomAllocation::query()
                ->whereIn('exam_id', $slotExamIds)
                ->where('is_duty_only', true)
                ->delete();

            RoomAllocation::query()
                ->whereIn('exam_id', $slotExamIds)
                ->where('is_duty_only', false)
                ->update(['invigilator_id' => null]);
        });

        return [
            'ok' => true,
            'allocation_id' => $allocationIds[0],
            'allocation_ids' => $allocationIds,
            'date' => $date,
            'slot' => $slot,
            'warnings' => [
                'Room allocation updated. Seating and invigilators were cleared for this slot; generate seating, then assign invigilators.',
            ],
        ];
    }

    public function moveSeatAssignments(array $assignmentIds, int $targetRoomId, ?int $targetRow = null, ?int $targetCol = null, ?string $targetSubslotLabel = null): array
    {
        $assignmentIds = array_values(array_unique(array_map('intval', $assignmentIds)));
        if ($assignmentIds === []) {
            abort(422, 'Select at least one student seat to move.');
        }

        $assignments = SeatingAssignment::query()
            ->with(['allocation.exam.course', 'allocation.room', 'student'])
            ->whereIn('id', $assignmentIds)
            ->orderBy('seat_row')
            ->orderBy('seat_col')
            ->get();

        if ($assignments->isEmpty()) {
            abort(404, 'Selected seat assignments were not found.');
        }

        $first = $assignments->first();
        $exam = $first->allocation?->exam;
        if (!$exam || !$exam->date) {
            abort(422, 'The selected seats are not attached to a valid exam slot.');
        }

        $date = $exam->date->format('Y-m-d');
        $slot = (string) $exam->time_slot;
        $ruleId = (int) $exam->rule_id;

        foreach ($assignments as $assignment) {
            $itemExam = $assignment->allocation?->exam;
            if (!$itemExam || !$itemExam->date || $itemExam->date->format('Y-m-d') !== $date || (string) $itemExam->time_slot !== $slot || (int) $itemExam->rule_id !== $ruleId) {
                abort(422, 'All selected students must belong to the same date and slot.');
            }
        }

        $targetRoom = Room::query()->findOrFail($targetRoomId);
        $targetSubslotLabel = $this->normalizeSubslotLabel($targetSubslotLabel);
        $positions = $this->freePositionsForRoom($targetRoom, $ruleId, $date, $slot, $assignmentIds, $targetSubslotLabel, $targetRow, $targetCol);

        if (count($positions) < $assignments->count()) {
            abort(422, 'Target room does not have enough free seats for the selected student(s).');
        }

        $affectedAllocationIds = $assignments->pluck('allocation_id')->map(fn ($value) => (int) $value)->all();

        DB::transaction(function () use ($assignments, $targetRoom, $targetSubslotLabel, $positions, &$affectedAllocationIds) {
            $positionIndex = 0;

            foreach ($assignments as $assignment) {
                $sourceAllocation = $assignment->allocation;
                $targetAllocation = RoomAllocation::query()
                    ->where('is_duty_only', false)
                    ->where('exam_id', $sourceAllocation->exam_id)
                    ->where('room_id', $targetRoom->id)
                    ->where(function ($query) use ($targetSubslotLabel) {
                        $this->whereSubslot($query, $targetSubslotLabel);
                    })
                    ->first();

                if (!$targetAllocation) {
                    $targetAllocation = RoomAllocation::query()->create([
                        'exam_id' => $sourceAllocation->exam_id,
                        'room_id' => $targetRoom->id,
                        'invigilator_id' => null,
                        'assigned_strength' => 0,
                        'subslot_label' => $targetSubslotLabel,
                        'is_duty_only' => false,
                    ]);
                }

                $position = $positions[$positionIndex++];

                $assignment->allocation_id = $targetAllocation->id;
                $assignment->seat_row = $position['row'];
                $assignment->seat_col = $position['col'];
                $assignment->save();

                $affectedAllocationIds[] = (int) $targetAllocation->id;
            }
        });

        $this->syncAllocationStrengths(array_values(array_unique($affectedAllocationIds)));
        $warnings = $this->adjacentCourseWarnings($targetRoomId, $ruleId, $date, $slot);

        return [
            'ok' => true,
            'moved' => $assignments->count(),
            'date' => $date,
            'slot' => $slot,
            'warnings' => $warnings,
        ];
    }

    public function moveSeatRow(int $ruleId, string $date, string $slot, int $sourceRoomId, int $sourceRow, int $targetRoomId, ?int $targetRow = null): array
    {
        $ids = SeatingAssignment::query()
            ->where('seat_row', $sourceRow)
            ->whereHas('allocation', function ($query) use ($sourceRoomId) {
                $query->where('room_id', $sourceRoomId);
            })
            ->whereHas('allocation.exam', function ($query) use ($ruleId, $date, $slot) {
                $query->where('rule_id', $ruleId)->whereDate('date', $date)->where('time_slot', $slot);
            })
            ->orderBy('seat_col')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            abort(422, 'No students were found in the selected source row.');
        }

        return $this->moveSeatAssignments($ids, $targetRoomId, $targetRow, 1, null);
    }

    public function moveInvigilator(int $sourceAllocationId, int $targetAllocationId, bool $swap = true): array
    {
        $source = RoomAllocation::query()->with(['exam', 'room', 'invigilator'])->findOrFail($sourceAllocationId);
        $target = RoomAllocation::query()->with(['exam', 'room', 'invigilator'])->findOrFail($targetAllocationId);

        if (!$source->invigilator_id) {
            abort(422, 'The source duty does not have an invigilator to move.');
        }

        if (!$source->exam || !$target->exam || (int) $source->exam->rule_id !== (int) $target->exam->rule_id) {
            abort(422, 'Invigilators can only be moved within the same exam session.');
        }

        $sourceFacultyId = (int) $source->invigilator_id;
        $targetFacultyId = $target->invigilator_id ? (int) $target->invigilator_id : null;

        if ($this->isOwnPaperInvigilator($source, $sourceFacultyId)) {
            abort(422, 'This invigilator is the course instructor for this physical room and cannot be moved or swapped.');
        }

        if ($targetFacultyId !== null && $this->isOwnPaperInvigilator($target, $targetFacultyId)) {
            abort(422, 'You cannot swap with an invigilator who is the course instructor for that paper.');
        }

        $rule = DateSheetRule::query()->findOrFail((int) $source->exam->rule_id);
        $ignoreIds = array_values(array_unique(array_filter([$source->id, $target->id])));

        [$sourceOk, $sourceReason] = $this->canManuallyAssignFaculty($target, $sourceFacultyId, $rule, $ignoreIds);
        if (!$sourceOk) {
            abort(422, 'The dragged faculty member is not eligible for the target room: ' . $sourceReason);
        }

        if ($swap && $targetFacultyId !== null) {
            [$targetOk, $targetReason] = $this->canManuallyAssignFaculty($source, $targetFacultyId, $rule, $ignoreIds);
            if (!$targetOk) {
                abort(422, 'The target faculty member is not eligible for the source room: ' . $targetReason);
            }
        }

        DB::transaction(function () use ($source, $target, $swap, $sourceFacultyId, $targetFacultyId, $rule) {
            $target->invigilator_id = $sourceFacultyId;
            $target->save();

            $source->invigilator_id = $swap ? $targetFacultyId : null;
            $source->save();

            $this->refreshFacultyDutyCountsForRule((int) $rule->id);
        });

        return ['ok' => true, 'warnings' => []];
    }

    public function assignInvigilator(int $targetAllocationId, int $facultyId, bool $additional = false): array
    {
        $target = RoomAllocation::query()->with(['exam', 'room', 'invigilator'])->findOrFail($targetAllocationId);
        $faculty = Faculty::query()->findOrFail($facultyId);

        if (!$target->exam) {
            abort(422, 'The target room is not attached to a valid exam.');
        }

        if (!$additional && $target->invigilator_id && $this->isOwnPaperInvigilator($target, (int) $target->invigilator_id)) {
            abort(422, 'The current invigilator is the course instructor for this paper and cannot be replaced.');
        }

        $rule = DateSheetRule::query()->findOrFail((int) $target->exam->rule_id);
        $ignoreIds = $additional ? [] : [(int) $target->id];
        [$ok, $reason] = $this->canManuallyAssignFaculty($target, (int) $faculty->id, $rule, $ignoreIds);
        if (!$ok) {
            abort(422, 'Selected faculty member is not eligible for this room/slot: ' . $reason);
        }

        DB::transaction(function () use ($target, $faculty, $additional, $rule) {
            if ($additional) {
                RoomAllocation::query()->create([
                    'exam_id' => $target->exam_id,
                    'room_id' => $target->room_id,
                    'invigilator_id' => $faculty->id,
                    'assigned_strength' => 0,
                    'subslot_label' => null,
                    'is_duty_only' => true,
                ]);

                $this->refreshFacultyDutyCountsForRule((int) $rule->id);
                return;
            }

            $target->invigilator_id = $faculty->id;
            $target->save();

            $this->refreshFacultyDutyCountsForRule((int) $rule->id);
        });

        return ['ok' => true, 'warnings' => []];
    }

    public function removeInvigilator(int $allocationId): array
    {
        $allocation = RoomAllocation::query()->with('exam')->find($allocationId);
        if (!$allocation) {
            abort(422, 'This invigilator duty was already removed or the grid is stale. Refresh the grid and try again.');
        }

        $ruleId = $allocation->exam ? (int) $allocation->exam->rule_id : null;

        DB::transaction(function () use ($allocation, $ruleId) {
            if ((bool) $allocation->is_duty_only) {
                $allocation->delete();
                if ($ruleId) {
                    $this->refreshFacultyDutyCountsForRule($ruleId);
                }
                return;
            }

            $allocation->invigilator_id = null;
            $allocation->save();
            if ($ruleId) {
                $this->refreshFacultyDutyCountsForRule($ruleId);
            }
        });

        return ['ok' => true, 'warnings' => []];
    }


    private function eligibleFacultyOptionsForDuty(RoomAllocation $target, Collection $faculties, ?DateSheetRule $rule = null, array $ignoreAllocationIds = []): array
    {
        $target->loadMissing(['exam', 'room']);
        if (!$target->exam) {
            return [];
        }

        $rule = $rule ?: DateSheetRule::query()->findOrFail((int) $target->exam->rule_id);
        $options = [];

        foreach ($faculties as $faculty) {
            [$ok] = $this->canManuallyAssignFaculty($target, (int) $faculty->id, $rule, $ignoreAllocationIds);
            if (!$ok) {
                continue;
            }

            $currentCount = $this->currentDutyCountForFaculty((int) $rule->id, (int) $faculty->id, $ignoreAllocationIds);
            $options[] = [
                'id' => (int) $faculty->id,
                'name' => (string) $faculty->name,
                'department' => (string) ($faculty->department ?? ''),
                'designation' => (string) ($faculty->designation ?? ''),
                'remaining_duties' => max(0, (int) $faculty->max_duties - $currentCount),
            ];
        }

        usort($options, function (array $left, array $right) {
            return [strtolower($left['name']), $left['id']] <=> [strtolower($right['name']), $right['id']];
        });

        return $options;
    }

    private function canManuallyAssignFaculty(RoomAllocation $target, int $facultyId, DateSheetRule $rule, array $ignoreAllocationIds = []): array
    {
        $target->loadMissing(['exam', 'room']);
        $faculty = Faculty::query()->find($facultyId);
        if (!$faculty) {
            return [false, 'faculty record was not found'];
        }

        if ((int) $faculty->max_duties <= 0) {
            return [false, 'faculty max duties is 0'];
        }

        if (!$target->exam || !$target->exam->date) {
            return [false, 'target allocation is not attached to a dated exam slot'];
        }

        if (!$target->room || !SlotHelper::isDutyEligibleRoom($target->room)) {
            return [false, 'target room is not duty eligible'];
        }

        $ruleId = (int) $rule->id;
        $date = $target->exam->date->format('Y-m-d');
        $slot = (string) $target->exam->time_slot;
        $ignore = array_values(array_unique(array_map('intval', $ignoreAllocationIds)));
        $duties = $this->currentDutyAllocationsForFaculty($ruleId, $facultyId, $ignore);

        if ($duties->count() >= (int) $faculty->max_duties) {
            return [false, 'faculty duty quota is already full'];
        }

        [$slotLookup, $slotOrder] = SlotHelper::buildSlotMaps(SlotHelper::buildSlotsFromRule($rule));
        $daySlotIndex = $this->manualDaySlotIndex($ruleId, $slotLookup, $slotOrder);
        $targetIdx = $this->manualSlotIndex($date, $slot, $daySlotIndex, $slotLookup, $slotOrder);
        $singleDutyPerDay = $rule->singleInvigilationDutyPerDayEnabled();

        foreach ($duties as $duty) {
            if (!$duty->exam || !$duty->exam->date) {
                continue;
            }

            $otherDate = $duty->exam->date->format('Y-m-d');
            if ($otherDate !== $date) {
                continue;
            }

            $otherSlot = (string) $duty->exam->time_slot;
            $otherIdx = $this->manualSlotIndex($otherDate, $otherSlot, $daySlotIndex, $slotLookup, $slotOrder);

            if ($otherIdx === $targetIdx) {
                return [false, 'faculty already has a duty in this slot'];
            }

            if ($singleDutyPerDay) {
                return [false, 'one-duty-per-day rule would be violated'];
            }

            if (abs($otherIdx - $targetIdx) === 1) {
                return [false, 'consecutive duty rule would be violated'];
            }
        }

        if (!$this->isManualFacultyAvailable($facultyId, $date, $slot, is_array($rule->faculty_slot_matrix) ? $rule->faculty_slot_matrix : [])) {
            return [false, 'faculty is marked unavailable for this slot'];
        }

        return [true, ''];
    }

    private function currentDutyAllocationsForFaculty(int $ruleId, int $facultyId, array $ignoreAllocationIds = []): Collection
    {
        return RoomAllocation::query()
            ->with(['exam', 'room'])
            ->whereNotNull('invigilator_id')
            ->where('invigilator_id', $facultyId)
            ->when($ignoreAllocationIds !== [], fn ($query) => $query->whereNotIn('id', $ignoreAllocationIds))
            ->whereHas('exam', fn ($query) => $query->where('rule_id', $ruleId))
            ->get()
            ->filter(fn (RoomAllocation $allocation) => $allocation->room && SlotHelper::isDutyEligibleRoom($allocation->room))
            ->values();
    }

    private function currentDutyCountForFaculty(int $ruleId, int $facultyId, array $ignoreAllocationIds = []): int
    {
        return $this->currentDutyAllocationsForFaculty($ruleId, $facultyId, $ignoreAllocationIds)->count();
    }

    private function isOwnPaperInvigilator(RoomAllocation $allocation, ?int $facultyId = null): bool
    {
        $allocation->loadMissing(['exam', 'room', 'invigilator']);
        $facultyId = $facultyId ?: (int) ($allocation->invigilator_id ?? 0);
        if ($facultyId <= 0 || !$allocation->exam || !$allocation->room) {
            return false;
        }

        $faculty = $allocation->relationLoaded('invigilator') && $allocation->invigilator && (int) $allocation->invigilator->id === $facultyId
            ? $allocation->invigilator
            : Faculty::query()->find($facultyId);
        if (!$faculty) {
            return false;
        }

        $facultyNorm = SlotHelper::normalizePersonName((string) $faculty->name);
        if ($facultyNorm === '') {
            return false;
        }

        return in_array($facultyNorm, $this->instructorNormsForPhysicalDutyCell($allocation), true);
    }

    private function instructorNormsForPhysicalDutyCell(RoomAllocation $allocation): array
    {
        $allocation->loadMissing(['exam', 'room']);
        if (!$allocation->exam || !$allocation->exam->date || !$allocation->room) {
            return [];
        }

        $targetKey = SlotHelper::physicalRoomKey($allocation->room);
        $exam = $allocation->exam;

        $rows = RoomAllocation::query()
            ->with(['exam', 'room'])
            ->where('is_duty_only', false)
            ->whereHas('exam', function ($query) use ($exam) {
                $query->where('rule_id', $exam->rule_id)
                    ->whereDate('date', $exam->date->format('Y-m-d'))
                    ->where('time_slot', $exam->time_slot);
            })
            ->get()
            ->filter(fn (RoomAllocation $row) => $row->room && SlotHelper::physicalRoomKey($row->room) === $targetKey);

        $norms = [];
        foreach ($rows as $row) {
            foreach (SlotHelper::splitExamInstructors((string) ($row->exam?->instructor ?? '')) as $name) {
                $norm = SlotHelper::normalizePersonName($name);
                if ($norm !== '') {
                    $norms[$norm] = true;
                }
            }
        }

        return array_keys($norms);
    }

    private function isManualFacultyAvailable(int $facultyId, string $date, string $slot, array $availabilityMatrix): bool
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

    private function manualDaySlotIndex(int $ruleId, array $slotLookup, array $slotOrder): array
    {
        $pairs = Exam::query()
            ->where('rule_id', $ruleId)
            ->select('date', 'time_slot')
            ->distinct()
            ->get();

        $byDay = [];
        foreach ($pairs as $pair) {
            $date = $pair->date?->format('Y-m-d') ?: (string) $pair->date;
            $slot = (string) $pair->time_slot;
            if ($date === '' || $slot === '') {
                continue;
            }
            $byDay[$date] = $byDay[$date] ?? [];
            $byDay[$date][] = $slot;
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

    private function manualSlotIndex(string $date, string $slot, array $daySlotIndex, array $slotLookup, array $slotOrder): int
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

    private function refreshFacultyDutyCountsForRule(int $ruleId): void
    {
        $counts = RoomAllocation::query()
            ->with(['exam', 'room'])
            ->whereNotNull('invigilator_id')
            ->whereHas('exam', fn ($query) => $query->where('rule_id', $ruleId))
            ->get()
            ->filter(fn (RoomAllocation $allocation) => $allocation->room && SlotHelper::isDutyEligibleRoom($allocation->room))
            ->groupBy(fn (RoomAllocation $allocation) => (int) $allocation->invigilator_id)
            ->map(fn ($items) => $items->count())
            ->all();

        foreach (Faculty::query()->get(['id']) as $faculty) {
            Faculty::query()->whereKey($faculty->id)->update([
                'assigned_duties' => (int) ($counts[(int) $faculty->id] ?? 0),
            ]);
        }
    }

    private function freePositionsForRoom(Room $room, int $ruleId, string $date, string $slot, array $excludedAssignmentIds = [], ?string $subslotLabel = null, ?int $preferredRow = null, ?int $preferredCol = null): array
    {
        $allPositions = $this->positionsForRoom($room, $subslotLabel);
        if ($preferredRow !== null) {
            usort($allPositions, function (array $left, array $right) use ($preferredRow, $preferredCol) {
                $leftScore = [
                    $left['row'] === $preferredRow ? 0 : 1,
                    abs($left['row'] - $preferredRow),
                    $preferredCol !== null ? abs($left['col'] - $preferredCol) : $left['col'],
                ];
                $rightScore = [
                    $right['row'] === $preferredRow ? 0 : 1,
                    abs($right['row'] - $preferredRow),
                    $preferredCol !== null ? abs($right['col'] - $preferredCol) : $right['col'],
                ];

                return $leftScore <=> $rightScore;
            });
        }

        $occupied = SeatingAssignment::query()
            ->whereNotIn('id', $excludedAssignmentIds)
            ->whereHas('allocation', function ($query) use ($room) {
                $query->where('room_id', $room->id);
            })
            ->whereHas('allocation.exam', function ($query) use ($ruleId, $date, $slot) {
                $query->where('rule_id', $ruleId)->whereDate('date', $date)->where('time_slot', $slot);
            })
            ->get()
            ->mapWithKeys(fn (SeatingAssignment $assignment) => [$assignment->seat_row . '|' . $assignment->seat_col => true])
            ->all();

        return array_values(array_filter($allPositions, fn (array $position) => !isset($occupied[$position['row'] . '|' . $position['col']])));
    }

    private function syncAllocationStrengths(array $allocationIds): void
    {
        foreach (array_values(array_unique(array_map('intval', $allocationIds))) as $allocationId) {
            if ($allocationId <= 0) {
                continue;
            }

            $count = SeatingAssignment::query()->where('allocation_id', $allocationId)->count();
            RoomAllocation::query()
                ->whereKey($allocationId)
                ->where('is_duty_only', false)
                ->update(['assigned_strength' => $count]);
        }
    }

    private function adjacentCourseWarnings(int $roomId, int $ruleId, string $date, string $slot): array
    {
        $room = Room::query()->find($roomId);
        if (!$room) {
            return [];
        }

        $board = $this->seatPlanBoard($ruleId, $date, $slot);
        foreach ($board['rooms'] as $roomCard) {
            if ((int) $roomCard['room_pk'] === $roomId) {
                return $this->adjacentCourseWarningsFromMatrix($roomCard);
            }
        }

        return [];
    }

    private function adjacentCourseWarningsFromMatrix(array $roomCard): array
    {
        $warnings = [];
        $previous = [];

        foreach ($roomCard['matrix'] as $rowNo => $row) {
            $courses = [];
            foreach ($row as $seat) {
                $code = $seat['assignment']['course_code'] ?? '';
                if ($code !== '') {
                    $courses[$code] = true;
                }
            }

            if ($courses !== [] && $previous !== []) {
                $overlap = array_intersect(array_keys($previous), array_keys($courses));
                if ($overlap !== []) {
                    $warnings[] = 'Adjacent row warning in ' . $roomCard['room_id'] . ': row ' . ((int) $rowNo - 1) . ' and row ' . $rowNo . ' both contain ' . implode(', ', $overlap) . '.';
                }
            }

            if ($courses !== []) {
                $previous = $courses;
            }
        }

        return $warnings;
    }

    private function roomDimensions(Room $room): array
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

        return ['rows' => $rows, 'cols' => $cols];
    }

    private function positionsForRoom(Room $room, ?string $subslotLabel = null): array
    {
        $dimensions = $this->roomDimensions($room);
        $startCol = 1;
        $endCol = $dimensions['cols'];

        if ($subslotLabel === 'A' && $dimensions['cols'] > 1) {
            $endCol = (int) ceil($dimensions['cols'] / 2);
        } elseif ($subslotLabel === 'B' && $dimensions['cols'] > 1) {
            $startCol = (int) ceil($dimensions['cols'] / 2) + 1;
        }

        $positions = [];
        for ($row = 1; $row <= $dimensions['rows']; $row++) {
            for ($col = $startCol; $col <= $endCol; $col++) {
                $positions[] = ['row' => $row, 'col' => $col];
            }
        }

        return $positions;
    }

    private function buildDisplayDays(DateSheetRule $rule, array $scheduledDays): array
    {
        $preferredDates = $this->preferredDatesWithinDisplayWindow($rule);

        $days = collect($this->candidateDays($rule))
            ->merge($scheduledDays)
            ->merge($preferredDates)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $cursor = count($days) > 0 ? end($days) : ($rule->end_date?->format('Y-m-d') ?: $rule->start_date?->format('Y-m-d'));
        $extraAdded = 0;
        $cutoffDay = $this->displayCutoffDay($rule);

        while ($cursor && $extraAdded < 5) {
            $cursor = $this->nextValidSchedulingDayAfter($cursor, $rule);
            if ($cursor === null) {
                break;
            }

            if ($cutoffDay !== null && $cursor > $cutoffDay) {
                break;
            }

            if (!in_array($cursor, $days, true)) {
                $days[] = $cursor;
                $extraAdded++;
            }
        }

        sort($days);

        return array_values($days);
    }

    private function preferredDatesWithinDisplayWindow(DateSheetRule $rule): array
    {
        $startDay = $rule->start_date?->format('Y-m-d');
        $cutoffDay = $this->displayCutoffDay($rule);

        return array_values(array_filter(
            $rule->allCourseDatePreferenceDates(),
            fn (string $day) => !($startDay !== null && $day < $startDay)
                && !($cutoffDay !== null && $day > $cutoffDay)
        ));
    }

    private function displayCutoffDay(DateSheetRule $rule): ?string
    {
        return $rule->end_date
            ? Carbon::instance($rule->end_date)->copy()->addDays(self::MAX_AUTO_EXTENSION_CALENDAR_DAYS)->format('Y-m-d')
            : null;
    }

    private function candidateDays(DateSheetRule $rule): array
    {
        if (!$rule->start_date || !$rule->end_date) {
            return [];
        }

        $offDays = array_flip($rule->offDaysOverallList());
        $weekendDays = $rule->weekend_days ?: [0, 6];
        $days = [];

        foreach (\Carbon\CarbonPeriod::create($rule->start_date, $rule->end_date) as $date) {
            $day = $date->format('Y-m-d');
            if (isset($offDays[$day])) {
                continue;
            }
            if ($rule->exclude_weekends && in_array($date->dayOfWeek, $weekendDays, true)) {
                continue;
            }
            $days[] = $day;
        }

        return $days;
    }

    private function nextValidSchedulingDayAfter(?string $afterDay, DateSheetRule $rule): ?string
    {
        $cursor = $afterDay ? \Carbon\Carbon::parse($afterDay)->addDay() : ($rule->start_date ? \Carbon\Carbon::instance($rule->start_date)->copy() : null);
        if ($cursor === null) {
            return null;
        }

        $offDays = array_flip($rule->offDaysOverallList());
        $weekendDays = $rule->weekend_days ?: [0, 6];

        for ($i = 0; $i < 365; $i++) {
            $day = $cursor->format('Y-m-d');

            if (!isset($offDays[$day]) && !($rule->exclude_weekends && in_array($cursor->dayOfWeek, $weekendDays, true))) {
                return $day;
            }

            $cursor->addDay();
        }

        return null;
    }

    private function baseRoomLanes(DateSheetRule $rule, Collection $rooms): array
    {
        $lanes = [];

        foreach ($rooms as $room) {
            if (!$room instanceof Room || $room->looksLikeWaitingRoom()) {
                continue;
            }

            // Room allocation is shown by physical room only. A/B are not
            // separate rooms; they are internal seating groups.
            $lanes[] = $this->makeLane($rule, $room, null);
        }

        return $lanes;
    }

    private function makeLane(DateSheetRule $rule, Room $room, ?string $subslotLabel): array
    {
        // UI/export lane is physical-room based. The optional subslot argument
        // is accepted for compatibility but intentionally ignored here.
        $physicalLabel = SlotHelper::physicalRoomLabel($room);
        $physicalLabel = $physicalLabel !== '' ? $physicalLabel : (string) $room->room_id;

        return [
            'lane_key' => $this->laneKey((int) $room->id, null),
            'room_pk' => (int) $room->id,
            'room_id' => $physicalLabel,
            'room_name' => (string) $room->room_name,
            'building' => (string) $room->building,
            'floor' => (string) $room->floor,
            'subslot_label' => null,
            'subslot_sort' => 'FULL',
            'lane_label' => $physicalLabel,
            'capacity' => $room->effectiveCapacity(),
            'planning_capacity' => $room->effectiveCapacity() + 5,
            'assigned_total' => 0,
            'cards' => [],
        ];
    }

    private function laneCapacity(DateSheetRule $rule, Room $room, ?string $subslotLabel): int
    {
        $capacity = max(0, (int) $room->effectiveCapacity());

        if (!(bool) $rule->split_rooms || !(bool) $room->allow_split || $capacity <= 1) {
            return $capacity;
        }

        if ($subslotLabel === 'A') {
            return (int) ceil($capacity / 2);
        }

        if ($subslotLabel === 'B') {
            return (int) floor($capacity / 2);
        }

        return $capacity;
    }

    private function lanePlanningCapacity(DateSheetRule $rule, Room $room, ?string $subslotLabel): int
    {
        $capacity = $this->laneCapacity($rule, $room, $subslotLabel);
        if ($capacity <= 0) {
            return 0;
        }

        if (!(bool) $rule->split_rooms || !(bool) $room->allow_split || $room->effectiveCapacity() <= 1 || $subslotLabel === null) {
            return $capacity + 5;
        }

        return $capacity + ($subslotLabel === 'A' ? 3 : 2);
    }

    private function clearDutyOnlyRowsForSlot(int $ruleId, string $date, string $slot): void
    {
        $slotExamIds = Exam::query()
            ->where('rule_id', $ruleId)
            ->whereDate('date', $date)
            ->where('time_slot', $slot)
            ->pluck('id')
            ->all();

        if ($slotExamIds === []) {
            return;
        }

        RoomAllocation::query()
            ->whereIn('exam_id', $slotExamIds)
            ->where('is_duty_only', true)
            ->delete();

        RoomAllocation::query()
            ->whereIn('exam_id', $slotExamIds)
            ->where('is_duty_only', false)
            ->update(['invigilator_id' => null]);
    }

    private function laneKey(int $roomPk, ?string $subslotLabel): string
    {
        return $roomPk . '|' . ($subslotLabel ?: 'FULL');
    }

    private function normalizeSubslotLabel(?string $value): ?string
    {
        $normalized = strtoupper(trim((string) $value));
        if ($normalized === '' || $normalized === 'FULL' || $normalized === 'NONE') {
            return null;
        }

        return $normalized;
    }

    private function whereSubslot($query, ?string $subslotLabel): void
    {
        if ($subslotLabel === null) {
            $query->whereNull('subslot_label');
            return;
        }

        $query->where('subslot_label', $subslotLabel);
    }
}
