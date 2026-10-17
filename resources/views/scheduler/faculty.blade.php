@include('scheduler.partials.nav')
@php
    $deptNames = collect($departments ?? [])->pluck('name')->filter()->values()->all();
    $designationNames = collect($designations ?? [])->pluck('name')->filter()->values()->all();
    $filters = $filters ?? [];
@endphp
<div class="wrap">
    <h1>Faculty Operations</h1>
    @if(session('status'))<div class="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="warn">{{ implode(' | ', $errors->all()) }}</div>@endif

    <div class="panel">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:space-between">
            <div>
                <h2 style="margin:0">Faculty master data</h2>
                <p class="muted" style="margin:6px 0 0">Manage departments, designations, faculty records, filters, and duty limits from one page. Use <strong>Max Duties = 0</strong> to exclude current HODs or other faculty from invigilation.</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                @if($selectedRule)
                    <a class="btn secondary" href="{{ route('scheduler.export-operations', ['rule_id' => $selectedRule->id, 'type' => 'faculty_list']) }}">Export faculty Excel</a>
                    <a class="btn" href="{{ route('scheduler.faculty-availability', ['rule_id' => $selectedRule->id]) }}">Set availability</a>
                @endif
            </div>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="panel">
            <h2>Departments</h2>
            <form method="post" action="{{ route('scheduler.departments.store') }}" style="display:flex;gap:8px;align-items:end">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule?->id }}">
                <div style="flex:1"><label>Add department</label><input name="name" placeholder="Computer Science" required></div>
                <button class="btn" type="submit">Add</button>
            </form>
            <div class="master-list">
                @forelse($departments as $department)
                    <div class="master-chip">
                        <span>{{ $department->name }}</span>
                        <form method="post" action="{{ route('scheduler.departments.delete', $department) }}" onsubmit="return confirm('Delete this department?')">
                            @csrf @method('DELETE')
                            <input type="hidden" name="rule_id" value="{{ $selectedRule?->id }}">
                            <button class="chip-delete" type="submit">×</button>
                        </form>
                    </div>
                @empty
                    <p class="muted">No departments yet.</p>
                @endforelse
            </div>
        </div>

        <div class="panel">
            <h2>Designations</h2>
            <form method="post" action="{{ route('scheduler.designations.store') }}" style="display:flex;gap:8px;align-items:end">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule?->id }}">
                <div style="flex:1"><label>Add designation</label><input name="name" placeholder="Assistant Professor" required></div>
                <button class="btn" type="submit">Add</button>
            </form>
            <div class="master-list">
                @forelse($designations as $designation)
                    <div class="master-chip">
                        <span>{{ $designation->name }}</span>
                        <form method="post" action="{{ route('scheduler.designations.delete', $designation) }}" onsubmit="return confirm('Delete this designation?')">
                            @csrf @method('DELETE')
                            <input type="hidden" name="rule_id" value="{{ $selectedRule?->id }}">
                            <button class="chip-delete" type="submit">×</button>
                        </form>
                    </div>
                @empty
                    <p class="muted">No designations yet.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>Filter faculty</h2>
        <form method="get" action="{{ route('scheduler.faculty') }}" class="filter-grid">
            <input type="hidden" name="rule_id" value="{{ $selectedRule?->id }}">
            <div>
                <label>Search</label>
                <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, department, designation">
            </div>
            <div>
                <label>Department</label>
                <select name="department">
                    <option value="">All departments</option>
                    @foreach($departments as $department)
                        <option value="{{ $department->name }}" @selected(($filters['department'] ?? '') === $department->name)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Designation</label>
                <select name="designation">
                    <option value="">All designations</option>
                    @foreach($designations as $designation)
                        <option value="{{ $designation->name }}" @selected(($filters['designation'] ?? '') === $designation->name)>{{ $designation->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Duty status</label>
                <select name="duty_status">
                    <option value="" @selected(($filters['duty_status'] ?? '') === '')>All</option>
                    <option value="active" @selected(($filters['duty_status'] ?? '') === 'active')>Active for duties</option>
                    <option value="excluded" @selected(($filters['duty_status'] ?? '') === 'excluded')>Excluded / max duties 0</option>
                    <option value="assigned" @selected(($filters['duty_status'] ?? '') === 'assigned')>Has assigned duties</option>
                    <option value="available" @selected(($filters['duty_status'] ?? '') === 'available')>Below duty limit</option>
                    <option value="limit_reached" @selected(($filters['duty_status'] ?? '') === 'limit_reached')>Limit reached</option>
                </select>
            </div>
            <div>
                <label>Max duties min</label>
                <input type="number" min="0" name="max_duties_min" value="{{ $filters['max_duties_min'] ?? '' }}">
            </div>
            <div>
                <label>Max duties max</label>
                <input type="number" min="0" name="max_duties_max" value="{{ $filters['max_duties_max'] ?? '' }}">
            </div>
            <div class="filter-actions">
                <button class="btn" type="submit">Apply filters</button>
                <a class="btn secondary" href="{{ route('scheduler.faculty', ['rule_id' => $selectedRule?->id]) }}">Clear</a>
            </div>
        </form>
        <p class="muted" style="margin-bottom:0">Showing {{ count($faculties) }} faculty member(s). Filters only affect the displayed list; bulk actions apply to the selected rows below.</p>
    </div>

    <form method="post" action="{{ route('scheduler.faculty.bulk') }}" id="facultyBulkForm">
        @csrf
        <input type="hidden" name="rule_id" value="{{ $selectedRule?->id }}">

        <div class="panel bulk-panel">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:space-between">
                <div>
                    <h2 style="margin:0">Apply change to selected faculty</h2>
                    <p class="muted" style="margin:6px 0 0"><span id="selectedCount">0</span> selected. Leave a field blank if you do not want to change it.</p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button class="btn secondary" type="button" id="selectAllVisible">Select all visible</button>
                    <button class="btn secondary" type="button" id="clearSelected">Clear selected</button>
                </div>
            </div>
            <div class="bulk-grid">
                <div>
                    <label>Set department</label>
                    <select name="bulk_department">
                        <option value="">No change</option>
                        @foreach($departments as $department)
                            <option value="{{ $department->name }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Set designation</label>
                    <select name="bulk_designation">
                        <option value="">No change</option>
                        @foreach($designations as $designation)
                            <option value="{{ $designation->name }}">{{ $designation->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Set max duties</label>
                    <input type="number" min="0" name="bulk_max_duties" placeholder="No change">
                    <small class="muted">Use 0 to exclude selected faculty from duties.</small>
                </div>
                <label class="checkline"><input type="checkbox" name="bulk_reset_assigned" value="1"> Reset assigned-duty count</label>
                <label class="checkline danger-text"><input type="checkbox" name="bulk_delete_selected" value="1"> Delete selected faculty</label>
            </div>
        </div>

        <div class="panel">
            <h2>Existing faculty</h2>
            <div style="overflow:auto;max-height:620px;border:1px solid #e5e7eb;border-radius:10px">
                <table class="faculty-table">
                    <thead>
                    <tr>
                        <th style="width:56px"><input type="checkbox" id="selectAllHeader" title="Select all visible"></th>
                        <th style="width:52px">Delete</th>
                        <th style="width:270px">Name</th>
                        <th style="width:230px">Department</th>
                        <th style="width:220px">Designation</th>
                        <th style="width:120px">Max Duties</th>
                        <th style="width:120px">Assigned</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($faculties as $faculty)
                        <tr @class(['excluded-row' => (int) $faculty->max_duties === 0])>
                            <td style="text-align:center">
                                <input type="checkbox" class="faculty-select" name="selected_faculty_ids[]" value="{{ $faculty->id }}">
                            </td>
                            <td style="text-align:center">
                                <input type="hidden" name="faculty[{{ $faculty->id }}][id]" value="{{ $faculty->id }}">
                                <input type="checkbox" name="faculty[{{ $faculty->id }}][delete]" value="1">
                            </td>
                            <td><input name="faculty[{{ $faculty->id }}][name]" value="{{ $faculty->name }}"></td>
                            <td>
                                <select name="faculty[{{ $faculty->id }}][department]">
                                    <option value="">-- Select department --</option>
                                    @if($faculty->department && !in_array($faculty->department, $deptNames, true))
                                        <option value="{{ $faculty->department }}" selected>{{ $faculty->department }}</option>
                                    @endif
                                    @foreach($departments as $department)
                                        <option value="{{ $department->name }}" @selected($faculty->department === $department->name)>{{ $department->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <select name="faculty[{{ $faculty->id }}][designation]">
                                    <option value="">-- Select designation --</option>
                                    @if($faculty->designation && !in_array($faculty->designation, $designationNames, true))
                                        <option value="{{ $faculty->designation }}" selected>{{ $faculty->designation }}</option>
                                    @endif
                                    @foreach($designations as $designation)
                                        <option value="{{ $designation->name }}" @selected($faculty->designation === $designation->name)>{{ $designation->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input type="number" min="0" name="faculty[{{ $faculty->id }}][max_duties]" value="{{ (int) $faculty->max_duties }}">
                                @if((int) $faculty->max_duties === 0)
                                    <small class="muted">Excluded</small>
                                @endif
                            </td>
                            <td>{{ $faculty->assigned_duties ?: 0 }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No faculty members match the current filters.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <h2>Add faculty in bulk</h2>
            <p class="muted">Paste one faculty per line. Supported formats: tab-separated or CSV. Columns: <strong>Name, Department, Designation, Max Duties</strong>. New departments and designations from this text are automatically added to the dropdown masters. Max duties may be <strong>0</strong>.</p>
            <textarea name="new_faculty_text" style="min-height:160px" placeholder="Dr. Ali Khan, Computer Science, Assistant Professor, 5&#10;Dr. Current HOD, Computer Science, Professor, 0"></textarea>
        </div>

        <div class="panel" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn green" type="submit">Save faculty changes</button>
            <span class="muted">Selected bulk changes, row edits, deletions, and new faculty import are saved together.</span>
        </div>
    </form>
</div>
<style>
    .faculty-table th{position:sticky;top:0;z-index:2;background:#f3f4f6}
    .faculty-table input[type="checkbox"]{width:auto;min-width:0}
    .faculty-table td{background:#fff;vertical-align:top}
    .faculty-table input,.faculty-table select{min-width:150px}
    .faculty-table .excluded-row td{background:#fff7ed}
    .master-list{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
    .master-chip{display:inline-flex;gap:8px;align-items:center;border:1px solid #d1d5db;background:#f9fafb;border-radius:999px;padding:5px 8px;font-size:12px}
    .master-chip form{margin:0}
    .chip-delete{border:0;background:#ef4444;color:#fff;border-radius:50%;width:20px;height:20px;line-height:16px;cursor:pointer;font-weight:700}
    .filter-grid,.bulk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end}
    .filter-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:end}
    .bulk-panel{border:2px solid #dbeafe;background:#eff6ff}
    .checkline{display:flex;gap:8px;align-items:center;margin-top:20px}
    .checkline input{width:auto;min-width:0}
    .danger-text{color:#991b1b;font-weight:700}
    small.muted{display:block;font-size:11px}
</style>
<script>
(function(){
    const selected = () => Array.from(document.querySelectorAll('.faculty-select'));
    const countEl = document.getElementById('selectedCount');
    const header = document.getElementById('selectAllHeader');
    function refreshCount(){
        const total = selected().filter(cb => cb.checked).length;
        if(countEl) countEl.textContent = total;
        if(header){
            const all = selected();
            header.checked = all.length > 0 && all.every(cb => cb.checked);
            header.indeterminate = total > 0 && total < all.length;
        }
    }
    function setAll(value){ selected().forEach(cb => cb.checked = value); refreshCount(); }
    selected().forEach(cb => cb.addEventListener('change', refreshCount));
    if(header) header.addEventListener('change', () => setAll(header.checked));
    const selectAllVisible = document.getElementById('selectAllVisible');
    const clearSelected = document.getElementById('clearSelected');
    if(selectAllVisible) selectAllVisible.addEventListener('click', () => setAll(true));
    if(clearSelected) clearSelected.addEventListener('click', () => setAll(false));
    const form = document.getElementById('facultyBulkForm');
    if(form){
        form.addEventListener('submit', function(e){
            const deleteSelected = form.querySelector('input[name="bulk_delete_selected"]');
            const rowDeletes = Array.from(form.querySelectorAll('input[name$="[delete]"]:checked')).length;
            if(deleteSelected && deleteSelected.checked){
                const total = selected().filter(cb => cb.checked).length;
                if(total === 0){
                    e.preventDefault();
                    alert('Select at least one faculty member before using Delete selected faculty.');
                    return;
                }
                if(!confirm('Delete ' + total + ' selected faculty member(s)?')) e.preventDefault();
                return;
            }
            if(rowDeletes > 0 && !confirm('Delete ' + rowDeletes + ' row-marked faculty member(s)?')) e.preventDefault();
        });
    }
    refreshCount();
})();
</script>
