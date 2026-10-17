<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Scheduler Setup</title>
</head>
<body>
@include('scheduler.partials.nav')

<div class="wrap">
    <h1>Exam Scheduler Setup</h1>
    @if(session('status')) <div class="status">{{ session('status') }}</div> @endif

    <div class="panel">
        <h2>Exam session</h2>
        <form method="post" action="{{ route('scheduler.rules.store') }}">
            @csrf
            <input type="hidden" name="rule_id" value="{{ $selectedRule?->id }}">
            @php
                $requestedSlotCount = (int) old('slots_per_day', $selectedRule?->slots_per_day ?? count($slots ?? []));
                $slotRowsCount = max(6, $requestedSlotCount);
                $visibleLastSlotIndex = max(0, ($requestedSlotCount > 0 ? $requestedSlotCount : $slotRowsCount) - 1);
            @endphp
            <div class="grid grid-3">
                <div><label>Session code</label><input name="session_code" value="{{ old('session_code', $selectedRule?->session_code ?? 'FA25') }}" required></div>
                <div><label>Session label</label><input name="session_label" value="{{ old('session_label', $selectedRule?->session_label ?? '') }}"></div>
                <div><label>Exam title</label><input name="exam_title" value="{{ old('exam_title', $selectedRule?->exam_title ?? '') }}"></div>
                <div><label>Exam type</label>
                    <select name="exam_type">
                        <option value="MID" @selected(($selectedRule?->exam_type ?? '') === 'MID')>Midterm</option>
                        <option value="FIN" @selected(($selectedRule?->exam_type ?? 'FIN') === 'FIN')>Terminal</option>
                    </select>
                </div>
                <div><label>Midterm minutes</label><input type="number" name="midterm_default_minutes" value="{{ old('midterm_default_minutes', $selectedRule?->midterm_default_minutes ?? 90) }}"></div>
                <div><label>Terminal minutes</label><input type="number" name="terminal_default_minutes" value="{{ old('terminal_default_minutes', $selectedRule?->terminal_default_minutes ?? 180) }}"></div>
                <div><label>Start date</label><input type="date" name="start_date" value="{{ old('start_date', $selectedRule?->start_date?->format('Y-m-d')) }}" required></div>
                <div><label>End date</label><input type="date" name="end_date" value="{{ old('end_date', $selectedRule?->end_date?->format('Y-m-d')) }}" required></div>
                <div><label>Slots per day</label><input type="number" name="slots_per_day" value="{{ old('slots_per_day', $selectedRule?->slots_per_day ?? 0) }}"></div>
                <div><label>Opening time</label><input type="time" name="day_start_time" value="{{ old('day_start_time', $selectedRule?->day_start_time ? substr($selectedRule->day_start_time,0,5) : '08:30') }}" required></div>
                <div><label>Closing time</label><input type="time" name="day_end_time" value="{{ old('day_end_time', $selectedRule?->day_end_time ? substr($selectedRule->day_end_time,0,5) : '16:30') }}" required></div>
                <div><label>Gap between slots (minutes)</label><input type="number" name="slot_break_minutes" value="{{ old('slot_break_minutes', $selectedRule?->slot_break_minutes ?? 0) }}"></div>
            </div>

            <div class="panel" style="box-shadow:none;background:#f9fafb">
                <h3 style="margin-top:0">Individual slot timings</h3>
                <p class="muted">Set Slots per day above, then edit the start/end time of each slot. Mark the MS/PHD slot as Evening. If labels are blank, they are generated from start/end time.</p>
                <div style="overflow:auto">
                    <table class="slot-editor">
                        <tr><th>#</th><th>Label</th><th>Start</th><th>End</th><th>Type</th></tr>
                        @for($i = 0; $i < $slotRowsCount; $i++)
                            @php $slot = $slots[$i] ?? []; @endphp
                            <tr data-slot-row="{{ $i }}">
                                <td>{{ $i + 1 }}</td>
                                <td><input name="slot_label[{{ $i }}]" value="{{ old('slot_label.'.$i, $slot['label'] ?? '') }}" placeholder="0830-1130"></td>
                                <td><input type="time" name="slot_start[{{ $i }}]" value="{{ old('slot_start.'.$i, $slot['start'] ?? '') }}"></td>
                                <td><input type="time" name="slot_end[{{ $i }}]" value="{{ old('slot_end.'.$i, $slot['end'] ?? '') }}"></td>
                                <td>
                                    <select name="slot_type[{{ $i }}]">
                                        @php $slotType = old('slot_type.'.$i, $slot['type'] ?? ($i === 0 ? 'MORNING' : ($i === $visibleLastSlotIndex ? 'EVENING' : 'AFTERNOON'))); @endphp
                                        <option value="MORNING" @selected($slotType === 'MORNING')>Morning / BS</option>
                                        <option value="AFTERNOON" @selected($slotType === 'AFTERNOON')>Afternoon / BS</option>
                                        <option value="EVENING" @selected($slotType === 'EVENING')>Evening / MS-PHD</option>
                                    </select>
                                </td>
                            </tr>
                        @endfor
                    </table>
                </div>
            </div>

            <div class="grid grid-2" style="margin-top:12px">
                <div><label>Overall off days, one YYYY-MM-DD per line</label><textarea name="off_days_overall">{{ old('off_days_overall', $selectedRule?->off_days_overall ?? '') }}</textarea></div>
                <div><label>Class off days, CLASS,YYYY-MM-DD per line</label><textarea name="off_days_per_class">{{ old('off_days_per_class', $selectedRule?->off_days_per_class ?? '') }}</textarea></div>
                <div><label>Course-slot preferences</label><textarea name="course_slot_preferences" placeholder="CSC101,BSE,MORNING&#10;CSC101,BCS,AFTERNOON&#10;HUM701,ALL,EVENING">{{ old('course_slot_preferences', $selectedRule?->course_slot_preferences ?? '') }}</textarea><p class="muted">Format: COURSE,PROGRAM,SLOT. Use ALL or * for every program. Old format COURSE,SLOT still works as a global preference.</p></div>
                <div><label>Course-date preferences</label><textarea name="course_date_preferences" placeholder="CSC101,BSE,2026-06-03&#10;CSC101,BCS,2026-06-04&#10;MTH104,ALL,3-06-2026">{{ old('course_date_preferences', $selectedRule?->course_date_preferences ?? '') }}</textarea><p class="muted">Format: COURSE,PROGRAM,DATE. Use ALL or * for every program. Old format COURSE,DATE still works as a global preference. Multiple dates can be separated by ;.</p></div>
                <div><label>Same course together / program scoped grouping</label><textarea name="same_course_together_codes" placeholder="CSC101&#10;CSC101,BSE,BCS&#10;BSE,BCS">{{ old('same_course_together_codes', $selectedRule?->same_course_together_codes ?? '') }}</textarea><p class="muted">Use COURSE for all programs, COURSE,PROGRAM1,PROGRAM2 for only those programs, or PROGRAM1,PROGRAM2 to group every same course code only across those programs.</p></div>
                <div><label>Manual course merge groups</label><textarea name="manual_course_merge_groups" placeholder="AI core: CSC101,BCS,4; AIC101,BAI,4; CSE101,BSE,4&#10;Management shared: MGT101,BBA,7; HUM111,BCS,10">{{ old('manual_course_merge_groups', $selectedRule?->manual_course_merge_groups ?? '') }}</textarea><p class="muted">One group per line. Use LABEL: COURSE,PROGRAM,SEMESTER; COURSE,PROGRAM,SEMESTER. Semester is optional, but use it when the same course code/program is taught by different teachers or appears in multiple semesters.</p></div>
                <div><label>Ignored courses</label><textarea name="ignored_course_offerings" placeholder="CSC432,BSE,8&#10;HUM111,BCS,10; MGT101,BBA,1">{{ old('ignored_course_offerings', $selectedRule?->ignored_course_offerings ?? '') }}</textarea><p class="muted">Use COURSE,PROGRAM,SEMESTER. One per line, or separate multiple entries with ;. Program and semester are optional; omitted values match every program or semester for that course.</p></div>
                <div><label>MS/PHD course codes</label><textarea name="pg_course_codes" placeholder="HUM701&#10;MTH706&#10;MTH731&#10;MTH765&#10;MTH769">{{ old('pg_course_codes', $selectedRule?->pg_course_codes ?? '') }}</textarea><p class="muted">These are forced to the configured PG slot. Course codes are optional if program prefixes or 700+ level identify them.</p></div>
                <div>
                    <label>PG slot preference</label>
                    <input name="pg_slot_preference" value="{{ old('pg_slot_preference', $selectedRule?->pg_slot_preference ?? 'EVENING') }}" placeholder="EVENING or 1730-2030">
                    <label style="margin-top:8px">PG program prefixes</label>
                    <input name="pg_program_prefixes" value="{{ old('pg_program_prefixes', $selectedRule?->pg_program_prefixes ?? 'P,R') }}" placeholder="P,R">
                    <label style="margin-top:8px">Course code number from which to treat as PG</label>
                    <input type="number" name="pg_course_level_min" value="{{ old('pg_course_level_min', $selectedRule?->pg_course_level_min ?? 700) }}">
                </div>
            </div>

            <div class="panel" style="box-shadow:none">
                <label><input type="checkbox" name="schedule_same_course_code_together" value="1" style="width:auto" @checked($selectedRule?->schedule_same_course_code_together ?? true)> Schedule same course code together</label>
                <label><input type="checkbox" name="allow_same_day_student_conflicts" value="1" style="width:auto" @checked($selectedRule?->allow_same_day_student_conflicts ?? false)> Allow same student more than one paper per day</label>
                <div style="max-width:420px;margin:6px 0 4px 24px">
                    <label>Allow same-day student clashes up to</label>
                    <input type="number" min="0" name="same_day_student_conflict_limit" value="{{ old('same_day_student_conflict_limit', $selectedRule?->same_day_student_conflict_limit ?? 0) }}">
                    <p class="muted">Use 10 to allow scheduling when at most 10 students in the paper have another paper on that date. Same-slot clashes are always blocked. If the checkbox is enabled and this value is 0, same-day clashes are unlimited.</p>
                </div>
                <label><input type="checkbox" name="balance_daily_strength" value="1" style="width:auto" @checked($selectedRule?->balance_daily_strength ?? true)> Balance daily strength</label>
                <label><input type="checkbox" name="exclude_weekends" value="1" style="width:auto" @checked($selectedRule?->exclude_weekends ?? false)> Exclude weekends</label>
                <label><input type="checkbox" name="split_rooms" value="1" style="width:auto" @checked($selectedRule?->split_rooms ?? true)> Allow room split A/B</label>
                <label><input type="checkbox" name="schedule_pg_courses_in_preferred_slot" value="1" style="width:auto" @checked($selectedRule?->schedule_pg_courses_in_preferred_slot ?? true)> Schedule MS/PHD papers in PG slot</label>
                <label><input type="checkbox" name="exclude_pg_preferred_slot_for_bs" value="1" style="width:auto" @checked($selectedRule?->exclude_pg_preferred_slot_for_bs ?? true)> Keep BS papers out of PG/evening slot</label>
                <label><input type="checkbox" name="prevent_cross_building_for_same_class" value="1" style="width:auto" @checked($selectedRule?->prevent_cross_building_for_same_class ?? true)> Do not use cross-building rooms for same class</label>
                <label><input type="checkbox" name="single_duty_per_faculty_per_day" value="1" style="width:auto" @checked($selectedRule?->single_duty_per_faculty_per_day ?? false)> Assign only one invigilation duty per faculty per day</label>
                <p class="muted">When enabled, a faculty member will not receive any second duty on the same date. If no eligible faculty remains, the duty is left as TBD.</p>
                <label><input type="checkbox" name="merge_same_course_program_instructor" value="1" style="width:auto" @checked($selectedRule?->merge_same_course_program_instructor ?? false)> Merge same course + program + instructor classes before scheduling</label>
                <div style="max-width:360px;margin:6px 0 4px 24px">
                    <label>Merge only sections below strength</label>
                    <input type="number" min="0" name="merge_same_course_strength_threshold" value="{{ old('merge_same_course_strength_threshold', $selectedRule?->merge_same_course_strength_threshold ?? 0) }}">
                </div>
                <p class="muted">Use 0 for old behavior: merge the full matching group. If a number is set, only smaller sections below that strength are merged into the largest matching class, and large sections stay separate.</p>
                <label><input type="checkbox" name="enforce_course_date_preferences" value="1" style="width:auto" @checked($selectedRule?->enforce_course_date_preferences ?? true)> Enforce course-date preferences during generation</label>
                <p class="muted">When enabled, listed courses are scheduled only on their preferred date. When disabled, the scheduler strongly prefers that date but may use another date if needed.</p>
                <label><input type="checkbox" name="is_active" value="1" style="width:auto" @checked($selectedRule?->is_active ?? true)> Active</label>
                <input type="hidden" name="weekend_days[]" value="0">
                <input type="hidden" name="weekend_days[]" value="6">
            </div>

            <button class="btn" type="submit">Save session</button>
        </form>
    </div>

    @if($selectedRule)
    <div class="grid grid-2">
        <div class="panel">
            <h2>Import and generate</h2>
            <form method="post" action="{{ route('scheduler.import') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <label>Student course workbook</label>
                <input type="file" name="workbook" accept=".xlsx,.xls" required>
                <label style="margin-top:8px">Import mode</label>
                <select name="import_mode">
                    <option value="append">Append/update existing data</option>
                    <option value="replace">Replace selected session student data first</option>
                </select>
                <button class="btn" type="submit" style="margin-top:8px">Import workbook</button>
                <a class="btn secondary" href="{{ route('scheduler.students', ['rule_id' => $selectedRule->id]) }}" style="margin-top:8px">Student management</a>
                <a class="btn secondary" href="{{ route('scheduler.courses', ['rule_id' => $selectedRule->id]) }}" style="margin-top:8px">Course management</a>
                <a class="btn secondary" href="{{ route('scheduler.merge-courses', ['rule_id' => $selectedRule->id]) }}" style="margin-top:8px">Course merge builder</a>
                <a class="btn secondary" href="{{ route('scheduler.ignore-courses', ['rule_id' => $selectedRule->id]) }}" style="margin-top:8px">Ignored courses</a>
            </form>
            <hr>
            <form method="post" action="{{ route('scheduler.generate') }}" onsubmit="return confirm('This will delete the existing datesheet plus room allocation, seat plan, and duty roster for this session, then regenerate the datesheet. Continue?')">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <input type="hidden" name="clear_existing" value="1">
                <p class="muted">Deletes the old datesheet and also clears room allocation, seat plan, and duty roster before regenerating.</p>
                <button class="btn green" type="submit">Delete datesheet and regenerate</button>
            </form>
            <hr>
            <form method="post" action="{{ route('scheduler.export') }}">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <select name="format"><option value="midterm">Midterm export</option><option value="terminal">Terminal export</option></select>
                <button class="btn secondary" type="submit" style="margin-top:8px">Export</button>
                <a class="btn green" style="margin-top:8px" href="{{ route('scheduler.export-operations', ['rule_id' => $selectedRule->id, 'type' => 'datesheet_final']) }}">Export date sheet with rooms</a>
            </form>
        </div>

        <div class="panel">
            <h2>Run slot operations</h2>
            <p class="muted">Use the all-slot buttons after the datesheet is generated. Invigilators should be generated after room allocation.</p>
            <div class="panel" style="box-shadow:none;background:#f9fafb">
                <form method="post" action="{{ route('scheduler.allocate-all') }}" style="display:inline-block;margin:0 8px 8px 0" onsubmit="return confirm('Rebuild room allocation for every generated datesheet slot? Existing seat plans and invigilators for those slots will be cleared by room allocation.')">
                    @csrf
                    <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                    <button class="btn" type="submit">Allocate rooms for all slots</button>
                </form>
                <form method="post" action="{{ route('scheduler.seating-all') }}" style="display:inline-block;margin:0 8px 8px 0" onsubmit="return confirm('Generate seat plans for every generated datesheet slot? Run all-slot room allocation first.')">
                    @csrf
                    <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                    <button class="btn green" type="submit">Generate seat plan for all slots</button>
                </form>
            </div>
            <form method="post" action="{{ route('scheduler.allocate') }}">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <div class="grid grid-2">
                    <div><label>Date</label><input type="date" name="date" value="{{ $candidateDays[0] ?? '' }}" required></div>
                    <div><label>Slot label</label><input name="slot" value="{{ $slots[0]['label'] ?? '' }}" required></div>
                </div>
                <button class="btn" type="submit" style="margin-top:8px">Allocate rooms</button>
            </form>
            <form method="post" action="{{ route('scheduler.seating') }}" style="margin-top:10px">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <div class="grid grid-2">
                    <div><label>Date</label><input type="date" name="date" value="{{ $candidateDays[0] ?? '' }}" required></div>
                    <div><label>Slot label</label><input name="slot" value="{{ $slots[0]['label'] ?? '' }}" required></div>
                </div>
                <button class="btn" type="submit" style="margin-top:8px">Generate seating</button>
            </form>
            <form method="post" action="{{ route('scheduler.invigilators') }}" style="margin-top:10px">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <input type="hidden" name="all" value="1">
                <button class="btn green" type="submit">Generate full duty roster</button>
            </form>
            <p style="margin-top:10px"><a class="btn secondary" href="{{ route('scheduler.faculty-availability', ['rule_id' => $selectedRule->id]) }}">Set faculty availability</a></p>
        </div>
    </div>
    @endif

    <div class="grid grid-2">
        <div class="panel">
            <h2>Add room</h2>
            <form method="post" action="{{ route('scheduler.rooms.store') }}">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule?->id }}">
                <div class="grid grid-2">
                    <div><label>Room ID</label><input name="room_id" required></div>
                    <div><label>Room name</label><input name="room_name" required></div>
                    <div><label>Capacity</label><input type="number" name="capacity" required></div>
                    <div><label>Rows</label><input type="number" name="rows" placeholder="5"></div>
                    <div><label>Columns</label><input type="number" name="cols" placeholder="11"></div>
                    <div><label>Invigilators required</label><input type="number" name="invigilators_required" value="2"></div>
                    <div><label>Building</label><input name="building"></div>
                    <div><label>Floor</label><input name="floor"></div>
                </div>
                <label><input type="checkbox" name="allow_split" value="1" style="width:auto" checked> Allow split</label>
                <label><input type="checkbox" name="contingency_only" value="1" style="width:auto"> Contingency only</label>
                <label><input type="checkbox" name="is_waiting_room" value="1" style="width:auto"> Waiting room only</label>
                <button class="btn" type="submit">Add room</button>
            </form>
        </div>

        <div class="panel">
            <h2>Add faculty</h2>
            <form method="post" action="{{ route('scheduler.faculty.store') }}">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule?->id }}">
                <label>Name</label><input name="name" required>
                <label>Department</label>
                <select name="department">
                    <option value="">-- Select department --</option>
                    @foreach(($departments ?? []) as $department)
                        <option value="{{ $department->name }}">{{ $department->name }}</option>
                    @endforeach
                </select>
                <label>Designation</label>
                <select name="designation">
                    <option value="">-- Select designation --</option>
                    @foreach(($designations ?? []) as $designation)
                        <option value="{{ $designation->name }}">{{ $designation->name }}</option>
                    @endforeach
                </select>
                <label>Max duties</label><input type="number" min="0" name="max_duties" value="5"><p class="muted" style="margin-top:4px">Use 0 to exclude faculty from invigilation duties.</p>
                <button class="btn" type="submit" style="margin-top:8px">Add faculty</button>
                <a class="btn secondary" href="{{ route('scheduler.faculty', ['rule_id' => $selectedRule?->id]) }}" style="margin-top:8px">Manage departments/designations</a>
            </form>
        </div>
    </div>

    <div class="panel">
        <h2>Rooms</h2>
        <table>
            <tr><th>Room</th><th>Name</th><th>Building</th><th>Rows × Cols</th><th>Capacity</th><th>Waiting</th></tr>
            @foreach($rooms as $room)
                <tr>
                    <td>{{ $room->room_id }}</td><td>{{ $room->room_name }}</td><td>{{ $room->building }}</td>
                    <td>{{ $room->rows }} × {{ $room->cols }}</td><td>{{ $room->capacity }}</td><td>{{ $room->looksLikeWaitingRoom() ? 'Yes' : 'No' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
<script>
(function(){
    const countInput = document.querySelector('input[name="slots_per_day"]');
    const rows = Array.from(document.querySelectorAll('[data-slot-row]'));
    function refreshSlotRows(){
        const count = Math.max(0, parseInt(countInput?.value || '0', 10));
        rows.forEach((row, index) => {
            row.style.display = (count === 0 || index < count) ? '' : 'none';
        });
    }
    if (countInput) {
        countInput.addEventListener('input', refreshSlotRows);
        refreshSlotRows();
    }
})();
</script>
</body>
</html>
