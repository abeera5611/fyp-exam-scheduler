<?php

namespace App\Http\Controllers\Scheduler;

use App\Http\Controllers\Controller;
use App\Models\Scheduler\Exam;
use App\Services\Scheduler\DateSheetExportService;
use App\Services\Scheduler\DateSheetGenerationService;
use App\Services\Scheduler\DutyRosterService;
use App\Services\Scheduler\InvigilatorAssignmentService;
use App\Services\Scheduler\PlanningBoardService;
use App\Services\Scheduler\RoomAllocationService;
use App\Services\Scheduler\SeatingGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchedulerOpsController extends Controller
{
    public function generateDateSheet(Request $request, DateSheetGenerationService $service): JsonResponse
    {
        $result = $service->generate(
            (int) $request->input('rule_id', 1),
            (bool) $request->boolean('clear_existing', true),
            (string) $request->input('source_tag', 'generated_datesheet')
        );

        return response()->json($result);
    }

    public function allocateRooms(Request $request, RoomAllocationService $service): JsonResponse
    {
        [$count, $warnings] = $service->allocateForSlot(
            (int) $request->input('rule_id', 1),
            (string) $request->input('date'),
            (string) $request->input('slot')
        );

        return response()->json(['allocations_created' => $count, 'warnings' => $warnings]);
    }

    public function allocateRoomsAll(Request $request, RoomAllocationService $service): JsonResponse
    {
        $ruleId = (int) $request->input('rule_id', 1);
        $pairs = $this->scheduledSlotPairs($ruleId);

        $totalAllocations = 0;
        $processed = 0;
        $warnings = [];

        foreach ($pairs as $pair) {
            [$count, $slotWarnings] = $service->allocateForSlot($ruleId, (string) $pair['date'], (string) $pair['slot']);
            $totalAllocations += $count;
            $processed++;

            foreach ($slotWarnings as $warning) {
                $warnings[] = $pair['date'] . ' ' . $pair['slot'] . ': ' . $warning;
            }
        }

        return response()->json([
            'slots_processed' => $processed,
            'allocations_created' => $totalAllocations,
            'warnings' => array_values(array_unique($warnings)),
        ]);
    }

    public function generateSeating(Request $request, SeatingGenerationService $service): JsonResponse
    {
        [$count, $warnings] = $service->generateForSlot(
            (int) $request->input('rule_id', 1),
            (string) $request->input('date'),
            (string) $request->input('slot')
        );

        return response()->json(['seats_created' => $count, 'warnings' => $warnings]);
    }

    public function generateSeatingAll(Request $request, SeatingGenerationService $service): JsonResponse
    {
        $ruleId = (int) $request->input('rule_id', 1);
        $pairs = $this->scheduledSlotPairs($ruleId);

        $totalSeats = 0;
        $processed = 0;
        $warnings = [];

        foreach ($pairs as $pair) {
            [$count, $slotWarnings] = $service->generateForSlot($ruleId, (string) $pair['date'], (string) $pair['slot']);
            $totalSeats += $count;
            $processed++;

            foreach ($slotWarnings as $warning) {
                $warnings[] = $pair['date'] . ' ' . $pair['slot'] . ': ' . $warning;
            }
        }

        return response()->json([
            'slots_processed' => $processed,
            'seats_created' => $totalSeats,
            'warnings' => array_values(array_unique($warnings)),
        ]);
    }

    public function assignInvigilators(Request $request, InvigilatorAssignmentService $slotService, DutyRosterService $dutyService): JsonResponse
    {
        $ruleId = (int) $request->input('rule_id', 1);

        if ($request->boolean('all', false)) {
            [$assigned, $tbd, $warnings] = $dutyService->regenerate($ruleId);

            return response()->json([
                'assigned' => $assigned,
                'tbd' => $tbd,
                'warnings' => $warnings,
            ]);
        }

        [$assigned, $tbd, $warnings] = $slotService->assignForSlot(
            $ruleId,
            (string) $request->input('date'),
            (string) $request->input('slot')
        );

        return response()->json(['assigned' => $assigned, 'tbd' => $tbd, 'warnings' => $warnings]);
    }

    public function exportDateSheet(Request $request, DateSheetExportService $service): JsonResponse
    {
        $ruleId = (int) $request->input('rule_id', 1);
        $format = (string) $request->input('format', 'terminal');
        $output = (string) $request->input('output', storage_path('app/scheduler/exports/' . $format . '_datesheet_rule_' . $ruleId . '.xlsx'));
        $path = $service->export($ruleId, $format, $output);

        return response()->json(['path' => $path]);
    }

    public function roomBoard(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'slot' => ['required', 'string', 'max:50'],
        ]);

        return response()->json($service->roomBoard((int) $data['rule_id'], (string) $data['date'], (string) $data['slot']));
    }

    public function seatPlanBoard(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'slot' => ['required', 'string', 'max:50'],
        ]);

        return response()->json($service->seatPlanBoard((int) $data['rule_id'], (string) $data['date'], (string) $data['slot']));
    }

    public function dutyRoster(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
        ]);

        return response()->json($service->facultyDutyMatrix((int) $data['rule_id']));
    }

    public function roomCourseGrid(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
            'pairs' => ['nullable', 'array'],
            'pairs.*' => ['string', 'max:120'],
            'missing_duties_only' => ['nullable', 'boolean'],
        ]);

        return response()->json($service->roomCourseGrid(
            (int) $data['rule_id'],
            $this->slotPairsFromKeys($data['pairs'] ?? []),
            $request->boolean('missing_duties_only'),
        ));
    }

    private function slotPairsFromKeys(array $keys): array
    {
        return collect($keys)
            ->map(function ($key) {
                [$date, $slot] = array_pad(explode('|', (string) $key, 2), 2, '');

                return [
                    'date' => trim($date),
                    'slot' => trim($slot),
                ];
            })
            ->filter(fn (array $pair) => $pair['date'] !== '' && $pair['slot'] !== '')
            ->unique(fn (array $pair) => $pair['date'] . '|' . $pair['slot'])
            ->values()
            ->all();
    }

    public function clusterDetails(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
            'cluster_key' => ['required', 'string', 'max:120'],
            'exam_ids' => ['nullable', 'array'],
            'exam_ids.*' => ['integer'],
        ]);

        return response()->json($service->clusterDetails(
            (int) $data['rule_id'],
            (string) $data['cluster_key'],
            $data['exam_ids'] ?? null,
        ));
    }

    public function classDayClashReport(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'classes' => ['required', 'string', 'max:1000'],
        ]);

        $classes = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            preg_split('/[|;,]+/', (string) $data['classes']) ?: []
        )));

        return response()->json($service->classDayClashReport(
            (int) $data['rule_id'],
            (string) $data['date'],
            $classes,
        ));
    }

    public function validateClusterMove(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
            'cluster_key' => ['required', 'string', 'max:120'],
            'target_date' => ['required', 'date'],
            'target_slot' => ['required', 'string', 'max:50'],
            'exam_ids' => ['nullable', 'array'],
            'exam_ids.*' => ['integer'],
        ]);

        return response()->json($service->validateClusterMove(
            (int) $data['rule_id'],
            (string) $data['cluster_key'],
            (string) $data['target_date'],
            (string) $data['target_slot'],
            $data['exam_ids'] ?? null,
        ));
    }

    public function rescheduleCluster(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
            'cluster_key' => ['required', 'string', 'max:120'],
            'target_date' => ['required', 'date'],
            'target_slot' => ['required', 'string', 'max:50'],
            'force' => ['nullable', 'boolean'],
            'exam_ids' => ['nullable', 'array'],
            'exam_ids.*' => ['integer'],
        ]);

        return response()->json($service->rescheduleCluster(
            (int) $data['rule_id'],
            (string) $data['cluster_key'],
            (string) $data['target_date'],
            (string) $data['target_slot'],
            (bool) ($data['force'] ?? false),
            $data['exam_ids'] ?? null,
        ));
    }

    public function validateClusterSwap(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
            'left_cluster_key' => ['required', 'string', 'max:120'],
            'right_cluster_key' => ['required', 'string', 'max:120'],
            'left_exam_ids' => ['nullable', 'array'],
            'left_exam_ids.*' => ['integer'],
            'right_exam_ids' => ['nullable', 'array'],
            'right_exam_ids.*' => ['integer'],
        ]);

        return response()->json($service->validateClusterSwap(
            (int) $data['rule_id'],
            (string) $data['left_cluster_key'],
            (string) $data['right_cluster_key'],
            $data['left_exam_ids'] ?? null,
            $data['right_exam_ids'] ?? null,
        ));
    }

    public function swapClusters(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
            'left_cluster_key' => ['required', 'string', 'max:120'],
            'right_cluster_key' => ['required', 'string', 'max:120'],
            'force' => ['nullable', 'boolean'],
            'left_exam_ids' => ['nullable', 'array'],
            'left_exam_ids.*' => ['integer'],
            'right_exam_ids' => ['nullable', 'array'],
            'right_exam_ids.*' => ['integer'],
        ]);

        return response()->json($service->swapClusters(
            (int) $data['rule_id'],
            (string) $data['left_cluster_key'],
            (string) $data['right_cluster_key'],
            (bool) ($data['force'] ?? false),
            $data['left_exam_ids'] ?? null,
            $data['right_exam_ids'] ?? null,
        ));
    }

    public function moveCluster(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
            'cluster_key' => ['required', 'string', 'max:120'],
            'target_date' => ['required', 'date'],
            'target_slot' => ['required', 'string', 'max:50'],
        ]);

        return response()->json($service->moveCluster(
            (int) $data['rule_id'],
            (string) $data['cluster_key'],
            (string) $data['target_date'],
            (string) $data['target_slot'],
        ));
    }

    public function moveAllocation(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'allocation_id' => ['nullable', 'integer'],
            'allocation_ids' => ['nullable', 'array', 'min:1'],
            'allocation_ids.*' => ['integer'],
            'target_room_id' => ['required', 'integer'],
            'target_subslot_label' => ['nullable', 'string', 'max:10'],
        ]);

        $allocationIds = array_values(array_filter(array_map(
            'intval',
            $data['allocation_ids'] ?? (isset($data['allocation_id']) ? [$data['allocation_id']] : [])
        )));

        if ($allocationIds === []) {
            abort(422, 'Select at least one paper segment to move.');
        }

        return response()->json($service->moveAllocations(
            $allocationIds,
            (int) $data['target_room_id'],
            $data['target_subslot_label'] ?? null,
        ));
    }

    public function moveSeatAssignments(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'seat_assignment_ids' => ['required', 'array'],
            'seat_assignment_ids.*' => ['integer'],
            'target_room_id' => ['required', 'integer'],
            'target_row' => ['nullable', 'integer', 'min:1'],
            'target_col' => ['nullable', 'integer', 'min:1'],
            'target_subslot_label' => ['nullable', 'string', 'max:10'],
        ]);

        return response()->json($service->moveSeatAssignments(
            $data['seat_assignment_ids'],
            (int) $data['target_room_id'],
            isset($data['target_row']) ? (int) $data['target_row'] : null,
            isset($data['target_col']) ? (int) $data['target_col'] : null,
            $data['target_subslot_label'] ?? null,
        ));
    }

    public function moveSeatRow(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'slot' => ['required', 'string', 'max:50'],
            'source_room_id' => ['required', 'integer'],
            'source_row' => ['required', 'integer', 'min:1'],
            'target_room_id' => ['required', 'integer'],
            'target_row' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($service->moveSeatRow(
            (int) $data['rule_id'],
            (string) $data['date'],
            (string) $data['slot'],
            (int) $data['source_room_id'],
            (int) $data['source_row'],
            (int) $data['target_room_id'],
            isset($data['target_row']) ? (int) $data['target_row'] : null,
        ));
    }

    public function moveInvigilator(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'source_allocation_id' => ['required', 'integer'],
            'target_allocation_id' => ['required', 'integer'],
            'swap' => ['nullable', 'boolean'],
        ]);

        return response()->json($service->moveInvigilator(
            (int) $data['source_allocation_id'],
            (int) $data['target_allocation_id'],
            (bool) ($data['swap'] ?? true),
        ));
    }

    public function assignInvigilator(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'target_allocation_id' => ['required', 'integer'],
            'faculty_id' => ['required', 'integer'],
            'additional' => ['nullable', 'boolean'],
        ]);

        return response()->json($service->assignInvigilator(
            (int) $data['target_allocation_id'],
            (int) $data['faculty_id'],
            (bool) ($data['additional'] ?? false),
        ));
    }

    public function removeInvigilator(Request $request, PlanningBoardService $service): JsonResponse
    {
        $data = $request->validate([
            'allocation_id' => ['required', 'integer'],
        ]);

        return response()->json($service->removeInvigilator((int) $data['allocation_id']));
    }
    private function scheduledSlotPairs(int $ruleId)
    {
        return Exam::query()
            ->where('rule_id', $ruleId)
            ->select('date', 'time_slot')
            ->distinct()
            ->orderBy('date')
            ->orderBy('time_slot')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date?->format('Y-m-d') ?: (string) $row->date,
                'slot' => (string) $row->time_slot,
            ]);
    }

}
