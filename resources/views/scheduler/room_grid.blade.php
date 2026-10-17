<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Room-Course Invigilator Grid</title>
    <style>
        .grid-table th,.grid-table td{font-size:12px}
        .drop-cell{min-height:64px;background:#f9fafb;min-width:320px}
        .drop-cell.drop-over{outline:3px solid #2563eb;background:#eff6ff}
        .invig-card{display:inline-block;background:#ecfdf5;border:1px solid #10b981;border-radius:999px;padding:4px 8px;margin:2px;cursor:grab;font-weight:700;user-select:none}
        .invig-card.duty-only{background:#fef3c7;border-color:#f59e0b}
        .invig-card.drop-target{outline:2px solid #2563eb;background:#dbeafe}
        .invig-card.locked{background:#fee2e2;border-color:#ef4444;cursor:not-allowed}
        .lock-badge{font-size:10px;background:#991b1b;color:white;border-radius:999px;padding:1px 5px;margin-left:4px}
        .eligible-note{font-size:11px;color:#6b7280;margin-top:3px}
        .paper-pill{display:inline-block;background:#e0f2fe;border:1px solid #7dd3fc;border-radius:8px;padding:3px 6px;margin:2px;line-height:1.5}
        .ajax-status{position:fixed;right:16px;bottom:16px;background:#111827;color:#fff;padding:10px 14px;border-radius:8px;display:none;max-width:520px;z-index:100}
        .assign-row{display:flex;gap:4px;margin-top:6px;align-items:center}
        .assign-row select{min-width:150px}
        .invigs{min-height:30px}
        .remove-invig{border:0;background:transparent;color:#991b1b;font-weight:900;cursor:pointer;margin-left:4px}
        .slot-filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:12px}
        .slot-filter select{min-width:320px;height:112px}
        .slot-filter .filter-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .slot-filter .inline-check{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#374151;margin-bottom:4px}
        .slot-filter .inline-check input{width:auto}
    </style>
</head>
<body>
@include('scheduler.partials.nav')

<div class="wrap">
    <h1>Room-Course Grid with Invigilators</h1>
    @if(session('status')) <div class="status">{{ session('status') }}</div> @endif
    @if(!$selectedRule)
        <div class="warn">Create an exam session first.</div>
    @else
        <div class="panel">
            <p class="muted">Drag an invigilator chip onto another invigilator chip to swap those exact two duties. Course-instructor duties are locked. The dropdown shows only faculty members who still have quota and will not violate availability, same-slot, one-duty-per-day, or consecutive-duty rules.</p>
            <form method="post" action="{{ route('scheduler.invigilators') }}" style="display:inline-block">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <input type="hidden" name="all" value="1">
                <button class="btn green" type="submit">Regenerate full roster</button>
            </form>
            <a class="btn" href="{{ route('scheduler.faculty-availability', ['rule_id' => $selectedRule->id]) }}">Set faculty availability</a>
            <a class="btn secondary" href="{{ route('scheduler.export-operations', array_merge(['rule_id' => $selectedRule->id, 'type' => 'room_grid', 'pairs' => $selectedPairKeys ?? []], !empty($missingDutiesOnly) ? ['missing_duties_only' => 1] : [])) }}">Export grid Excel</a>
            <form class="slot-filter" method="get" action="{{ route('scheduler.room-grid') }}">
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <div>
                    <label>Visible date / slot</label>
                    <select name="pairs[]" multiple>
                        @foreach($pairs as $pair)
                            @php($pairKey = $pair['date'] . '|' . $pair['slot'])
                            <option value="{{ $pairKey }}" @selected(in_array($pairKey, $selectedPairKeys ?? [], true))>
                                {{ $pair['date'] }} - {{ $pair['slot'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="inline-check">
                        <input type="checkbox" name="missing_duties_only" value="1" @checked(!empty($missingDutiesOnly))>
                        Missing duties only
                    </label>
                    <div class="muted">Rows where assigned duties are less than required.</div>
                </div>
                <div class="filter-actions">
                    <button class="btn small" type="submit">Apply</button>
                    <a class="btn small secondary" href="{{ route('scheduler.room-grid', ['rule_id' => $selectedRule->id]) }}">First slot</a>
                    <span class="muted">Showing {{ count($selectedPairs ?? []) }} of {{ $pairs->count() }} generated slot(s){{ !empty($missingDutiesOnly) ? ', missing duties only' : '' }}.</span>
                </div>
            </form>
        </div>

        <div id="roomGridBoard" class="panel">
            <table class="grid-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Slot</th>
                        <th>Room</th>
                        <th>Course(s)</th>
                        <th>Invigilator(s)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($grid['rows'] as $row)
                        <tr>
                            <td>{{ $row['date'] }}</td>
                            <td>{{ $row['slot'] }}</td>
                            <td><strong>{{ $row['room'] }}</strong><br><span class="muted">{{ $row['building'] }} {{ $row['floor'] }}</span></td>
                            <td>
                                @forelse($row['papers'] as $paper)
                                    <span class="paper-pill" title="{{ $paper['course_title'] ?? '' }}">
                                        <strong>{{ $paper['course_code'] ?? '' }}</strong>
                                        @if(!empty($paper['class_label']))
                                            [{{ $paper['class_label'] }}]
                                        @endif
                                        ({{ $paper['strength'] ?? 0 }}@if(!empty($paper['lane'])) {{ $paper['lane'] }}@endif)
                                    </span>
                                @empty
                                    <span class="muted">No paper</span>
                                @endforelse
                            </td>
                            <td class="drop-cell" data-target-allocation-id="{{ $row['primary_allocation_id'] }}">
                                <div class="invigs">
                                    @forelse($row['invigilators'] as $invigilator)
                                        @if(!empty($invigilator['is_tbd']))
                                            <span class="muted">TBD</span>
                                        @else
                                        <span class="invig-card {{ $invigilator['is_duty_only'] ? 'duty-only' : '' }} {{ !empty($invigilator['is_course_instructor']) ? 'locked' : '' }}"
                                              draggable="{{ !empty($invigilator['is_course_instructor']) ? 'false' : 'true' }}"
                                              data-allocation-id="{{ $invigilator['allocation_id'] }}"
                                              data-locked="{{ !empty($invigilator['is_course_instructor']) ? '1' : '0' }}"
                                              title="{{ !empty($invigilator['is_course_instructor']) ? 'Course instructor duty: cannot be moved or swapped, but can be removed' : 'Drag to move/swap this duty' }}">
                                            {{ $invigilator['name'] }}
                                            @if(!empty($invigilator['is_course_instructor']))
                                                <span class="lock-badge">Instructor</span>
                                                <button class="remove-invig" type="button" data-allocation-id="{{ $invigilator['allocation_id'] }}" title="Remove this invigilator">&times;</button>
                                            @else
                                                <button class="remove-invig" type="button" data-allocation-id="{{ $invigilator['allocation_id'] }}">&times;</button>
                                            @endif
                                        </span>
                                        @endif
                                    @empty
                                        @php($missing = max(1, (int) ($row['required_invigilators'] ?? 1)))
                                        @for($i = 0; $i < $missing; $i++)
                                            @if($i > 0) <br> @endif
                                            <span class="muted">TBD</span>
                                        @endfor
                                    @endforelse
                                </div>
                                <div class="assign-row">
                                    <select class="faculty-select">
                                        <option value="">{{ ($row['eligible_count'] ?? 0) > 0 ? 'Select eligible faculty' : 'No eligible faculty' }}</option>
                                        @foreach(($row['eligible_faculties'] ?? []) as $faculty)
                                            <option value="{{ $faculty['id'] }}">
                                                {{ $faculty['name'] }}
                                                @if(!empty($faculty['department'])) — {{ $faculty['department'] }} @endif
                                                (left: {{ $faculty['remaining_duties'] }})
                                            </option>
                                        @endforeach
                                    </select>
                                    <button class="btn small assign-primary" type="button" @if(!empty($row['primary_locked'])) disabled title="Cannot replace course instructor duty" @endif>Set</button>
                                    <button class="btn small secondary assign-additional" type="button">Add</button>
                                    <div class="eligible-note">Eligible now: {{ $row['eligible_count'] ?? 0 }}{{ !empty($row['primary_locked']) ? ' · primary duty locked because it is the course instructor' : '' }}</div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No matching room allocations/duties exist for the selected filter(s).</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>

<div id="ajaxStatus" class="ajax-status"></div>

<script>
const csrfToken = @json(csrf_token());
const moveInvigUrl = @json(route('scheduler.api.move-invigilator'));
const assignInvigUrl = @json(route('scheduler.api.assign-invigilator'));
const removeInvigUrl = @json(route('scheduler.api.remove-invigilator'));
let draggedAllocationId = null;

function showStatus(message, isError = false) {
    const box = document.getElementById('ajaxStatus');
    box.textContent = message;
    box.style.background = isError ? '#991b1b' : '#111827';
    box.style.display = 'block';
    setTimeout(() => { box.style.display = 'none'; }, 4500);
}

async function refreshGrid() {
    const html = await fetch(window.location.href, {headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(r => r.text());
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const fresh = doc.getElementById('roomGridBoard');
    if (fresh) document.getElementById('roomGridBoard').replaceWith(fresh);
}

async function jsonOrError(response, fallbackMessage) {
    let data = {};
    try {
        data = await response.json();
    } catch (error) {
        data = {message: await response.text().catch(() => '')};
    }

    if (!response.ok) {
        throw new Error(data.message || fallbackMessage);
    }

    return data;
}

function clearDragHighlights() {
    document.querySelectorAll('.drop-cell.drop-over').forEach(el => el.classList.remove('drop-over'));
    document.querySelectorAll('.invig-card.drop-target').forEach(el => el.classList.remove('drop-target'));
}

function resolveInvigilatorDropTarget(event) {
    const cell = event.target.closest('.drop-cell');
    if (!cell) return null;

    // Drop on a faculty chip means swap with that exact chip. Dropping on
    // empty room space still targets the room's primary duty allocation.
    const targetCard = event.target.closest('.invig-card');
    const targetAllocationId = targetCard
        ? parseInt(targetCard.dataset.allocationId, 10)
        : parseInt(cell.dataset.targetAllocationId, 10);

    return {cell, targetCard, targetAllocationId};
}

document.addEventListener('dragstart', function(event) {
    const card = event.target.closest('.invig-card');
    if (!card) return;
    if (card.dataset.locked === '1') {
        event.preventDefault();
        showStatus('Course instructor duties are locked and cannot be moved.', true);
        return;
    }
    draggedAllocationId = parseInt(card.dataset.allocationId, 10);
    event.dataTransfer.setData('text/plain', String(draggedAllocationId));
});

document.addEventListener('dragover', function(event) {
    const target = resolveInvigilatorDropTarget(event);
    if (!target) return;
    event.preventDefault();
    clearDragHighlights();
    target.cell.classList.add('drop-over');
    if (target.targetCard && parseInt(target.targetCard.dataset.allocationId, 10) !== draggedAllocationId) {
        target.targetCard.classList.add('drop-target');
    }
});

document.addEventListener('dragleave', function(event) {
    if (!event.relatedTarget || !event.target.closest('.drop-cell')) return;
    const currentCell = event.target.closest('.drop-cell');
    if (!currentCell.contains(event.relatedTarget)) {
        clearDragHighlights();
    }
});

document.addEventListener('dragend', function() {
    draggedAllocationId = null;
    clearDragHighlights();
});

document.addEventListener('drop', async function(event) {
    const target = resolveInvigilatorDropTarget(event);
    if (!target || !draggedAllocationId) return;
    event.preventDefault();
    clearDragHighlights();

    const targetAllocationId = target.targetAllocationId;
    if (!targetAllocationId || targetAllocationId === draggedAllocationId) return;

    const sourceCard = document.querySelector('.invig-card[data-allocation-id="' + draggedAllocationId + '"]');
    if (sourceCard && sourceCard.dataset.locked === '1') {
        showStatus('Course instructor duties are locked and cannot be moved.', true);
        return;
    }
    if (target.targetCard && target.targetCard.dataset.locked === '1') {
        showStatus('You cannot swap with an invigilator who is the course instructor for that paper.', true);
        return;
    }

    try {
        showStatus('Updating invigilator duty...');
        const response = await fetch(moveInvigUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
            body: JSON.stringify({
                source_allocation_id: draggedAllocationId,
                target_allocation_id: targetAllocationId,
                swap: true
            })
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Unable to move invigilator.');
        await refreshGrid();
        showStatus('Invigilator grid updated.');
    } catch (error) {
        showStatus(error.message, true);
    } finally {
        draggedAllocationId = null;
        clearDragHighlights();
    }
});

document.addEventListener('click', async function(event) {
    const setButton = event.target.closest('.assign-primary');
    const addButton = event.target.closest('.assign-additional');
    const removeButton = event.target.closest('.remove-invig');

    try {
        if (setButton || addButton) {
            const cell = event.target.closest('.drop-cell');
            const facultyId = parseInt(cell.querySelector('.faculty-select').value, 10);
            const targetAllocationId = parseInt(cell.dataset.targetAllocationId, 10);
            if (!facultyId) {
                showStatus('Select a faculty member first.', true);
                return;
            }

            const response = await fetch(assignInvigUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                body: JSON.stringify({
                    target_allocation_id: targetAllocationId,
                    faculty_id: facultyId,
                    additional: !!addButton
                })
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Unable to assign invigilator.');
            await refreshGrid();
            showStatus('Invigilator assigned.');
        }

        if (removeButton) {
            event.preventDefault();
            event.stopPropagation();
            const allocationId = parseInt(removeButton.dataset.allocationId, 10);
            if (!allocationId) {
                showStatus('This invigilator duty is missing an allocation id. Refresh the grid and try again.', true);
                return;
            }
            const response = await fetch(removeInvigUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                body: JSON.stringify({allocation_id: allocationId})
            });
            await jsonOrError(response, 'Unable to remove invigilator.');
            await refreshGrid();
            showStatus('Invigilator removed.');
        }
    } catch (error) {
        showStatus(error.message, true);
    }
});
</script>
</body>
</html>
