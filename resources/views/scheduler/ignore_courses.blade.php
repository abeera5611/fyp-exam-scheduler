<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Ignored Courses</title>
    <style>
        .ignore-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
        .ignore-stats{display:flex;gap:8px;flex-wrap:wrap}
        .ignore-stat{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;font-size:12px}
        .ignore-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .ignore-layout{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:14px;align-items:start}
        .ignore-filters{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr)) auto;gap:8px;align-items:end;margin-bottom:12px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc}
        .ignore-drop{min-height:520px;border:1px dashed #cbd5e1;border-radius:8px;background:#fff;padding:12px;display:flex;flex-wrap:wrap;gap:7px;align-content:flex-start}
        .ignore-drop.available{max-height:70vh;overflow:auto;background:#f8fafc}
        .ignore-drop.drag-over{background:#eff6ff;outline:2px dashed #2563eb;outline-offset:-5px}
        .ignore-pill{border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:8px;padding:6px 8px;display:grid;grid-template-columns:auto 1fr auto;gap:3px 6px;align-items:center;max-width:100%;cursor:grab;user-select:none;font-size:11px;line-height:1.2}
        .ignore-pill:hover{border-color:#2563eb;background:#dbeafe}
        .ignore-pill.is-ignored{border-color:#fecaca;background:#fef2f2;color:#7f1d1d}
        .ignore-pill.dragging{opacity:.45}
        .pill-main{font-weight:900;white-space:nowrap}
        .pill-title{grid-column:1 / -1;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155}
        .pill-meta{color:#475569;white-space:nowrap}
        .pill-remove{grid-row:1;border:0;background:#991b1b;color:#fff;border-radius:999px;width:18px;height:18px;line-height:18px;padding:0;font-size:12px;font-weight:900;cursor:pointer}
        .empty-drop{width:100%;border:1px dashed #d1d5db;border-radius:8px;background:#fafafa;color:#9ca3af;text-align:center;padding:32px 8px;font-size:12px}
        .palette-tools{display:flex;gap:8px;align-items:end;margin-bottom:10px}
        .palette-tools>div{flex:1}
        @media (max-width: 1000px){.ignore-layout{grid-template-columns:1fr}.ignore-drop.available{max-height:none}.ignore-filters{grid-template-columns:1fr 1fr}}
        @media (max-width: 620px){.ignore-filters{grid-template-columns:1fr}}
    </style>
</head>
<body>
@include('scheduler.partials.nav')

<div class="wrap">
    <h1>Ignored Courses</h1>
    @if(session('status')) <div class="status">{{ session('status') }}</div> @endif
    @if($errors->any())
        <div class="warn">{{ $errors->first() }}</div>
    @endif

    @if(!$selectedRule)
        <div class="warn">Create an exam session first.</div>
    @else
        <div class="panel">
            <div class="ignore-head">
                <div>
                    <strong>Session:</strong> #{{ $selectedRule->id }} {{ $selectedRule->session_code }}
                    {{ $selectedRule->exam_title ?: $selectedRule->session_label }}
                    <div class="muted">Ignored offerings are excluded when the datesheet is generated.</div>
                </div>
                <div class="ignore-stats">
                    <div class="ignore-stat"><strong>{{ $ignoreBoard['offerings_count'] ?? 0 }}</strong><br><span class="muted">Offerings</span></div>
                    <div class="ignore-stat"><strong id="statIgnored">0</strong><br><span class="muted">Ignored</span></div>
                    <div class="ignore-stat"><strong id="statAvailable">0</strong><br><span class="muted">Available</span></div>
                </div>
            </div>
        </div>

        <form id="ignoreSaveForm" method="post" action="{{ route('scheduler.ignore-courses.store') }}">
            @csrf
            <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
            <input id="ignoredCoursesJson" type="hidden" name="ignored_courses_json" value="[]">

            <div class="panel">
                <div class="ignore-actions">
                    <button class="btn green" type="submit">Save ignored courses</button>
                    <button class="btn secondary" type="button" id="clearIgnoredBtn">Move all back</button>
                    <a class="btn secondary" href="{{ route('scheduler.index', ['rule_id' => $selectedRule->id]) }}">Back to setup</a>
                </div>
            </div>

            <div class="ignore-layout">
                <div class="panel">
                    <h2>Ignored Courses</h2>
                    <div class="ignore-filters">
                        <div>
                            <label for="ignoredFilterCode">Code</label>
                            <input id="ignoredFilterCode" type="text" placeholder="CSC101">
                        </div>
                        <div>
                            <label for="ignoredFilterTitle">Title</label>
                            <input id="ignoredFilterTitle" type="text" placeholder="Database">
                        </div>
                        <div>
                            <label for="ignoredFilterTeacher">Teacher</label>
                            <input id="ignoredFilterTeacher" type="text" placeholder="Instructor">
                        </div>
                        <div>
                            <label for="ignoredFilterProgram">Program</label>
                            <input id="ignoredFilterProgram" type="text" placeholder="BCS">
                        </div>
                        <button class="btn secondary" type="button" id="clearIgnoredFiltersBtn">Clear</button>
                    </div>
                    <div id="ignoredDrop" class="ignore-drop" data-drop-zone="ignored"></div>
                </div>

                <div class="panel">
                    <h2>Course Pills</h2>
                    <div class="palette-tools">
                        <div>
                            <label for="availableSearch">Search</label>
                            <input id="availableSearch" type="text" placeholder="code, title, teacher, program">
                        </div>
                    </div>
                    <div id="availableDrop" class="ignore-drop available" data-drop-zone="available"></div>
                </div>
            </div>
        </form>
    @endif
</div>

@if($selectedRule)
<script>
const boot = @json($ignoreBoard);
let ignored = boot.ignored || [];
let available = boot.available || [];
let draggedKey = null;

const ignoredEl = document.getElementById('ignoredDrop');
const availableEl = document.getElementById('availableDrop');
const availableSearchEl = document.getElementById('availableSearch');
const saveInput = document.getElementById('ignoredCoursesJson');
const ignoredFilterEls = {
    code: document.getElementById('ignoredFilterCode'),
    title: document.getElementById('ignoredFilterTitle'),
    teacher: document.getElementById('ignoredFilterTeacher'),
    program: document.getElementById('ignoredFilterProgram'),
};

function html(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[char]));
}

function pillMarkup(offering, isIgnored) {
    const title = offering.course_title || '';
    const teacher = offering.instructor_name || 'TBD';
    const classText = [offering.class_label, offering.strength ? `${offering.strength} students` : ''].filter(Boolean).join(' | ');
    return `
        <div class="ignore-pill ${isIgnored ? 'is-ignored' : ''}" draggable="true" data-key="${html(offering.key)}" title="${html(offering.display)}">
            <span class="pill-main">${html(offering.course_code)}</span>
            <span class="pill-title">${html(title)}</span>
            <span class="pill-meta">${html(teacher)}</span>
            ${classText ? `<span class="pill-meta">${html(classText)}</span>` : ''}
            ${isIgnored ? '<button class="pill-remove" type="button" data-remove-pill="1">x</button>' : ''}
        </div>
    `;
}

function emptyMarkup(text) {
    return `<div class="empty-drop">${html(text)}</div>`;
}

function render() {
    const visibleIgnored = ignored.filter(matchesIgnoredFilters);
    ignoredEl.innerHTML = visibleIgnored.length
        ? visibleIgnored.map(offering => pillMarkup(offering, true)).join('')
        : emptyMarkup(ignored.length ? 'No ignored courses match the filters' : 'Drop courses here to ignore them');

    const needle = availableSearchEl.value.trim().toLowerCase();
    const visibleAvailable = needle === ''
        ? available
        : available.filter(offering => String(offering.display || '').toLowerCase().includes(needle));

    availableEl.innerHTML = visibleAvailable.length
        ? visibleAvailable.map(offering => pillMarkup(offering, false)).join('')
        : emptyMarkup(available.length ? 'No matching courses' : 'No available courses');

    document.getElementById('statIgnored').textContent = visibleIgnored.length === ignored.length
        ? ignored.length
        : `${visibleIgnored.length}/${ignored.length}`;
    document.getElementById('statAvailable').textContent = available.length;
    syncSaveInput();
}

function matchesIgnoredFilters(offering) {
    const code = ignoredFilterEls.code.value.trim().toLowerCase();
    const title = ignoredFilterEls.title.value.trim().toLowerCase();
    const teacher = ignoredFilterEls.teacher.value.trim().toLowerCase();
    const program = ignoredFilterEls.program.value.trim().toLowerCase();

    const courseCode = String(offering.course_code || '').toLowerCase();
    const courseTitle = String(offering.course_title || '').toLowerCase();
    const instructor = String(offering.instructor_name || '').toLowerCase();
    const programCode = String(offering.program_code || '').toLowerCase();
    const classLabel = String(offering.class_label || '').toLowerCase();

    return (!code || courseCode.includes(code))
        && (!title || courseTitle.includes(title))
        && (!teacher || instructor.includes(teacher))
        && (!program || programCode.includes(program) || classLabel.includes(program));
}

function syncSaveInput() {
    saveInput.value = JSON.stringify(ignored.map(offering => offering.member));
}

function pullOffering(key) {
    let index = ignored.findIndex(offering => offering.key === key);
    if (index >= 0) {
        return ignored.splice(index, 1)[0];
    }

    index = available.findIndex(offering => offering.key === key);
    if (index >= 0) {
        return available.splice(index, 1)[0];
    }

    return null;
}

function moveOffering(key, targetZone) {
    const offering = pullOffering(key);
    if (!offering) {
        return;
    }

    if (targetZone === 'ignored') {
        ignored.push(offering);
    } else {
        available.push(offering);
    }

    sortLists();
    render();
}

function sortLists() {
    const sorter = (left, right) => String(left.course_code).localeCompare(String(right.course_code))
        || String(left.class_label).localeCompare(String(right.class_label))
        || String(left.instructor_name).localeCompare(String(right.instructor_name));
    ignored.sort(sorter);
    available.sort(sorter);
}

document.addEventListener('dragstart', event => {
    const pill = event.target.closest('.ignore-pill');
    if (!pill) {
        return;
    }

    draggedKey = pill.dataset.key;
    event.dataTransfer.setData('text/plain', draggedKey);
    event.dataTransfer.effectAllowed = 'move';
    pill.classList.add('dragging');
});

document.addEventListener('dragend', event => {
    const pill = event.target.closest('.ignore-pill');
    if (pill) {
        pill.classList.remove('dragging');
    }
    draggedKey = null;
});

document.addEventListener('dragover', event => {
    const zone = event.target.closest('[data-drop-zone]');
    if (!zone) {
        return;
    }
    event.preventDefault();
    zone.classList.add('drag-over');
});

document.addEventListener('dragleave', event => {
    const zone = event.target.closest('[data-drop-zone]');
    if (zone && !zone.contains(event.relatedTarget)) {
        zone.classList.remove('drag-over');
    }
});

document.addEventListener('drop', event => {
    const zone = event.target.closest('[data-drop-zone]');
    if (!zone) {
        return;
    }

    event.preventDefault();
    zone.classList.remove('drag-over');
    const key = event.dataTransfer.getData('text/plain') || draggedKey;
    if (key) {
        moveOffering(key, zone.dataset.dropZone);
    }
});

document.addEventListener('click', event => {
    const removeButton = event.target.closest('[data-remove-pill]');
    if (removeButton) {
        const pill = removeButton.closest('.ignore-pill');
        moveOffering(pill.dataset.key, 'available');
    }
});

document.addEventListener('input', event => {
    if (event.target === availableSearchEl || Object.values(ignoredFilterEls).includes(event.target)) {
        render();
    }
});

document.getElementById('clearIgnoredFiltersBtn').addEventListener('click', () => {
    for (const input of Object.values(ignoredFilterEls)) {
        input.value = '';
    }
    render();
});

document.getElementById('clearIgnoredBtn').addEventListener('click', () => {
    available.push(...ignored);
    ignored = [];
    sortLists();
    render();
});

document.getElementById('ignoreSaveForm').addEventListener('submit', () => {
    syncSaveInput();
});

sortLists();
render();
</script>
@endif
</body>
</html>
