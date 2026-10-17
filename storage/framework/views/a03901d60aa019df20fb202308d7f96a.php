<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Datesheet Board</title>
    <style>
        .board-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}
        .slot-time-panel{display:grid;grid-template-columns:1.1fr .8fr .8fr 1fr .9fr auto;gap:8px;align-items:end;margin-top:12px;padding:12px;border:1px solid #e5e7eb;border-radius:12px;background:#f8fafc}
        .slot-time-panel label{display:block;font-size:11px;font-weight:900;color:#334155;margin-bottom:4px}
        .slot-time-panel input,.slot-time-panel select{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:9px;padding:8px;background:#fff;font-size:13px}
        .filter-panel{display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-top:12px;padding:12px;border:1px solid #e5e7eb;border-radius:12px;background:#f8fafc}
        .filter-field{display:flex;flex-direction:column;gap:4px;min-width:150px}
        .filter-field.wide{min-width:240px;flex:1}
        .filter-field label{font-size:11px;font-weight:900;color:#334155}
        .filter-field input,.filter-field select{border:1px solid #cbd5e1;border-radius:9px;padding:8px;background:#fff;font-size:13px}
        .filter-inline{display:flex;gap:7px;align-items:center;font-size:12px;font-weight:800;color:#334155;padding-bottom:8px}
        .filter-stats{font-size:12px;color:#475569;font-weight:800;padding-bottom:8px}
        .filter-hidden{display:none!important}
        .filter-empty-note{font-size:12px;color:#9ca3af;text-align:center;padding-top:36px;border:1px dashed #e5e7eb;border-radius:10px;min-height:54px;background:#fafafa;display:none}
        .board-scroll{overflow:auto;max-height:76vh;border:1px solid #e5e7eb;border-radius:14px;background:#fff;padding:0}
        .datesheet-board{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%}
        .datesheet-board th,.datesheet-board td{border:0;border-right:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;padding:0;vertical-align:top;background:#fff}
        .datesheet-board thead th{position:sticky;top:0;background:#111827;color:#fff;z-index:6;text-align:center;padding:10px;min-width:300px;box-shadow:0 2px 0 rgba(0,0,0,.08)}
        .datesheet-board thead th.date-sticky{left:0;z-index:8;min-width:132px;width:132px}
        .datesheet-board tbody th.date-sticky{position:sticky;left:0;z-index:5;background:#f3f4f6;min-width:132px;width:132px;padding:12px 10px;font-size:13px;font-weight:900;color:#111827;box-shadow:2px 0 0 rgba(0,0,0,.04)}
        .datesheet-board tbody tr:nth-child(even) td{background:#fbfdff}
        .slot-cell{box-sizing:border-box;width:300px;min-height:178px;background:transparent;padding:8px;overflow:visible}
        .slot-cell.empty{background:linear-gradient(135deg,#f9fafb,#ffffff)}
        .slot-head{display:flex;justify-content:space-between;align-items:center;font-size:11px;font-weight:900;color:#374151;margin-bottom:8px;gap:8px}
        .slot-count{background:#eef2ff;color:#3730a3;border-radius:999px;padding:2px 7px;font-size:10px;white-space:nowrap}
        .cards{display:flex;flex-direction:column;gap:8px;min-height:118px}
        .course-card{box-sizing:border-box;width:100%;background:#fff;border:1px solid #93c5fd;border-left:5px solid #2563eb;border-radius:12px;padding:9px;box-shadow:0 1px 3px rgba(15,23,42,.10);cursor:pointer;user-select:none;-webkit-user-drag:none;overflow:hidden;word-break:break-word;text-align:left}
        .course-card:hover{background:#eff6ff;border-color:#2563eb;box-shadow:0 4px 12px rgba(37,99,235,.18)}
        .course-card:focus{outline:3px solid rgba(37,99,235,.25)}
        .course-card.has-class-conflict{background:#fff7ed;border-color:#fb923c;border-left-color:#ea580c;box-shadow:0 1px 5px rgba(234,88,12,.20)}
        .course-card.has-class-conflict:hover{background:#ffedd5;border-color:#f97316;box-shadow:0 4px 14px rgba(234,88,12,.24)}
        .course-card.has-high-class-conflict{background:#fef2f2;border-color:#ef4444;border-left-color:#dc2626;box-shadow:0 1px 6px rgba(220,38,38,.24)}
        .course-card.has-high-class-conflict:hover{background:#fee2e2;border-color:#dc2626;box-shadow:0 4px 16px rgba(220,38,38,.28)}
        .course-card.has-high-class-conflict .conflict-pill{background:#fecaca;color:#991b1b}
        .course-card.has-high-class-conflict .report-link{background:#dc2626}
        .course-card.has-high-class-conflict .report-link:hover{background:#991b1b}
        .course-title{font-weight:900;font-size:13px;line-height:1.25;color:#111827}
        .course-subtitle{font-size:11px;color:#475569;margin-top:2px;line-height:1.25;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
        .pill{display:inline-block;background:#e0f2fe;border-radius:999px;padding:2px 7px;font-size:10px;margin:3px 3px 0 0;color:#075985;font-weight:700}
        .conflict-pill{display:inline-block;background:#fed7aa;color:#9a3412;border-radius:999px;padding:2px 7px;font-size:10px;margin:5px 3px 0 0;font-weight:900}
        .report-link{display:inline-block;background:#ea580c;color:#fff;border-radius:8px;padding:4px 7px;font-size:10px;margin:5px 3px 0 0;font-weight:900;text-decoration:none}
        .report-link:hover{background:#c2410c}
        .strength{font-weight:900;color:#065f46}
        .empty-note{font-size:12px;color:#9ca3af;text-align:center;padding-top:36px;border:1px dashed #e5e7eb;border-radius:10px;min-height:54px;background:#fafafa}
        .ajax-status{position:fixed;right:16px;bottom:16px;background:#111827;color:#fff;padding:10px 14px;border-radius:8px;display:none;max-width:560px;z-index:100}
        .modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);display:none;z-index:90;align-items:center;justify-content:center;padding:18px}
        .modal{width:min(980px,96vw);max-height:92vh;overflow:auto;background:#fff;border-radius:16px;box-shadow:0 20px 70px rgba(0,0,0,.35)}
        .modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid #e5e7eb;background:#f8fafc;border-radius:16px 16px 0 0}
        .modal-title{font-size:18px;font-weight:900;color:#111827;margin:0}
        .modal-body{padding:16px 18px}
        .modal-tabs{display:flex;gap:8px;border-bottom:1px solid #e5e7eb;margin:-2px 0 14px 0}
        .modal-tab{border:0;background:#e5e7eb;color:#334155;border-radius:9px 9px 0 0;padding:9px 12px;font-weight:900;cursor:pointer}
        .modal-tab.active{background:#2563eb;color:#fff}
        .tab-panel{display:none}
        .tab-panel.active{display:block}
        .modal-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:14px}
        .modal-section{border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff}
        .modal-section h3{margin:0 0 8px 0;font-size:14px;color:#111827}
        .close-btn{border:0;background:#111827;color:#fff;border-radius:9px;padding:7px 11px;font-weight:800;cursor:pointer}
        .constraint{border-left:4px solid #94a3b8;padding:7px 9px;background:#f8fafc;border-radius:8px;margin:6px 0;font-size:12px;color:#334155}
        .constraint.active{border-left-color:#2563eb;background:#eff6ff}
        .constraint.warning{border-left-color:#f59e0b;background:#fffbeb}
        .selection-tools{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0 0 8px 0;font-size:12px;color:#334155}
        .selection-tools label{font-weight:900;display:flex;gap:6px;align-items:center}
        .selection-tools input,.exam-row input{width:auto}
        .exam-row{display:grid;grid-template-columns:34px 90px 110px 1fr 150px 70px;gap:7px;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:12px;align-items:center}
        .exam-row.header{font-weight:900;color:#475569;background:#f8fafc;border-radius:8px;padding:7px}
        .reschedule-form{display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:end}
        .reschedule-form label{display:block;font-weight:800;font-size:12px;color:#334155;margin-bottom:4px}
        .reschedule-form select{width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:8px;background:#fff}
        .swap-form label{display:block;font-weight:800;font-size:12px;color:#334155;margin-bottom:4px}
        .swap-form select{width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:8px;background:#fff}
        .swap-summary{font-size:12px;color:#334155;background:#f8fafc;border:1px solid #e5e7eb;border-radius:9px;padding:8px;margin-top:8px}
        .modal-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
        .btn-small{border:0;border-radius:9px;padding:8px 12px;font-weight:900;cursor:pointer;background:#2563eb;color:#fff;text-decoration:none;display:inline-block}
        .btn-small.secondary{background:#475569}
        .btn-small.green{background:#059669}
        .btn-small:disabled{background:#94a3b8;cursor:not-allowed}
        .check-box{margin-top:12px;border-radius:12px;padding:10px;background:#f8fafc;border:1px solid #e5e7eb;display:none}
        .check-box.good{background:#ecfdf5;border-color:#86efac}
        .check-box.bad{background:#fef2f2;border-color:#fecaca}
        .check-box.warn{background:#fffbeb;border-color:#fde68a}
        .check-box ul{margin:6px 0 0 18px;padding:0}
        .conflict-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}
        .conflict-table th,.conflict-table td{border:1px solid #e5e7eb;padding:5px;text-align:left}
        .conflict-table th{background:#f8fafc}
        .clash-report-class{margin:0 0 14px 0}
        .clash-report-title{font-weight:900;color:#111827;margin:0 0 6px 0}
        @media (max-width: 900px){.datesheet-board thead th{min-width:250px}.slot-cell{width:250px}.datesheet-board thead th.date-sticky,.datesheet-board tbody th.date-sticky{min-width:112px;width:112px}.modal-grid{grid-template-columns:1fr}.exam-row{grid-template-columns:1fr}.exam-row.header{display:none}.slot-time-panel{grid-template-columns:1fr}}
    </style>
</head>
<body>
<?php echo $__env->make('scheduler.partials.nav', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>

<div class="wrap">
    <h1>Datesheet Board</h1>
    <?php if(session('status')): ?> <div class="status"><?php echo e(session('status')); ?></div> <?php endif; ?>
    <?php if(!$selectedRule): ?>
        <div class="warn">Create an exam session first.</div>
    <?php else: ?>
        <?php
            $filterCards = collect($datesheetBoard['cells'] ?? [])->flatMap(fn ($cards) => $cards);
            $filterClasses = $filterCards->flatMap(fn ($card) => $card['classes'] ?? [])->filter()->unique()->sort()->values();
            $filterPrograms = $filterCards->flatMap(fn ($card) => $card['programs'] ?? [])->filter()->unique()->sort()->values();
            $filterCourseCodes = $filterCards
                ->flatMap(fn ($card) => $card['course_codes'] ?? array_filter(array_map('trim', explode(',', (string) ($card['course_code'] ?? '')))))
                ->filter()
                ->unique()
                ->sort()
                ->values();
        ?>
        <div class="panel">
            <strong>Session:</strong> #<?php echo e($selectedRule->id); ?> <?php echo e($selectedRule->session_code); ?> —
            <?php echo e($selectedRule->exam_title ?: $selectedRule->session_label); ?>

            <div class="muted">Click any paper to open the reschedule modal. Every move is checked against same-course grouping, preferred dates, capacity, and student-clash rules before saving.</div>
            <div class="board-actions">
                <form method="post" action="<?php echo e(route('scheduler.allocate-all')); ?>" style="display:inline-block;margin:0" onsubmit="return confirm('Allocate rooms for every generated datesheet slot?')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="rule_id" value="<?php echo e($selectedRule->id); ?>">
                    <button class="btn" type="submit">Allocate rooms for all slots</button>
                </form>
                <form method="post" action="<?php echo e(route('scheduler.seating-all')); ?>" style="display:inline-block;margin:0" onsubmit="return confirm('Generate seat plans for every generated datesheet slot? Run all-slot room allocation first.')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="rule_id" value="<?php echo e($selectedRule->id); ?>">
                    <button class="btn green" type="submit">Generate seating for all slots</button>
                </form>
                <a class="btn secondary" href="<?php echo e(route('scheduler.export-operations', ['rule_id' => $selectedRule->id, 'type' => 'datesheet_board'])); ?>">Export board Excel</a>
                <a class="btn green" href="<?php echo e(route('scheduler.export-operations', ['rule_id' => $selectedRule->id, 'type' => 'datesheet_final'])); ?>">Export final date sheet with rooms</a>
            </div>
            <form class="slot-time-panel" method="post" action="<?php echo e(route('scheduler.rename-slot')); ?>" onsubmit="return confirm('Update this slot time without regenerating the datesheet? Existing exam rows using the old slot label will be renamed to the new slot label.')">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="rule_id" value="<?php echo e($selectedRule->id); ?>">
                <div>
                    <label for="renameOldSlot">Current slot</label>
                    <select id="renameOldSlot" name="old_slot" onchange="fillRenameSlotFields()">
                        <?php $__currentLoopData = $datesheetBoard['slots']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $slot): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($slot['label']); ?>" data-start="<?php echo e($slot['start'] ?? ''); ?>" data-end="<?php echo e($slot['end'] ?? ''); ?>" data-type="<?php echo e($slot['type'] ?? 'AFTERNOON'); ?>"><?php echo e($slot['label']); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
                <div>
                    <label for="renameSlotStart">New start</label>
                    <input id="renameSlotStart" type="time" name="new_slot_start" required>
                </div>
                <div>
                    <label for="renameSlotEnd">New end</label>
                    <input id="renameSlotEnd" type="time" name="new_slot_end" required>
                </div>
                <div>
                    <label for="renameSlotLabel">New label</label>
                    <input id="renameSlotLabel" name="new_slot_label" placeholder="0900-1200">
                </div>
                <div>
                    <label for="renameSlotType">Type</label>
                    <select id="renameSlotType" name="new_slot_type">
                        <option value="MORNING">Morning / BS</option>
                        <option value="AFTERNOON">Afternoon / BS</option>
                        <option value="EVENING">Evening / MS-PHD</option>
                    </select>
                </div>
                <button class="btn secondary" type="submit">Update slot time</button>
            </form>
            <div class="filter-panel" id="datesheetFilters">
                <div class="filter-field">
                    <label for="filterClass">Class</label>
                    <input id="filterClass" type="text" list="classFilterOptions" placeholder="BCS-IV">
                    <datalist id="classFilterOptions">
                        <?php $__currentLoopData = $filterClasses; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $class): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($class); ?>"></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </datalist>
                </div>
                <div class="filter-field">
                    <label for="filterProgram">Program</label>
                    <select id="filterProgram">
                        <option value="">All programs</option>
                        <?php $__currentLoopData = $filterPrograms; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $program): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($program); ?>"><?php echo e($program); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="filterLevel">Level</label>
                    <select id="filterLevel">
                        <option value="">All levels</option>
                        <option value="undergraduate">Undergraduate</option>
                        <option value="postgraduate">Graduate / PG</option>
                        <option value="mixed">Mixed</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="filterCourse">Course Code</label>
                    <input id="filterCourse" type="text" list="courseFilterOptions" placeholder="CSC211">
                    <datalist id="courseFilterOptions">
                        <?php $__currentLoopData = $filterCourseCodes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $courseCode): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($courseCode); ?>"></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </datalist>
                </div>
                <div class="filter-field wide">
                    <label for="filterText">Search</label>
                    <input id="filterText" type="text" placeholder="course title, instructor, class, code">
                </div>
                <label class="filter-inline">
                    <input id="filterHighClashesOnly" type="checkbox">
                    Only clashes &gt; 10
                </label>
                <label class="filter-inline">
                    <input id="filterHideEmptyDates" type="checkbox">
                    Hide dates with no matches
                </label>
                <button class="btn secondary" type="button" onclick="resetDatesheetFilters()">Reset filters</button>
                <div id="filterStats" class="filter-stats"></div>
            </div>
        </div>

        <div class="board-scroll panel">
            <table class="datesheet-board">
                <thead>
                    <tr>
                        <th class="date-sticky">Date</th>
                        <?php $__currentLoopData = $datesheetBoard['slots']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $slot): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <th><?php echo e($slot['label']); ?></th>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $datesheetBoard['days']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $day): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <th class="date-sticky"><?php echo e($day); ?></th>
                            <?php $__currentLoopData = $datesheetBoard['slots']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $slot): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php
                                    $cellKey = $day . '|' . $slot['label'];
                                    $cards = $datesheetBoard['cells'][$cellKey] ?? [];
                                    $slotStrength = collect($cards)->sum(fn ($card) => (int) ($card['strength'] ?? 0));
                                ?>
                                <td>
                                    <div class="slot-cell <?php echo e(count($cards) === 0 ? 'empty' : ''); ?>" data-date="<?php echo e($day); ?>" data-slot="<?php echo e($slot['label']); ?>">
                                        <div class="slot-head">
                                            <span><?php echo e($slot['label']); ?></span>
                                            <span class="slot-count"><?php echo e(count($cards)); ?> paper<?php echo e(count($cards) === 1 ? '' : 's'); ?> / <?php echo e($slotStrength); ?> students</span>
                                        </div>
                                        <div class="cards">
                                            <?php $__empty_1 = true; $__currentLoopData = $cards; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $card): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                                                <button type="button" class="course-card <?php echo e(!empty($card['has_class_day_conflict']) ? 'has-class-conflict' : ''); ?> <?php echo e(!empty($card['has_high_class_day_conflict']) ? 'has-high-class-conflict' : ''); ?>" draggable="false"
                                                     ondragstart="return false"
                                                     onclick="event.preventDefault(); openClusterModal(this); return false;"
                                                     data-cluster-key="<?php echo e($card['cluster_key']); ?>"
                                                     data-exam-ids="<?php echo e(implode('|', $card['exam_ids'] ?? [])); ?>"
                                                     data-date="<?php echo e($day); ?>"
                                                     data-slot="<?php echo e($slot['label']); ?>"
                                                     data-course-codes="<?php echo e(implode('|', $card['course_codes'] ?? array_filter(array_map('trim', explode(',', (string) ($card['course_code'] ?? '')))))); ?>"
                                                     data-classes="<?php echo e(implode('|', $card['classes'] ?? [])); ?>"
                                                     data-programs="<?php echo e(implode('|', $card['programs'] ?? [])); ?>"
                                                     data-level="<?php echo e($card['academic_level'] ?? ''); ?>"
                                                     data-strength="<?php echo e((int) ($card['strength'] ?? 0)); ?>"
                                                     data-class-day-conflict="<?php echo e(!empty($card['has_class_day_conflict']) ? '1' : '0'); ?>"
                                                     data-high-class-day-conflict="<?php echo e(!empty($card['has_high_class_day_conflict']) ? '1' : '0'); ?>"
                                                     data-max-class-day-conflict="<?php echo e((int) ($card['max_class_day_conflict_count'] ?? 0)); ?>"
                                                     data-conflict-classes="<?php echo e(implode('|', $card['class_day_conflict_classes'] ?? [])); ?>"
                                                     data-filter-text="<?php echo e(trim(($card['course_code'] ?? '') . ' ' . implode(' ', $card['course_codes'] ?? []) . ' ' . ($card['course_title'] ?? '') . ' ' . implode(' ', $card['classes'] ?? []) . ' ' . implode(' ', $card['programs'] ?? []) . ' ' . ($card['instructors_text'] ?? ''))); ?>">
                                                    <div class="course-title"><?php echo e($card['course_code'] ?: 'Course'); ?></div>
                                                    <?php if($card['course_title']): ?>
                                                        <div class="course-subtitle"><?php echo e($card['course_title']); ?></div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <?php $__currentLoopData = $card['classes']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $class): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                            <span class="pill"><?php echo e($class); ?></span>
                                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                                    </div>
                                                    <div class="muted"><?php echo e($card['exam_count']); ?> exam row(s) · <span class="strength"><?php echo e($card['strength']); ?></span> students</div>
                                                    <?php if(!empty($card['has_class_day_conflict'])): ?>
                                                        <div>
                                                            <span class="conflict-pill"><?php echo e($card['class_day_conflict_text'] ?? 'Student clash on this date'); ?></span>
                                                            <span class="report-link" role="button" tabindex="0" onclick="openClassClashReport(this, event)" onkeydown="if(event.key==='Enter'||event.key===' '){openClassClashReport(this,event)}">Clash report</span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if($card['instructors_text']): ?>
                                                        <div class="muted"><?php echo e($card['instructors_text']); ?></div>
                                                    <?php endif; ?>
                                                </button>
                                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                                                <div class="empty-note">Empty slot</div>
                                            <?php endif; ?>
                                            <div class="filter-empty-note">No matching papers</div>
                                        </div>
                                    </div>
                                </td>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div id="clashReportModal" class="modal-backdrop" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="clashReportTitle">
        <div class="modal-head">
            <div>
                <h2 id="clashReportTitle" class="modal-title">Class clash report</h2>
                <div id="clashReportSubtitle" class="muted"></div>
            </div>
            <button type="button" class="close-btn" onclick="closeClashReportModal()">Close</button>
        </div>
        <div class="modal-body">
            <div id="clashReportBody" class="muted">Loading clash report...</div>
        </div>
    </div>
</div>

<div id="rescheduleModal" class="modal-backdrop" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-head">
            <div>
                <h2 id="modalTitle" class="modal-title">Reschedule paper</h2>
                <div id="modalSubtitle" class="muted"></div>
            </div>
            <button type="button" class="close-btn" onclick="closeModal()">Close</button>
        </div>
        <div class="modal-body">
            <div class="modal-tabs" role="tablist">
                <button type="button" id="rescheduleTabBtn" class="modal-tab active" onclick="showModalTab('reschedule')">Reschedule</button>
                <button type="button" id="swapTabBtn" class="modal-tab" onclick="showModalTab('swap')">Swap</button>
            </div>
            <div id="rescheduleTabPanel" class="tab-panel active">
                <div class="modal-grid">
                    <div>
                        <div class="modal-section">
                            <h3>Linked exam rows</h3>
                            <div id="examRows"></div>
                        </div>
                        <div class="modal-section" style="margin-top:12px">
                            <h3>Constraints</h3>
                            <div id="constraintRows"></div>
                        </div>
                    </div>
                    <div>
                        <div class="modal-section">
                            <h3>Reschedule</h3>
                            <div class="reschedule-form">
                                <div>
                                    <label for="targetDate">Target date</label>
                                    <select id="targetDate"></select>
                                </div>
                                <div>
                                    <label for="targetSlot">Target slot</label>
                                    <select id="targetSlot"></select>
                                </div>
                            </div>
                            <div class="modal-actions">
                                <button type="button" class="btn-small secondary" onclick="checkReschedule()">Check constraints</button>
                                <button type="button" id="saveRescheduleBtn" class="btn-small green" onclick="saveReschedule()" disabled>Save reschedule</button>
                            </div>
                            <div id="checkResult" class="check-box"></div>
                        </div>
                        <div class="modal-section" style="margin-top:12px">
                            <h3>Student clashes</h3>
                            <div id="conflictRows" class="muted">Run check to see student-clash details.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="swapTabPanel" class="tab-panel">
                <div class="modal-grid">
                    <div>
                        <div class="modal-section">
                            <h3>First paper rows</h3>
                            <div id="swapLeftRows"></div>
                        </div>
                        <div class="modal-section" style="margin-top:12px">
                            <h3>Second paper rows</h3>
                            <div class="swap-form">
                                <label for="swapTargetSelect">Paper to swap with</label>
                                <select id="swapTargetSelect" onchange="loadSwapTarget()"></select>
                            </div>
                            <div id="swapTargetSummary" class="swap-summary">Choose a paper from the datesheet board.</div>
                            <div id="swapRightRows" style="margin-top:10px"></div>
                        </div>
                    </div>
                    <div>
                        <div class="modal-section">
                            <h3>Swap check</h3>
                            <div class="muted">The selected first rows move to the second paper's slot, and the selected second rows move to the first paper's slot.</div>
                            <div class="modal-actions">
                                <button type="button" class="btn-small secondary" onclick="checkSwap()">Check swap</button>
                                <button type="button" id="saveSwapBtn" class="btn-small green" onclick="saveSwap()" disabled>Save swap</button>
                            </div>
                            <div id="swapCheckResult" class="check-box"></div>
                        </div>
                        <div class="modal-section" style="margin-top:12px">
                            <h3>Swap clashes</h3>
                            <div id="swapConflictRows" class="muted">Run check to see student-clash details for both sides.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="ajaxStatus" class="ajax-status"></div>

<script>
const csrfToken = <?php echo json_encode(csrf_token(), 15, 512) ?>;
const ruleId = <?php echo json_encode($selectedRule?->id, 15, 512) ?>;
const detailsUrl = <?php echo json_encode(route('scheduler.api.cluster-details'), 15, 512) ?>;
const clashReportUrl = <?php echo json_encode(route('scheduler.api.class-day-clash-report'), 15, 512) ?>;
const checkUrl = <?php echo json_encode(route('scheduler.api.validate-cluster-move'), 15, 512) ?>;
const saveUrl = <?php echo json_encode(route('scheduler.api.reschedule-cluster'), 15, 512) ?>;
const swapCheckUrl = <?php echo json_encode(route('scheduler.api.validate-cluster-swap'), 15, 512) ?>;
const swapSaveUrl = <?php echo json_encode(route('scheduler.api.swap-clusters'), 15, 512) ?>;
let activeClusterKey = null;
let activeCard = null;
let activeCardExamIds = [];
let selectedExamIds = [];
let allModalExamIds = [];
let latestCheckOk = false;
let lastValidation = null;
let targetSlotsByDate = {};
let swapTargets = [];
let swapTargetCard = null;
let swapRightClusterKey = null;
let swapLeftExamIds = [];
let swapRightExamIds = [];
let swapLeftAllExamIds = [];
let swapRightAllExamIds = [];
let swapLeftClassesByExamId = {};
let latestSwapCheckOk = false;
let lastSwapValidation = null;

function normalizeFilterValue(value) {
    return String(value || '').trim().toLowerCase();
}

function tokenList(value) {
    return String(value || '')
        .split('|')
        .map(item => normalizeFilterValue(item))
        .filter(Boolean);
}

function cardStrength(card) {
    return parseInt(card?.dataset?.strength || '0', 10) || 0;
}

function slotBadgeText(visibleCount, totalCount, visibleStrength, totalStrength, active = false) {
    const paperWord = totalCount === 1 ? 'paper' : 'papers';
    if (active) {
        return visibleCount + ' / ' + totalCount + ' ' + paperWord + ' / ' + visibleStrength + ' / ' + totalStrength + ' students';
    }

    return totalCount + ' ' + paperWord + ' / ' + totalStrength + ' students';
}

function cardMatchesFilters(card) {
    const classFilter = normalizeFilterValue(document.getElementById('filterClass')?.value);
    const programFilter = normalizeFilterValue(document.getElementById('filterProgram')?.value);
    const levelFilter = normalizeFilterValue(document.getElementById('filterLevel')?.value);
    const courseFilter = normalizeFilterValue(document.getElementById('filterCourse')?.value);
    const textFilter = normalizeFilterValue(document.getElementById('filterText')?.value);
    const highClashesOnly = Boolean(document.getElementById('filterHighClashesOnly')?.checked);

    const classes = tokenList(card.dataset.classes);
    const programs = tokenList(card.dataset.programs);
    const courses = tokenList(card.dataset.courseCodes);
    const level = normalizeFilterValue(card.dataset.level);
    const text = normalizeFilterValue(card.dataset.filterText);

    if (highClashesOnly && card.dataset.highClassDayConflict !== '1') return false;
    if (classFilter && !classes.some(item => item.includes(classFilter))) return false;
    if (programFilter && !programs.includes(programFilter)) return false;
    if (levelFilter && level !== levelFilter) return false;
    if (courseFilter && !courses.some(item => item.includes(courseFilter))) return false;
    if (textFilter && !text.includes(textFilter)) return false;

    return true;
}

function filtersAreActive() {
    return ['filterClass', 'filterProgram', 'filterLevel', 'filterCourse', 'filterText']
        .some(id => normalizeFilterValue(document.getElementById(id)?.value))
        || Boolean(document.getElementById('filterHighClashesOnly')?.checked)
        || Boolean(document.getElementById('filterHideEmptyDates')?.checked);
}

function applyDatesheetFilters() {
    const active = filtersAreActive();
    const hideEmptyDates = Boolean(document.getElementById('filterHideEmptyDates')?.checked);
    let total = 0;
    let visible = 0;
    let totalStrength = 0;
    let visibleStrength = 0;

    document.querySelectorAll('.course-card').forEach(card => {
        total++;
        const strength = cardStrength(card);
        totalStrength += strength;
        const match = cardMatchesFilters(card);
        card.classList.toggle('filter-hidden', active && !match);
        if (!active || match) {
            visible++;
            visibleStrength += strength;
        }
    });

    document.querySelectorAll('.slot-cell').forEach(cell => {
        const cards = Array.from(cell.querySelectorAll('.course-card'));
        const visibleCards = cards.filter(card => !card.classList.contains('filter-hidden'));
        const totalStrength = cards.reduce((sum, card) => sum + cardStrength(card), 0);
        const visibleStrength = visibleCards.reduce((sum, card) => sum + cardStrength(card), 0);
        const filterNote = cell.querySelector('.filter-empty-note');
        const emptyNote = cell.querySelector('.empty-note');
        const counter = cell.querySelector('.slot-count');

        if (filterNote) {
            filterNote.style.display = active && cards.length > 0 && visibleCards.length === 0 ? 'block' : 'none';
        }
        if (emptyNote) {
            emptyNote.style.display = cards.length === 0 ? 'block' : 'none';
        }
        if (counter) {
            counter.textContent = slotBadgeText(visibleCards.length, cards.length, visibleStrength, totalStrength, active);
        }
    });

    document.querySelectorAll('.datesheet-board tbody tr').forEach(row => {
        const hasVisibleCard = Array.from(row.querySelectorAll('.course-card')).some(card => !card.classList.contains('filter-hidden'));
        row.classList.toggle('filter-hidden', active && hideEmptyDates && !hasVisibleCard);
    });

    const stats = document.getElementById('filterStats');
    if (stats) {
        stats.textContent = active
            ? visible + ' of ' + total + ' papers shown / ' + visibleStrength + ' of ' + totalStrength + ' students'
            : total + ' papers / ' + totalStrength + ' students';
    }
}

function resetDatesheetFilters() {
    for (const id of ['filterClass', 'filterProgram', 'filterLevel', 'filterCourse', 'filterText']) {
        const el = document.getElementById(id);
        if (el) el.value = '';
    }
    const hideEmpty = document.getElementById('filterHideEmptyDates');
    if (hideEmpty) hideEmpty.checked = false;
    const highClashes = document.getElementById('filterHighClashesOnly');
    if (highClashes) highClashes.checked = false;
    applyDatesheetFilters();
}

function compactTimeLabel(value) {
    return String(value || '').replace(':', '');
}

function fillRenameSlotFields() {
    const select = document.getElementById('renameOldSlot');
    const option = select?.selectedOptions?.[0];
    if (!option) return;

    const start = option.dataset.start || '';
    const end = option.dataset.end || '';
    const type = option.dataset.type || 'AFTERNOON';
    const startInput = document.getElementById('renameSlotStart');
    const endInput = document.getElementById('renameSlotEnd');
    const labelInput = document.getElementById('renameSlotLabel');
    const typeInput = document.getElementById('renameSlotType');

    if (startInput) startInput.value = start;
    if (endInput) endInput.value = end;
    if (labelInput) labelInput.value = start && end ? compactTimeLabel(start) + '-' + compactTimeLabel(end) : option.value;
    if (typeInput) typeInput.value = type;
}

function showStatus(message, isError = false) {
    const box = document.getElementById('ajaxStatus');
    box.textContent = message;
    box.style.background = isError ? '#991b1b' : '#111827';
    box.style.display = 'block';
    setTimeout(() => { box.style.display = 'none'; }, 5000);
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
    });
}

function normalizeExamIds(ids) {
    const seen = new Set();
    const normalized = [];
    for (const id of ids || []) {
        const value = parseInt(id, 10);
        if (!value || seen.has(value)) continue;
        seen.add(value);
        normalized.push(value);
    }
    return normalized;
}

function examIdsFromCard(card) {
    return normalizeExamIds(String(card?.dataset?.examIds || '').split('|'));
}

function selectedExamIdsFromRows() {
    return normalizeExamIds(Array.from(document.querySelectorAll('.exam-select:checked')).map(input => input.value));
}

function clusterDetailsUrlFor(clusterKey, examIds) {
    const params = new URLSearchParams();
    params.set('rule_id', ruleId);
    params.set('cluster_key', clusterKey);
    for (const id of normalizeExamIds(examIds)) {
        params.append('exam_ids[]', id);
    }
    return detailsUrl + '?' + params.toString();
}

function clusterDetailsRequestUrl(examIds) {
    return clusterDetailsUrlFor(activeClusterKey, examIds);
}

function reschedulePayload(targetDate, targetSlot, force = false) {
    return {
        rule_id: ruleId,
        cluster_key: activeClusterKey,
        target_date: targetDate,
        target_slot: targetSlot,
        force: force,
        exam_ids: selectedExamIds,
    };
}

function updateEmptyState(cell) {
    if (!cell) return;
    const cards = cell.querySelector('.cards');
    if (!cards) return;
    const existingNote = cards.querySelector('.empty-note');
    const count = cards.querySelectorAll('.course-card').length;
    if (count === 0) {
        if (!existingNote) {
            const note = document.createElement('div');
            note.className = 'empty-note';
            note.textContent = 'Empty slot';
            cards.appendChild(note);
        }
        cell.classList.add('empty');
    } else {
        if (existingNote) existingNote.remove();
        cell.classList.remove('empty');
    }
    const counter = cell.querySelector('.slot-count');
    if (counter) {
        const allCards = Array.from(cell.querySelectorAll('.course-card'));
        const totalStrength = allCards.reduce((sum, card) => sum + cardStrength(card), 0);
        counter.textContent = slotBadgeText(count, count, totalStrength, totalStrength, false);
    }
}

function findSlotCell(date, slot) {
    return Array.from(document.querySelectorAll('.slot-cell')).find(cell => cell.dataset.date === date && cell.dataset.slot === slot) || null;
}

function openModal() {
    const modal = document.getElementById('rescheduleModal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}

function closeModal() {
    const modal = document.getElementById('rescheduleModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    activeClusterKey = null;
    activeCard = null;
    activeCardExamIds = [];
    selectedExamIds = [];
    allModalExamIds = [];
    latestCheckOk = false;
    lastValidation = null;
    resetSwapState();
}

function openClashReportModal() {
    const modal = document.getElementById('clashReportModal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}

function closeClashReportModal() {
    const modal = document.getElementById('clashReportModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function showModalTab(tab) {
    const isSwap = tab === 'swap';
    document.getElementById('rescheduleTabBtn').classList.toggle('active', !isSwap);
    document.getElementById('swapTabBtn').classList.toggle('active', isSwap);
    document.getElementById('rescheduleTabPanel').classList.toggle('active', !isSwap);
    document.getElementById('swapTabPanel').classList.toggle('active', isSwap);
}

function resetSwapState() {
    swapTargets = [];
    swapTargetCard = null;
    swapRightClusterKey = null;
    swapLeftExamIds = [];
    swapRightExamIds = [];
    swapLeftAllExamIds = [];
    swapRightAllExamIds = [];
    swapLeftClassesByExamId = {};
    latestSwapCheckOk = false;
    lastSwapValidation = null;
    const saveBtn = document.getElementById('saveSwapBtn');
    if (saveBtn) saveBtn.disabled = true;
    const checkBox = document.getElementById('swapCheckResult');
    if (checkBox) checkBox.style.display = 'none';
    const conflicts = document.getElementById('swapConflictRows');
    if (conflicts) conflicts.innerHTML = 'Run check to see student-clash details for both sides.';
}

async function openClassClashReport(trigger, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const card = trigger.closest('.course-card');
    if (!ruleId || !card) return;

    const date = card.dataset.date || '';
    const classes = card.dataset.conflictClasses || '';
    if (!date || !classes) {
        showStatus('No class clash details are attached to this paper.', true);
        return;
    }

    document.getElementById('clashReportTitle').textContent = 'Class clash report';
    document.getElementById('clashReportSubtitle').textContent = date + ' / ' + classes.split('|').join(', ');
    document.getElementById('clashReportBody').innerHTML = 'Loading clash report...';
    openClashReportModal();

    try {
        const url = clashReportUrl
            + '?rule_id=' + encodeURIComponent(ruleId)
            + '&date=' + encodeURIComponent(date)
            + '&classes=' + encodeURIComponent(classes);
        const response = await fetch(url);
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Unable to load clash report.');
        renderClassClashReport(data);
    } catch (error) {
        document.getElementById('clashReportBody').innerHTML = '<div class="warn">' + escapeHtml(error.message) + '</div>';
        showStatus(error.message, true);
    }
}

function renderClassClashReport(data) {
    const target = document.getElementById('clashReportBody');
    const classReports = Array.isArray(data.classes) ? data.classes : [];

    if (!classReports.length) {
        target.innerHTML = '<div class="muted">No class report rows found.</div>';
        return;
    }

    let html = '';
    for (const report of classReports) {
        const students = Array.isArray(report.students) ? report.students : [];
        html += '<div class="clash-report-class">';
        html += '<div class="clash-report-title">' + escapeHtml(report.class_label || '') + ' - ' + escapeHtml(students.length) + ' student(s) with 2+ papers</div>';

        if (!students.length) {
            html += '<div class="constraint active"><strong>No individual student clash found.</strong> The current schedule data has no student registered in 2+ of these papers.</div>';
            html += '</div>';
            continue;
        }

        html += '<table class="conflict-table"><thead><tr><th>Reg No</th><th>Name</th><th>Paper count</th><th>Papers</th></tr></thead><tbody>';
        for (const student of students) {
            html += '<tr>'
                + '<td>' + escapeHtml(student.registration_id || '') + '</td>'
                + '<td>' + escapeHtml(student.student_name || '') + '</td>'
                + '<td>' + escapeHtml(student.paper_count || '') + '</td>'
                + '<td>' + escapeHtml(student.papers_text || '') + '</td>'
                + '</tr>';
        }
        html += '</tbody></table></div>';
    }

    target.innerHTML = html;
}

async function openClusterModal(card) {
    if (!ruleId) return;
    activeCard = card;
    activeClusterKey = card.dataset.clusterKey;
    activeCardExamIds = examIdsFromCard(card);
    selectedExamIds = activeCardExamIds.slice();
    allModalExamIds = [];
    latestCheckOk = false;
    lastValidation = null;
    resetSwapState();
    showModalTab('reschedule');
    document.getElementById('saveRescheduleBtn').disabled = true;
    document.getElementById('checkResult').style.display = 'none';
    document.getElementById('conflictRows').innerHTML = 'Run check to see student-clash details.';
    openModal();

    try {
        showStatus('Loading paper constraints...');
        const url = clusterDetailsRequestUrl(activeCardExamIds);
        const response = await fetch(url);
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Unable to load paper details.');

        document.getElementById('modalTitle').textContent = (data.course_codes || []).join(', ') || 'Reschedule paper';
        updateModalSubtitle(data);

        targetSlotsByDate = data.target_slots_by_date || {};
        renderExamRows(data.exams || [], data.selected_exam_ids || activeCardExamIds);
        renderSwapLeftRows(data.exams || [], data.selected_exam_ids || activeCardExamIds);
        buildSwapTargets();
        renderConstraints(data.constraints || []);
        fillTargets({
            days: data.available_dates || data.candidate_days || [],
            slots: data.available_slots || data.slots || [],
            slotsByDate: targetSlotsByDate
        }, data.current_date, data.current_slot);
        showStatus('Paper loaded. Choose a date/slot and click Check constraints.');
    } catch (error) {
        showStatus(error.message, true);
        document.getElementById('examRows').innerHTML = '<div class="warn">' + escapeHtml(error.message) + '</div>';
    }
}

function updateModalSubtitle(data) {
    const selected = normalizeExamIds(data.selected_exam_ids || selectedExamIds);
    const totalRows = parseInt(data.total_exam_count || data.exam_count || selected.length || 0, 10) || 0;
    const selectedRows = parseInt(data.exam_count || selected.length || 0, 10) || 0;
    const selectedStrength = parseInt(data.student_count || data.strength || 0, 10) || 0;
    const totalStrength = parseInt(data.total_strength || selectedStrength || 0, 10) || 0;
    const rowText = totalRows && selectedRows !== totalRows
        ? selectedRows + ' of ' + totalRows + ' rows'
        : selectedRows + ' row' + (selectedRows === 1 ? '' : 's');
    const strengthText = totalStrength && selectedStrength !== totalStrength
        ? selectedStrength + ' of ' + totalStrength + ' students'
        : selectedStrength + ' students';
    document.getElementById('modalSubtitle').textContent = 'Current: ' + (data.current_date || '') + ' / ' + (data.current_slot || '') + ' · ' + rowText + ' · ' + strengthText;
}

function updateExamSelectionSummary() {
    selectedExamIds = selectedExamIdsFromRows();
    const selectAll = document.getElementById('selectAllExamRows');
    const summary = document.getElementById('examSelectionSummary');
    if (selectAll) {
        selectAll.checked = selectedExamIds.length > 0 && selectedExamIds.length === allModalExamIds.length;
        selectAll.indeterminate = selectedExamIds.length > 0 && selectedExamIds.length < allModalExamIds.length;
    }
    if (summary) {
        summary.textContent = selectedExamIds.length + ' of ' + allModalExamIds.length + ' selected';
    }
}

function markRescheduleDirty() {
    latestCheckOk = false;
    lastValidation = null;
    document.getElementById('saveRescheduleBtn').disabled = true;
    document.getElementById('checkResult').style.display = 'none';
    document.getElementById('conflictRows').innerHTML = 'Run check to see student-clash details.';
}

async function refreshSelectionDetails() {
    updateExamSelectionSummary();
    markRescheduleDirty();

    if (!selectedExamIds.length) {
        document.getElementById('constraintRows').innerHTML = '<div class="constraint warning"><strong>Rows affected:</strong> Select at least one exam row to reschedule.</div>';
        document.getElementById('saveRescheduleBtn').disabled = true;
        return;
    }

    try {
        const response = await fetch(clusterDetailsRequestUrl(selectedExamIds));
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Unable to refresh selected rows.');

        document.getElementById('modalTitle').textContent = (data.course_codes || []).join(', ') || 'Reschedule paper';
        updateModalSubtitle(data);
        targetSlotsByDate = data.target_slots_by_date || {};
        renderConstraints(data.constraints || []);
        fillTargets({
            days: data.available_dates || data.candidate_days || [],
            slots: data.available_slots || data.slots || [],
            slotsByDate: targetSlotsByDate
        }, data.current_date, data.current_slot);
    } catch (error) {
        showStatus(error.message, true);
        document.getElementById('constraintRows').innerHTML = '<div class="warn">' + escapeHtml(error.message) + '</div>';
    }
}

function toggleAllExamRows(checked) {
    document.querySelectorAll('.exam-select').forEach(input => {
        input.checked = checked;
    });
    refreshSelectionDetails();
}

function renderExamRows(exams, selectedIds = []) {
    allModalExamIds = normalizeExamIds(exams.map(exam => exam.id));
    selectedExamIds = normalizeExamIds(selectedIds.length ? selectedIds : allModalExamIds);
    const selectedSet = new Set(selectedExamIds);
    let html = '<div class="selection-tools"><label><input type="checkbox" id="selectAllExamRows" onchange="toggleAllExamRows(this.checked)"> Move all rows</label><span id="examSelectionSummary"></span></div>';
    html += '<div class="exam-row header"><div>Move</div><div>Course</div><div>Class</div><div>Title</div><div>Instructor</div><div>Strength</div></div>';
    for (const exam of exams) {
        const id = parseInt(exam.id || 0, 10) || 0;
        const checked = selectedSet.has(id) ? ' checked' : '';
        html += '<div class="exam-row">'
            + '<div><input type="checkbox" class="exam-select" value="' + escapeHtml(id) + '"' + checked + ' onchange="refreshSelectionDetails()"></div>'
            + '<div><strong>' + escapeHtml(exam.course_code) + '</strong></div>'
            + '<div>' + escapeHtml(exam.class_label) + '</div>'
            + '<div>' + escapeHtml(exam.course_title) + '</div>'
            + '<div>' + escapeHtml(exam.instructor) + '</div>'
            + '<div>' + escapeHtml(exam.strength) + '</div>'
            + '</div>';
    }
    document.getElementById('examRows').innerHTML = html;
    updateExamSelectionSummary();
}

function selectedIdsForClass(className) {
    return normalizeExamIds(Array.from(document.querySelectorAll('.' + className + ':checked')).map(input => input.value));
}

function markSwapDirty() {
    latestSwapCheckOk = false;
    lastSwapValidation = null;
    document.getElementById('saveSwapBtn').disabled = true;
    document.getElementById('swapCheckResult').style.display = 'none';
    document.getElementById('swapConflictRows').innerHTML = 'Run check to see student-clash details for both sides.';
}

function updateSwapSelectionSummary(side) {
    const inputClass = side === 'left' ? 'swap-left-exam-select' : 'swap-right-exam-select';
    const allIds = side === 'left' ? swapLeftAllExamIds : swapRightAllExamIds;
    const selected = selectedIdsForClass(inputClass);
    if (side === 'left') {
        swapLeftExamIds = selected;
    } else {
        swapRightExamIds = selected;
    }

    const selectAll = document.getElementById(side === 'left' ? 'selectAllSwapLeftRows' : 'selectAllSwapRightRows');
    const summary = document.getElementById(side === 'left' ? 'swapLeftSelectionSummary' : 'swapRightSelectionSummary');
    if (selectAll) {
        selectAll.checked = selected.length > 0 && selected.length === allIds.length;
        selectAll.indeterminate = selected.length > 0 && selected.length < allIds.length;
    }
    if (summary) {
        summary.textContent = selected.length + ' of ' + allIds.length + ' selected';
    }
}

function refreshSwapSelection(side) {
    updateSwapSelectionSummary(side);
    markSwapDirty();
    if (side === 'left') {
        buildSwapTargets();
    }
}

function toggleAllSwapRows(side, checked) {
    const inputClass = side === 'left' ? 'swap-left-exam-select' : 'swap-right-exam-select';
    document.querySelectorAll('.' + inputClass).forEach(input => {
        input.checked = checked;
    });
    refreshSwapSelection(side);
}

function renderSwapRows(targetId, side, exams, selectedIds = []) {
    const inputClass = side === 'left' ? 'swap-left-exam-select' : 'swap-right-exam-select';
    const selectAllId = side === 'left' ? 'selectAllSwapLeftRows' : 'selectAllSwapRightRows';
    const summaryId = side === 'left' ? 'swapLeftSelectionSummary' : 'swapRightSelectionSummary';
    const allIds = normalizeExamIds(exams.map(exam => exam.id));
    const selected = normalizeExamIds(selectedIds.length ? selectedIds : allIds);
    const selectedSet = new Set(selected);

    if (side === 'left') {
        swapLeftAllExamIds = allIds;
        swapLeftExamIds = selected;
        swapLeftClassesByExamId = {};
        for (const exam of exams) {
            const id = parseInt(exam.id || 0, 10) || 0;
            swapLeftClassesByExamId[id] = classesForExamRow(exam);
        }
    } else {
        swapRightAllExamIds = allIds;
        swapRightExamIds = selected;
    }

    if (!exams.length) {
        document.getElementById(targetId).innerHTML = '<div class="muted">No exam rows found.</div>';
        return;
    }

    let html = '<div class="selection-tools"><label><input type="checkbox" id="' + selectAllId + '" onchange="toggleAllSwapRows(\'' + side + '\', this.checked)"> Swap all rows</label><span id="' + summaryId + '"></span></div>';
    html += '<div class="exam-row header"><div>Swap</div><div>Course</div><div>Class</div><div>Title</div><div>Instructor</div><div>Strength</div></div>';
    for (const exam of exams) {
        const id = parseInt(exam.id || 0, 10) || 0;
        const checked = selectedSet.has(id) ? ' checked' : '';
        html += '<div class="exam-row">'
            + '<div><input type="checkbox" class="' + inputClass + '" value="' + escapeHtml(id) + '"' + checked + ' onchange="refreshSwapSelection(\'' + side + '\')"></div>'
            + '<div><strong>' + escapeHtml(exam.course_code) + '</strong></div>'
            + '<div>' + escapeHtml(exam.class_label) + '</div>'
            + '<div>' + escapeHtml(exam.course_title) + '</div>'
            + '<div>' + escapeHtml(exam.instructor) + '</div>'
            + '<div>' + escapeHtml(exam.strength) + '</div>'
            + '</div>';
    }

    document.getElementById(targetId).innerHTML = html;
    updateSwapSelectionSummary(side);
}

function renderSwapLeftRows(exams, selectedIds = []) {
    renderSwapRows('swapLeftRows', 'left', exams, selectedIds);
}

function normalizeClassToken(value) {
    return normalizeFilterValue(value).replace(/\s+/g, ' ');
}

function classesForExamRow(exam) {
    const labels = Array.isArray(exam.class_labels)
        ? exam.class_labels
        : String(exam.class_label || '').split(/[|;,]+/);

    return labels.map(normalizeClassToken).filter(Boolean);
}

function selectedSwapLeftClasses() {
    const classes = new Set();
    for (const id of selectedIdsForClass('swap-left-exam-select')) {
        for (const classLabel of swapLeftClassesByExamId[id] || []) {
            classes.add(classLabel);
        }
    }
    return Array.from(classes);
}

function buildSwapTargets() {
    const select = document.getElementById('swapTargetSelect');
    const allowedClasses = selectedSwapLeftClasses();
    const allowedClassSet = new Set(allowedClasses);
    swapTargets = Array.from(document.querySelectorAll('.course-card'))
        .filter(card => card !== activeCard)
        .filter(card => {
            if (!allowedClasses.length) return false;
            return tokenList(card.dataset.classes)
                .map(normalizeClassToken)
                .some(classLabel => allowedClassSet.has(classLabel));
        })
        .map((card, index) => {
            const course = (card.querySelector('.course-title')?.textContent || 'Paper').trim();
            const classes = String(card.dataset.classes || '').split('|').filter(Boolean).join(', ');
            const date = card.dataset.date || '';
            const slot = card.dataset.slot || '';
            const strength = card.dataset.strength || '0';
            return {
                index,
                card,
                clusterKey: card.dataset.clusterKey || '',
                examIds: examIdsFromCard(card),
                label: course + (classes ? ' [' + classes + ']' : '') + ' - ' + date + ' ' + slot + ' - ' + strength + ' students',
            };
        });

    select.innerHTML = '';
    swapTargetCard = null;
    swapRightClusterKey = null;
    swapRightExamIds = [];
    swapRightAllExamIds = [];
    document.getElementById('swapRightRows').innerHTML = '';

    if (!swapTargets.length) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = allowedClasses.length ? 'No matching class paper is available' : 'Select first paper rows';
        select.appendChild(option);
        select.disabled = true;
        document.getElementById('swapTargetSummary').textContent = allowedClasses.length
            ? 'No second paper on the board includes: ' + allowedClasses.join(', ') + '.'
            : 'Select at least one first-side row before choosing a swap target.';
        return;
    }

    select.disabled = false;
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Choose paper...';
    select.appendChild(placeholder);
    for (const target of swapTargets) {
        const option = document.createElement('option');
        option.value = String(target.index);
        option.textContent = target.label;
        select.appendChild(option);
    }
    document.getElementById('swapTargetSummary').textContent = 'Showing papers for selected class(es): ' + allowedClasses.join(', ') + '.';
}

async function loadSwapTarget() {
    const select = document.getElementById('swapTargetSelect');
    const target = swapTargets.find(item => String(item.index) === String(select.value));
    swapTargetCard = target?.card || null;
    swapRightClusterKey = target?.clusterKey || null;
    swapRightExamIds = [];
    swapRightAllExamIds = [];
    markSwapDirty();

    if (!target) {
        document.getElementById('swapTargetSummary').textContent = 'Choose a paper from the datesheet board.';
        document.getElementById('swapRightRows').innerHTML = '';
        return;
    }

    document.getElementById('swapTargetSummary').textContent = 'Second paper current slot: ' + (target.card.dataset.date || '') + ' / ' + (target.card.dataset.slot || '');
    document.getElementById('swapRightRows').innerHTML = '<div class="muted">Loading second paper rows...</div>';

    try {
        const response = await fetch(clusterDetailsUrlFor(target.clusterKey, target.examIds));
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Unable to load swap target.');
        renderSwapRows('swapRightRows', 'right', data.exams || [], data.selected_exam_ids || target.examIds);
    } catch (error) {
        showStatus(error.message, true);
        document.getElementById('swapRightRows').innerHTML = '<div class="warn">' + escapeHtml(error.message) + '</div>';
    }
}

function renderConstraints(constraints) {
    if (!constraints.length) {
        document.getElementById('constraintRows').innerHTML = '<div class="muted">No special constraints found for this paper.</div>';
        return;
    }
    let html = '';
    for (const item of constraints) {
        const level = item.status || item.level || 'active';
        const title = item.label || item.title || 'Constraint';
        const cssLevel = level === 'hard' ? 'warning' : level;
        html += '<div class="constraint ' + escapeHtml(cssLevel) + '"><strong>' + escapeHtml(title) + ':</strong> ' + escapeHtml(item.message) + '</div>';
    }
    document.getElementById('constraintRows').innerHTML = html;
}

function slotOptionText(slot) {
    let text = slot.label || '';
    if (slot.type) text += ' (' + slot.type + ')';
    if (slot.same_position) text += ' - current';
    if ((parseInt(slot.warning_count || 0, 10) || 0) > 0) text += ' - warnings';
    return text;
}

function fillSlotTargets(date, preferredSlot = '') {
    const slotSelect = document.getElementById('targetSlot');
    slotSelect.innerHTML = '';

    const slots = Array.isArray(targetSlotsByDate[date]) ? targetSlotsByDate[date] : [];
    if (!slots.length) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'No valid slots';
        option.disabled = true;
        option.selected = true;
        slotSelect.appendChild(option);
        slotSelect.disabled = true;
        return;
    }

    slotSelect.disabled = false;
    for (const slot of slots) {
        const option = document.createElement('option');
        option.value = slot.label;
        option.textContent = slotOptionText(slot);
        if (slot.label === preferredSlot) option.selected = true;
        slotSelect.appendChild(option);
    }

    if (preferredSlot && slotSelect.value !== preferredSlot) {
        slotSelect.selectedIndex = 0;
    }
}

function fillTargets(targets, currentDate, currentSlot) {
    const dateSelect = document.getElementById('targetDate');
    const slotSelect = document.getElementById('targetSlot');
    dateSelect.innerHTML = '';
    slotSelect.innerHTML = '';

    targetSlotsByDate = targets.slotsByDate || {};
    const days = targets.days || Object.keys(targetSlotsByDate);

    if (!days.length) {
        const dateOption = document.createElement('option');
        dateOption.value = '';
        dateOption.textContent = 'No valid dates';
        dateOption.disabled = true;
        dateOption.selected = true;
        dateSelect.appendChild(dateOption);
        fillSlotTargets('', '');
        document.getElementById('saveRescheduleBtn').disabled = true;
        document.getElementById('checkResult').style.display = 'block';
        document.getElementById('checkResult').className = 'check-box bad';
        document.getElementById('checkResult').innerHTML = 'No valid reschedule target was found for this paper under the current rules.';
        return;
    }

    for (const day of days) {
        const option = document.createElement('option');
        option.value = day;
        option.textContent = day;
        if (day === currentDate) option.selected = true;
        dateSelect.appendChild(option);
    }

    if (currentDate && !days.includes(currentDate)) {
        dateSelect.selectedIndex = 0;
    }
    fillSlotTargets(dateSelect.value, dateSelect.value === currentDate ? currentSlot : '');

    const markDirty = () => {
        latestCheckOk = false;
        lastValidation = null;
        document.getElementById('saveRescheduleBtn').disabled = true;
        document.getElementById('checkResult').style.display = 'none';
        document.getElementById('conflictRows').innerHTML = 'Run check to see student-clash details.';
    };
    dateSelect.onchange = () => {
        fillSlotTargets(dateSelect.value, dateSelect.value === currentDate ? currentSlot : '');
        markDirty();
    };
    slotSelect.onchange = markDirty;
}

async function checkReschedule() {
    if (!activeClusterKey) return;
    selectedExamIds = selectedExamIdsFromRows();
    const targetDate = document.getElementById('targetDate').value;
    const targetSlot = document.getElementById('targetSlot').value;
    const box = document.getElementById('checkResult');
    const saveBtn = document.getElementById('saveRescheduleBtn');

    try {
        if (!targetDate || !targetSlot) {
            throw new Error('No valid date/slot target is available for this paper.');
        }
        if (!selectedExamIds.length) {
            throw new Error('Select at least one exam row to reschedule.');
        }

        box.style.display = 'block';
        box.className = 'check-box warn';
        box.innerHTML = 'Checking constraints...';
        saveBtn.disabled = true;
        latestCheckOk = false;

        const response = await fetch(checkUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
            body: JSON.stringify(reschedulePayload(targetDate, targetSlot))
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Unable to check reschedule.');

        lastValidation = data;
        latestCheckOk = true;
        saveBtn.disabled = false;
        renderCheckResult(data);
        renderConflictRows(data.student_conflicts || []);
    } catch (error) {
        latestCheckOk = false;
        lastValidation = null;
        saveBtn.disabled = true;
        box.style.display = 'block';
        box.className = 'check-box bad';
        box.innerHTML = escapeHtml(error.message);
    }
}

function renderCheckResult(data) {
    const box = document.getElementById('checkResult');
    const hard = data.hard_warnings || data.hard_violations || [];
    const warnings = data.warnings || [];
    const notices = data.notices || [];
    box.className = 'check-box ' + (hard.length ? 'bad' : (warnings.length ? 'warn' : 'good'));

    let html = hard.length ? '<strong>Rule warnings / student clashes:</strong>' : '<strong>Move is allowed.</strong>';
    if (hard.length) html += '<ul>' + hard.map(x => '<li>' + escapeHtml(x) + '</li>').join('') + '</ul>';
    if (warnings.length) html += '<div style="margin-top:8px"><strong>Warnings:</strong><ul>' + warnings.map(x => '<li>' + escapeHtml(x) + '</li>').join('') + '</ul></div>';
    if (notices.length) html += '<div style="margin-top:8px"><strong>Notes:</strong><ul>' + notices.map(x => '<li>' + escapeHtml(x) + '</li>').join('') + '</ul></div>';
    box.innerHTML = html;
}

function renderConflictRows(conflicts) {
    const target = document.getElementById('conflictRows');
    const rows = Array.isArray(conflicts) ? conflicts : (conflicts.rows || conflicts.samples || []);
    const count = Array.isArray(conflicts)
        ? conflicts.reduce((sum, row) => sum + (parseInt(row.count || 0, 10) || 0), 0)
        : (conflicts.student_count || rows.length || 0);

    if (!count) {
        target.innerHTML = '<div class="constraint active"><strong>No student clash found</strong> for the selected date/slot.</div>';
        return;
    }

    let html = '<div class="constraint warning"><strong>' + escapeHtml(count) + ' conflicted student(s).</strong> Review before saving.</div>';
    html += '<table class="conflict-table"><thead><tr><th>Other paper</th><th>Other class</th><th>Other slot</th><th>Count / sample</th></tr></thead><tbody>';
    for (const row of rows) {
        const sample = Array.isArray(row.sample_students) ? row.sample_students.join(', ') : (row.sample || row.registration_id || '');
        html += '<tr><td>' + escapeHtml(row.course_code || row.other_course || '') + '</td><td>' + escapeHtml(row.class_label || row.other_class || '') + '</td><td>' + escapeHtml(row.slot || row.other_slot || '') + '</td><td>' + escapeHtml((row.count || '') + (sample ? ' — ' + sample : '')) + '</td></tr>';
    }
    html += '</tbody></table>';
    target.innerHTML = html;
}

async function saveReschedule() {
    if (!activeClusterKey || !latestCheckOk) return;
    selectedExamIds = selectedExamIdsFromRows();
    const targetDate = document.getElementById('targetDate').value;
    const targetSlot = document.getElementById('targetSlot').value;
    const hard = (lastValidation && (lastValidation.hard_warnings || lastValidation.hard_violations)) || [];
    const warnings = (lastValidation && lastValidation.warnings) || [];
    const force = hard.length > 0 || warnings.length > 0;

    if (force && !confirm('This move has rule warnings or student clashes. Save anyway?')) return;
    if (!force && !confirm('Save this reschedule? Room allocation, seat plan and duty roster for the moved paper will be cleared and must be regenerated.')) return;

    try {
        showStatus('Saving reschedule...');
        const response = await fetch(saveUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
            body: JSON.stringify(reschedulePayload(targetDate, targetSlot, force))
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Unable to save reschedule.');
        if (data.ok === false && data.needs_confirmation) {
            renderCheckResult(data.validation || {});
            renderConflictRows((data.validation || {}).student_conflicts || []);
            showStatus(data.message || 'Please review warnings and save again.', true);
            return;
        }

        const selectedSet = new Set(selectedExamIds);
        const selectedWholeCard = activeCardExamIds.length > 0
            && activeCardExamIds.length === selectedExamIds.length
            && activeCardExamIds.every(id => selectedSet.has(id));
        const targetCell = findSlotCell(targetDate, targetSlot);
        const targetHasSameClusterCard = targetCell && Array.from(targetCell.querySelectorAll('.course-card'))
            .some(card => card !== activeCard && card.dataset.clusterKey === activeClusterKey);

        if (!selectedWholeCard || targetHasSameClusterCard || data.is_partial_selection) {
            closeModal();
            showStatus('Datesheet updated. Reloading board...');
            setTimeout(() => window.location.reload(), 450);
            return;
        }

        const sourceCell = activeCard ? activeCard.closest('.slot-cell') : null;
        if (activeCard && targetCell) {
            targetCell.querySelector('.cards').appendChild(activeCard);
            activeCard.dataset.date = targetDate;
            activeCard.dataset.slot = targetSlot;
            updateEmptyState(sourceCell);
            updateEmptyState(targetCell);
            applyDatesheetFilters();
        }

        closeModal();
        showStatus('Datesheet updated. Run room allocation/seating/roster for the affected slots.');
    } catch (error) {
        showStatus(error.message, true);
    }
}

function swapPayload(force = false) {
    return {
        rule_id: ruleId,
        left_cluster_key: activeClusterKey,
        right_cluster_key: swapRightClusterKey,
        left_exam_ids: selectedIdsForClass('swap-left-exam-select'),
        right_exam_ids: selectedIdsForClass('swap-right-exam-select'),
        force: force,
    };
}

function renderSwapCheckResult(data) {
    const box = document.getElementById('swapCheckResult');
    const hard = data.hard_warnings || [];
    const warnings = data.warnings || [];
    const notices = data.notices || [];
    box.style.display = 'block';
    box.className = 'check-box ' + (hard.length ? 'bad' : (warnings.length ? 'warn' : 'good'));

    let html = hard.length ? '<strong>Swap has rule warnings / student clashes:</strong>' : '<strong>Swap is allowed.</strong>';
    if (hard.length) html += '<ul>' + hard.map(x => '<li>' + escapeHtml(x) + '</li>').join('') + '</ul>';
    if (warnings.length) html += '<div style="margin-top:8px"><strong>Warnings:</strong><ul>' + warnings.map(x => '<li>' + escapeHtml(x) + '</li>').join('') + '</ul></div>';
    if (notices.length) html += '<div style="margin-top:8px"><strong>Notes:</strong><ul>' + notices.map(x => '<li>' + escapeHtml(x) + '</li>').join('') + '</ul></div>';
    box.innerHTML = html;
}

function conflictRowsHtml(conflicts, title) {
    const rows = Array.isArray(conflicts) ? conflicts : [];
    const count = rows.reduce((sum, row) => sum + (parseInt(row.count || 0, 10) || 0), 0);
    if (!count) {
        return '<div class="constraint active"><strong>' + escapeHtml(title) + ':</strong> No student clash found.</div>';
    }

    let html = '<div class="constraint warning"><strong>' + escapeHtml(title) + ': ' + escapeHtml(count) + ' conflicted student(s).</strong></div>';
    html += '<table class="conflict-table"><thead><tr><th>Other paper</th><th>Other class</th><th>Other slot</th><th>Count / sample</th></tr></thead><tbody>';
    for (const row of rows) {
        const sample = Array.isArray(row.sample_students) ? row.sample_students.join(', ') : (row.sample || row.registration_id || '');
        html += '<tr><td>' + escapeHtml(row.course_code || row.other_course || '') + '</td><td>' + escapeHtml(row.class_label || row.other_class || '') + '</td><td>' + escapeHtml(row.slot || row.other_slot || '') + '</td><td>' + escapeHtml((row.count || '') + (sample ? ' - ' + sample : '')) + '</td></tr>';
    }
    html += '</tbody></table>';
    return html;
}

function renderSwapConflictRows(data) {
    const conflicts = data.student_conflicts || {};
    document.getElementById('swapConflictRows').innerHTML =
        conflictRowsHtml(conflicts.left || [], 'First paper in second paper slot')
        + conflictRowsHtml(conflicts.right || [], 'Second paper in first paper slot');
}

async function checkSwap() {
    const box = document.getElementById('swapCheckResult');
    const saveBtn = document.getElementById('saveSwapBtn');

    try {
        const payload = swapPayload(false);
        if (!payload.left_cluster_key || !payload.right_cluster_key) {
            throw new Error('Choose a second paper to swap with.');
        }
        if (!payload.left_exam_ids.length || !payload.right_exam_ids.length) {
            throw new Error('Select at least one exam row on both sides of the swap.');
        }

        box.style.display = 'block';
        box.className = 'check-box warn';
        box.innerHTML = 'Checking swap constraints...';
        saveBtn.disabled = true;
        latestSwapCheckOk = false;

        const response = await fetch(swapCheckUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Unable to check swap.');

        lastSwapValidation = data;
        latestSwapCheckOk = true;
        saveBtn.disabled = false;
        renderSwapCheckResult(data);
        renderSwapConflictRows(data);
    } catch (error) {
        latestSwapCheckOk = false;
        lastSwapValidation = null;
        saveBtn.disabled = true;
        box.style.display = 'block';
        box.className = 'check-box bad';
        box.innerHTML = escapeHtml(error.message);
    }
}

async function saveSwap() {
    if (!activeClusterKey || !swapRightClusterKey || !latestSwapCheckOk) return;
    const hard = (lastSwapValidation && lastSwapValidation.hard_warnings) || [];
    const warnings = (lastSwapValidation && lastSwapValidation.warnings) || [];
    const force = hard.length > 0 || warnings.length > 0;

    if (force && !confirm('This swap has rule warnings or student clashes. Save anyway?')) return;
    if (!force && !confirm('Save this swap? Room allocation, seat plan and duty roster for affected papers will be cleared and must be regenerated.')) return;

    try {
        showStatus('Saving swap...');
        const response = await fetch(swapSaveUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
            body: JSON.stringify(swapPayload(force))
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Unable to save swap.');
        if (data.ok === false && data.needs_confirmation) {
            renderSwapCheckResult(data.validation || {});
            renderSwapConflictRows(data.validation || {});
            showStatus(data.message || 'Please review warnings and save again.', true);
            return;
        }

        closeModal();
        showStatus('Datesheet swap saved. Reloading board...');
        setTimeout(() => window.location.reload(), 450);
    } catch (error) {
        showStatus(error.message, true);
    }
}


document.addEventListener('dragstart', function (event) {
    if (event.target.closest('.course-card')) {
        event.preventDefault();
        return false;
    }
});

document.addEventListener('click', function (event) {
    const card = event.target.closest('.course-card');
    if (!card) return;
    if (event.defaultPrevented) return;
    event.preventDefault();
    openClusterModal(card);
});

document.getElementById('rescheduleModal').addEventListener('click', function (event) {
    if (event.target.id === 'rescheduleModal') closeModal();
});

document.getElementById('clashReportModal').addEventListener('click', function (event) {
    if (event.target.id === 'clashReportModal') closeClashReportModal();
});

document.addEventListener('DOMContentLoaded', function () {
    fillRenameSlotFields();
    for (const id of ['filterClass', 'filterProgram', 'filterLevel', 'filterCourse', 'filterText', 'filterHighClashesOnly', 'filterHideEmptyDates']) {
        const el = document.getElementById(id);
        if (!el) continue;
        el.addEventListener('input', applyDatesheetFilters);
        el.addEventListener('change', applyDatesheetFilters);
    }
    applyDatesheetFilters();
});
</script>
</body>
</html>
<?php /**PATH D:\xampp\htdocs\Datesheet_Generator\Datesheet_Generator\resources\views/scheduler/datesheet.blade.php ENDPATH**/ ?>