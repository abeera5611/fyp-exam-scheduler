<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Faculty Availability</title>
    <style>
        .availability-toolbar{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;align-items:end}
        .availability-toolbar .wide{grid-column:span 2}
        .availability-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:end}
        .availability-scroll{overflow:auto;max-height:72vh;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
        .availability-table{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%}
        .availability-table th,.availability-table td{white-space:nowrap;text-align:center;padding:8px;border-right:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb}
        .availability-table thead th{position:sticky;top:0;background:#f3f4f6;z-index:3}
        .availability-table .faculty-col{position:sticky;left:0;text-align:left;background:#fff;z-index:2;min-width:260px;font-weight:700}
        .availability-table thead .faculty-col{background:#f3f4f6;z-index:4}
        .availability-table tr:nth-child(even) .faculty-col{background:#f9fafb}
        .availability-table tr:nth-child(even) td{background:#f9fafb}
        .availability-table .date-head{text-align:center;background:#e5e7eb}
        .availability-check{width:18px;height:18px;cursor:pointer}
        .faculty-detail-scroll{overflow:auto;max-height:72vh;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
        .faculty-detail-table{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%}
        .faculty-detail-table th,.faculty-detail-table td{white-space:nowrap;text-align:center;padding:8px;border-right:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb}
        .faculty-detail-table thead th{position:sticky;top:0;background:#f3f4f6;z-index:3}
        .faculty-detail-table .date-col{position:sticky;left:0;text-align:left;background:#fff;z-index:2;min-width:150px;font-weight:900}
        .faculty-detail-table thead .date-col{background:#f3f4f6;z-index:4}
        .faculty-detail-title{font-weight:900;margin:0 0 8px;color:#111827}
        .slot-help{font-size:11px;color:#6b7280;margin-top:4px}
        .export-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
        .filter-summary{font-size:12px;color:#374151;margin-top:8px}
        .reset-availability-form{display:inline-block;margin-top:12px}
        @media (max-width:1100px){.availability-toolbar{grid-template-columns:repeat(2,minmax(0,1fr))}.availability-toolbar .wide{grid-column:span 2}}
    </style>
</head>
<body>
@include('scheduler.partials.nav')

<div class="wrap">
    <h1>Faculty Availability</h1>
    @if(session('status')) <div class="status">{{ session('status') }}</div> @endif
    @if(!$selectedRule)
        <div class="warn">Create an exam session first.</div>
    @else
        @php
            $filters = $filters ?? ['q'=>'','department'=>'','designation'=>'','slot'=>'','duty_status'=>'','availability_status'=>''];
            $filterOptions = $filterOptions ?? ['departments'=>[],'designations'=>[]];
            $viewMode = $viewMode ?? 'single_date';
            $availabilityColumns = $availabilityColumns ?? [];
            $visibleDates = collect($availabilityColumns)->pluck('date')->unique()->values()->all();
            $visibleSlots = collect($availabilityColumns)->pluck('slot.label')->unique()->values()->all();
            $facultyDetailSlots = collect($availabilityColumns)->pluck('slot')->unique('label')->values()->all();
            $facultyDetailColumnsByDate = collect($availabilityColumns)->groupBy('date');
            $exportQuery = array_merge(request()->only(['q','department','designation','slot_filter','duty_status','availability_status']), ['rule_id' => $selectedRule->id, 'type' => 'faculty_availability']);
            if ($viewMode !== 'all_dates') {
                $exportQuery['date'] = $selectedDate;
            }
        @endphp
        <div class="panel">
            <form class="availability-toolbar" method="get" action="{{ route('scheduler.faculty-availability') }}">
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <div>
                    <label>View</label>
                    <select name="view_mode">
                        <option value="single_date" @selected($viewMode === 'single_date')>One date</option>
                        <option value="all_dates" @selected($viewMode === 'all_dates')>All dates</option>
                        <option value="faculty_member" @selected($viewMode === 'faculty_member')>Faculty member</option>
                    </select>
                </div>
                <div>
                    <label>Date</label>
                    <select name="date">
                        @foreach($dates as $day)
                            <option value="{{ $day }}" @selected($selectedDate === $day)>{{ $day }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Slot</label>
                    <select name="slot_filter">
                        <option value="">All slots</option>
                        @foreach(($allSlots ?? $slots) as $slot)
                            <option value="{{ $slot['label'] }}" @selected(($filters['slot'] ?? '') === $slot['label'])>{{ $slot['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="wide">
                    <label>Search faculty</label>
                    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, department, designation">
                </div>
                <div class="wide">
                    <label>Faculty member view</label>
                    <select name="faculty_id">
                        <option value="">First matching faculty</option>
                        @foreach($faculties as $facultyOption)
                            <option value="{{ $facultyOption->id }}" @selected((int) ($selectedFacultyId ?? 0) === (int) $facultyOption->id)>
                                {{ $facultyOption->name }} - {{ $facultyOption->department }}
                            </option>
                        @endforeach
                    </select>
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
                    <label>Faculty status</label>
                    <select name="duty_status">
                        <option value="">All faculty</option>
                        <option value="active" @selected(($filters['duty_status'] ?? '') === 'active')>Active only</option>
                        <option value="excluded" @selected(($filters['duty_status'] ?? '') === 'excluded')>Excluded / max duties 0</option>
                        <option value="quota_available" @selected(($filters['duty_status'] ?? '') === 'quota_available')>Quota available</option>
                        <option value="quota_full" @selected(($filters['duty_status'] ?? '') === 'quota_full')>Quota full</option>
                    </select>
                </div>
                <div>
                    <label>Availability</label>
                    <select name="availability_status">
                        <option value="">All availability states</option>
                        <option value="available_all" @selected(($filters['availability_status'] ?? '') === 'available_all')>Available in visible slot(s)</option>
                        <option value="unavailable_any" @selected(($filters['availability_status'] ?? '') === 'unavailable_any')>Unavailable in any visible slot</option>
                        <option value="unavailable_all" @selected(($filters['availability_status'] ?? '') === 'unavailable_all')>Unavailable in all visible slots</option>
                    </select>
                </div>
                <div class="availability-actions">
                    <button class="btn" type="submit">Apply filters</button>
                    <a class="btn secondary" href="{{ route('scheduler.faculty-availability', ['rule_id' => $selectedRule->id, 'date' => $selectedDate]) }}">Reset</a>
                    <a class="btn secondary" href="{{ route('scheduler.export-operations', $exportQuery) }}">Export filtered Excel</a>
                </div>
            </form>
            <form class="reset-availability-form" method="post" action="{{ route('scheduler.faculty-availability.store') }}" onsubmit="return confirm('Reset all saved faculty unavailable slots for this session? Everyone will become available for every date/slot.')">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <input type="hidden" name="date" value="{{ $selectedDate }}">
                <input type="hidden" name="view_mode" value="{{ $viewMode }}">
                <input type="hidden" name="availability_action" value="reset_all_available">
                @foreach(request()->only(['q','department','designation','slot_filter','duty_status','availability_status','faculty_id']) as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach
                <button class="btn red" type="submit">Reset all to available</button>
            </form>
            <p class="muted">Tick the slots where a faculty member is <strong>not available</strong>. The invigilation generator will skip those faculty members for those exact slots.</p>
            <div class="filter-summary">Showing {{ count($faculties) }} faculty member(s) and {{ count($availabilityColumns) }} date/slot column(s).</div>
        </div>

        @if(empty($availabilityColumns))
            <div class="warn">No generated date/slot was found for the current filters. Generate the datesheet first, then set availability.</div>
        @elseif(count($faculties) === 0)
            <div class="warn">No faculty member matches the selected filters.</div>
        @elseif($viewMode === 'faculty_member')
            @if(!$selectedFaculty)
                <div class="warn">Select a faculty member to view all date/slot availability.</div>
            @else
                <form method="post" action="{{ route('scheduler.faculty-availability.store') }}">
                    @csrf
                    <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                    <input type="hidden" name="date" value="{{ $selectedDate }}">
                    <input type="hidden" name="view_mode" value="{{ $viewMode }}">
                    <input type="hidden" name="availability_action" value="save">
                    @foreach(request()->only(['q','department','designation','slot_filter','duty_status','availability_status','faculty_id']) as $key => $value)
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endforeach
                    @foreach($visibleDates as $date)
                        <input type="hidden" name="visible_dates[]" value="{{ $date }}">
                    @endforeach
                    @foreach($visibleSlots as $slotLabel)
                        <input type="hidden" name="visible_slots[]" value="{{ $slotLabel }}">
                    @endforeach
                    @foreach($availabilityColumns as $column)
                        <input type="hidden" name="visible_columns[{{ $column['date'] }}][]" value="{{ $column['slot']['label'] }}">
                    @endforeach
                    <input type="hidden" name="visible_faculty_ids[]" value="{{ $selectedFaculty->id }}">

                    <div class="panel">
                        <div class="faculty-detail-title">
                            {{ $selectedFaculty->name }}
                            <span class="muted">{{ $selectedFaculty->department }} - {{ $selectedFaculty->designation }} - Max {{ $selectedFaculty->max_duties }}</span>
                        </div>
                        <div class="faculty-detail-scroll">
                            <table class="faculty-detail-table">
                                <thead>
                                    <tr>
                                        <th class="date-col">Date</th>
                                        @foreach($facultyDetailSlots as $slot)
                                            <th>
                                                {{ $slot['label'] }}
                                                @if(($slot['start'] ?? '') || ($slot['end'] ?? ''))
                                                    <div class="slot-help">{{ $slot['start'] ?? '' }} - {{ $slot['end'] ?? '' }}</div>
                                                @endif
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($facultyDetailColumnsByDate as $date => $dateColumns)
                                        @php
                                            $slotsForDate = collect($dateColumns)->mapWithKeys(fn($column) => [$column['slot']['label'] => $column['slot']])->all();
                                        @endphp
                                        <tr>
                                            <th class="date-col">{{ $date }}</th>
                                            @foreach($facultyDetailSlots as $slot)
                                                @php
                                                    $slotLabel = $slot['label'];
                                                    $existsForDate = isset($slotsForDate[$slotLabel]);
                                                    $unavailable = $existsForDate && (($matrix[$date][(string) $selectedFaculty->id][$slotLabel] ?? false)
                                                        || ($matrix[$date][(string) $selectedFaculty->id][\App\Services\Scheduler\SlotHelper::normalizeSlotKey($slotLabel)] ?? false));
                                                @endphp
                                                <td>
                                                    @if($existsForDate)
                                                        <input class="availability-check" type="checkbox"
                                                               name="unavailable[{{ $date }}][{{ $selectedFaculty->id }}][{{ $slotLabel }}]"
                                                               value="1" @checked($unavailable)>
                                                    @else
                                                        <span class="muted">-</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="export-row">
                        <button class="btn green" type="submit">Save availability</button>
                        <a class="btn secondary" href="{{ route('scheduler.duty-roster', ['rule_id' => $selectedRule->id]) }}">Back to duty roster</a>
                    </div>
                </form>
            @endif
        @else
            <form method="post" action="{{ route('scheduler.faculty-availability.store') }}">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <input type="hidden" name="date" value="{{ $selectedDate }}">
                <input type="hidden" name="view_mode" value="{{ $viewMode }}">
                <input type="hidden" name="availability_action" value="save">
                @foreach(request()->only(['q','department','designation','slot_filter','duty_status','availability_status']) as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach
                @foreach($visibleDates as $date)
                    <input type="hidden" name="visible_dates[]" value="{{ $date }}">
                @endforeach
                @foreach($visibleSlots as $slotLabel)
                    <input type="hidden" name="visible_slots[]" value="{{ $slotLabel }}">
                @endforeach
                @foreach($availabilityColumns as $column)
                    <input type="hidden" name="visible_columns[{{ $column['date'] }}][]" value="{{ $column['slot']['label'] }}">
                @endforeach
                @foreach($faculties as $faculty)
                    <input type="hidden" name="visible_faculty_ids[]" value="{{ $faculty->id }}">
                @endforeach

                <div class="availability-scroll">
                    <table class="availability-table">
                        <thead>
                            <tr>
                                <th class="faculty-col">Faculty</th>
                                @foreach(collect($availabilityColumns)->groupBy('date') as $date => $dateColumns)
                                    <th class="date-head" colspan="{{ count($dateColumns) }}">{{ $date }}</th>
                                @endforeach
                            </tr>
                            <tr>
                                <th class="faculty-col"></th>
                                @foreach($availabilityColumns as $column)
                                    @php $slot = $column['slot']; @endphp
                                    <th>
                                        {{ $slot['label'] }}
                                        @if(($slot['start'] ?? '') || ($slot['end'] ?? ''))
                                            <div class="slot-help">{{ $slot['start'] ?? '' }} - {{ $slot['end'] ?? '' }}</div>
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($faculties as $faculty)
                                <tr>
                                    <th class="faculty-col">
                                        {{ $faculty->name }}
                                        <div class="muted">{{ $faculty->department }} - {{ $faculty->designation }} - Max {{ $faculty->max_duties }}</div>
                                    </th>
                                    @foreach($availabilityColumns as $column)
                                        @php
                                            $date = $column['date'];
                                            $slot = $column['slot'];
                                            $slotLabel = $slot['label'];
                                            $unavailable = ($matrix[$date][(string) $faculty->id][$slotLabel] ?? false)
                                                || ($matrix[$date][(string) $faculty->id][\App\Services\Scheduler\SlotHelper::normalizeSlotKey($slotLabel)] ?? false);
                                        @endphp
                                        <td>
                                            <input class="availability-check" type="checkbox"
                                                   name="unavailable[{{ $date }}][{{ $faculty->id }}][{{ $slotLabel }}]"
                                                   value="1" @checked($unavailable)>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="export-row">
                    <button class="btn green" type="submit">Save availability</button>
                    <a class="btn secondary" href="{{ route('scheduler.duty-roster', ['rule_id' => $selectedRule->id]) }}">Back to duty roster</a>
                </div>
            </form>
        @endif
    @endif
</div>
</body>
</html>
