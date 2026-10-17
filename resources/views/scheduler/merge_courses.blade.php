<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Course Merge Builder</title>
    <style>
        .merge-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
        .merge-stats{display:flex;gap:8px;flex-wrap:wrap}
        .merge-stat{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;font-size:12px}
        .merge-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .merge-layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:14px;align-items:start}
        .block-filters{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr)) auto;gap:8px;align-items:end;margin-bottom:12px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc}
        .merge-blocks{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}
        .merge-block{border:1px solid #dbe4ef;border-radius:8px;background:#fff;box-shadow:0 1px 2px rgba(15,23,42,.06);overflow:hidden}
        .merge-block-head{display:flex;gap:8px;align-items:center;background:#f8fafc;border-bottom:1px solid #e5e7eb;padding:9px}
        .merge-block-head input{font-weight:800}
        .merge-count{font-size:11px;font-weight:900;color:#0f766e;background:#ccfbf1;border-radius:999px;padding:3px 7px;white-space:nowrap}
        .merge-drop{min-height:138px;padding:10px;display:flex;flex-wrap:wrap;gap:7px;align-content:flex-start}
        .merge-drop.drag-over{background:#eff6ff;outline:2px dashed #2563eb;outline-offset:-5px}
        .merge-palette{position:sticky;top:12px}
        .palette-tools{display:flex;gap:8px;align-items:end;margin-bottom:10px}
        .palette-tools>div{flex:1}
        .palette-drop{min-height:430px;max-height:68vh;overflow:auto;border:1px dashed #cbd5e1;border-radius:8px;background:#f8fafc;padding:10px;display:flex;flex-wrap:wrap;gap:7px;align-content:flex-start}
        .merge-pill{border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:8px;padding:6px 8px;display:grid;grid-template-columns:auto 1fr auto;gap:3px 6px;align-items:center;max-width:100%;cursor:grab;user-select:none;font-size:11px;line-height:1.2}
        .merge-pill:hover{border-color:#2563eb;background:#dbeafe}
        .merge-pill.dragging{opacity:.45}
        .pill-main{font-weight:900;white-space:nowrap}
        .pill-title{grid-column:1 / -1;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155}
        .pill-meta{color:#475569;white-space:nowrap}
        .pill-remove{grid-row:1;border:0;background:#1d4ed8;color:#fff;border-radius:999px;width:18px;height:18px;line-height:18px;padding:0;font-size:12px;font-weight:900;cursor:pointer}
        .empty-drop{width:100%;border:1px dashed #d1d5db;border-radius:8px;background:#fafafa;color:#9ca3af;text-align:center;padding:28px 8px;font-size:12px}
        .block-delete{border:0;background:#b91c1c;color:#fff;border-radius:8px;padding:7px 9px;font-size:12px;font-weight:800;cursor:pointer}
        @media (max-width: 1000px){.merge-layout{grid-template-columns:1fr}.merge-palette{position:static}.palette-drop{max-height:none}.block-filters{grid-template-columns:1fr 1fr}}
        @media (max-width: 620px){.block-filters{grid-template-columns:1fr}}
    </style>
</head>
<body>
@include('scheduler.partials.nav')

<div class="wrap">
    <h1>Course Merge Builder</h1>
    @if(session('status')) <div class="status">{{ session('status') }}</div> @endif
    @if($errors->any())
        <div class="warn">{{ $errors->first() }}</div>
    @endif

    @if(!$selectedRule)
        <div class="warn">Create an exam session first.</div>
    @else
        <div class="panel">
            <div class="merge-head">
                <div>
                    <strong>Session:</strong> #{{ $selectedRule->id }} {{ $selectedRule->session_code }}
                    {{ $selectedRule->exam_title ?: $selectedRule->session_label }}
                    @if($mergeBoard['draft_suggestions'] ?? false)
                        <div class="warn" style="margin-bottom:0">Suggested blocks are shown as a draft. Save the layout before generating the datesheet.</div>
                    @endif
                </div>
                <div class="merge-stats">
                    <div class="merge-stat"><strong id="statOfferings">{{ $mergeBoard['offerings_count'] ?? 0 }}</strong><br><span class="muted">Offerings</span></div>
                    <div class="merge-stat"><strong id="statBlocks">0</strong><br><span class="muted">Blocks</span></div>
                    <div class="merge-stat"><strong id="statUngrouped">0</strong><br><span class="muted">Ungrouped</span></div>
                </div>
            </div>
        </div>

        <form id="mergeSaveForm" method="post" action="{{ route('scheduler.merge-courses.store') }}">
            @csrf
            <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
            <input id="mergeGroupsJson" type="hidden" name="merge_groups_json" value="[]">

            <div class="panel">
                <div class="merge-actions">
                    <button class="btn" type="button" id="addBlockBtn">Add block</button>
                    <button class="btn secondary" type="button" id="suggestBlocksBtn">Create title/code + teacher blocks</button>
                    <button class="btn green" type="submit">Save merge blocks</button>
                    <a class="btn secondary" href="{{ route('scheduler.index', ['rule_id' => $selectedRule->id]) }}">Back to setup</a>
                </div>
            </div>

            <div class="merge-layout">
                <div class="panel">
                    <h2>Merge Blocks</h2>
                    <div class="block-filters">
                        <div>
                            <label for="blockFilterCode">Code</label>
                            <input id="blockFilterCode" type="text" placeholder="CSC101">
                        </div>
                        <div>
                            <label for="blockFilterTitle">Title</label>
                            <input id="blockFilterTitle" type="text" placeholder="Database">
                        </div>
                        <div>
                            <label for="blockFilterTeacher">Teacher</label>
                            <input id="blockFilterTeacher" type="text" placeholder="Instructor">
                        </div>
                        <div>
                            <label for="blockFilterProgram">Program</label>
                            <input id="blockFilterProgram" type="text" placeholder="BCS">
                        </div>
                        <div>
                            <label for="blockFilterLevel">Level</label>
                            <select id="blockFilterLevel">
                                <option value="">All levels</option>
                                <option value="BS">BS</option>
                                <option value="MS">MS</option>
                                <option value="PG">PG</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <button class="btn secondary" type="button" id="clearBlockFiltersBtn">Clear</button>
                    </div>
                    <div id="mergeBlocks" class="merge-blocks"></div>
                </div>

                <div class="panel merge-palette">
                    <h2>Course Pills</h2>
                    <div class="palette-tools">
                        <div>
                            <label for="courseSearch">Search</label>
                            <input id="courseSearch" type="text" placeholder="code, title, teacher">
                        </div>
                        <div>
                            <label for="courseLevelFilter">Level</label>
                            <select id="courseLevelFilter">
                                <option value="">All levels</option>
                                <option value="BS">BS</option>
                                <option value="MS">MS</option>
                                <option value="PG">PG</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div id="paletteDrop" class="palette-drop" data-drop-zone="palette"></div>
                </div>
            </div>
        </form>
    @endif
</div>

@if($selectedRule)
<script>
const boot = @json($mergeBoard);
let blocks = (boot.groups || []).map((block, index) => ({
    id: block.id || `group-${index + 1}`,
    label: block.label || `Group ${index + 1}`,
    members: block.members || [],
}));
let palette = boot.ungrouped || [];
let draggedKey = null;
let customBlockCounter = blocks.length;

const blocksEl = document.getElementById('mergeBlocks');
const paletteEl = document.getElementById('paletteDrop');
const searchEl = document.getElementById('courseSearch');
const levelFilterEl = document.getElementById('courseLevelFilter');
const saveInput = document.getElementById('mergeGroupsJson');
const blockFilterEls = {
    code: document.getElementById('blockFilterCode'),
    title: document.getElementById('blockFilterTitle'),
    teacher: document.getElementById('blockFilterTeacher'),
    program: document.getElementById('blockFilterProgram'),
    level: document.getElementById('blockFilterLevel'),
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

function pillMarkup(offering, removable) {
    const title = offering.course_title || '';
    const teacher = offering.instructor_name || 'TBD';
    const classText = [offering.class_label, offering.strength ? `${offering.strength} students` : ''].filter(Boolean).join(' | ');
    const levelText = offering.level || 'Other';
    return `
        <div class="merge-pill" draggable="true" data-key="${html(offering.key)}" title="${html(offering.display)}">
            <span class="pill-main">${html(offering.course_code)}</span>
            <span class="pill-meta">${html(levelText)}</span>
            <span class="pill-title">${html(title)}</span>
            <span class="pill-meta">${html(teacher)}</span>
            ${classText ? `<span class="pill-meta">${html(classText)}</span>` : ''}
            ${removable ? '<button class="pill-remove" type="button" data-remove-pill="1">x</button>' : ''}
        </div>
    `;
}

function emptyMarkup(text) {
    return `<div class="empty-drop">${html(text)}</div>`;
}

function render() {
    const visibleBlocks = blocks.filter(blockMatchesFilters);

    blocksEl.innerHTML = visibleBlocks.map(block => `
        <section class="merge-block" data-block-id="${html(block.id)}">
            <div class="merge-block-head">
                <input value="${html(block.label)}" data-label-input="${html(block.id)}">
                <span class="merge-count">${block.members.length}</span>
                <button class="block-delete" type="button" data-delete-block="${html(block.id)}">Delete</button>
            </div>
            <div class="merge-drop" data-drop-zone="block" data-block-id="${html(block.id)}">
                ${block.members.length ? block.members.map(offering => pillMarkup(offering, true)).join('') : emptyMarkup('Drop courses here')}
            </div>
        </section>
    `).join('') || emptyMarkup(blocks.length ? 'No merge blocks match the filters' : 'No merge blocks');

    const needle = searchEl.value.trim().toLowerCase();
    const selectedLevel = levelFilterEl.value;
    const visiblePalette = palette.filter(offering => {
        const matchesSearch = needle === '' || String(offering.display || '').toLowerCase().includes(needle);
        const matchesLevel = selectedLevel === '' || String(offering.level || 'Other') === selectedLevel;
        return matchesSearch && matchesLevel;
    });

    paletteEl.innerHTML = visiblePalette.length
        ? visiblePalette.map(offering => pillMarkup(offering, false)).join('')
        : emptyMarkup(palette.length ? 'No matching courses' : 'No ungrouped courses');

    document.getElementById('statBlocks').textContent = visibleBlocks.length === blocks.length
        ? blocks.length
        : `${visibleBlocks.length}/${blocks.length}`;
    document.getElementById('statUngrouped').textContent = palette.length;
    syncSaveInput();
}

function blockMatchesFilters(block) {
    const code = blockFilterEls.code.value.trim().toLowerCase();
    const title = blockFilterEls.title.value.trim().toLowerCase();
    const teacher = blockFilterEls.teacher.value.trim().toLowerCase();
    const program = blockFilterEls.program.value.trim().toLowerCase();
    const level = blockFilterEls.level.value;

    if (!code && !title && !teacher && !program && !level) {
        return true;
    }

    if (block.members.length === 0) {
        return true;
    }

    return block.members.some(offering => {
        const courseCode = String(offering.course_code || '').toLowerCase();
        const courseTitle = String(offering.course_title || '').toLowerCase();
        const instructor = String(offering.instructor_name || '').toLowerCase();
        const programCode = String(offering.program_code || '').toLowerCase();
        const classLabel = String(offering.class_label || '').toLowerCase();
        const offeringLevel = String(offering.level || 'Other');

        return (!code || courseCode.includes(code))
            && (!title || courseTitle.includes(title))
            && (!teacher || instructor.includes(teacher))
            && (!program || programCode.includes(program) || classLabel.includes(program))
            && (!level || offeringLevel === level);
    });
}

function syncSaveInput() {
    saveInput.value = JSON.stringify(blocks.map(block => ({
        label: block.label,
        members: block.members.map(offering => offering.member),
    })));
}

function pullOffering(key) {
    const paletteIndex = palette.findIndex(offering => offering.key === key);
    if (paletteIndex >= 0) {
        return palette.splice(paletteIndex, 1)[0];
    }

    for (const block of blocks) {
        const memberIndex = block.members.findIndex(offering => offering.key === key);
        if (memberIndex >= 0) {
            return block.members.splice(memberIndex, 1)[0];
        }
    }

    return null;
}

function moveOffering(key, targetBlockId) {
    const offering = pullOffering(key);
    if (!offering) {
        return;
    }

    if (targetBlockId === 'palette') {
        palette.push(offering);
    } else {
        const block = blocks.find(item => item.id === targetBlockId);
        if (block) {
            block.members.push(offering);
        } else {
            palette.push(offering);
        }
    }

    sortPalette();
    render();
}

function sortPalette() {
    palette.sort((left, right) => String(left.course_code).localeCompare(String(right.course_code))
        || String(left.class_label).localeCompare(String(right.class_label))
        || String(left.instructor_name).localeCompare(String(right.instructor_name)));
}

document.addEventListener('dragstart', event => {
    const pill = event.target.closest('.merge-pill');
    if (!pill) {
        return;
    }

    draggedKey = pill.dataset.key;
    event.dataTransfer.setData('text/plain', draggedKey);
    event.dataTransfer.effectAllowed = 'move';
    pill.classList.add('dragging');
});

document.addEventListener('dragend', event => {
    const pill = event.target.closest('.merge-pill');
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
    if (!key) {
        return;
    }

    moveOffering(key, zone.dataset.dropZone === 'palette' ? 'palette' : zone.dataset.blockId);
});

document.addEventListener('click', event => {
    const removeButton = event.target.closest('[data-remove-pill]');
    if (removeButton) {
        const pill = removeButton.closest('.merge-pill');
        moveOffering(pill.dataset.key, 'palette');
        return;
    }

    const deleteButton = event.target.closest('[data-delete-block]');
    if (deleteButton) {
        const blockIndex = blocks.findIndex(block => block.id === deleteButton.dataset.deleteBlock);
        if (blockIndex >= 0) {
            palette.push(...blocks[blockIndex].members);
            blocks.splice(blockIndex, 1);
            sortPalette();
            render();
        }
    }
});

document.addEventListener('input', event => {
    const labelInput = event.target.closest('[data-label-input]');
    if (labelInput) {
        const block = blocks.find(item => item.id === labelInput.dataset.labelInput);
        if (block) {
            block.label = labelInput.value;
            syncSaveInput();
        }
        return;
    }

    if (event.target === searchEl || event.target === levelFilterEl) {
        render();
        return;
    }

    if (Object.values(blockFilterEls).includes(event.target)) {
        render();
    }
});

document.getElementById('clearBlockFiltersBtn').addEventListener('click', () => {
    for (const input of Object.values(blockFilterEls)) {
        input.value = '';
    }
    render();
});

document.getElementById('addBlockBtn').addEventListener('click', () => {
    const blockId = `custom-${Date.now()}-${++customBlockCounter}`;

    blocks.push({
        id: blockId,
        label: `Group ${blocks.length + 1}`,
        members: [],
    });
    render();

    const labelInput = blocksEl.querySelector(`[data-label-input="${blockId}"]`);
    if (labelInput) {
        labelInput.focus();
        labelInput.select();
    }
});

function suggestionKey(offering) {
    const code = String(offering.course_code || '').trim().toUpperCase();
    const title = String(offering.course_title || '').trim().replace(/\s+/g, ' ').toUpperCase();
    const teacher = String(offering.instructor_name || '').trim().replace(/\s+/g, ' ').toUpperCase();
    if (Boolean(offering.is_postgraduate)) {
        return `PG_TEACHER|${teacher}`;
    }
    return title ? `TITLE|${title}|${teacher}` : `CODE|${code}|${teacher}`;
}

function suggestionLabel(offerings) {
    const codes = [...new Set(offerings.map(offering => String(offering.course_code || '').trim()).filter(Boolean))];
    const isPgGroup = offerings.length > 0 && offerings.every(offering => Boolean(offering.is_postgraduate));
    const title = String(offerings.find(offering => String(offering.course_title || '').trim())?.course_title || '').trim();
    const teacher = String(offerings.find(offering => String(offering.instructor_name || '').trim())?.instructor_name || '').trim();
    return [isPgGroup ? 'PG' : '', codes.join('/'), isPgGroup ? '' : title, teacher].filter(Boolean).join(' - ');
}

document.getElementById('suggestBlocksBtn').addEventListener('click', () => {
    const byCodeAndTeacher = {};
    for (const offering of palette) {
        const key = suggestionKey(offering);
        byCodeAndTeacher[key] = byCodeAndTeacher[key] || [];
        byCodeAndTeacher[key].push(offering);
    }

    for (const [groupKey, offerings] of Object.entries(byCodeAndTeacher)) {
        if (offerings.length < 2) {
            continue;
        }
        const keys = new Set(offerings.map(offering => offering.key));
        palette = palette.filter(offering => !keys.has(offering.key));
        blocks.push({
            id: `code-teacher-${groupKey.replace(/[^A-Z0-9]+/gi, '-')}-${Date.now()}-${blocks.length}`,
            label: suggestionLabel(offerings),
            members: offerings,
        });
    }

    sortPalette();
    render();
});

document.getElementById('mergeSaveForm').addEventListener('submit', () => {
    syncSaveInput();
});

sortPalette();
render();
</script>
@endif
</body>
</html>
