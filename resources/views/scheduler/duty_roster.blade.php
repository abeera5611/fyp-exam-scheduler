<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Faculty Duty Roster</title>
    <style>
        .screen-actions .btn{margin-right:6px;margin-bottom:6px}
        .filter-panel{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;align-items:end}
        .filter-panel .wide{grid-column:span 2}
        .filter-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:end}
        .filter-summary{font-size:12px;color:#374151;margin-top:8px}
        .roster-summary{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:8px 0 12px}
        .summary-pill{background:#eef2ff;color:#1e3a8a;border:1px solid #c7d2fe;border-radius:10px;padding:8px 10px;font-weight:900;font-size:13px}
        .summary-pill.warn{background:#fff7ed;color:#9a3412;border-color:#fed7aa}
        .roster-shell{overflow:auto;max-height:78vh;border:1px solid #dbe1ea;border-radius:12px;background:#fff}
        .faculty-roster{border-collapse:collapse;width:max-content;min-width:100%}
        .faculty-roster th,.faculty-roster td{border:1px solid #dbe1ea;padding:6px;font-size:11px;vertical-align:top;color:#111827}
        .faculty-roster thead th{position:sticky;top:0;background:#e9eef7;text-align:center;font-weight:900;z-index:5}
        .faculty-roster thead tr:nth-child(2) th{top:29px}
        .faculty-roster .faculty-col{position:sticky;left:0;background:#f8fafc;z-index:6;width:230px;min-width:230px;font-weight:700}
        .faculty-roster thead .faculty-col{z-index:8;background:#e9eef7}
        .faculty-roster .slot-col{width:175px;min-width:175px;max-width:175px;white-space:pre-wrap;line-height:1.25}
        .faculty-roster .duty-cell{min-height:54px;white-space:pre-wrap;line-height:1.25}
        .faculty-roster .zero{color:#6b7280;font-weight:500}
        .faculty-roster .multi-duty-day td,.faculty-roster .multi-duty-day .faculty-col{background:#ffe1e1}
        .multi-duty-dates{display:block;margin-top:4px;font-size:10px;color:#991b1b;font-weight:700}
        .department-title{margin:18px 0 8px;font-size:15px;font-weight:900;color:#111827}
        .duty-note{font-size:12px;color:#374151;margin-top:8px}
        .print-head{text-align:center;margin-bottom:14px;line-height:1.25}
        .print-head h2{margin:0 0 4px;font-size:20px;text-transform:uppercase}
        .print-head .sub{font-weight:700;font-size:13px}
        @media (max-width:1100px){.filter-panel{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-panel .wide{grid-column:span 2}}
        @media print {
            .topbar,.screen-actions,.session-picker,.filter-card{display:none!important}
            body{background:#fff;color:#000}
            .wrap{max-width:none;padding:0}
            .panel{box-shadow:none;border:0;padding:0;margin:0}
            .roster-shell{overflow:visible;max-height:none;border:0;border-radius:0}
            .faculty-roster{width:100%;min-width:0;page-break-inside:auto}
            .faculty-roster th,.faculty-roster td{font-size:8px;padding:3px}
            .faculty-roster .faculty-col{position:static;width:120px;min-width:120px}
            .faculty-roster thead th{position:static}
            .department-title{page-break-before:auto}
        }
    </style>
</head>
<body>
@include('scheduler.partials.nav')

<div class="wrap">
    <div class="screen-actions panel">
        <h1>Faculty Duty Roster</h1>
        @if(session('status')) <div class="status">{{ session('status') }}</div> @endif
        @if($selectedRule)
            @php
                $dutySummary = $matrix['summary'] ?? [];
                $maximumAssignableDuties = (int) ($dutySummary['maximum_assignable_duties'] ?? 0);
                $totalDutiesRequired = (int) ($dutySummary['total_duties_required'] ?? 0);
                $unfilledDuties = (int) ($dutySummary['unfilled_duties'] ?? 0);
            @endphp
            <div class="roster-summary">
                <div class="summary-pill {{ $maximumAssignableDuties < $totalDutiesRequired ? 'warn' : '' }}">
                    Maximum duties assignable / duties required: {{ $maximumAssignableDuties }} / {{ $totalDutiesRequired }}
                </div>
                @if($unfilledDuties > 0)
                    <div class="summary-pill warn">Unfilled duties: {{ $unfilledDuties }}</div>
                @endif
            </div>
            @php
                $exportQuery = array_merge(request()->only(['q','department','designation','date','slot','duty_status']), ['rule_id' => $selectedRule->id, 'type' => 'duty_roster']);
            @endphp
            <form method="post" action="{{ route('scheduler.invigilators') }}" style="display:inline-block">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <input type="hidden" name="all" value="1">
                <button class="btn green" type="submit">Regenerate duty roster</button>
            </form>
            <a class="btn" href="{{ route('scheduler.faculty-availability', ['rule_id' => $selectedRule->id]) }}">Set faculty availability</a>
            <a class="btn secondary" href="{{ route('scheduler.export-operations', $exportQuery) }}">Export filtered roster Excel</a>
            <a class="btn secondary" href="{{ route('scheduler.room-grid', ['rule_id' => $selectedRule->id]) }}">Room-course grid</a>
            <button class="btn secondary" onclick="window.print()">Print</button>
            <div class="duty-note">This roster is faculty-focused like the Django roster. A row is highlighted only when that faculty has more than one duty on the same date. Physical rooms such as CR2 A/B are grouped as CR2 for duty counting.</div>
        @endif
    </div>

    @if(!$selectedRule)
        <div class="warn">Create an exam session first.</div>
    @else
        @php
            $filters = $filters ?? ['q'=>'','department'=>'','designation'=>'','date'=>'','slot'=>'','duty_status'=>''];
            $filterOptions = $filterOptions ?? ['departments'=>[],'designations'=>[],'dates'=>[],'slots'=>[]];
            $columns = $matrix['columns'] ?? [];
            $departments = $matrix['departments'] ?? [];
            $visibleFacultyCount = collect($departments)->sum(fn($department) => count($department['faculties'] ?? []));
        @endphp

        <div class="panel filter-card">
            <form method="get" action="{{ route('scheduler.duty-roster') }}" class="filter-panel">
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <div class="wide">
                    <label>Search faculty</label>
                    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, department, designation">
                </div>
                <div>
                    <label>Department</label>
                    <select name="department">
                        <option value="">All departments</option>
                        @foreach(($filterOptions['departments'] ?? []) as $department)
                            <option value="{{ $department }}" @selected(($filters['department'] ?? '') === $department)>{{ $department }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Designation</label>
                    <select name="designation">
                        <option value="">All designations</option>
                        @foreach(($filterOptions['designations'] ?? []) as $designation)
                            <option value="{{ $designation }}" @selected(($filters['designation'] ?? '') === $designation)>{{ $designation }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Date</label>
                    <select name="date">
                        <option value="">All dates</option>
                        @foreach(($filterOptions['dates'] ?? []) as $date)
                            <option value="{{ $date }}" @selected(($filters['date'] ?? '') === $date)>{{ $date }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Slot</label>
                    <select name="slot">
                        <option value="">All slots</option>
                        @foreach(($filterOptions['slots'] ?? []) as $slot)
                            <option value="{{ $slot }}" @selected(($filters['slot'] ?? '') === $slot)>{{ $slot }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Duty status</label>
                    <select name="duty_status">
                        <option value="">All faculty</option>
                        <option value="assigned" @selected(($filters['duty_status'] ?? '') === 'assigned')>Assigned in visible range</option>
                        <option value="not_assigned" @selected(($filters['duty_status'] ?? '') === 'not_assigned')>Not assigned in visible range</option>
                        <option value="multi_same_day" @selected(($filters['duty_status'] ?? '') === 'multi_same_day')>Multiple duties same day</option>
                        <option value="no_multi_same_day" @selected(($filters['duty_status'] ?? '') === 'no_multi_same_day')>No multiple same-day duty</option>
                        <option value="active" @selected(($filters['duty_status'] ?? '') === 'active')>Active only</option>
                        <option value="excluded" @selected(($filters['duty_status'] ?? '') === 'excluded')>Excluded / max duties 0</option>
                    </select>
                </div>
                <div class="filter-actions">
                    <button class="btn" type="submit">Apply filters</button>
                    <a class="btn secondary" href="{{ route('scheduler.duty-roster', ['rule_id' => $selectedRule->id]) }}">Reset</a>
                </div>
            </form>
            <div class="filter-summary">Showing {{ $visibleFacultyCount }} faculty member(s) and {{ count($columns) }} slot column(s).</div>
        </div>

        <div class="panel">
            <div class="print-head">
                <h2>{{ $selectedRule->exam_title ?: 'Examination Duty Roster' }}</h2>
                <div class="sub">{{ $selectedRule->session_code }} {{ $selectedRule->session_label }}</div>
                <div>COMSATS University Islamabad, Attock Campus</div>
            </div>

            @php
                $dateGroups = collect($columns)->groupBy('date');
            @endphp

            @if(count($columns) === 0)
                <div class="warn">No duty roster column matches the selected filters, or no duty roster has been generated yet. Run room allocation first, then regenerate duties.</div>
            @elseif(count($departments) === 0)
                <div class="warn">No faculty member matches the selected filters.</div>
            @else
                @foreach($departments as $department)
                    <div class="department-title">{{ $department['department'] }}</div>
                    <div class="roster-shell">
                        <table class="faculty-roster">
                            <thead>
                                <tr>
                                    <th class="faculty-col">Faculty</th>
                                    @foreach($dateGroups as $date => $items)
                                        <th class="slot-col" colspan="{{ count($items) }}">{{ $date }}</th>
                                    @endforeach
                                </tr>
                                <tr>
                                    <th class="faculty-col"></th>
                                    @foreach($columns as $column)
                                        <th class="slot-col">{{ $column['slot'] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($department['faculties'] as $faculty)
                                    @php
                                        $hasMultiDutyDay = !empty($faculty['visible_has_multi_duty_day'] ?? $faculty['has_multi_duty_day'] ?? false);
                                        $multiDutyDates = $faculty['visible_multi_duty_dates'] ?? $faculty['multi_duty_dates'] ?? [];
                                    @endphp
                                    <tr class="{{ $hasMultiDutyDay ? 'multi-duty-day' : '' }}">
                                        <td class="faculty-col {{ ($faculty['visible_duty_count'] ?? $faculty['duty_count'] ?? 0) === 0 ? 'zero' : '' }}">
                                            {{ $faculty['name'] }} ({{ $faculty['visible_duty_count'] ?? $faculty['duty_count'] ?? 0 }})
                                            @if($hasMultiDutyDay)
                                                <span class="multi-duty-dates">Multiple on: {{ implode(', ', $multiDutyDates) }}</span>
                                            @endif
                                        </td>
                                        @foreach($columns as $column)
                                            <td class="slot-col duty-cell">{{ $faculty['cells'][$column['key']] ?? '' }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            @endif
        </div>
    @endif
</div>
</body>
</html>
