<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Seat Plan Board</title>
    <style>
        .slot-select{display:flex;gap:8px;align-items:end;flex-wrap:wrap}
        .room-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:14px}
        .room-card{border:1px solid #d1d5db;border-radius:12px;background:#fff;overflow:auto}
        .room-card.waiting{border-color:#f59e0b;background:#fffbeb}
        .room-title{padding:10px 12px;background:#f3f4f6;border-bottom:1px solid #e5e7eb;font-weight:800;position:sticky;left:0}
        .waiting .room-title{background:#fef3c7}
        .seat-table{border-collapse:separate;border-spacing:4px;padding:8px;min-width:max-content}
        .seat-table td,.seat-table th{border:0;padding:0}
        .row-head{width:78px;height:24px;text-align:center;font-size:11px;color:#6b7280;cursor:grab}
        .col-head{width:52px;text-align:center;font-size:11px;color:#6b7280}
        .corner-head,.row-head,.col-head{background:#e5e7eb;border-radius:6px}
        .corner-head{width:52px}
        .seat{width:78px;height:72px;border:1px dashed #cbd5e1;border-radius:8px;background:#f8fafc;font-size:10px;vertical-align:top;position:relative;overflow:hidden}
        .seat.occupied{border-style:solid;background:#eff6ff;border-color:#60a5fa;cursor:grab}
        .seat.waiting-seat.occupied{background:#fff7ed;border-color:#f59e0b}
        .seat.drop-over{outline:3px solid #2563eb}
        .seat-num{position:absolute;right:4px;top:2px;color:#64748b;font-size:9px}
        .seat-check{position:absolute;left:3px;top:2px;width:auto}
        .seat-body{padding:18px 4px 4px}
        .reg{font-weight:800;font-size:10px}
        .course{font-weight:800;color:#1d4ed8;font-size:11px}
        .class-label{color:#475569}
        .row-summary{font-size:10px;color:#374151;margin-top:2px}
        .allocation-board{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:12px;margin-bottom:18px}
        .building-box{border:1px solid #d1d5db;border-radius:12px;background:#fff;overflow:hidden}
        .building-head{background:#111827;color:#fff;font-weight:900;padding:8px 10px}
        .allocation-lane{border-top:1px solid #e5e7eb;padding:8px;min-height:86px;background:#f8fafc}
        .allocation-lane.drop-over{outline:3px solid #2563eb;background:#eff6ff}
        .lane-title{font-weight:800;font-size:12px;margin-bottom:6px;display:flex;justify-content:space-between;gap:8px}
        .allocation-card{background:#fff;border:1px solid #bae6fd;border-left:4px solid #0284c7;border-radius:8px;padding:7px;margin:6px 0;cursor:grab;font-size:12px}
        .allocation-card.selected{border-color:#2563eb;border-left-color:#1d4ed8;background:#eff6ff;box-shadow:0 0 0 2px rgba(37,99,235,.18)}
        .allocation-card .code{font-weight:900;color:#075985}
        .allocation-selection-bar{display:flex;align-items:center;gap:8px;justify-content:space-between;background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:7px 9px;margin:8px 0 10px;font-size:12px}
        .unallocated-box{border:1px solid #fecaca;background:#fef2f2;border-radius:8px;padding:10px;margin:10px 0}
        .unallocated-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
        .unallocated-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px}
        .unallocated-card{background:#fff;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:8px;padding:7px;font-size:12px}
        .unallocated-card .code{font-weight:900;color:#991b1b}
        .unallocated-slot{border-top:1px solid #fecaca;padding-top:8px;margin-top:8px}
        .unallocated-slot:first-child{border-top:0;padding-top:0;margin-top:0}
        .unallocated-slot-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:6px}
        .slot-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .ajax-status{position:fixed;right:16px;bottom:16px;background:#111827;color:#fff;padding:10px 14px;border-radius:8px;display:none;max-width:520px;z-index:100}
        .legend span{display:inline-block;margin-right:12px;font-size:12px}
        .legend i{display:inline-block;width:14px;height:14px;border-radius:3px;margin-right:4px;vertical-align:-2px}
    </style>
</head>
<body>
@include('scheduler.partials.nav')

<div class="wrap">
    <h1>Seat Plan Board</h1>
    @if(session('status')) <div class="status">{{ session('status') }}</div> @endif
    @if(!$selectedRule)
        <div class="warn">Create an exam session first.</div>
    @else
        <div class="panel">
            <form class="slot-select" method="get" action="{{ route('scheduler.seat-plan') }}">
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <div>
                    <label>Date / slot</label>
                    <select name="pair" onchange="const [d,s]=this.value.split('|'); this.form.date.value=d; this.form.slot.value=s; this.form.submit();">
                        @foreach($pairs as $pair)
                            <option value="{{ $pair['date'] }}|{{ $pair['slot'] }}" @selected($pair['date'] === $date && $pair['slot'] === $slot)>
                                {{ $pair['date'] }} — {{ $pair['slot'] }}
                            </option>
                        @endforeach
                    </select>
                    <input type="hidden" name="date" value="{{ $date }}">
                    <input type="hidden" name="slot" value="{{ $slot }}">
                </div>
            </form>
            <div style="margin-top:10px">
                <form method="post" action="{{ route('scheduler.allocate-all') }}" style="display:inline-block;margin:0 8px 8px 0" onsubmit="return confirm('Rebuild room allocation for every generated datesheet slot? Existing seat plans and invigilators for those slots will be cleared by room allocation.')">
                    @csrf
                    <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                    <button class="btn small" type="submit">Allocate rooms for all slots</button>
                </form>
                <form method="post" action="{{ route('scheduler.seating-all') }}" style="display:inline-block;margin:0 8px 8px 0" onsubmit="return confirm('Generate seat plans for every generated datesheet slot? Run all-slot room allocation first.')">
                    @csrf
                    <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                    <button class="btn small green" type="submit">Generate seating for all slots</button>
                </form>
            </div>
            @if($date && $slot)
                <form method="post" action="{{ route('scheduler.allocate') }}" style="display:inline-block;margin-top:10px">
                    @csrf
                    <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                    <input type="hidden" name="date" value="{{ $date }}">
                    <input type="hidden" name="slot" value="{{ $slot }}">
                    <button class="btn small" type="submit">Regenerate room allocation for this slot only</button>
                </form>
                <form method="post" action="{{ route('scheduler.seating') }}" style="display:inline-block;margin-top:10px">
                    @csrf
                    <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                    <input type="hidden" name="date" value="{{ $date }}">
                    <input type="hidden" name="slot" value="{{ $slot }}">
                    <button class="btn small green" type="submit">Regenerate seating</button>
                </form>
                <a class="btn small secondary" style="margin-top:10px" href="{{ route('scheduler.export-operations', ['rule_id' => $selectedRule->id, 'type' => 'room_allocation', 'date' => $date, 'slot' => $slot]) }}">Export room allocation Excel</a>
                <a class="btn small secondary" style="margin-top:10px" href="{{ route('scheduler.export-operations', ['rule_id' => $selectedRule->id, 'type' => 'seat_plan', 'date' => $date, 'slot' => $slot]) }}">Export seat plan Excel</a>
                <a class="btn small secondary" style="margin-top:10px" href="{{ route('scheduler.export-operations', ['rule_id' => $selectedRule->id, 'type' => 'seat_plan_all']) }}">Export all slot seat plans ZIP</a>
            @endif
            <p class="muted">Drag one student, selected students, or a full row into another room/row/seat. Updates are saved with AJAX and the board refreshes without a full page reload.</p>
            <div class="legend">
                <span><i style="background:#eff6ff;border:1px solid #60a5fa"></i> Normal seat</span>
                <span><i style="background:#fff7ed;border:1px solid #f59e0b"></i> Waiting room / overflow</span>
            </div>
            @if(!empty($unallocatedRoomSummary))
                <div class="unallocated-box">
                    <div class="unallocated-head">
                        <div>
                            <strong>Exams without complete room allocation</strong>
                            <div class="muted">Use this list to find papers that were moved in the datesheet and lost room allocation.</div>
                        </div>
                    </div>
                    @foreach($unallocatedRoomSummary as $slotGroup)
                        <div class="unallocated-slot">
                            <div class="unallocated-slot-head">
                                <div>
                                    <strong>{{ $slotGroup['date'] }} {{ $slotGroup['slot'] }}</strong>
                                    <span class="muted">{{ count($slotGroup['exams']) }} exam(s), {{ $slotGroup['missing_strength'] }} student(s) uncovered</span>
                                </div>
                                <div class="slot-actions">
                                    <a class="btn small secondary" href="{{ route('scheduler.seat-plan', ['rule_id' => $selectedRule->id, 'date' => $slotGroup['date'], 'slot' => $slotGroup['slot']]) }}">Open slot</a>
                                    <form method="post" action="{{ route('scheduler.allocate') }}" style="margin:0" onsubmit="return confirm('Regenerate room allocation for {{ $slotGroup['date'] }} {{ $slotGroup['slot'] }} only? Existing seat plans and invigilators for this slot will be cleared.')">
                                        @csrf
                                        <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                                        <input type="hidden" name="date" value="{{ $slotGroup['date'] }}">
                                        <input type="hidden" name="slot" value="{{ $slotGroup['slot'] }}">
                                        <button class="btn small" type="submit">Regenerate slot only</button>
                                    </form>
                                </div>
                            </div>
                            <div class="unallocated-grid">
                                @foreach($slotGroup['exams'] as $exam)
                                    <div class="unallocated-card">
                                        <div class="code">{{ $exam['course_code'] }} {{ $exam['class_label'] ? '['.$exam['class_label'].']' : '' }}</div>
                                        <div>{{ $exam['course_title'] }}</div>
                                        <div class="muted">
                                            {{ $exam['status'] === 'partial' ? 'Partially allocated' : 'Unallocated' }}:
                                            {{ $exam['allocated_strength'] }}/{{ $exam['required_strength'] }} students covered
                                        </div>
                                        @if(!empty($exam['instructor']))
                                            <div class="muted">{{ $exam['instructor'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div id="roomAllocationBoard" class="panel">
            <h2>Room allocation grid</h2>
            <p class="muted">This is the separate room allocation step. Drag a paper segment to another physical room, or long-click paper segments to select multiple and drag any selected paper. The move clears seat plan and invigilator duties for this slot, so regenerate seating and then assign invigilators afterwards.</p>
            <div id="allocationSelectionBar" class="allocation-selection-bar" hidden>
                <strong id="allocationSelectionCount">0 papers selected</strong>
                <button id="clearAllocationSelection" class="btn small secondary" type="button">Clear selection</button>
            </div>
            @if(!empty($roomAllocationBoard['unallocated_exams']))
                <div class="unallocated-box">
                    <div class="unallocated-head">
                        <div>
                            <strong>{{ count($roomAllocationBoard['unallocated_exams']) }} exam(s) need room allocation in this slot</strong>
                            <div class="muted">These papers are in the selected date/slot but do not have enough room allocation yet.</div>
                        </div>
                        <form method="post" action="{{ route('scheduler.allocate') }}" style="margin:0" onsubmit="return confirm('Regenerate room allocation for this selected date/slot only? Existing seat plans and invigilators for this slot will be cleared.')">
                            @csrf
                            <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                            <input type="hidden" name="date" value="{{ $date }}">
                            <input type="hidden" name="slot" value="{{ $slot }}">
                            <button class="btn small" type="submit">Regenerate this slot only</button>
                        </form>
                    </div>
                    <div class="unallocated-grid">
                        @foreach($roomAllocationBoard['unallocated_exams'] as $exam)
                            <div class="unallocated-card">
                                <div class="code">{{ $exam['course_code'] }} {{ $exam['class_label'] ? '['.$exam['class_label'].']' : '' }}</div>
                                <div>{{ $exam['course_title'] }}</div>
                                <div class="muted">
                                    {{ $exam['status'] === 'partial' ? 'Partially allocated' : 'Unallocated' }}:
                                    {{ $exam['allocated_strength'] }}/{{ $exam['required_strength'] }} students covered
                                </div>
                                @if(!empty($exam['instructor']))
                                    <div class="muted">{{ $exam['instructor'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="allocation-board">
                @forelse(($roomAllocationBoard['buildings'] ?? []) as $buildingGroup)
                    <div class="building-box">
                        <div class="building-head">{{ $buildingGroup['building'] }}</div>
                        @foreach($buildingGroup['lanes'] as $lane)
                            <div class="allocation-lane"
                                 data-room-id="{{ $lane['room_pk'] }}"
                                 data-subslot-label="{{ $lane['subslot_label'] }}">
                                <div class="lane-title">
                                    <span>{{ $lane['lane_label'] }}</span>
                                    <span class="muted">{{ $lane['assigned_total'] }}/{{ $lane['planning_capacity'] ?? $lane['capacity'] }} plan · {{ $lane['capacity'] }} real seats</span>
                                </div>
                                @forelse($lane['cards'] as $card)
                                    <div class="allocation-card" draggable="true" data-allocation-id="{{ $card['allocation_id'] }}">
                                        <div class="code">{{ $card['course_code'] }} {{ $card['class_label'] ? '['.$card['class_label'].']' : '' }}</div>
                                        <div>{{ $card['course_title'] }}</div>
                                        <div class="muted">Strength: {{ $card['assigned_strength'] }} · {{ $card['instructor'] }}</div>
                                        @if(!empty($card['source_lane']))
                                            <div class="muted">Internal row group: {{ $card['source_lane'] }}</div>
                                        @endif
                                        @if(!empty($card['row_group']))
                                            <div class="muted">Row group: {{ $card['row_group'] }}</div>
                                        @endif
                                    </div>
                                @empty
                                    <div class="muted">Empty physical room</div>
                                @endforelse
                            </div>
                        @endforeach
                    </div>
                @empty
                    <div class="warn">No room allocation exists yet for this slot. Run room allocation first, then generate seating.</div>
                @endforelse
            </div>
        </div>

        <div id="seatPlanBoard">
            @if(!empty($seatPlanBoard['warnings']))
                <div class="warn">
                    @foreach($seatPlanBoard['warnings'] as $warning)
                        <div>{{ $warning }}</div>
                    @endforeach
                </div>
            @endif

            <div class="room-grid">
                @foreach($seatPlanBoard['rooms'] as $room)
                    <div class="room-card {{ $room['is_waiting_room'] ? 'waiting' : '' }}" data-room-id="{{ $room['room_pk'] }}">
                        <div class="room-title">
                            {{ $room['room_id'] }} — {{ $room['room_name'] }}
                            <span class="muted">({{ $room['building'] }} {{ $room['floor'] }})</span>
                            <div class="muted">{{ $room['rows'] }} rows × {{ $room['cols'] }} cols · {{ $room['occupied_count'] }}/{{ $room['capacity'] }} occupied</div>
                        </div>
                        <table class="seat-table">
                            <thead>
                                <tr>
                                    <th class="corner-head">Col / Row</th>
                                    @foreach($room['matrix'] as $rowNo => $row)
                                        @php
                                            $rowIds = collect($row)->pluck('assignment.seat_assignment_id')->filter()->values()->implode(',');
                                        @endphp
                                        <th class="row-head" draggable="true" data-row-assignment-ids="{{ $rowIds }}" title="Drag this row">
                                            Row {{ $rowNo }}
                                            @if(isset($room['row_summaries'][$rowNo]))
                                                <div class="row-summary">{{ $room['row_summaries'][$rowNo] }}</div>
                                            @endif
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @for($colNo = 1; $colNo <= (int) $room['cols']; $colNo++)
                                    <tr data-room-id="{{ $room['room_pk'] }}">
                                        <th class="col-head">Col {{ $colNo }}</th>
                                        @foreach($room['matrix'] as $rowNo => $row)
                                            @php $seat = $row[$colNo] ?? ['row' => $rowNo, 'col' => $colNo, 'occupied' => false, 'assignment' => null]; @endphp
                                            @php $a = $seat['assignment']; @endphp
                                            <td class="seat {{ $seat['occupied'] ? 'occupied' : '' }} {{ $room['is_waiting_room'] ? 'waiting-seat' : '' }}"
                                                data-room-id="{{ $room['room_pk'] }}"
                                                data-row="{{ $seat['row'] }}"
                                                data-col="{{ $seat['col'] }}"
                                                @if($seat['occupied']) draggable="true" data-seat-assignment-id="{{ $a['seat_assignment_id'] }}" @endif>
                                                <span class="seat-num">{{ $seat['row'] }}-{{ $seat['col'] }}</span>
                                                @if($seat['occupied'])
                                                    <input class="seat-check" type="checkbox" value="{{ $a['seat_assignment_id'] }}">
                                                    <div class="seat-body">
                                                        <div class="course">{{ $a['course_code'] }}</div>
                                                        <div class="reg">{{ $a['registration_id'] }}</div>
                                                        <div class="class-label">{{ $a['class_label'] }}</div>
                                                    </div>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endfor
                            </tbody>
                        </table>

                        @if(!empty($room['overflow']))
                            <div class="warn">Some students had seat positions outside this room layout. Move them or increase rows/columns.</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>

<div id="ajaxStatus" class="ajax-status"></div>

<script>
const csrfToken = @json(csrf_token());
const moveSeatsUrl = @json(route('scheduler.api.move-seat-assignments'));
const moveAllocationUrl = @json(route('scheduler.api.move-allocation'));
let draggedSeatIds = [];
let draggedAllocationIds = [];
const selectedAllocationIds = new Set();
let allocationLongPressTimer = null;
let allocationLongPressStart = null;
let suppressAllocationClick = false;
const allocationLongPressMs = 450;
const allocationLongPressMoveTolerance = 8;

function showStatus(message, isError = false) {
    const box = document.getElementById('ajaxStatus');
    box.textContent = message;
    box.style.background = isError ? '#991b1b' : '#111827';
    box.style.display = 'block';
    setTimeout(() => { box.style.display = 'none'; }, 5000);
}

function selectedSeatIds() {
    return Array.from(document.querySelectorAll('.seat-check:checked')).map(el => parseInt(el.value, 10)).filter(Boolean);
}

function allocationCardId(card) {
    return parseInt(card?.dataset.allocationId || '', 10);
}

function allocationLabel(count) {
    return count === 1 ? '1 paper selected' : count + ' papers selected';
}

function updateAllocationSelectionUi() {
    document.querySelectorAll('.allocation-card').forEach(card => {
        card.classList.toggle('selected', selectedAllocationIds.has(allocationCardId(card)));
    });

    const bar = document.getElementById('allocationSelectionBar');
    const count = document.getElementById('allocationSelectionCount');
    if (!bar || !count) return;

    count.textContent = allocationLabel(selectedAllocationIds.size);
    bar.hidden = selectedAllocationIds.size === 0;
}

function clearAllocationSelection() {
    selectedAllocationIds.clear();
    updateAllocationSelectionUi();
}

function toggleAllocationSelection(card) {
    const id = allocationCardId(card);
    if (!id) return;

    if (selectedAllocationIds.has(id)) {
        selectedAllocationIds.delete(id);
    } else {
        selectedAllocationIds.add(id);
    }
    updateAllocationSelectionUi();
}

function allocationDragIds(card) {
    const ownId = allocationCardId(card);
    if (!ownId) return [];
    return selectedAllocationIds.has(ownId) ? Array.from(selectedAllocationIds) : [ownId];
}

async function refreshOperationalBoards() {
    const html = await fetch(window.location.href, {headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(r => r.text());
    const doc = new DOMParser().parseFromString(html, 'text/html');
    for (const id of ['roomAllocationBoard', 'seatPlanBoard']) {
        const fresh = doc.getElementById(id);
        const current = document.getElementById(id);
        if (fresh && current) current.replaceWith(fresh);
    }
    updateAllocationSelectionUi();
}

document.addEventListener('pointerdown', function (event) {
    const card = event.target.closest('.allocation-card');
    if (!card || event.button > 0) return;

    allocationLongPressStart = {x: event.clientX, y: event.clientY, card};
    clearTimeout(allocationLongPressTimer);
    allocationLongPressTimer = setTimeout(() => {
        toggleAllocationSelection(card);
        suppressAllocationClick = true;
        allocationLongPressTimer = null;
    }, allocationLongPressMs);
});

document.addEventListener('pointermove', function (event) {
    if (!allocationLongPressStart || !allocationLongPressTimer) return;

    const dx = Math.abs(event.clientX - allocationLongPressStart.x);
    const dy = Math.abs(event.clientY - allocationLongPressStart.y);
    if (dx > allocationLongPressMoveTolerance || dy > allocationLongPressMoveTolerance) {
        clearTimeout(allocationLongPressTimer);
        allocationLongPressTimer = null;
    }
});

document.addEventListener('pointerup', function () {
    clearTimeout(allocationLongPressTimer);
    allocationLongPressTimer = null;
    allocationLongPressStart = null;
});

document.addEventListener('click', function (event) {
    const clearButton = event.target.closest('#clearAllocationSelection');
    if (clearButton) {
        clearAllocationSelection();
        return;
    }

    const card = event.target.closest('.allocation-card');
    if (!card) return;

    if (suppressAllocationClick) {
        suppressAllocationClick = false;
        event.preventDefault();
        return;
    }

    if (event.ctrlKey || event.metaKey) {
        event.preventDefault();
        toggleAllocationSelection(card);
    }
});

document.addEventListener('dragstart', function (event) {
    const allocationCard = event.target.closest('.allocation-card');
    if (allocationCard) {
        clearTimeout(allocationLongPressTimer);
        allocationLongPressTimer = null;
        draggedAllocationIds = allocationDragIds(allocationCard);
        event.dataTransfer.setData('text/plain', 'allocation:' + draggedAllocationIds.join(','));
        return;
    }

    const rowHead = event.target.closest('.row-head');
    if (rowHead) {
        draggedSeatIds = (rowHead.dataset.rowAssignmentIds || '').split(',').map(v => parseInt(v, 10)).filter(Boolean);
        event.dataTransfer.setData('text/plain', draggedSeatIds.join(','));
        return;
    }

    const seat = event.target.closest('.seat.occupied');
    if (!seat) return;

    const selected = selectedSeatIds();
    const ownId = parseInt(seat.dataset.seatAssignmentId, 10);
    draggedSeatIds = selected.includes(ownId) ? selected : [ownId];
    event.dataTransfer.setData('text/plain', draggedSeatIds.join(','));
});

document.addEventListener('dragend', function (event) {
    if (event.target.closest('.allocation-card')) {
        draggedAllocationIds = [];
    }
});

document.addEventListener('dragover', function (event) {
    const target = event.target.closest('.seat, tr[data-row], .room-card, .allocation-lane');
    if (!target) return;
    event.preventDefault();
    if (target.classList.contains('seat') || target.classList.contains('allocation-lane')) target.classList.add('drop-over');
});

document.addEventListener('dragleave', function (event) {
    const seat = event.target.closest('.seat');
    if (seat) seat.classList.remove('drop-over');
    const lane = event.target.closest('.allocation-lane');
    if (lane) lane.classList.remove('drop-over');
});

document.addEventListener('drop', async function (event) {
    const lane = event.target.closest('.allocation-lane');
    if (lane && draggedAllocationIds.length > 0) {
        event.preventDefault();
        lane.classList.remove('drop-over');
        try {
            showStatus(draggedAllocationIds.length === 1 ? 'Moving room allocation...' : 'Moving selected room allocations...');
            const response = await fetch(moveAllocationUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                body: JSON.stringify({
                    allocation_ids: draggedAllocationIds,
                    target_room_id: parseInt(lane.dataset.roomId, 10),
                    target_subslot_label: lane.dataset.subslotLabel || null
                })
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Unable to move room allocation.');
            clearAllocationSelection();
            await refreshOperationalBoards();
            const warnings = data.warnings && data.warnings.length ? ' ' + data.warnings.slice(0, 2).join(' | ') : '';
            showStatus('Room allocation updated.' + warnings);
        } catch (error) {
            showStatus(error.message, true);
        } finally {
            draggedAllocationIds = [];
        }
        return;
    }

    const seat = event.target.closest('.seat');
    const row = event.target.closest('tr[data-row]');
    const room = event.target.closest('.room-card');
    if (!room || draggedSeatIds.length === 0) return;

    event.preventDefault();
    if (seat) seat.classList.remove('drop-over');

    const targetRoomId = parseInt((seat || row || room).dataset.roomId || room.dataset.roomId, 10);
    const targetRow = seat ? parseInt(seat.dataset.row, 10) : (row ? parseInt(row.dataset.row, 10) : null);
    const targetCol = seat ? parseInt(seat.dataset.col, 10) : 1;

    try {
        showStatus('Moving student seat(s)...');
        const response = await fetch(moveSeatsUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
            body: JSON.stringify({
                seat_assignment_ids: draggedSeatIds,
                target_room_id: targetRoomId,
                target_row: targetRow,
                target_col: targetCol
            })
        });

        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.message || 'Unable to move selected seats.');
        }

        await refreshOperationalBoards();
        const warnings = data.warnings && data.warnings.length ? ' Warnings: ' + data.warnings.slice(0, 3).join(' | ') : '';
        showStatus('Seat plan updated.' + warnings);
    } catch (error) {
        showStatus(error.message, true);
    } finally {
        draggedSeatIds = [];
    }
});
</script>
</body>
</html>
