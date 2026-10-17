<?php

namespace App\Services\Scheduler;

use App\Models\Scheduler\Course;
use App\Models\Scheduler\CourseRegistration;
use App\Models\Scheduler\DateSheetRule;
use App\Models\Scheduler\Exam;
use App\Models\Scheduler\ExamStudent;
use App\Models\Scheduler\Room;
use App\Models\Scheduler\RoomAllocation;
use App\Models\Scheduler\SeatingAssignment;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class DateSheetGenerationService
{
    private const MAX_AUTO_EXTENSION_CALENDAR_DAYS = 8;
    private const CLASS_SPREAD_DISTANCE_CAP = 4;

    public function generate(int $ruleId = 1, bool $clearExisting = true, string $sourceTag = 'generated_datesheet'): array
    {
        $rule = DateSheetRule::query()->findOrFail($ruleId);
        $slots = SlotHelper::buildSlotsFromRule($rule);
        $days = $this->buildCandidateDays($rule);
        $warnings = [];
        $extendedDays = [];
        $autoExtensionCutoffDay = $this->autoExtensionCutoffDay($rule);
        $autoExtensionLimitHit = false;

        if ($slots === []) {
            $warnings[] = 'No valid slots could be derived from the current session timings and duration.';
        }

        if ((int) ($rule->slots_per_day ?? 0) > 0 && count($slots) < (int) $rule->slots_per_day) {
            $warnings[] = 'Requested slots_per_day exceeds the institute timing window; only ' . count($slots) . ' slots fit.';
        }

        $outOfWindowPreferredDates = $this->outOfWindowPreferredDates($rule, $autoExtensionCutoffDay);
        if ($outOfWindowPreferredDates !== [] && $rule->enforceCourseDatePreferences()) {
            $warnings[] = 'Hard course-date preference(s) outside the scheduling window may leave courses unscheduled: '
                . implode(', ', $outOfWindowPreferredDates) . '.';
        }

        if ($slots === []) {
            return [
                'scheduled_exams' => 0,
                'scheduled_clusters' => 0,
                'unscheduled_clusters' => [],
                'warnings' => $warnings,
            ];
        }

        if ($days === []) {
            $firstExtraDay = $this->nextValidSchedulingDayAfter($rule->end_date?->format('Y-m-d') ?: $rule->start_date?->format('Y-m-d'), $rule);
            if ($firstExtraDay !== null && $autoExtensionCutoffDay !== null && $firstExtraDay > $autoExtensionCutoffDay) {
                $firstExtraDay = null;
            }

            if ($firstExtraDay !== null) {
                $days[] = $firstExtraDay;
                $extendedDays[] = $firstExtraDay;
                $warnings[] = 'The configured range had no usable workday, so scheduling started on the next valid day: ' . $firstExtraDay . '.';
            } else {
                $warnings[] = 'No valid exam days are available in the current date range after off-days and weekends are excluded.';

                return [
                    'scheduled_exams' => 0,
                    'scheduled_clusters' => 0,
                    'unscheduled_clusters' => [],
                    'warnings' => $warnings,
                ];
            }
        }

        $offerings = $this->buildOfferings($rule);
        if ($offerings === []) {
            if ($clearExisting) {
                $this->clearGeneratedSchedule($ruleId);
                $warnings[] = 'Existing datesheet, room allocation, seating, and duty roster data were cleared because regeneration was requested.';
            }

            $warnings[] = 'No course registrations exist for the selected exam session.';

            return [
                'scheduled_exams' => 0,
                'scheduled_clusters' => 0,
                'unscheduled_clusters' => [],
                'warnings' => $warnings,
                'slots' => $slots,
                'days' => $days,
            ];
        }

        $clusters = $this->buildClusters($offerings, $rule);
        $conflictGraph = $this->buildConflictGraph($clusters);

        $scheduledClusters = 0;
        $scheduledExams = 0;
        $unscheduled = [];

        $slotCapacity = $this->slotCapacity();

        $dayLoads = [];
        $slotLoads = [];
        $dayClusterCounts = [];
        $studentDayAssignments = [];
        $studentSlotAssignments = [];
        $classDateAssignments = [];

        foreach ($days as $day) {
            $dayLoads[$day] = 0;
            $dayClusterCounts[$day] = 0;
            foreach ($slots as $slot) {
                $slotLoads[$day . '|' . $slot['label']] = 0;
            }
        }

        $totalStrength = collect($clusters)->sum('total_strength');
        $targetDayStrength = count($days) > 0 ? ($totalStrength / count($days)) : 0.0;
        $lastKnownDay = end($days) ?: ($rule->end_date?->format('Y-m-d') ?: $rule->start_date?->format('Y-m-d'));

        DB::beginTransaction();

        try {
            if ($clearExisting) {
                $this->clearGeneratedSchedule($ruleId);
            }

            usort($clusters, function (array $a, array $b) use ($conflictGraph) {
                $leftDegree = count($conflictGraph[$a['cluster_key']] ?? []);
                $rightDegree = count($conflictGraph[$b['cluster_key']] ?? []);

                return ($b['total_strength'] <=> $a['total_strength'])
                    ?: ($rightDegree <=> $leftDegree)
                    ?: (count($b['offerings']) <=> count($a['offerings']))
                    ?: strcmp((string) $a['cluster_key'], (string) $b['cluster_key']);
            });

            foreach ($clusters as $cluster) {
                $pick = $this->pickBestSlot(
                    $cluster,
                    $days,
                    $slots,
                    $rule,
                    $slotCapacity,
                    $dayLoads,
                    $slotLoads,
                    $dayClusterCounts,
                    $studentDayAssignments,
                    $studentSlotAssignments,
                    $classDateAssignments,
                    $targetDayStrength
                );

                $extensionGuard = 0;
                while ($pick === null && $extensionGuard < 180) {
                    $nextDay = $this->nextValidSchedulingDayAfter($lastKnownDay, $rule);
                    if ($nextDay === null) {
                        break;
                    }

                    if ($autoExtensionCutoffDay !== null && $nextDay > $autoExtensionCutoffDay) {
                        $autoExtensionLimitHit = true;
                        break;
                    }

                    if (!in_array($nextDay, $days, true)) {
                        $days[] = $nextDay;
                        $dayLoads[$nextDay] = 0;
                        $dayClusterCounts[$nextDay] = 0;
                        foreach ($slots as $slot) {
                            $slotLoads[$nextDay . '|' . $slot['label']] = 0;
                        }
                        $extendedDays[] = $nextDay;
                        $lastKnownDay = $nextDay;
                        $targetDayStrength = count($days) > 0 ? ($totalStrength / count($days)) : 0.0;
                    } else {
                        $lastKnownDay = $nextDay;
                    }

                    $pick = $this->pickBestSlot(
                        $cluster,
                        $days,
                        $slots,
                        $rule,
                        $slotCapacity,
                        $dayLoads,
                        $slotLoads,
                        $dayClusterCounts,
                        $studentDayAssignments,
                        $studentSlotAssignments,
                        $classDateAssignments,
                        $targetDayStrength
                    );
                    $extensionGuard++;
                }

                if ($pick === null) {
                    $unscheduled[] = $cluster['cluster_key'];
                    $warnings[] = "Unable to place cluster {$cluster['cluster_key']} within the available constraints.";
                    continue;
                }

                [$day, $slotLabel] = $pick;

                foreach ($cluster['offerings'] as $offering) {
                    $exam = Exam::query()->create([
                        'rule_id' => $ruleId,
                        'date' => $day,
                        'time_slot' => $slotLabel,
                        'course_id' => $offering['course_id'],
                        'strength' => $offering['strength'],
                        'class_label' => $offering['class_label'],
                        'merged_class_labels' => isset($offering['merged_class_labels']) ? implode('|', (array) $offering['merged_class_labels']) : null,
                        'program_code' => $offering['program_code'],
                        'semester_no' => $offering['semester_no'],
                        'instructor' => $offering['instructor_name'],
                        'duration_minutes' => $rule->examDurationMinutes(),
                        'cluster_key' => $cluster['cluster_key'],
                        'source_tag' => (string) ($offering['source_tag'] ?? $sourceTag),
                    ]);
                    $this->saveExamStudents($exam, $offering);
                    $scheduledExams++;
                }

                $slotKey = $day . '|' . $slotLabel;
                $slotLoads[$slotKey] += $cluster['total_strength'];
                $dayLoads[$day] += $cluster['total_strength'];
                $dayClusterCounts[$day] = ((int) ($dayClusterCounts[$day] ?? 0)) + 1;

                foreach ($this->clusterClassKeys($cluster) as $classKey) {
                    $classDateAssignments[$classKey][$day] = ((int) ($classDateAssignments[$classKey][$day] ?? 0)) + 1;
                }

                foreach ($cluster['student_ids'] as $studentId) {
                    $studentDayAssignments[$studentId][$day] = true;
                    $studentSlotAssignments[$studentId][$slotKey] = true;
                }

                $scheduledClusters++;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if ($extendedDays !== []) {
            $warnings[] = 'Date range auto-extended beyond ' . ($rule->end_date?->format('Y-m-d') ?: 'the configured end date')
                . ' and scheduling continued until ' . end($extendedDays) . '.';
        }

        if ($autoExtensionLimitHit && $autoExtensionCutoffDay !== null) {
            $warnings[] = 'Auto-extension stopped at ' . $autoExtensionCutoffDay
                . ' (' . self::MAX_AUTO_EXTENSION_CALENDAR_DAYS . ' calendar days after the configured end date). Extend the rule end date or relax constraints for the remaining unscheduled clusters.';
        }

        return [
            'scheduled_exams' => $scheduledExams,
            'scheduled_clusters' => $scheduledClusters,
            'unscheduled_clusters' => $unscheduled,
            'warnings' => $warnings,
            'slots' => $slots,
            'days' => $days,
        ];
    }


    /**
     * Remove the generated schedule and every dependent operational record for
     * this session. This is intentionally explicit instead of relying only on
     * database cascade rules, because some local SQLite/MySQL setups may have
     * foreign-key enforcement disabled during development.
     */
    private function clearGeneratedSchedule(int $ruleId): void
    {
        $examIds = Exam::query()
            ->where('rule_id', $ruleId)
            ->pluck('id')
            ->all();

        if ($examIds !== []) {
            $allocationIds = RoomAllocation::query()
                ->whereIn('exam_id', $examIds)
                ->pluck('id')
                ->all();

            if ($allocationIds !== []) {
                SeatingAssignment::query()
                    ->whereIn('allocation_id', $allocationIds)
                    ->delete();

                RoomAllocation::query()
                    ->whereIn('id', $allocationIds)
                    ->delete();
            }
        }

        ExamStudent::query()->whereIn('exam_id', $examIds)->delete();
        Exam::query()->where('rule_id', $ruleId)->delete();
    }

    private function saveExamStudents(Exam $exam, array $offering): void
    {
        $studentIds = array_values(array_unique(array_filter(array_map(
            fn ($studentId) => (int) $studentId,
            (array) ($offering['student_ids'] ?? [])
        ))));

        if ($studentIds === []) {
            return;
        }

        $sourceClassByStudent = $this->sourceClassLabelsForOffering($exam, $offering, $studentIds);
        $rows = [];
        foreach ($studentIds as $studentId) {
            $rows[] = [
                'exam_id' => (int) $exam->id,
                'student_id' => $studentId,
                'source_class_label' => (string) ($sourceClassByStudent[$studentId] ?? ($offering['class_label'] ?? '')),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ExamStudent::query()->insertOrIgnore($chunk);
        }
    }

    private function sourceClassLabelsForOffering(Exam $exam, array $offering, array $studentIds): array
    {
        $courseId = (int) ($offering['course_id'] ?? $exam->course_id ?? 0);
        if ($courseId <= 0) {
            return [];
        }

        $query = CourseRegistration::query()
            ->where('rule_id', (int) $exam->rule_id)
            ->where('course_id', $courseId)
            ->whereIn('student_id', $studentIds);

        $labels = (array) ($offering['merged_class_labels'] ?? []);
        if ($labels !== []) {
            $query->whereIn('class_label', array_values(array_filter(array_map(
                fn ($label) => trim((string) $label),
                $labels
            ))));
        } elseif (trim((string) ($offering['class_label'] ?? '')) !== '') {
            $query->where('class_label', (string) $offering['class_label']);
        }

        if (trim((string) ($offering['program_code'] ?? '')) !== '') {
            $query->where('program_code', (string) $offering['program_code']);
        }

        if (trim((string) ($offering['instructor_name'] ?? '')) !== '') {
            $query->where('instructor_name', (string) $offering['instructor_name']);
        }

        return $query
            ->get(['student_id', 'class_label'])
            ->mapWithKeys(fn (CourseRegistration $registration) => [
                (int) $registration->student_id => (string) $registration->class_label,
            ])
            ->all();
    }

    private function buildOfferings(DateSheetRule $rule): array
    {
        $ruleId = (int) $rule->id;
        $rows = CourseRegistration::query()
            ->where('rule_id', $ruleId)
            ->select(
                'course_id',
                'class_label',
                'program_code',
                'semester_no',
                'instructor_name',
                DB::raw('COUNT(DISTINCT student_id) AS strength')
            )
            ->groupBy('course_id', 'class_label', 'program_code', 'semester_no', 'instructor_name')
            ->get();

        $courseMap = Course::query()->whereIn('id', $rows->pluck('course_id')->filter()->all())->get()->keyBy('id');
        $offerings = [];

        foreach ($rows as $row) {
            $studentIds = CourseRegistration::query()
                ->where('rule_id', $ruleId)
                ->where('course_id', $row->course_id)
                ->where('class_label', $row->class_label)
                ->where('program_code', $row->program_code)
                ->where(function ($query) use ($row) {
                    if ($row->semester_no === null) {
                        $query->whereNull('semester_no');
                    } else {
                        $query->where('semester_no', $row->semester_no);
                    }
                })
                ->where('instructor_name', $row->instructor_name)
                ->pluck('student_id')
                ->unique()
                ->values()
                ->all();

            $course = $courseMap->get($row->course_id);
            $programCode = (string) $row->program_code;
            $semesterNo = $row->semester_no !== null ? (int) $row->semester_no : null;
            $classLabel = (string) ($row->class_label ?: SlotHelper::classLabel($programCode, $semesterNo));

            $offering = [
                'course_id' => (int) $row->course_id,
                'course_code' => $course?->code ?? ('COURSE-' . $row->course_id),
                'course_title' => $course?->title ?? '',
                'class_label' => $classLabel,
                'program_code' => $programCode,
                'semester_no' => $semesterNo,
                'instructor_name' => (string) $row->instructor_name,
                'strength' => (int) $row->strength,
                'student_ids' => $studentIds,
            ];

            if ($rule->shouldIgnoreCourseOffering($offering)) {
                continue;
            }

            $offerings[] = $offering;
        }

        if ($rule->shouldMergeSameCourseProgramInstructor()) {
            return $this->mergeSameCourseProgramInstructorOfferings($offerings, $rule);
        }

        return $offerings;
    }

    private function mergeSameCourseProgramInstructorOfferings(array $offerings, DateSheetRule $rule): array
    {
        $groups = [];

        foreach ($offerings as $offering) {
            $key = $rule->mergedOfferingKey($offering);

            if ($key === '||') {
                $key = implode('|', [
                    (string) ($offering['course_id'] ?? ''),
                    strtoupper(trim((string) ($offering['program_code'] ?? ''))),
                    $rule->normalizeInstructorName((string) ($offering['instructor_name'] ?? '')),
                ]);
            }

            $groups[$key] = $groups[$key] ?? [];
            $groups[$key][] = $offering;
        }

        $merged = [];
        $threshold = $rule->mergeStrengthThreshold();

        foreach ($groups as $items) {
            if (count($items) === 1) {
                $merged[] = $items[0];
                continue;
            }

            usort($items, function (array $left, array $right) {
                return ((int) ($right['strength'] ?? 0) <=> (int) ($left['strength'] ?? 0))
                    ?: strcmp((string) ($left['class_label'] ?? ''), (string) ($right['class_label'] ?? ''));
            });

            // If a threshold is configured, only small sections below that
            // strength are merged into the largest section. Other large
            // sections stay separate, so the merge does not hide genuinely
            // independent large classes. Threshold 0 keeps the old behaviour:
            // merge the full group.
            $itemsToMerge = $items;
            $separateItems = [];
            if ($threshold > 0) {
                $anchor = $items[0];
                $smallSections = [];
                foreach (array_slice($items, 1) as $item) {
                    if ((int) ($item['strength'] ?? 0) < $threshold) {
                        $smallSections[] = $item;
                    } else {
                        $separateItems[] = $item;
                    }
                }

                if ($smallSections === []) {
                    foreach ($items as $item) {
                        $merged[] = $item;
                    }
                    continue;
                }

                $itemsToMerge = array_merge([$anchor], $smallSections);
            }

            $primary = $itemsToMerge[0];
            $studentIds = [];
            $strengthSum = 0;
            $classLabels = [];

            foreach ($itemsToMerge as $item) {
                $strengthSum += max(0, (int) ($item['strength'] ?? 0));
                $label = trim((string) ($item['class_label'] ?? ''));
                if ($label !== '') {
                    $classLabels[$label] = true;
                }

                foreach ((array) ($item['student_ids'] ?? []) as $studentId) {
                    $studentIds[(int) $studentId] = true;
                }
            }

            $primary['student_ids'] = array_values(array_keys($studentIds));
            $primary['strength'] = $primary['student_ids'] !== [] ? count($primary['student_ids']) : $strengthSum;
            $primary['merged_class_labels'] = array_keys($classLabels);
            $primary['merged_offering_count'] = count($itemsToMerge);
            $primary['merge_strength_threshold'] = $threshold;
            $primary['source_tag'] = 'merged_same_course_program_instructor';

            $merged[] = $primary;

            foreach ($separateItems as $item) {
                $merged[] = $item;
            }
        }

        return $merged;
    }

    private function buildClusters(array $offerings, DateSheetRule $rule): array
    {
        $clusters = [];

        foreach ($offerings as $offering) {
            $courseCode = (string) ($offering['course_code'] ?? '');
            $sameCourseGroupingKey = $rule->sameCourseGroupingKeyForOffering($offering);
            $clusterKey = $sameCourseGroupingKey !== null
                ? $sameCourseGroupingKey
                : implode('|', [
                    $courseCode,
                    $offering['class_label'],
                    $offering['program_code'],
                    $offering['semester_no'] ?? '',
                    $offering['instructor_name'],
                ]);

            if (!isset($clusters[$clusterKey])) {
                $clusters[$clusterKey] = [
                    'cluster_key' => $clusterKey,
                    'total_strength' => 0,
                    'student_ids' => [],
                    'offerings' => [],
                ];
            }

            $clusters[$clusterKey]['offerings'][] = $offering;
            $clusters[$clusterKey]['total_strength'] += $offering['strength'];
            $clusters[$clusterKey]['student_ids'] = array_values(array_unique(array_merge(
                $clusters[$clusterKey]['student_ids'],
                $offering['student_ids']
            )));
        }

        return array_values($clusters);
    }

    private function buildConflictGraph(array $clusters): array
    {
        $studentToClusters = [];
        $graph = [];

        foreach ($clusters as $cluster) {
            $graph[$cluster['cluster_key']] = [];
            foreach ($cluster['student_ids'] as $studentId) {
                $studentToClusters[$studentId] = $studentToClusters[$studentId] ?? [];
                $studentToClusters[$studentId][] = $cluster['cluster_key'];
            }
        }

        foreach ($studentToClusters as $clusterKeys) {
            $clusterKeys = array_values(array_unique($clusterKeys));
            $count = count($clusterKeys);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $left = $clusterKeys[$i];
                    $right = $clusterKeys[$j];
                    $graph[$left][$right] = true;
                    $graph[$right][$left] = true;
                }
            }
        }

        return array_map(fn ($neighbors) => array_keys($neighbors), $graph);
    }

    private function pickBestSlot(
        array $cluster,
        array $days,
        array $slots,
        DateSheetRule $rule,
        int $slotCapacity,
        array $dayLoads,
        array $slotLoads,
        array $dayClusterCounts,
        array $studentDayAssignments,
        array $studentSlotAssignments,
        array $classDateAssignments,
        float $targetDayStrength
    ): ?array {
        if ($this->clusterHasInternalStudentCourseConflict($cluster)) {
            return null;
        }

        $offDaysPerClass = $rule->offDaysPerClassMap();
        $overallOffDays = array_flip($rule->offDaysOverallList());
        $slotOffMatrix = is_array($rule->slot_off_matrix) ? $rule->slot_off_matrix : [];
        $preferredSlots = $this->clusterPreferredSlotLabels($cluster, $rule, $slots);
        $preferredDates = $this->clusterPreferredDates($cluster, $rule);
        $enforcePreferredDates = $rule->enforceCourseDatePreferences();
        $sameDayConflictLimit = $rule->sameDayStudentConflictLimit();

        $best = null;
        $bestScore = null;

        foreach ($days as $day) {
            if (isset($overallOffDays[$day])) {
                continue;
            }

            if ($this->clusterBlockedOnDay($cluster, $day, $offDaysPerClass)) {
                continue;
            }

            $dayMatchesPreference = $preferredDates === [] || in_array($day, $preferredDates, true);
            if ($preferredDates !== [] && $enforcePreferredDates && !$dayMatchesPreference) {
                continue;
            }

            foreach ($slots as $slot) {
                $slotLabel = $slot['label'];
                $slotKey = $day . '|' . $slotLabel;

                if ($preferredSlots !== [] && !in_array($slotLabel, $preferredSlots, true)) {
                    continue;
                }

                if ($this->clusterBlockedOnSlot($cluster, $day, $slotLabel, $slotOffMatrix)) {
                    continue;
                }

                if ($this->hasSameSlotConflict($cluster['student_ids'], $slotKey, $studentSlotAssignments)) {
                    continue;
                }

                $sameDayConflictCount = $this->sameDayConflictCount($cluster['student_ids'], $day, $studentDayAssignments);
                if ($sameDayConflictCount > $sameDayConflictLimit) {
                    continue;
                }

                if ($slotCapacity > 0 && (($slotLoads[$slotKey] ?? 0) + $cluster['total_strength']) > $slotCapacity) {
                    continue;
                }

                $score = 0.0;
                if ($preferredDates !== [] && !$enforcePreferredDates && !$dayMatchesPreference) {
                    $score += 500000000.0;
                }

                $currentDayLoad = (float) ($dayLoads[$day] ?? 0);
                $currentSlotLoad = (float) ($slotLoads[$slotKey] ?? 0);
                $projectedSlotLoad = $currentSlotLoad + $cluster['total_strength'];
                $dayOrder = array_search($day, $days, true);
                $slotOrder = array_search($slot, $slots, true);

                // Capacity-first scheduling:
                // Earlier versions optimized for balanced day strength. The
                // institute requirement is now different: pack every slot as
                // close as possible to total available room capacity, while
                // still respecting student clashes, PG/BS slot rules, preferred
                // dates, and class/program spread. Lower score is better.
                $score += $this->slotCapacityPackingScore(
                    $slotCapacity,
                    (int) $currentSlotLoad,
                    (int) ($cluster['total_strength'] ?? 0)
                );

                // Capacity utilization is now the primary objective. Class spread is
                // only a light tie-breaker; it must not cause the generator to
                // open excessive dates while usable capacity is still available.
                $score += $this->classSpreadPenalty($cluster, $day, $days, $classDateAssignments) * 20.0;

                // Prefer clash-free slots, but allow a small configured number
                // of same-day clashes when no clean placement exists.
                $score += $sameDayConflictCount * 100000.0;

                // Small deterministic tie-breakers only. Do not rebalance daily
                // strength here, because that under-utilizes slots and causes
                // unnecessary extension.
                $score += ((int) ($dayClusterCounts[$day] ?? 0)) * 5.0;
                $score += $currentDayLoad * 0.0005;

                // Deterministic tie breakers.
                $score += ((int) ($dayOrder !== false ? $dayOrder : 999999)) * 0.001;
                $score += ((int) ($slotOrder !== false ? $slotOrder : 999999)) * 0.0001;

                if ($bestScore === null || $score < $bestScore) {
                    $bestScore = $score;
                    $best = [$day, $slotLabel];
                }
            }
        }

        return $best;
    }

    private function slotCapacityPackingScore(int $slotCapacity, int $currentSlotLoad, int $clusterStrength): float
    {
        if ($slotCapacity <= 0) {
            // No room data configured yet; fall back to light packing by slot load.
            return max(0, $currentSlotLoad) * 1000.0;
        }

        $clusterStrength = max(0, $clusterStrength);
        $projectedLoad = $currentSlotLoad + $clusterStrength;
        $remainingAfter = max(0, $slotCapacity - $projectedLoad);
        $remainingBefore = max(0, $slotCapacity - $currentSlotLoad);

        // Best-fit packing: the closer the slot becomes to full capacity, the
        // better. This intentionally prefers an already-open slot when it can
        // absorb the paper, instead of starting a lightly-used new slot.
        $score = $remainingAfter * 1000.0;

        if ($currentSlotLoad === 0) {
            // A small penalty prevents unnecessary opening of new slots when a
            // partly-filled slot can still hold the cluster.
            $score += min($slotCapacity, $clusterStrength + 1) * 25.0;
        } elseif ($clusterStrength <= $remainingBefore) {
            // Reward filling a slot that is already in use.
            $score -= min($currentSlotLoad, $slotCapacity) * 5.0;
        }

        return $score;
    }

    private function classSpreadPenalty(array $cluster, string $candidateDay, array $days, array $classDateAssignments): float
    {
        $keys = $this->clusterClassKeys($cluster);
        if ($keys === []) {
            return 0.0;
        }

        $candidateIndex = array_search($candidateDay, $days, true);
        if ($candidateIndex === false) {
            return 0.0;
        }

        $penalty = 0.0;
        $activeKeys = 0;

        foreach ($keys as $key) {
            $assignedDates = array_keys((array) ($classDateAssignments[$key] ?? []));
            if ($assignedDates === []) {
                continue;
            }

            $activeKeys++;
            $sameDayCount = (int) ($classDateAssignments[$key][$candidateDay] ?? 0);
            if ($sameDayCount > 0) {
                // This should normally already be prevented by student-clash
                // rules, but keep it extremely undesirable for partial/relaxed
                // registrations too.
                $penalty += 100000.0 * $sameDayCount;
            }

            $closestDistance = null;
            foreach ($assignedDates as $assignedDate) {
                $assignedIndex = array_search($assignedDate, $days, true);
                if ($assignedIndex === false) {
                    continue;
                }

                $distance = abs(((int) $candidateIndex) - ((int) $assignedIndex));
                $closestDistance = $closestDistance === null ? $distance : min($closestDistance, $distance);
            }

            if ($closestDistance !== null) {
                if ($closestDistance <= 0) {
                    $penalty += 100000.0;
                } else {
                    // Adjacent-day papers are allowed only when needed. Once a
                    // paper is several valid exam days away, stop rewarding
                    // even more distance; otherwise tiny leftovers drift into
                    // far future auto-extended dates.
                    $effectiveDistance = min($closestDistance, self::CLASS_SPREAD_DISTANCE_CAP);
                    $penalty += 1000.0 / $effectiveDistance;
                }
            }
        }

        return $activeKeys > 0 ? ($penalty / $activeKeys) : 0.0;
    }

    private function clusterClassKeys(array $cluster): array
    {
        $keys = [];

        foreach ((array) ($cluster['offerings'] ?? []) as $offering) {
            $programCode = strtoupper(trim((string) ($offering['program_code'] ?? '')));
            $semesterNo = $offering['semester_no'] ?? null;

            foreach ($this->classLabelsForOffering($offering) as $classLabel) {
                $keys['CLASS:' . strtoupper($classLabel)] = true;
            }

            if ($programCode !== '') {
                $keys['PROGRAM:' . $programCode] = true;
            }

            if ($programCode !== '' && $semesterNo !== null && $semesterNo !== '') {
                $keys['PROGRAM_SEM:' . $programCode . ':' . (int) $semesterNo] = true;
            }
        }

        return array_keys($keys);
    }

    private function clusterBlockedOnDay(array $cluster, string $day, array $offDaysPerClass): bool
    {
        foreach ($cluster['offerings'] as $offering) {
            $keys = array_filter(array_merge($this->classLabelsForOffering($offering), [(string) ($offering['program_code'] ?? '')]));
            foreach ($keys as $key) {
                if (in_array($day, $offDaysPerClass[$key] ?? [], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function clusterBlockedOnSlot(array $cluster, string $day, string $slotLabel, array $slotOffMatrix): bool
    {
        foreach ($cluster['offerings'] as $offering) {
            foreach (array_filter(array_merge($this->classLabelsForOffering($offering), [(string) ($offering['program_code'] ?? '')])) as $groupKey) {
                $flag = $slotOffMatrix[$day][$groupKey][$slotLabel]
                    ?? $slotOffMatrix[$day][$groupKey][SlotHelper::normalizeSlotKey($slotLabel)]
                    ?? false;

                if ($flag) {
                    return true;
                }
            }
        }

        return false;
    }

    private function classLabelsForOffering(array $offering): array
    {
        $labels = [];

        foreach ([(string) ($offering['class_label'] ?? '')] as $label) {
            $label = trim($label);
            if ($label !== '') {
                $labels[$label] = true;
            }
        }

        $merged = $offering['merged_class_labels'] ?? [];
        if (is_string($merged)) {
            $merged = preg_split('/[|;,]+/', $merged) ?: [];
        }

        foreach ((array) $merged as $label) {
            $label = trim((string) $label);
            if ($label !== '') {
                $labels[$label] = true;
            }
        }

        return array_keys($labels);
    }

    private function hasSameDayConflict(array $studentIds, string $day, array $studentDayAssignments): bool
    {
        return $this->sameDayConflictCount($studentIds, $day, $studentDayAssignments) > 0;
    }

    private function sameDayConflictCount(array $studentIds, string $day, array $studentDayAssignments): int
    {
        $count = 0;
        foreach (array_values(array_unique(array_map('intval', $studentIds))) as $studentId) {
            if (($studentDayAssignments[$studentId][$day] ?? false) === true) {
                $count++;
            }
        }

        return $count;
    }

    private function hasSameSlotConflict(array $studentIds, string $slotKey, array $studentSlotAssignments): bool
    {
        foreach ($studentIds as $studentId) {
            if (($studentSlotAssignments[$studentId][$slotKey] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function clusterHasInternalStudentCourseConflict(array $cluster): bool
    {
        $studentCourseCodes = [];

        foreach ((array) ($cluster['offerings'] ?? []) as $offering) {
            $courseCode = strtoupper(trim((string) ($offering['course_code'] ?? '')));
            if ($courseCode === '') {
                continue;
            }

            foreach ((array) ($offering['student_ids'] ?? []) as $studentId) {
                $studentCourseCodes[(int) $studentId][$courseCode] = true;
            }
        }

        foreach ($studentCourseCodes as $courseSet) {
            if (count($courseSet) > 1) {
                return true;
            }
        }

        return false;
    }

    private function buildCandidateDays(DateSheetRule $rule): array
    {
        if (!$rule->start_date || !$rule->end_date) {
            return [];
        }

        $offDays = array_flip($rule->offDaysOverallList());
        $weekendDays = $rule->weekend_days ?: [0, 6];
        $days = [];

        foreach (CarbonPeriod::create($rule->start_date, $rule->end_date) as $date) {
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
        $cursor = $afterDay ? Carbon::parse($afterDay)->addDay() : ($rule->start_date ? Carbon::instance($rule->start_date)->copy() : null);
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

    private function autoExtensionCutoffDay(DateSheetRule $rule): ?string
    {
        if (!$rule->end_date) {
            return null;
        }

        return Carbon::instance($rule->end_date)
            ->copy()
            ->addDays(self::MAX_AUTO_EXTENSION_CALENDAR_DAYS)
            ->format('Y-m-d');
    }

    private function outOfWindowPreferredDates(DateSheetRule $rule, ?string $autoExtensionCutoffDay): array
    {
        $startDay = $rule->start_date?->format('Y-m-d');
        $dates = [];

        foreach ($rule->allCourseDatePreferenceDates() as $date) {
            if (($startDay !== null && $date < $startDay)
                || ($autoExtensionCutoffDay !== null && $date > $autoExtensionCutoffDay)) {
                $dates[$date] = true;
            }
        }

        $dates = array_keys($dates);
        sort($dates);

        return $dates;
    }

    private function clusterPreferredDates(array $cluster, DateSheetRule $rule): array
    {
        return $rule->preferredDatesForOfferings((array) ($cluster['offerings'] ?? []));
    }

    private function clusterPreferredSlotLabels(array $cluster, DateSheetRule $rule, array $slots): array
    {
        if ($slots === []) {
            return [];
        }

        // 1) Explicit course/program slot preferences are the strongest rule.
        // Example: HUM701,EVENING or CSC101,BSE,MORNING.
        $coursePreferences = $rule->preferredSlotPreferencesForOfferings((array) ($cluster['offerings'] ?? []));
        $labels = [];
        if ($coursePreferences !== []) {
            foreach ($coursePreferences as $preference) {
                foreach ($this->resolveSlotPreferenceLabels((string) $preference, $slots) as $resolved) {
                    $labels[$resolved] = true;
                }
            }

            if ($labels !== []) {
                return array_keys($labels);
            }
        }

        // 2) Default configurable PG/MS/PHD policy.
        // MS/PHD courses go to evening by default. BS courses avoid the PG
        // slot by default, but both behaviours are controlled from the UI.
        $hasPostgraduate = false;
        $hasUndergraduate = false;

        foreach ($cluster['offerings'] as $offering) {
            if ($rule->isPostgraduateOffering($offering)) {
                $hasPostgraduate = true;
            } elseif ($rule->isUndergraduateOffering($offering)) {
                $hasUndergraduate = true;
            }
        }

        if ($hasPostgraduate && (bool) ($rule->schedule_pg_courses_in_preferred_slot ?? true)) {
            $preference = (string) ($rule->pg_slot_preference ?? 'EVENING');
            return $this->resolveSlotPreferenceLabels($preference, $slots);
        }

        if (!$hasPostgraduate && $hasUndergraduate && (bool) ($rule->exclude_pg_preferred_slot_for_bs ?? true)) {
            $preference = (string) ($rule->pg_slot_preference ?? 'EVENING');
            $pgLabels = $this->resolveSlotPreferenceLabels($preference, $slots);
            if ($pgLabels === []) {
                return $this->resolveSlotPreferenceLabels('NOT_EVENING', $slots);
            }

            $blocked = array_flip($pgLabels);
            $allowed = [];
            foreach ($slots as $slot) {
                $label = (string) ($slot['label'] ?? $slot['id'] ?? '');
                if ($label !== '' && !isset($blocked[$label])) {
                    $allowed[$label] = true;
                }
            }

            // If there is only one slot, do not make BS impossible to schedule.
            return $allowed !== [] ? array_keys($allowed) : [];
        }

        return [];
    }

    private function resolveSlotPreferenceLabels(string $preference, array $slots): array
    {
        $preferences = preg_split('/\s*[;|]\s*/', trim($preference)) ?: [];
        $labels = [];

        foreach ($preferences as $item) {
            foreach ($this->resolveSingleSlotPreferenceLabels($item, $slots) as $label) {
                if ($label !== '') {
                    $labels[$label] = true;
                }
            }
        }

        return array_keys($labels);
    }

    private function resolveSingleSlotPreferenceLabels(string $preference, array $slots): array
    {
        $preference = trim($preference);
        if ($preference === '' || $slots === []) {
            return [];
        }

        $upper = strtoupper($preference);
        $slotLabels = array_values(array_filter(array_map(
            fn ($slot) => (string) ($slot['label'] ?? $slot['id'] ?? ''),
            $slots
        )));

        if (in_array($upper, ['ALL', 'ANY', '*'], true)) {
            return $slotLabels;
        }

        if (in_array($upper, ['LAST', 'EVENING', 'PG', 'POSTGRAD', 'POSTGRADUATE'], true)) {
            $typed = $this->labelsBySlotType($slots, 'EVENING');
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
            if ($allowed !== []) {
                return $allowed;
            }
            return count($slotLabels) > 1 ? array_slice($slotLabels, 0, -1) : [];
        }

        if (in_array($upper, ['FIRST', 'MORNING'], true)) {
            $typed = $this->labelsBySlotType($slots, 'MORNING');
            if ($typed !== []) {
                return $typed;
            }
            return isset($slots[0]) ? [(string) ($slots[0]['label'] ?? $slots[0]['id'] ?? '')] : [];
        }

        if ($upper === 'AFTERNOON') {
            $typed = $this->labelsBySlotType($slots, 'AFTERNOON');
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
            if ($normalized !== '' && (
                SlotHelper::normalizeSlotKey($label) === $normalized
                || SlotHelper::normalizeSlotKey($id) === $normalized
            )) {
                return [$label !== '' ? $label : $id];
            }
        }

        return [];
    }

    private function labelsBySlotType(array $slots, string $type): array
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

    private function resolvePreferredSlotLabel(string $preference, array $slots): ?string
    {
        $labels = $this->resolveSlotPreferenceLabels($preference, $slots);

        return $labels[0] ?? null;
    }

    private function slotCapacity(): int
    {
        $rooms = Room::query()->get();
        $primary = $rooms->filter(fn (Room $room) => $room->isAllocatableExamRoom());
        $fallback = $rooms->filter(fn (Room $room) => $room->isUsableNonWaitingRoom());
        $usable = $primary->isNotEmpty() ? $primary : $fallback;

        // Match the room-allocation planning rule: each physical room may be
        // planned up to declared capacity + 5. Seat generation still uses the
        // real fixed seats and only true overflow goes to the waiting room.
        return (int) $usable->sum(function (Room $room) {
            $capacity = (int) $room->effectiveCapacity();
            return $capacity > 0 ? ($capacity + 5) : 0;
        });
    }
}
