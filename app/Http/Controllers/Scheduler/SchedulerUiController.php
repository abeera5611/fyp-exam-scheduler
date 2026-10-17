<?php

namespace App\Http\Controllers\Scheduler;

use App\Http\Controllers\Controller;
use App\Models\Scheduler\Course;
use App\Models\Scheduler\CourseFacultyAssignment;
use App\Models\Scheduler\CourseRegistration;
use App\Models\Scheduler\DateSheetRule;
use App\Models\Scheduler\Department;
use App\Models\Scheduler\Designation;
use App\Models\Scheduler\Exam;
use App\Models\Scheduler\Faculty;
use App\Models\Scheduler\Room;
use App\Models\Scheduler\Student;
use App\Services\Scheduler\AttendanceWorkbookImportService;
use App\Services\Scheduler\DateSheetExportService;
use App\Services\Scheduler\DateSheetGenerationService;
use App\Services\Scheduler\DutyRosterService;
use App\Services\Scheduler\InvigilatorAssignmentService;
use App\Services\Scheduler\OperationalExportService;
use App\Services\Scheduler\PlanningBoardService;
use App\Services\Scheduler\RoomAllocationService;
use App\Services\Scheduler\SchedulerDataResetService;
use App\Services\Scheduler\SeatingGenerationService;
use App\Services\Scheduler\SlotHelper;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SchedulerUiController extends Controller
{
    public function home(Request $request): View
    {
        [$rules, $selectedRule] = $this->selectedRule($request);

        return view('scheduler.home', [
            'rules' => $rules,
            'selectedRule' => $selectedRule,
        ]);
    }

    public function index(Request $request): View
    {
        [$rules, $selectedRule] = $this->selectedRule($request);

        return view('scheduler.index', [
            'rules' => $rules,
            'selectedRule' => $selectedRule,
            'rooms' => Room::query()->orderBy('building')->orderBy('floor')->orderBy('room_id')->get(),
            'faculties' => Faculty::query()->orderBy('name')->get(),
            'departments' => Department::query()->orderBy('name')->get(),
            'designations' => Designation::query()->orderBy('name')->get(),
            'slots' => $selectedRule ? SlotHelper::buildSlotsFromRule($selectedRule) : [],
            'candidateDays' => $selectedRule ? $this->candidateDays($selectedRule) : [],
        ]);
    }

    public function datesheet(Request $request, PlanningBoardService $boardService): View
    {
        [$rules, $selectedRule] = $this->selectedRule($request);
        $datesheetBoard = $selectedRule
            ? $boardService->datesheetBoard($selectedRule)
            : ['days' => [], 'slots' => [], 'cells' => [], 'scheduled_days' => []];

        return view('scheduler.datesheet', [
            'rules' => $rules,
            'selectedRule' => $selectedRule,
            'datesheetBoard' => $datesheetBoard,
        ]);
    }

    public function seatPlan(Request $request, PlanningBoardService $boardService): View
    {
        [$rules, $selectedRule] = $this->selectedRule($request);
        $pairs = $selectedRule ? $this->scheduledSlotPairs((int) $selectedRule->id) : collect();
        [$date, $slot] = $this->requestedOrFirstPair($request, $pairs);
        $seatPlanBoard = ($selectedRule && $date && $slot)
            ? $boardService->seatPlanBoard((int) $selectedRule->id, $date, $slot)
            : ['rooms' => [], 'warnings' => [], 'date' => $date, 'slot' => $slot];
        $roomAllocationBoard = ($selectedRule && $date && $slot)
            ? $boardService->roomBoard((int) $selectedRule->id, $date, $slot)
            : ['buildings' => [], 'lanes' => [], 'date' => $date, 'slot' => $slot];
        $unallocatedRoomSummary = $selectedRule
            ? $boardService->unallocatedRoomSummary((int) $selectedRule->id)
            : [];

        return view('scheduler.seat_plan', [
            'rules' => $rules,
            'selectedRule' => $selectedRule,
            'pairs' => $pairs,
            'date' => $date,
            'slot' => $slot,
            'seatPlanBoard' => $seatPlanBoard,
            'roomAllocationBoard' => $roomAllocationBoard,
            'unallocatedRoomSummary' => $unallocatedRoomSummary,
        ]);
    }

    public function dutyRoster(Request $request, PlanningBoardService $boardService): View
    {
        [$rules, $selectedRule] = $this->selectedRule($request);
        $matrix = $selectedRule
            ? $boardService->facultyDutyMatrix((int) $selectedRule->id)
            : ['columns' => [], 'departments' => [], 'faculties' => [], 'dates' => [], 'slots_by_date' => []];

        $filters = $this->dutyRosterFilters($request);
        $filterOptions = [
            'departments' => Faculty::query()->whereNotNull('department')->where('department', '!=', '')->distinct()->orderBy('department')->pluck('department')->values()->all(),
            'designations' => Faculty::query()->whereNotNull('designation')->where('designation', '!=', '')->distinct()->orderBy('designation')->pluck('designation')->values()->all(),
            'dates' => $matrix['dates'] ?? [],
            'slots' => collect($matrix['columns'] ?? [])->pluck('slot')->filter()->unique()->values()->all(),
        ];
        $filteredMatrix = $this->filterFacultyDutyMatrix($matrix, $filters);

        return view('scheduler.duty_roster', [
            'rules' => $rules,
            'selectedRule' => $selectedRule,
            'matrix' => $filteredMatrix,
            'filters' => $filters,
            'filterOptions' => $filterOptions,
        ]);
    }

    public function roomGrid(Request $request, PlanningBoardService $boardService): View
    {
        [$rules, $selectedRule] = $this->selectedRule($request);
        $pairs = $selectedRule ? $this->scheduledSlotPairs((int) $selectedRule->id) : collect();
        $selectedPairs = $this->selectedRoomGridPairs($request, $pairs);
        $missingDutiesOnly = $request->boolean('missing_duties_only');
        $grid = $selectedRule ? $boardService->roomCourseGrid((int) $selectedRule->id, $selectedPairs, $missingDutiesOnly) : ['rows' => []];

        return view('scheduler.room_grid', [
            'rules' => $rules,
            'selectedRule' => $selectedRule,
            'pairs' => $pairs,
            'selectedPairs' => $selectedPairs,
            'selectedPairKeys' => collect($selectedPairs)->map(fn (array $pair) => $this->slotPairKey($pair))->all(),
            'missingDutiesOnly' => $missingDutiesOnly,
            'grid' => $grid,
            'faculties' => Faculty::query()->orderBy('name')->get(),
        ]);
    }

    public function courses(Request $request): View
    {
        [$rules, $selectedRule] = $this->selectedRule($request);

        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'registered' => trim((string) $request->input('registered', '')),
        ];

        $coursesQuery = Course::query();

        if ($filters['q'] !== '') {
            $needle = '%' . $filters['q'] . '%';
            $coursesQuery->where(function ($query) use ($needle) {
                $query->where('code', 'like', $needle)
                    ->orWhere('title', 'like', $needle);
            });
        }

        if ($selectedRule && $filters['registered'] === 'yes') {
            $coursesQuery->whereHas('registrations', function ($query) use ($selectedRule) {
                $query->where('rule_id', $selectedRule->id);
            });
        }

        $courses = $coursesQuery
            ->withCount(['registrations as selected_session_registrations_count' => function ($query) use ($selectedRule) {
                if ($selectedRule) {
                    $query->where('rule_id', $selectedRule->id);
                }
            }])
            ->orderBy('code')
            ->paginate(200)
            ->withQueryString();

        return view('scheduler.courses', [
            'rules' => $rules,
            'selectedRule' => $selectedRule,
            'courses' => $courses,
            'filters' => $filters,
            'totalCourses' => Course::query()->count(),
        ]);
    }

    public function mergeCourses(Request $request): View
    {
        [$rules, $selectedRule] = $this->selectedRule($request);

        return view('scheduler.merge_courses', [
            'rules' => $rules,
            'selectedRule' => $selectedRule,
            'mergeBoard' => $selectedRule
                ? $this->buildCourseMergeBoard($selectedRule)
                : ['groups' => [], 'ungrouped' => [], 'offerings_count' => 0, 'draft_suggestions' => false],
        ]);
    }

    public function storeMergeCourses(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
            'merge_groups_json' => ['required', 'string'],
        ]);

        $groups = json_decode((string) $data['merge_groups_json'], true);
        if (!is_array($groups)) {
            return redirect()->route('scheduler.merge-courses', ['rule_id' => $data['rule_id']])
                ->with('status', 'Merge blocks were not saved because the submitted layout was invalid.');
        }

        $lines = [];
        foreach ($groups as $index => $group) {
            if (!is_array($group)) {
                continue;
            }

            $label = trim((string) ($group['label'] ?? ''));
            $label = preg_replace('/[\r\n:=]+/', ' ', $label) ?: '';
            if ($label === '') {
                $label = 'Group ' . ($index + 1);
            }

            $members = [];
            foreach ((array) ($group['members'] ?? []) as $member) {
                if (!is_array($member)) {
                    continue;
                }

                $course = $this->normalizeMergeCourseCode((string) ($member['course'] ?? ''));
                if ($course === '') {
                    continue;
                }

                $program = $this->normalizeMergeProgramCode((string) ($member['program'] ?? '*')) ?: '*';
                $semester = $this->normalizeMergeSemester($member['semester'] ?? null);
                $classLabel = trim((string) ($member['class_label'] ?? ''));
                $instructor = trim((string) ($member['instructor_name'] ?? ''));
                $memberKey = implode(':', [
                    $course,
                    $program,
                    $semester !== null ? (string) $semester : '*',
                    strtoupper(preg_replace('/\s+/', ' ', $classLabel)),
                    strtoupper(preg_replace('/\s+/', ' ', $instructor)),
                ]);
                $members[$memberKey] = $this->formatMergeMember($course, $program, $semester, $classLabel, $instructor);
            }

            if (count($members) < 2) {
                continue;
            }

            $lines[] = $label . ': ' . implode('; ', array_values($members));
        }

        $rule = DateSheetRule::query()->findOrFail((int) $data['rule_id']);
        $rule->manual_course_merge_groups = implode("\n", $lines);
        $rule->save();

        return redirect()->route('scheduler.merge-courses', ['rule_id' => $rule->id])
            ->with('status', 'Course merge blocks saved. Saved blocks: ' . count($lines) . '.');
    }

    public function ignoreCourses(Request $request): View
    {
        [$rules, $selectedRule] = $this->selectedRule($request);

        return view('scheduler.ignore_courses', [
            'rules' => $rules,
            'selectedRule' => $selectedRule,
            'ignoreBoard' => $selectedRule
                ? $this->buildCourseIgnoreBoard($selectedRule)
                : ['ignored' => [], 'available' => [], 'offerings_count' => 0],
        ]);
    }

    public function storeIgnoreCourses(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
            'ignored_courses_json' => ['required', 'string'],
        ]);

        $members = json_decode((string) $data['ignored_courses_json'], true);
        if (!is_array($members)) {
            return redirect()->route('scheduler.ignore-courses', ['rule_id' => $data['rule_id']])
                ->with('status', 'Ignored courses were not saved because the submitted layout was invalid.');
        }

        $lines = [];
        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }

            $course = $this->normalizeMergeCourseCode((string) ($member['course'] ?? ''));
            if ($course === '') {
                continue;
            }

            $program = $this->normalizeMergeProgramCode((string) ($member['program'] ?? '*')) ?: '*';
            $semester = $this->normalizeMergeSemester($member['semester'] ?? null);
            $lineKey = $course . ':' . $program . ':' . ($semester !== null ? $semester : '*');
            $lines[$lineKey] = $this->formatMergeMember($course, $program, $semester);
        }

        $rule = DateSheetRule::query()->findOrFail((int) $data['rule_id']);
        $rule->ignored_course_offerings = implode("\n", array_values($lines));
        $rule->save();

        return redirect()->route('scheduler.ignore-courses', ['rule_id' => $rule->id])
            ->with('status', 'Ignored courses saved. Ignored offerings: ' . count($lines) . '.');
    }

    public function bulkUpdateCourses(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['nullable', 'integer', 'exists:scheduler_datesheetrule,id'],
            'courses' => ['nullable', 'array'],
            'courses.*.id' => ['required_with:courses', 'integer', 'exists:scheduler_course,id'],
            'courses.*.title' => ['nullable', 'string', 'max:200'],
        ]);

        $updated = 0;
        foreach ((array) ($data['courses'] ?? []) as $row) {
            $course = Course::query()->find((int) ($row['id'] ?? 0));
            if (!$course) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            if ((string) $course->title !== $title) {
                $course->title = $title;
                $course->save();
                $updated++;
            }
        }

        return redirect()->route('scheduler.courses', ['rule_id' => $data['rule_id'] ?? $request->input('rule_id')])
            ->with('status', "Course titles saved. Updated: {$updated}.");
    }

    public function importCourseTitles(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['nullable', 'integer', 'exists:scheduler_datesheetrule,id'],
            'course_titles_csv' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        [$created, $updated, $skipped] = $this->importCourseTitleCsv($request->file('course_titles_csv')->getRealPath());

        return redirect()->route('scheduler.courses', ['rule_id' => $data['rule_id'] ?? $request->input('rule_id')])
            ->with('status', "Course title CSV imported. Created: {$created}, updated: {$updated}, skipped: {$skipped}.");
    }

    public function students(Request $request): View
    {
        [$rules, $selectedRule] = $this->selectedRule($request);

        $programOptions = collect();
        $selectedProgram = trim((string) $request->input('program_code', ''));
        $search = trim((string) $request->input('q', ''));

        if ($selectedRule) {
            $programOptions = CourseRegistration::query()
                ->where('rule_id', $selectedRule->id)
                ->where('program_code', '<>', '')
                ->select('program_code')
                ->distinct()
                ->orderBy('program_code')
                ->pluck('program_code');

            if ($selectedProgram === '' && $programOptions->isNotEmpty()) {
                $selectedProgram = (string) $programOptions->first();
            }
        }

        $studentQuery = Student::query();
        if ($selectedRule) {
            $studentQuery->whereHas('registrations', function ($query) use ($selectedRule, $selectedProgram) {
                $query->where('rule_id', $selectedRule->id);

                if ($selectedProgram !== '') {
                    $query->where('program_code', $selectedProgram);
                }
            });
        } else {
            $studentQuery->whereRaw('1 = 0');
        }

        if ($selectedProgram === '') {
            $studentQuery->whereRaw('1 = 0');
        }

        if ($search !== '') {
            $studentQuery->where(function ($query) use ($search) {
                $query->where('name', 'like', '%' . $search . '%')
                    ->orWhere('registration_id', 'like', '%' . $search . '%');
            });
        }

        $students = $studentQuery
            ->with(['registrations' => function ($query) use ($selectedRule, $selectedProgram) {
                if ($selectedRule) {
                    $query->where('rule_id', $selectedRule->id);
                }

                if ($selectedProgram !== '') {
                    $query->where('program_code', $selectedProgram);
                }

                $query->with('course')
                    ->orderBy('program_code')
                    ->orderBy('class_label')
                    ->orderBy('course_id');
            }])
            ->withCount(['registrations as selected_session_courses_count' => function ($query) use ($selectedRule, $selectedProgram) {
                if ($selectedRule) {
                    $query->where('rule_id', $selectedRule->id);
                }

                if ($selectedProgram !== '') {
                    $query->where('program_code', $selectedProgram);
                }
            }])
            ->orderBy('program_code')
            ->orderBy('registration_id')
            ->paginate(200)
            ->withQueryString();

        $studentCourses = $students->getCollection()
            ->mapWithKeys(function (Student $student) {
                return [
                    $student->id => $student->registrations
                        ->map(fn (CourseRegistration $registration) => [
                            'code' => $registration->course?->code ?: '',
                            'title' => $registration->course?->title ?: '',
                            'class_label' => (string) $registration->class_label,
                            'instructor_name' => (string) $registration->instructor_name,
                            'program_code' => (string) $registration->program_code,
                            'semester_no' => $registration->semester_no,
                        ])
                        ->values(),
                ];
            });

        $stats = [
            'total_students' => Student::query()->count(),
            'selected_session_students' => $selectedRule
                ? CourseRegistration::query()->where('rule_id', $selectedRule->id)->distinct('student_id')->count('student_id')
                : 0,
            'selected_session_registrations' => $selectedRule
                ? CourseRegistration::query()->where('rule_id', $selectedRule->id)->count()
                : 0,
            'selected_session_faculty_assignments' => $selectedRule
                ? CourseFacultyAssignment::query()->where('rule_id', $selectedRule->id)->count()
                : 0,
            'generated_exams' => $selectedRule
                ? Exam::query()->where('rule_id', $selectedRule->id)->count()
                : 0,
        ];

        return view('scheduler.students', [
            'rules' => $rules,
            'selectedRule' => $selectedRule,
            'students' => $students,
            'programOptions' => $programOptions,
            'selectedProgram' => $selectedProgram,
            'filters' => [
                'q' => $search,
                'program_code' => $selectedProgram,
            ],
            'studentCourses' => $studentCourses,
            'stats' => $stats,
        ]);
    }

    public function clearStudents(Request $request, SchedulerDataResetService $resetService): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
            'scope' => ['required', 'in:selected_session,all_scheduler_students'],
        ]);

        if ($data['scope'] === 'all_scheduler_students') {
            $stats = $resetService->clearAllStudentData();
            $message = 'All scheduler student data was cleared. ';
        } else {
            $stats = $resetService->clearStudentDataForRule((int) $data['rule_id'], true);
            $message = 'Selected session student data was cleared. ';
        }

        $message .= collect($stats)->map(fn ($value, $key) => $key . ': ' . $value)->implode(' | ');

        return redirect()->route('scheduler.students', ['rule_id' => $data['rule_id']])->with('status', $message);
    }

    public function importStudentsWorkbook(Request $request, AttendanceWorkbookImportService $service): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
            'workbook' => ['required', 'file', 'mimes:xlsx,xls'],
            'import_mode' => ['nullable', 'in:append,replace'],
        ]);

        $storedPath = $request->file('workbook')->store('scheduler-imports');
        $replaceExisting = ($data['import_mode'] ?? 'append') === 'replace';
        $stats = $service->import((int) $data['rule_id'], Storage::path($storedPath), 'student_management_import', $replaceExisting);

        $message = ($replaceExisting ? 'Existing selected-session student data was cleared, then workbook imported. ' : 'Workbook appended/updated. ')
            . collect($stats)->map(fn ($value, $key) => $key . ': ' . $value)->implode(' | ');

        return redirect()->route('scheduler.students', ['rule_id' => $data['rule_id']])->with('status', $message);
    }

    public function faculty(Request $request): View
    {
        [$rules, $selectedRule] = $this->selectedRule($request);

        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'department' => trim((string) $request->input('department', '')),
            'designation' => trim((string) $request->input('designation', '')),
            'duty_status' => trim((string) $request->input('duty_status', '')),
            'max_duties_min' => $request->input('max_duties_min', ''),
            'max_duties_max' => $request->input('max_duties_max', ''),
        ];

        $facultyQuery = Faculty::query();

        if ($filters['q'] !== '') {
            $needle = '%' . $filters['q'] . '%';
            $facultyQuery->where(function ($query) use ($needle) {
                $query->where('name', 'like', $needle)
                    ->orWhere('department', 'like', $needle)
                    ->orWhere('designation', 'like', $needle);
            });
        }

        if ($filters['department'] !== '') {
            $facultyQuery->where('department', $filters['department']);
        }

        if ($filters['designation'] !== '') {
            $facultyQuery->where('designation', $filters['designation']);
        }

        if ($filters['max_duties_min'] !== '' && is_numeric($filters['max_duties_min'])) {
            $facultyQuery->where('max_duties', '>=', max(0, (int) $filters['max_duties_min']));
        }

        if ($filters['max_duties_max'] !== '' && is_numeric($filters['max_duties_max'])) {
            $facultyQuery->where('max_duties', '<=', max(0, (int) $filters['max_duties_max']));
        }

        match ($filters['duty_status']) {
            'excluded' => $facultyQuery->where('max_duties', 0),
            'active' => $facultyQuery->where('max_duties', '>', 0),
            'assigned' => $facultyQuery->where('assigned_duties', '>', 0),
            'available' => $facultyQuery->whereColumn('assigned_duties', '<', 'max_duties')->where('max_duties', '>', 0),
            'limit_reached' => $facultyQuery->whereColumn('assigned_duties', '>=', 'max_duties')->where('max_duties', '>', 0),
            default => null,
        };

        return view('scheduler.faculty', [
            'rules' => $rules,
            'selectedRule' => $selectedRule,
            'faculties' => $facultyQuery->orderBy('department')->orderBy('designation')->orderBy('name')->get(),
            'departments' => Department::query()->orderBy('name')->get(),
            'designations' => Designation::query()->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function facultyAvailability(Request $request): View
    {
        [$rules, $selectedRule] = $this->selectedRule($request);
        $pairs = $selectedRule ? $this->scheduledSlotPairs((int) $selectedRule->id) : collect();
        $dates = $pairs->pluck('date')->unique()->values()->all();
        if ($dates === [] && $selectedRule) {
            $dates = $this->candidateDays($selectedRule);
        }

        $selectedDate = (string) ($request->input('date') ?: ($dates[0] ?? ''));
        $filters = $this->facultyAvailabilityFilters($request);
        $viewModeInput = (string) $request->input('view_mode');
        $viewMode = in_array($viewModeInput, ['all_dates', 'faculty_member'], true) ? $viewModeInput : 'single_date';
        $baseSlots = $selectedRule ? SlotHelper::buildSlotsFromRule($selectedRule) : [];
        $allSlots = $this->availabilitySlotOptions($pairs, $baseSlots);
        $columns = $this->availabilityColumns($dates, $pairs, $baseSlots, $viewMode === 'faculty_member' ? 'all_dates' : $viewMode, $selectedDate, $filters['slot']);
        $slots = $viewMode === 'single_date'
            ? array_values(array_map(fn (array $column) => $column['slot'], $columns))
            : array_values(collect($columns)->pluck('slot')->unique('label')->all());

        $matrix = $selectedRule && is_array($selectedRule->faculty_slot_matrix) ? $selectedRule->faculty_slot_matrix : [];
        $faculties = $this->filteredAvailabilityFaculty($filters, $columns, $matrix);
        $selectedFacultyId = (int) $request->input('faculty_id', 0);
        if ($selectedFacultyId <= 0 && $viewMode === 'faculty_member' && $faculties->isNotEmpty()) {
            $selectedFacultyId = (int) $faculties->first()->id;
        }
        $selectedFaculty = $selectedFacultyId > 0
            ? Faculty::query()->find($selectedFacultyId)
            : null;

        return view('scheduler.faculty_availability', [
            'rules' => $rules,
            'selectedRule' => $selectedRule,
            'faculties' => $faculties,
            'selectedFacultyId' => $selectedFacultyId,
            'selectedFaculty' => $selectedFaculty,
            'dates' => $dates,
            'selectedDate' => $selectedDate,
            'viewMode' => $viewMode,
            'slots' => $slots,
            'allSlots' => $allSlots,
            'availabilityColumns' => $columns,
            'matrix' => $matrix,
            'filters' => $filters,
            'filterOptions' => [
                'departments' => Faculty::query()->whereNotNull('department')->where('department', '!=', '')->distinct()->orderBy('department')->pluck('department')->values()->all(),
                'designations' => Faculty::query()->whereNotNull('designation')->where('designation', '!=', '')->distinct()->orderBy('designation')->pluck('designation')->values()->all(),
            ],
        ]);
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['nullable', 'integer', 'exists:scheduler_datesheetrule,id'],
            'session_code' => ['required', 'string', 'max:20'],
            'session_label' => ['nullable', 'string', 'max:50'],
            'exam_title' => ['nullable', 'string', 'max:200'],
            'exam_type' => ['required', 'in:MID,FIN'],
            'midterm_default_minutes' => ['required', 'integer', 'min:30'],
            'terminal_default_minutes' => ['required', 'integer', 'min:30'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'off_days_overall' => ['nullable', 'string'],
            'off_days_per_class' => ['nullable', 'string'],
            'course_slot_preferences' => ['nullable', 'string'],
            'course_date_preferences' => ['nullable', 'string'],
            'same_course_together_codes' => ['nullable', 'string'],
            'manual_course_merge_groups' => ['nullable', 'string'],
            'ignored_course_offerings' => ['nullable', 'string'],
            'merge_same_course_program_instructor' => ['nullable', 'boolean'],
            'merge_same_course_strength_threshold' => ['nullable', 'integer', 'min:0'],
            'same_day_student_conflict_limit' => ['nullable', 'integer', 'min:0'],
            'enforce_course_date_preferences' => ['nullable', 'boolean'],
            'single_duty_per_faculty_per_day' => ['nullable', 'boolean'],
            'pg_course_codes' => ['nullable', 'string'],
            'pg_slot_preference' => ['nullable', 'string', 'max:50'],
            'pg_program_prefixes' => ['nullable', 'string', 'max:100'],
            'pg_course_level_min' => ['nullable', 'integer', 'min:0'],
            'slot_start' => ['nullable', 'array'],
            'slot_start.*' => ['nullable', 'date_format:H:i'],
            'slot_end' => ['nullable', 'array'],
            'slot_end.*' => ['nullable', 'date_format:H:i'],
            'slot_label' => ['nullable', 'array'],
            'slot_label.*' => ['nullable', 'string', 'max:50'],
            'slot_type' => ['nullable', 'array'],
            'slot_type.*' => ['nullable', 'in:MORNING,AFTERNOON,EVENING'],
            'day_start_time' => ['required', 'date_format:H:i'],
            'day_end_time' => ['required', 'date_format:H:i', 'after:day_start_time'],
            'slots_per_day' => ['required', 'integer', 'min:0'],
            'slot_break_minutes' => ['required', 'integer', 'min:0'],
            'weekend_days' => ['nullable', 'array'],
            'weekend_days.*' => ['integer', 'min:0', 'max:6'],
        ]);

        $payload = [
            'session_code' => $data['session_code'],
            'session_label' => $data['session_label'] ?? '',
            'exam_title' => $data['exam_title'] ?? '',
            'exam_type' => $data['exam_type'],
            'midterm_default_minutes' => $data['midterm_default_minutes'],
            'terminal_default_minutes' => $data['terminal_default_minutes'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'off_days_overall' => $data['off_days_overall'] ?? '',
            'off_days_per_class' => $data['off_days_per_class'] ?? '',
            'course_slot_preferences' => $data['course_slot_preferences'] ?? '',
            'course_date_preferences' => $data['course_date_preferences'] ?? '',
            'same_course_together_codes' => $data['same_course_together_codes'] ?? '',
            'manual_course_merge_groups' => $data['manual_course_merge_groups'] ?? '',
            'ignored_course_offerings' => $data['ignored_course_offerings'] ?? '',
            'pg_course_codes' => $data['pg_course_codes'] ?? '',
            'pg_slot_preference' => $data['pg_slot_preference'] ?? 'EVENING',
            'pg_program_prefixes' => $data['pg_program_prefixes'] ?? 'P,R',
            'pg_course_level_min' => $data['pg_course_level_min'] ?? 700,
            'day_start_time' => $data['day_start_time'] . ':00',
            'day_end_time' => $data['day_end_time'] . ':00',
            'slots_per_day' => $data['slots_per_day'],
            'slot_break_minutes' => $data['slot_break_minutes'],
            'slots' => $this->buildManualSlotsFromRequest($request, (int) $data['slots_per_day']),
            'schedule_same_course_code_together' => $request->boolean('schedule_same_course_code_together'),
            'allow_same_day_student_conflicts' => $request->boolean('allow_same_day_student_conflicts'),
            'same_day_student_conflict_limit' => (int) ($data['same_day_student_conflict_limit'] ?? 0),
            'balance_daily_strength' => $request->boolean('balance_daily_strength'),
            'exclude_weekends' => $request->boolean('exclude_weekends'),
            'weekend_days' => array_map('intval', $request->input('weekend_days', [0, 6])),
            'split_rooms' => $request->boolean('split_rooms'),
            'schedule_pg_courses_in_preferred_slot' => $request->boolean('schedule_pg_courses_in_preferred_slot', true),
            'exclude_pg_preferred_slot_for_bs' => $request->boolean('exclude_pg_preferred_slot_for_bs', true),
            'prevent_cross_building_for_same_class' => $request->boolean('prevent_cross_building_for_same_class', true),
            'merge_same_course_program_instructor' => $request->boolean('merge_same_course_program_instructor'),
            'merge_same_course_strength_threshold' => (int) ($data['merge_same_course_strength_threshold'] ?? 0),
            'enforce_course_date_preferences' => $request->boolean('enforce_course_date_preferences', true),
            'single_duty_per_faculty_per_day' => $request->boolean('single_duty_per_faculty_per_day'),
            'is_active' => $request->boolean('is_active', true),
        ];

        if (!empty($data['rule_id'])) {
            $rule = DateSheetRule::query()->findOrFail($data['rule_id']);
            $rule->update($payload);
            $message = 'Exam session updated successfully.';
        } else {
            $rule = DateSheetRule::query()->create($payload);
            $message = 'Exam session created successfully.';
        }

        return redirect()->route('scheduler.index', ['rule_id' => $rule->id])->with('status', $message);
    }

    public function storeRoom(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'string', 'max:50', 'unique:scheduler_room,room_id'],
            'room_name' => ['required', 'string', 'max:100'],
            'capacity' => ['required', 'integer', 'min:0'],
            'rows' => ['nullable', 'integer', 'min:0'],
            'cols' => ['nullable', 'integer', 'min:0'],
            'invigilators_required' => ['required', 'integer', 'min:0'],
            'building' => ['nullable', 'string', 'max:100'],
            'floor' => ['nullable', 'string', 'max:50'],
        ]);

        Room::query()->create([
            'room_id' => $data['room_id'],
            'room_name' => $data['room_name'],
            'capacity' => $data['capacity'],
            'rows' => $data['rows'] ?? 0,
            'cols' => $data['cols'] ?? 0,
            'invigilators_required' => $data['invigilators_required'],
            'building' => $data['building'] ?? '',
            'floor' => $data['floor'] ?? '',
            'contingency_only' => $request->boolean('contingency_only'),
            'allow_split' => $request->boolean('allow_split', true),
            'is_waiting_room' => $request->boolean('is_waiting_room'),
        ]);

        return redirect()->route('scheduler.index', ['rule_id' => $request->input('rule_id')])->with('status', 'Room created successfully.');
    }

    public function storeFaculty(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'department' => ['nullable', 'string', 'max:200'],
            'designation' => ['nullable', 'string', 'max:200'],
            'max_duties' => ['required', 'integer', 'min:0'],
        ]);

        $department = trim((string) ($data['department'] ?? ''));
        $designation = trim((string) ($data['designation'] ?? ''));
        $this->ensureFacultyMasters($department, $designation);

        Faculty::query()->create([
            'name' => $data['name'],
            'department' => $department,
            'designation' => $designation,
            'max_duties' => $data['max_duties'],
            'assigned_duties' => 0,
        ]);

        return redirect()->route('scheduler.index', ['rule_id' => $request->input('rule_id')])->with('status', 'Faculty created successfully.');
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'rule_id' => ['nullable', 'integer', 'exists:scheduler_datesheetrule,id'],
        ]);

        Department::query()->updateOrCreate(['name' => trim((string) $data['name'])]);

        return redirect()->route('scheduler.faculty', ['rule_id' => $data['rule_id'] ?? $request->input('rule_id')])->with('status', 'Department saved successfully.');
    }

    public function deleteDepartment(Request $request, Department $department): RedirectResponse
    {
        $ruleId = $request->input('rule_id');
        if (Faculty::query()->where('department', $department->name)->exists()) {
            return redirect()->route('scheduler.faculty', ['rule_id' => $ruleId])->with('status', 'Department is in use by faculty and was not deleted.');
        }

        $department->delete();

        return redirect()->route('scheduler.faculty', ['rule_id' => $ruleId])->with('status', 'Department deleted successfully.');
    }

    public function storeDesignation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'rule_id' => ['nullable', 'integer', 'exists:scheduler_datesheetrule,id'],
        ]);

        Designation::query()->updateOrCreate(['name' => trim((string) $data['name'])]);

        return redirect()->route('scheduler.faculty', ['rule_id' => $data['rule_id'] ?? $request->input('rule_id')])->with('status', 'Designation saved successfully.');
    }

    public function deleteDesignation(Request $request, Designation $designation): RedirectResponse
    {
        $ruleId = $request->input('rule_id');
        if (Faculty::query()->where('designation', $designation->name)->exists()) {
            return redirect()->route('scheduler.faculty', ['rule_id' => $ruleId])->with('status', 'Designation is in use by faculty and was not deleted.');
        }

        $designation->delete();

        return redirect()->route('scheduler.faculty', ['rule_id' => $ruleId])->with('status', 'Designation deleted successfully.');
    }

    public function bulkUpdateFaculty(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['nullable', 'integer', 'exists:scheduler_datesheetrule,id'],
            'faculty' => ['nullable', 'array'],
            'faculty.*.id' => ['required_with:faculty', 'integer', 'exists:scheduler_faculty,id'],
            'faculty.*.name' => ['nullable', 'string', 'max:200'],
            'faculty.*.department' => ['nullable', 'string', 'max:200'],
            'faculty.*.designation' => ['nullable', 'string', 'max:200'],
            'faculty.*.max_duties' => ['nullable', 'integer', 'min:0'],
            'faculty.*.delete' => ['nullable'],
            'selected_faculty_ids' => ['nullable', 'array'],
            'selected_faculty_ids.*' => ['integer', 'exists:scheduler_faculty,id'],
            'bulk_department' => ['nullable', 'string', 'max:200'],
            'bulk_designation' => ['nullable', 'string', 'max:200'],
            'bulk_max_duties' => ['nullable', 'integer', 'min:0'],
            'bulk_reset_assigned' => ['nullable'],
            'bulk_delete_selected' => ['nullable'],
            'new_faculty_text' => ['nullable', 'string'],
        ]);

        $updated = 0;
        $deleted = 0;
        $created = 0;

        foreach ((array) ($data['faculty'] ?? []) as $row) {
            $faculty = Faculty::query()->find((int) ($row['id'] ?? 0));
            if (!$faculty) {
                continue;
            }

            if (!empty($row['delete'])) {
                $faculty->delete();
                $deleted++;
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $department = trim((string) ($row['department'] ?? ''));
            $designation = trim((string) ($row['designation'] ?? ''));
            $this->ensureFacultyMasters($department, $designation);

            $faculty->update([
                'name' => $name,
                'department' => $department,
                'designation' => $designation,
                'max_duties' => max(0, (int) ($row['max_duties'] ?? $faculty->max_duties ?? 0)),
            ]);
            $updated++;
        }

        $selectedIds = collect((array) ($data['selected_faculty_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $bulkTouched = 0;
        if ($selectedIds !== []) {
            if ($request->boolean('bulk_delete_selected')) {
                $bulkTouched = Faculty::query()->whereIn('id', $selectedIds)->delete();
                $deleted += $bulkTouched;
            } else {
                $bulkPayload = [];
                if ($request->filled('bulk_department')) {
                    $bulkPayload['department'] = trim((string) $data['bulk_department']);
                }
                if ($request->filled('bulk_designation')) {
                    $bulkPayload['designation'] = trim((string) $data['bulk_designation']);
                }
                if ($request->has('bulk_max_duties') && $request->input('bulk_max_duties') !== null && $request->input('bulk_max_duties') !== '') {
                    $bulkPayload['max_duties'] = max(0, (int) $data['bulk_max_duties']);
                }
                if ($request->boolean('bulk_reset_assigned')) {
                    $bulkPayload['assigned_duties'] = 0;
                }

                if ($bulkPayload !== []) {
                    $this->ensureFacultyMasters($bulkPayload['department'] ?? '', $bulkPayload['designation'] ?? '');
                    $bulkTouched = Faculty::query()->whereIn('id', $selectedIds)->update($bulkPayload);
                    $updated += $bulkTouched;
                }
            }
        }

        foreach ($this->parseBulkFacultyText((string) ($data['new_faculty_text'] ?? '')) as $row) {
            $this->ensureFacultyMasters($row['department'], $row['designation']);
            Faculty::query()->updateOrCreate(
                ['name' => $row['name']],
                [
                    'department' => $row['department'],
                    'designation' => $row['designation'],
                    'max_duties' => $row['max_duties'],
                    'assigned_duties' => 0,
                ]
            );
            $created++;
        }

        $message = "Faculty saved. Updated: {$updated}, created: {$created}, deleted: {$deleted}.";

        return redirect()->route('scheduler.faculty', ['rule_id' => $data['rule_id'] ?? $request->input('rule_id')])->with('status', $message);
    }

    public function storeFacultyAvailability(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
            'date' => ['nullable', 'date'],
            'view_mode' => ['nullable', 'in:single_date,all_dates,faculty_member'],
            'availability_action' => ['nullable', 'in:save,reset_all_available'],
            'unavailable' => ['nullable', 'array'],
            'visible_dates' => ['nullable', 'array'],
            'visible_dates.*' => ['date'],
            'visible_slots' => ['nullable', 'array'],
            'visible_slots.*' => ['string', 'max:50'],
            'visible_columns' => ['nullable', 'array'],
            'visible_columns.*' => ['array'],
            'visible_columns.*.*' => ['string', 'max:50'],
            'visible_faculty_ids' => ['nullable', 'array'],
            'visible_faculty_ids.*' => ['integer', 'exists:scheduler_faculty,id'],
        ]);

        $rule = DateSheetRule::query()->findOrFail((int) $data['rule_id']);
        $matrix = is_array($rule->faculty_slot_matrix) ? $rule->faculty_slot_matrix : [];
        $viewModeInput = (string) ($data['view_mode'] ?? 'single_date');
        $viewMode = in_array($viewModeInput, ['single_date', 'all_dates', 'faculty_member'], true) ? $viewModeInput : 'single_date';
        $date = (string) ($data['date'] ?? '');
        $visibleDates = array_values(array_unique(array_filter(array_map('strval', (array) ($data['visible_dates'] ?? [])))));
        $visibleSlots = array_values(array_unique(array_filter(array_map('strval', (array) ($data['visible_slots'] ?? [])))));
        $visibleFacultyIds = array_values(array_unique(array_map('intval', (array) ($data['visible_faculty_ids'] ?? []))));

        if ($visibleDates === [] && $date !== '') {
            $visibleDates = [$date];
        }
        $visibleColumnMap = $this->visibleAvailabilityColumnMap((array) ($data['visible_columns'] ?? []), $visibleDates, $visibleSlots);
        $visibleDates = array_keys($visibleColumnMap);
        $visibleSlots = array_values(array_unique(array_merge(...array_values($visibleColumnMap ?: [[]]))));

        $action = (string) ($data['availability_action'] ?? 'save');
        if ($action === 'reset_all_available') {
            $rule->faculty_slot_matrix = [];
            $rule->save();

            $redirectParams = $this->facultyAvailabilityRedirectParams($request, (int) $rule->id, $date, $viewMode);

            return redirect()->route('scheduler.faculty-availability', $redirectParams)
                ->with('status', 'All faculty availability has been reset. Every faculty member is now available for every generated slot.');
        }

        if ($visibleColumnMap === [] || $visibleFacultyIds === []) {
            return redirect()->back()->withInput()->with('status', 'No visible availability cells were available to save.');
        }

        foreach ($visibleColumnMap as $visibleDate => $slotLabels) {
            foreach ($visibleFacultyIds as $facultyId) {
                $facultyKey = (string) $facultyId;
                foreach ($slotLabels as $slotLabel) {
                    unset($matrix[$visibleDate][$facultyKey][(string) $slotLabel]);
                    unset($matrix[$visibleDate][$facultyKey][SlotHelper::normalizeSlotKey((string) $slotLabel)]);
                }
            }
        }

        foreach ((array) ($data['unavailable'] ?? []) as $visibleDate => $facultyMap) {
            if (!in_array((string) $visibleDate, $visibleDates, true) || !is_array($facultyMap)) {
                continue;
            }

            foreach ($facultyMap as $facultyId => $slotMap) {
                if (!in_array((int) $facultyId, $visibleFacultyIds, true) || !is_array($slotMap)) {
                    continue;
                }

                foreach ($slotMap as $slotLabel => $value) {
                    if (!$value || !in_array((string) $slotLabel, $visibleColumnMap[(string) $visibleDate] ?? [], true)) {
                        continue;
                    }

                    $matrix[(string) $visibleDate][(string) $facultyId][(string) $slotLabel] = true;
                    $matrix[(string) $visibleDate][(string) $facultyId][SlotHelper::normalizeSlotKey((string) $slotLabel)] = true;
                }
            }
        }

        $rule->faculty_slot_matrix = $this->cleanFacultyAvailabilityMatrix($matrix);
        $rule->save();

        $redirectParams = $this->facultyAvailabilityRedirectParams($request, (int) $rule->id, $date, $viewMode);

        return redirect()->route('scheduler.faculty-availability', $redirectParams)->with('status', $viewMode === 'all_dates'
            ? 'Faculty availability saved for the visible dates and slots.'
            : 'Faculty availability saved for ' . $date . '.');
    }

    public function importWorkbook(Request $request, AttendanceWorkbookImportService $service): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
            'workbook' => ['required', 'file', 'mimes:xlsx,xls'],
            'import_mode' => ['nullable', 'in:append,replace'],
        ]);

        $storedPath = $request->file('workbook')->store('scheduler-imports');
        $replaceExisting = ($data['import_mode'] ?? 'append') === 'replace';
        $stats = $service->import((int) $data['rule_id'], Storage::path($storedPath), 'ui_workbook_import', $replaceExisting);

        $message = ($replaceExisting ? 'Existing selected-session student data was cleared, then workbook imported. ' : 'Workbook imported/appended successfully. ')
            . collect($stats)->map(fn ($value, $key) => $key . ': ' . $value)->implode(' | ');

        return redirect()->route('scheduler.index', ['rule_id' => $data['rule_id']])->with('status', $message);
    }

    public function generateDateSheet(Request $request, DateSheetGenerationService $service): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
        ]);

        $result = $service->generate(
            (int) $data['rule_id'],
            $request->boolean('clear_existing', true),
            'ui_generated_datesheet'
        );

        $message = ($request->boolean('clear_existing', true) ? 'Datesheet deleted/regenerated; room allocations, seating, and duty roster were cleared. Scheduled exams: ' : 'Datesheet generated. Scheduled exams: ') . ($result['scheduled_exams'] ?? 0)
            . ', clusters: ' . ($result['scheduled_clusters'] ?? 0)
            . ', unscheduled: ' . count($result['unscheduled_clusters'] ?? []);

        if (!empty($result['warnings'])) {
            $message .= ' Warnings: ' . implode(' | ', array_slice($result['warnings'], 0, 5));
        }

        return redirect()->route('scheduler.datesheet', ['rule_id' => $data['rule_id']])->with('status', $message);
    }

    public function allocateRooms(Request $request, RoomAllocationService $service): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
            'date' => ['required', 'date'],
            'slot' => ['required', 'string', 'max:50'],
        ]);

        [$count, $warnings] = $service->allocateForSlot((int) $data['rule_id'], $data['date'], $data['slot']);

        $message = 'Room allocation completed. Allocations created: ' . $count;
        if ($warnings) {
            $message .= ' Warnings: ' . implode(' | ', array_slice($warnings, 0, 5));
        }

        return redirect()->route('scheduler.seat-plan', ['rule_id' => $data['rule_id'], 'date' => $data['date'], 'slot' => $data['slot']])->with('status', $message);
    }

    public function renameSlot(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
            'old_slot' => ['required', 'string', 'max:50'],
            'new_slot_label' => ['nullable', 'string', 'max:50'],
            'new_slot_start' => ['required', 'date_format:H:i'],
            'new_slot_end' => ['required', 'date_format:H:i', 'after:new_slot_start'],
            'new_slot_type' => ['required', 'in:MORNING,AFTERNOON,EVENING'],
        ]);

        $rule = DateSheetRule::query()->findOrFail((int) $data['rule_id']);
        $slots = SlotHelper::buildSlotsFromRule($rule);
        $oldSlot = trim((string) $data['old_slot']);
        $newLabel = trim((string) ($data['new_slot_label'] ?? ''));
        if ($newLabel === '') {
            $newLabel = str_replace(':', '', (string) $data['new_slot_start']) . '-' . str_replace(':', '', (string) $data['new_slot_end']);
        }

        $matched = false;
        foreach ($slots as $index => $slot) {
            $slotLabel = (string) ($slot['label'] ?? '');
            $slotId = (string) ($slot['id'] ?? '');
            if ($slotLabel !== $oldSlot && $slotId !== $oldSlot) {
                continue;
            }

            $slots[$index] = [
                'id' => SlotHelper::normalizeSlotKey($newLabel) ?: ('slot' . ($index + 1)),
                'label' => $newLabel,
                'start' => (string) $data['new_slot_start'],
                'end' => (string) $data['new_slot_end'],
                'type' => (string) $data['new_slot_type'],
            ];
            $matched = true;
            break;
        }

        if (!$matched) {
            return back()->withErrors(['old_slot' => 'Selected slot was not found in this session.'])->withInput();
        }

        $duplicateLabels = collect($slots)
            ->map(fn (array $slot) => trim((string) ($slot['label'] ?? '')))
            ->filter()
            ->map(fn (string $label) => SlotHelper::normalizeSlotKey($label))
            ->duplicates();

        if ($duplicateLabels->isNotEmpty()) {
            return back()->withErrors(['new_slot_label' => 'Another slot already has this label. Use a unique slot time/label.'])->withInput();
        }

        $updatedExams = 0;
        DB::transaction(function () use ($rule, $slots, $oldSlot, $newLabel, &$updatedExams) {
            $rule->slots = array_values($slots);
            $rule->slots_per_day = count($slots);
            $rule->save();

            if ($newLabel !== $oldSlot) {
                $updatedExams = Exam::query()
                    ->where('rule_id', (int) $rule->id)
                    ->where('time_slot', $oldSlot)
                    ->update(['time_slot' => $newLabel]);
            }
        });

        $message = 'Slot time updated from ' . $oldSlot . ' to ' . $newLabel . '. Existing datesheet rows updated: ' . $updatedExams . '.';

        return redirect()->route('scheduler.datesheet', ['rule_id' => $rule->id])->with('status', $message);
    }

    public function allocateRoomsAll(Request $request, RoomAllocationService $service): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
        ]);

        $ruleId = (int) $data['rule_id'];
        $pairs = $this->scheduledSlotPairs($ruleId);

        if ($pairs->isEmpty()) {
            return redirect()->route('scheduler.datesheet', ['rule_id' => $ruleId])
                ->with('status', 'No generated datesheet slots were found. Generate the datesheet first.');
        }

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

        $first = $pairs->first();
        $message = 'Room allocation completed for all datesheet slots. Slots processed: ' . $processed
            . ', allocations created: ' . $totalAllocations . '.';
        if ($warnings !== []) {
            $message .= ' Warnings: ' . implode(' | ', array_slice(array_unique($warnings), 0, 8));
        }

        return redirect()->route('scheduler.seat-plan', [
            'rule_id' => $ruleId,
            'date' => $first['date'] ?? null,
            'slot' => $first['slot'] ?? null,
        ])->with('status', $message);
    }

    public function generateSeating(Request $request, SeatingGenerationService $service): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
            'date' => ['required', 'date'],
            'slot' => ['required', 'string', 'max:50'],
        ]);

        [$count, $warnings] = $service->generateForSlot((int) $data['rule_id'], $data['date'], $data['slot']);

        $message = 'Seating generated. Seats created: ' . $count;
        if ($warnings) {
            $message .= ' Warnings: ' . implode(' | ', array_slice($warnings, 0, 5));
        }

        return redirect()->route('scheduler.seat-plan', ['rule_id' => $data['rule_id'], 'date' => $data['date'], 'slot' => $data['slot']])->with('status', $message);
    }

    public function generateSeatingAll(Request $request, SeatingGenerationService $service): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
        ]);

        $ruleId = (int) $data['rule_id'];
        $pairs = $this->scheduledSlotPairs($ruleId);

        if ($pairs->isEmpty()) {
            return redirect()->route('scheduler.datesheet', ['rule_id' => $ruleId])
                ->with('status', 'No generated datesheet slots were found. Generate the datesheet first.');
        }

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

        $first = $pairs->first();
        $message = 'Seat plan generated for all datesheet slots. Slots processed: ' . $processed
            . ', seats created: ' . $totalSeats . '.';
        if ($warnings !== []) {
            $message .= ' Warnings: ' . implode(' | ', array_slice(array_unique($warnings), 0, 8));
        }

        return redirect()->route('scheduler.seat-plan', [
            'rule_id' => $ruleId,
            'date' => $first['date'] ?? null,
            'slot' => $first['slot'] ?? null,
        ])->with('status', $message);
    }

    public function assignInvigilators(Request $request, InvigilatorAssignmentService $slotService, DutyRosterService $dutyService): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
            'date' => ['nullable', 'date'],
            'slot' => ['nullable', 'string', 'max:50'],
        ]);

        if ($request->boolean('all', false)) {
            [$assigned, $tbd, $warnings] = $dutyService->regenerate((int) $data['rule_id']);
            $message = 'Invigilation regenerated for all slots. Assigned: ' . $assigned . ', TBD: ' . $tbd;
            if ($warnings) {
                $message .= ' Warnings: ' . implode(' | ', array_slice($warnings, 0, 5));
            }

            return redirect()->route('scheduler.duty-roster', ['rule_id' => $data['rule_id']])->with('status', $message);
        }

        $slotData = $request->validate([
            'date' => ['required', 'date'],
            'slot' => ['required', 'string', 'max:50'],
        ]);

        [$assigned, $tbd, $warnings] = $slotService->assignForSlot((int) $data['rule_id'], $slotData['date'], $slotData['slot']);
        $message = 'Invigilators assigned for selected slot. Assigned: ' . $assigned . ', TBD: ' . $tbd;
        if ($warnings) {
            $message .= ' Warnings: ' . implode(' | ', array_slice($warnings, 0, 5));
        }

        return redirect()->route('scheduler.duty-roster', ['rule_id' => $data['rule_id']])->with('status', $message);
    }

    public function exportDateSheet(Request $request, DateSheetExportService $service)
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
            'format' => ['required', 'in:midterm,terminal,with_rooms'],
        ]);

        $output = storage_path('app/scheduler/exports/' . $data['format'] . '_datesheet_rule_' . $data['rule_id'] . '.xlsx');
        $path = $service->export((int) $data['rule_id'], $data['format'], $output);

        return response()->download($path)->deleteFileAfterSend(false);
    }

    public function exportOperations(Request $request, OperationalExportService $service)
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer', 'exists:scheduler_datesheetrule,id'],
            'type' => ['required', 'in:datesheet_board,datesheet_final,room_allocation,seat_plan,seat_plan_all,duty_roster,room_grid,faculty_availability,faculty_list'],
            'date' => ['nullable', 'date'],
            'slot' => ['nullable', 'string', 'max:50'],
            'pairs' => ['nullable', 'array'],
            'pairs.*' => ['string', 'max:120'],
            'missing_duties_only' => ['nullable', 'boolean'],
        ]);

        $type = (string) $data['type'];
        $name = $type . '_rule_' . $data['rule_id'];
        if (!empty($data['date'])) {
            $name .= '_' . preg_replace('/[^0-9A-Za-z_-]/', '', (string) $data['date']);
        }
        if (!empty($data['slot'])) {
            $name .= '_' . preg_replace('/[^0-9A-Za-z_-]/', '', (string) $data['slot']);
        }

        $extension = $type === 'seat_plan_all' ? '.zip' : '.xlsx';
        $output = storage_path('app/scheduler/exports/' . $name . $extension);
        $path = $service->export((int) $data['rule_id'], $type, $output, $data['date'] ?? null, $data['slot'] ?? null, [
            'q' => (string) $request->input('q', ''),
            'date' => (string) ($data['date'] ?? $request->input('date', '')),
            'slot' => (string) ($data['slot'] ?? $request->input('slot', '')),
            'department' => (string) $request->input('department', ''),
            'designation' => (string) $request->input('designation', ''),
            'duty_status' => (string) $request->input('duty_status', ''),
            'availability_status' => (string) $request->input('availability_status', ''),
            'slot_filter' => (string) $request->input('slot_filter', ''),
            'pairs' => (array) $request->input('pairs', []),
            'missing_duties_only' => $request->boolean('missing_duties_only'),
        ]);

        return response()->download($path)->deleteFileAfterSend(false);
    }


    private function dutyRosterFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'department' => trim((string) $request->input('department', '')),
            'designation' => trim((string) $request->input('designation', '')),
            'date' => trim((string) $request->input('date', '')),
            'slot' => trim((string) $request->input('slot', '')),
            'duty_status' => trim((string) $request->input('duty_status', '')),
        ];
    }

    private function facultyAvailabilityFilters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            'department' => trim((string) $request->input('department', '')),
            'designation' => trim((string) $request->input('designation', '')),
            'slot' => trim((string) $request->input('slot_filter', '')),
            'duty_status' => trim((string) $request->input('duty_status', '')),
            'availability_status' => trim((string) $request->input('availability_status', '')),
        ];
    }

    private function filterFacultyDutyMatrix(array $matrix, array $filters): array
    {
        $columns = collect($matrix['columns'] ?? [])
            ->filter(function (array $column) use ($filters) {
                if (($filters['date'] ?? '') !== '' && (string) ($column['date'] ?? '') !== $filters['date']) {
                    return false;
                }
                if (($filters['slot'] ?? '') !== '' && (string) ($column['slot'] ?? '') !== $filters['slot']) {
                    return false;
                }
                return true;
            })
            ->values()
            ->all();

        $columnKeys = collect($columns)->pluck('key')->flip()->all();

        $faculties = collect($matrix['faculties'] ?? [])
            ->map(function (array $faculty) use ($columnKeys) {
                $cells = [];
                foreach ((array) ($faculty['cells'] ?? []) as $key => $value) {
                    if (isset($columnKeys[$key])) {
                        $cells[$key] = $value;
                    }
                }

                $visibleDutyCount = collect($cells)->filter(fn ($value) => trim((string) $value) !== '')->count();
                $dailyCounts = [];
                foreach ($cells as $key => $value) {
                    if (trim((string) $value) === '') {
                        continue;
                    }
                    $date = explode('|', (string) $key)[0] ?? '';
                    if ($date !== '') {
                        $dailyCounts[$date] = ($dailyCounts[$date] ?? 0) + 1;
                    }
                }
                $multiDutyDates = collect($dailyCounts)->filter(fn ($count) => (int) $count > 1)->keys()->values()->all();

                $faculty['cells'] = $cells;
                $faculty['visible_duty_count'] = $visibleDutyCount;
                $faculty['visible_daily_duty_counts'] = $dailyCounts;
                $faculty['visible_multi_duty_dates'] = $multiDutyDates;
                $faculty['visible_has_multi_duty_day'] = count($multiDutyDates) > 0;

                return $faculty;
            })
            ->filter(fn (array $faculty) => $this->facultyDutyRowMatches($faculty, $filters))
            ->values()
            ->all();

        $departments = collect($faculties)
            ->groupBy(fn (array $faculty) => trim((string) ($faculty['department'] ?? '')) !== '' ? (string) $faculty['department'] : 'Unassigned')
            ->map(fn ($items, $department) => [
                'department' => (string) $department,
                'faculties' => $items->values()->all(),
            ])
            ->values()
            ->all();

        $matrix['columns'] = $columns;
        $matrix['faculties'] = $faculties;
        $matrix['departments'] = $departments;
        $matrix['dates'] = collect($columns)->pluck('date')->unique()->values()->all();
        $matrix['slots_by_date'] = collect($columns)->groupBy('date')->map(fn ($items) => $items->pluck('slot')->values()->all())->all();

        return $matrix;
    }

    private function facultyDutyRowMatches(array $faculty, array $filters): bool
    {
        $needle = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        if ($needle !== '') {
            $haystack = mb_strtolower(implode(' ', [
                (string) ($faculty['name'] ?? ''),
                (string) ($faculty['department'] ?? ''),
                (string) ($faculty['designation'] ?? ''),
            ]));
            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }

        if (($filters['department'] ?? '') !== '' && (string) ($faculty['department'] ?? '') !== $filters['department']) {
            return false;
        }

        if (($filters['designation'] ?? '') !== '' && (string) ($faculty['designation'] ?? '') !== $filters['designation']) {
            return false;
        }

        $visibleDutyCount = (int) ($faculty['visible_duty_count'] ?? 0);
        $maxDuties = (int) ($faculty['max_duties'] ?? 0);

        return match ((string) ($filters['duty_status'] ?? '')) {
            'assigned' => $visibleDutyCount > 0,
            'not_assigned' => $visibleDutyCount === 0,
            'multi_same_day' => !empty($faculty['visible_has_multi_duty_day']),
            'no_multi_same_day' => empty($faculty['visible_has_multi_duty_day']),
            'excluded' => $maxDuties === 0,
            'active' => $maxDuties > 0,
            default => true,
        };
    }

    private function filteredAvailabilityFaculty(array $filters, array $visibleColumns, array $matrix)
    {
        $query = Faculty::query();

        if (($filters['q'] ?? '') !== '') {
            $needle = '%' . $filters['q'] . '%';
            $query->where(function ($builder) use ($needle) {
                $builder->where('name', 'like', $needle)
                    ->orWhere('department', 'like', $needle)
                    ->orWhere('designation', 'like', $needle);
            });
        }

        if (($filters['department'] ?? '') !== '') {
            $query->where('department', $filters['department']);
        }

        if (($filters['designation'] ?? '') !== '') {
            $query->where('designation', $filters['designation']);
        }

        match ((string) ($filters['duty_status'] ?? '')) {
            'excluded' => $query->where('max_duties', 0),
            'active' => $query->where('max_duties', '>', 0),
            'quota_available' => $query->whereColumn('assigned_duties', '<', 'max_duties')->where('max_duties', '>', 0),
            'quota_full' => $query->whereColumn('assigned_duties', '>=', 'max_duties')->where('max_duties', '>', 0),
            default => null,
        };

        $faculties = $query->orderBy('department')->orderBy('name')->get();
        $availabilityStatus = (string) ($filters['availability_status'] ?? '');
        if ($availabilityStatus === '' || $visibleColumns === []) {
            return $faculties;
        }

        return $faculties->filter(function (Faculty $faculty) use ($availabilityStatus, $visibleColumns, $matrix) {
            $states = [];
            foreach ($visibleColumns as $column) {
                $date = (string) ($column['date'] ?? '');
                $slot = (array) ($column['slot'] ?? []);
                $label = (string) ($slot['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                $states[] = (bool) (($matrix[$date][(string) $faculty->id][$label] ?? false)
                    || ($matrix[$date][(string) $faculty->id][SlotHelper::normalizeSlotKey($label)] ?? false));
            }

            if ($states === []) {
                return true;
            }

            $unavailableCount = collect($states)->filter()->count();

            return match ($availabilityStatus) {
                'unavailable_any' => $unavailableCount > 0,
                'unavailable_all' => $unavailableCount === count($states),
                'available_all' => $unavailableCount === 0,
                default => true,
            };
        })->values();
    }

    private function availabilityColumns(array $dates, $pairs, array $baseSlots, string $viewMode, string $selectedDate, string $slotFilter): array
    {
        $visibleDates = $viewMode === 'all_dates'
            ? $dates
            : array_values(array_filter([$selectedDate]));
        $columns = [];

        foreach ($visibleDates as $date) {
            foreach ($this->availabilitySlotsForDate($pairs, $baseSlots, (string) $date) as $slot) {
                $label = (string) ($slot['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                if ($slotFilter !== '' && $label !== $slotFilter) {
                    continue;
                }

                $columns[] = [
                    'date' => (string) $date,
                    'slot' => $slot,
                    'key' => (string) $date . '|' . SlotHelper::normalizeSlotKey($label),
                ];
            }
        }

        return $columns;
    }

    private function availabilitySlotsForDate($pairs, array $baseSlots, string $date): array
    {
        $slotLabels = $pairs->isNotEmpty()
            ? $pairs->where('date', $date)->pluck('slot')->unique()->values()->all()
            : [];

        if ($slotLabels === []) {
            return $baseSlots;
        }

        $slots = array_values(array_filter($baseSlots, fn ($slot) => in_array((string) ($slot['label'] ?? ''), $slotLabels, true)));
        $existingLabels = collect($slots)->pluck('label')->map(fn ($label) => (string) $label)->flip()->all();

        foreach ($slotLabels as $label) {
            if (!isset($existingLabels[(string) $label])) {
                $slots[] = ['id' => (string) $label, 'label' => (string) $label, 'start' => '', 'end' => '', 'type' => ''];
            }
        }

        return $slots;
    }

    private function availabilitySlotOptions($pairs, array $baseSlots): array
    {
        $slots = [];
        foreach ($baseSlots as $slot) {
            $label = (string) ($slot['label'] ?? '');
            if ($label !== '') {
                $slots[$label] = $slot;
            }
        }

        foreach ($pairs->pluck('slot')->unique()->values()->all() as $label) {
            $label = (string) $label;
            if ($label !== '' && !isset($slots[$label])) {
                $slots[$label] = ['id' => $label, 'label' => $label, 'start' => '', 'end' => '', 'type' => ''];
            }
        }

        return array_values($slots);
    }

    private function visibleAvailabilityColumnMap(array $visibleColumns, array $visibleDates, array $visibleSlots): array
    {
        $map = [];

        foreach ($visibleColumns as $date => $slotLabels) {
            if (!is_array($slotLabels)) {
                continue;
            }

            $date = (string) $date;
            foreach ($slotLabels as $slotLabel) {
                $slotLabel = trim((string) $slotLabel);
                if ($date !== '' && $slotLabel !== '') {
                    $map[$date][$slotLabel] = true;
                }
            }
        }

        if ($map === [] && $visibleDates !== [] && $visibleSlots !== []) {
            foreach ($visibleDates as $date) {
                foreach ($visibleSlots as $slotLabel) {
                    $date = (string) $date;
                    $slotLabel = trim((string) $slotLabel);
                    if ($date !== '' && $slotLabel !== '') {
                        $map[$date][$slotLabel] = true;
                    }
                }
            }
        }

        foreach ($map as $date => $slotSet) {
            $map[$date] = array_keys($slotSet);
        }

        ksort($map);

        return $map;
    }

    private function cleanFacultyAvailabilityMatrix(array $matrix): array
    {
        foreach ($matrix as $date => $facultyMap) {
            if (!is_array($facultyMap)) {
                unset($matrix[$date]);
                continue;
            }

            foreach ($facultyMap as $facultyId => $slotMap) {
                if (!is_array($slotMap)) {
                    unset($matrix[$date][$facultyId]);
                    continue;
                }

                $slotMap = array_filter($slotMap, fn ($value) => (bool) $value);
                if ($slotMap === []) {
                    unset($matrix[$date][$facultyId]);
                } else {
                    $matrix[$date][$facultyId] = $slotMap;
                }
            }

            if (($matrix[$date] ?? []) === []) {
                unset($matrix[$date]);
            }
        }

        return $matrix;
    }

    private function facultyAvailabilityRedirectParams(Request $request, int $ruleId, string $date, string $viewMode): array
    {
        $redirectParams = ['rule_id' => $ruleId, 'view_mode' => $viewMode];
        if ($date !== '') {
            $redirectParams['date'] = $date;
        }

        foreach (['q', 'department', 'designation', 'slot_filter', 'duty_status', 'availability_status', 'faculty_id'] as $filterKey) {
            if ($request->filled($filterKey)) {
                $redirectParams[$filterKey] = (string) $request->input($filterKey);
            }
        }

        return $redirectParams;
    }

    private function importCourseTitleCsv(string $path): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException('Could not open uploaded CSV file.');
        }

        $header = null;
        $lineNo = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $lineNo++;
            $row = array_map(fn ($value) => trim((string) $value), $row);
            if (count(array_filter($row, fn ($value) => $value !== '')) === 0) {
                continue;
            }

            if ($lineNo === 1) {
                $lower = array_map(fn ($value) => strtolower($value), $row);
                if (in_array('course_code', $lower, true) || in_array('code', $lower, true)) {
                    $header = $lower;
                    continue;
                }
            }

            $codeIndex = 0;
            $titleIndex = 1;
            if ($header !== null) {
                $codeIndex = array_search('course_code', $header, true);
                if ($codeIndex === false) {
                    $codeIndex = array_search('code', $header, true);
                }
                $titleIndex = array_search('course_title', $header, true);
                if ($titleIndex === false) {
                    $titleIndex = array_search('title', $header, true);
                }
                if ($codeIndex === false || $titleIndex === false) {
                    $skipped++;
                    continue;
                }
            }

            $code = strtoupper(trim((string) ($row[$codeIndex] ?? '')));
            $title = trim((string) ($row[$titleIndex] ?? ''));

            if ($code === '' || $title === '') {
                $skipped++;
                continue;
            }

            $course = Course::query()->where('code', $code)->first();
            if (!$course) {
                Course::query()->create(['code' => $code, 'title' => $title]);
                $created++;
                continue;
            }

            if ((string) $course->title !== $title) {
                $course->title = $title;
                $course->save();
                $updated++;
            }
        }

        fclose($handle);

        return [$created, $updated, $skipped];
    }

    private function buildCourseMergeBoard(DateSheetRule $rule): array
    {
        $offerings = $this->buildCourseMergeOfferings($rule);
        $groups = [];
        $assigned = [];
        $manualGroups = $rule->manualCourseMergeGroups();

        foreach ($manualGroups as $index => $manualGroup) {
            $groups[(string) ($manualGroup['key'] ?? ('MANUAL_MERGE|' . $index))] = [
                'id' => 'group-' . ($index + 1),
                'label' => (string) ($manualGroup['label'] ?? ('Group ' . ($index + 1))),
                'members' => [],
                'suggested' => false,
            ];
        }

        foreach ($offerings as $offering) {
            $groupingKey = $rule->manualCourseMergeGroupingKeyForOffering($offering);
            if ($groupingKey === null || !isset($groups[$groupingKey])) {
                continue;
            }

            $groups[$groupingKey]['members'][] = $offering;
            $assigned[$offering['key']] = true;
        }

        $draftSuggestions = false;
        if ($groups === []) {
            $suggestedGroups = collect($offerings)
                ->groupBy(fn (array $offering) => $this->mergeSuggestionKey($offering))
                ->filter(fn ($items) => $items->count() > 1)
                ->sortKeys();

            foreach ($suggestedGroups as $items) {
                $groupId = 'suggested-' . count($groups);
                $groups[$groupId] = [
                    'id' => $groupId,
                    'label' => $this->mergeSuggestionLabel($items->values()->all()),
                    'members' => $items->values()->all(),
                    'suggested' => true,
                ];

                foreach ($items as $offering) {
                    $assigned[$offering['key']] = true;
                }
            }

            $draftSuggestions = $groups !== [];
        }

        $ungrouped = array_values(array_filter(
            $offerings,
            fn (array $offering) => !isset($assigned[$offering['key']])
        ));

        return [
            'groups' => array_values($groups),
            'ungrouped' => $ungrouped,
            'offerings_count' => count($offerings),
            'draft_suggestions' => $draftSuggestions,
        ];
    }

    private function mergeSuggestionKey(array $offering): string
    {
        $instructorName = $this->normalizeMergeInstructorName((string) ($offering['instructor_name'] ?? ''));
        if ((bool) ($offering['is_postgraduate'] ?? false)) {
            return 'PG_TEACHER|' . $instructorName;
        }

        $title = $this->normalizeMergeCourseTitle((string) ($offering['course_title'] ?? ''));
        $groupType = $title !== '' ? 'TITLE' : 'CODE';

        return implode('|', [
            $groupType,
            $title !== '' ? $title : $this->normalizeMergeCourseCode((string) ($offering['course_code'] ?? '')),
            $instructorName,
        ]);
    }

    private function mergeSuggestionLabel(array $offerings): string
    {
        $codes = [];
        $levels = [];
        $title = '';
        $instructorName = '';

        foreach ($offerings as $offering) {
            $courseCode = trim((string) ($offering['course_code'] ?? ''));
            if ($courseCode !== '') {
                $codes[$courseCode] = true;
            }

            if ($title === '') {
                $title = trim((string) ($offering['course_title'] ?? ''));
            }

            if ($instructorName === '') {
                $instructorName = trim((string) ($offering['instructor_name'] ?? ''));
            }

            $level = (bool) ($offering['is_postgraduate'] ?? false)
                ? 'PG'
                : trim((string) ($offering['level'] ?? ''));
            if ($level !== '') {
                $levels[$level] = true;
            }
        }

        $isPgGroup = count($levels) === 1 && isset($levels['PG']);
        $parts = [];
        if ($isPgGroup) {
            $parts[] = 'PG';
        }
        if ($codes !== []) {
            $parts[] = implode('/', array_keys($codes));
        }
        if (!$isPgGroup && $title !== '') {
            $parts[] = $title;
        }
        if ($instructorName !== '') {
            $parts[] = $instructorName;
        }

        return implode(' - ', $parts);
    }

    private function buildCourseIgnoreBoard(DateSheetRule $rule): array
    {
        $offerings = $this->buildCourseMergeOfferings($rule, true);
        $ignored = [];
        $available = [];

        foreach ($offerings as $offering) {
            if ($rule->shouldIgnoreCourseOffering($offering)) {
                $ignored[] = $offering;
            } else {
                $available[] = $offering;
            }
        }

        return [
            'ignored' => $ignored,
            'available' => $available,
            'offerings_count' => count($offerings),
        ];
    }

    private function buildCourseMergeOfferings(DateSheetRule $rule, bool $includeIgnored = false): array
    {
        $rows = CourseRegistration::query()
            ->where('rule_id', (int) $rule->id)
            ->join('scheduler_course', 'scheduler_course.id', '=', 'scheduler_course_registration.course_id')
            ->select(
                'scheduler_course_registration.course_id',
                'scheduler_course.code as course_code',
                'scheduler_course.title as course_title',
                'scheduler_course_registration.class_label',
                'scheduler_course_registration.program_code',
                'scheduler_course_registration.semester_no',
                'scheduler_course_registration.instructor_name',
                DB::raw('COUNT(DISTINCT scheduler_course_registration.student_id) as strength')
            )
            ->groupBy(
                'scheduler_course_registration.course_id',
                'scheduler_course.code',
                'scheduler_course.title',
                'scheduler_course_registration.class_label',
                'scheduler_course_registration.program_code',
                'scheduler_course_registration.semester_no',
                'scheduler_course_registration.instructor_name'
            )
            ->orderBy('scheduler_course.code')
            ->orderBy('scheduler_course_registration.program_code')
            ->orderBy('scheduler_course_registration.semester_no')
            ->orderBy('scheduler_course_registration.instructor_name')
            ->get();

        return $rows->map(function ($row) use ($rule, $includeIgnored) {
            $programCode = trim((string) $row->program_code);
            $semesterNo = $row->semester_no !== null ? (int) $row->semester_no : null;
            $classLabel = trim((string) $row->class_label);
            if ($classLabel === '') {
                $classLabel = SlotHelper::classLabel($programCode, $semesterNo);
            }

            $offering = [
                'course_id' => (int) $row->course_id,
                'course_code' => $this->normalizeMergeCourseCode((string) $row->course_code),
                'course_title' => (string) $row->course_title,
                'class_label' => $classLabel,
                'program_code' => $programCode,
                'semester_no' => $semesterNo,
                'instructor_name' => trim((string) $row->instructor_name),
                'strength' => (int) $row->strength,
            ];
            $offering['level'] = $this->courseOfferingLevel($rule, $offering);
            $offering['is_postgraduate'] = $rule->isPostgraduateOffering($offering);

            $member = $this->mergeMemberForOffering($offering);
            $offering['key'] = $this->mergeOfferingKey($offering);
            $offering['member'] = $member;
            $offering['display'] = trim(implode(' ', array_filter([
                (string) $offering['course_code'],
                (string) $offering['course_title'],
                (string) $offering['class_label'],
                (string) $offering['program_code'],
                (string) $offering['instructor_name'],
            ])));

            if (!$includeIgnored && $rule->shouldIgnoreCourseOffering($offering)) {
                return null;
            }

            return $offering;
        })->filter()->values()->all();
    }

    private function courseOfferingLevel(DateSheetRule $rule, array $offering): string
    {
        $programCode = strtoupper(trim((string) ($offering['program_code'] ?? '')));
        $classLabel = strtoupper(trim((string) ($offering['class_label'] ?? '')));
        $combined = trim($programCode . ' ' . $classLabel);

        if (preg_match('/\bBS\b|^B[A-Z]*/', $combined)) {
            return 'BS';
        }

        if (preg_match('/\bMS\b|^M[A-Z]*/', $combined)) {
            return 'MS';
        }

        if (preg_match('/\b(?:PG|PHD|MPHIL)\b|^P[A-Z]*|^R[A-Z]*/', $combined)) {
            return 'PG';
        }

        if ($rule->isUndergraduateOffering($offering)) {
            return 'BS';
        }

        if ($rule->isPostgraduateOffering($offering)) {
            return 'PG';
        }

        return 'Other';
    }

    private function mergeOfferingKey(array $offering): string
    {
        return md5(json_encode([
            $offering['course_id'] ?? '',
            $offering['course_code'] ?? '',
            $offering['program_code'] ?? '',
            $offering['semester_no'] ?? '',
            $offering['class_label'] ?? '',
            $offering['instructor_name'] ?? '',
        ]));
    }

    private function mergeMemberForOffering(array $offering): array
    {
        return [
            'course' => $this->normalizeMergeCourseCode((string) ($offering['course_code'] ?? '')),
            'program' => $this->normalizeMergeProgramCode((string) ($offering['program_code'] ?? '*')) ?: '*',
            'semester' => $this->normalizeMergeSemester($offering['semester_no'] ?? null),
            'class_label' => trim((string) ($offering['class_label'] ?? '')),
            'instructor_name' => trim((string) ($offering['instructor_name'] ?? '')),
        ];
    }

    private function formatMergeMember(
        string $course,
        string $program,
        ?int $semester,
        string $classLabel = '',
        string $instructor = ''
    ): string
    {
        $classLabel = trim($classLabel);
        $instructor = trim($instructor);

        if ($classLabel !== '' || $instructor !== '') {
            return $this->csvMergeMember([
                $course,
                $program !== '' ? $program : '*',
                $semester !== null ? (string) $semester : '*',
                $classLabel,
                $instructor,
            ]);
        }

        $parts = [$course];
        if ($program !== '' && $program !== '*') {
            $parts[] = $program;
        }
        if ($semester !== null) {
            $parts[] = (string) $semester;
        }

        return implode(',', $parts);
    }

    private function csvMergeMember(array $parts): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $parts);
        rewind($handle);
        $csv = rtrim((string) stream_get_contents($handle), "\r\n");
        fclose($handle);

        return $csv;
    }

    private function normalizeMergeCourseCode(string $courseCode): string
    {
        return strtoupper(trim((string) preg_replace('/[^A-Z0-9]/i', '', $courseCode)));
    }

    private function normalizeMergeProgramCode(string $programCode): string
    {
        $programCode = strtoupper(trim($programCode));
        if ($programCode === '' || $programCode === '*' || $programCode === 'ALL') {
            return '*';
        }

        if (preg_match('/^[A-Z]+/', $programCode, $matches)) {
            return $matches[0];
        }

        return strtoupper(trim((string) preg_replace('/[^A-Z0-9]/i', '', $programCode)));
    }

    private function normalizeMergeInstructorName(string $instructorName): string
    {
        return strtoupper(trim((string) preg_replace('/\s+/', ' ', $instructorName)));
    }

    private function normalizeMergeCourseTitle(string $courseTitle): string
    {
        return strtoupper(trim((string) preg_replace('/\s+/', ' ', $courseTitle)));
    }

    private function normalizeMergeSemester(mixed $semester): ?int
    {
        if ($semester === null || $semester === '' || $semester === '*') {
            return null;
        }

        $value = (int) $semester;

        return $value > 0 ? $value : null;
    }

    private function buildManualSlotsFromRequest(Request $request, int $slotCount): array
    {
        $starts = $request->input('slot_start', []);
        $ends = $request->input('slot_end', []);
        $labels = $request->input('slot_label', []);
        $types = $request->input('slot_type', []);

        $slots = [];
        $limit = max(0, $slotCount);
        if ($limit === 0) {
            $limit = max(count($starts), count($ends), count($labels));
        }

        for ($index = 0; $index < $limit; $index++) {
            $start = trim((string) ($starts[$index] ?? ''));
            $end = trim((string) ($ends[$index] ?? ''));

            if ($start === '' || $end === '') {
                continue;
            }

            $label = trim((string) ($labels[$index] ?? ''));
            if ($label === '') {
                $label = str_replace(':', '', $start) . '-' . str_replace(':', '', $end);
            }

            $type = strtoupper(trim((string) ($types[$index] ?? '')));
            if (!in_array($type, ['MORNING', 'AFTERNOON', 'EVENING'], true)) {
                $type = $index === ($limit - 1) && $limit > 1 ? 'EVENING' : ($index === 0 ? 'MORNING' : 'AFTERNOON');
            }

            $slots[] = [
                'id' => SlotHelper::normalizeSlotKey($label) ?: ('slot' . ($index + 1)),
                'label' => $label,
                'start' => $start,
                'end' => $end,
                'type' => $type,
            ];
        }

        return $slots;
    }

    private function ensureFacultyMasters(string $department, string $designation): void
    {
        $department = trim($department);
        $designation = trim($designation);

        if ($department !== '') {
            Department::query()->updateOrCreate(['name' => $department]);
        }

        if ($designation !== '') {
            Designation::query()->updateOrCreate(['name' => $designation]);
        }
    }

    private function parseBulkFacultyText(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = str_contains($line, "\t")
                ? array_map('trim', explode("\t", $line))
                : array_map('trim', str_getcsv($line));

            $name = trim((string) ($parts[0] ?? ''));
            if ($name === '') {
                continue;
            }

            $max = (int) ($parts[3] ?? 5);
            $rows[] = [
                'name' => $name,
                'department' => trim((string) ($parts[1] ?? '')),
                'designation' => trim((string) ($parts[2] ?? '')),
                'max_duties' => max(0, $max),
            ];
        }

        return $rows;
    }

    private function selectedRule(Request $request): array
    {
        $rules = DateSheetRule::query()->orderByDesc('id')->get();
        $selectedRule = null;

        if ($request->filled('rule_id')) {
            $selectedRule = DateSheetRule::query()->find($request->integer('rule_id'));
        }

        if (!$selectedRule) {
            $selectedRule = $rules->first();
        }

        return [$rules, $selectedRule];
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

    private function requestedOrFirstPair(Request $request, $pairs): array
    {
        $date = $request->input('date');
        $slot = $request->input('slot');

        if ($date && $slot) {
            return [(string) $date, (string) $slot];
        }

        $first = $pairs->first();

        return [
            $first['date'] ?? null,
            $first['slot'] ?? null,
        ];
    }

    private function selectedRoomGridPairs(Request $request, $pairs): array
    {
        $availablePairs = $pairs->values();
        $availableByKey = $availablePairs->keyBy(fn (array $pair) => $this->slotPairKey($pair));
        $requestedKeys = collect((array) $request->input('pairs', []))
            ->map(fn ($key) => (string) $key)
            ->filter(fn (string $key) => $availableByKey->has($key))
            ->unique()
            ->values();

        if ($requestedKeys->isEmpty()) {
            $first = $availablePairs->first();
            return $first ? [$first] : [];
        }

        return $requestedKeys
            ->map(fn (string $key) => $availableByKey->get($key))
            ->filter()
            ->values()
            ->all();
    }

    private function slotPairKey(array $pair): string
    {
        return (string) ($pair['date'] ?? '') . '|' . (string) ($pair['slot'] ?? '');
    }

    private function candidateDays(?DateSheetRule $rule): array
    {
        if (!$rule?->start_date || !$rule?->end_date) {
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
}
