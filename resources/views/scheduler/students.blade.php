<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Student Management</title>
    <style>
        .stats{display:flex;gap:10px;flex-wrap:wrap}.stat{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px;min-width:160px}.danger-zone{border-color:#fecaca;background:#fff7f7}.danger-zone h2{color:#991b1b}.inline-form{display:inline-block;margin-right:8px}.pagination{margin-top:12px}.toolbar{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.toolbar>div{min-width:210px}.student-row{cursor:pointer}.student-row:hover{background:#f8fafc}.student-row:focus{outline:2px solid #2563eb;outline-offset:-2px}.student-modal-backdrop{position:fixed;inset:0;background:rgba(17,24,39,.55);display:none;align-items:center;justify-content:center;z-index:2000;padding:18px}.student-modal-backdrop.open{display:flex}.student-modal{width:min(880px,100%);max-height:88vh;overflow:auto;background:#fff;border-radius:10px;border:1px solid #e5e7eb;box-shadow:0 20px 60px rgba(15,23,42,.25)}.student-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:16px;border-bottom:1px solid #e5e7eb}.student-modal-title{margin:0;font-size:20px}.student-modal-body{padding:16px}.student-modal-close{border:0;background:#111827;color:#fff;border-radius:8px;padding:7px 10px;font-weight:700;cursor:pointer}.course-meta{color:#6b7280;font-size:12px}
    </style>
</head>
<body>
@include('scheduler.partials.nav')
<div class="wrap">
    <h1>Student Management</h1>
    @if(session('status')) <div class="status">{{ session('status') }}</div> @endif
    @if(!$selectedRule)
        <div class="warn">Create/select an exam session first.</div>
    @endif

    <div class="panel">
        <h2>Current data</h2>
        <div class="stats">
            <div class="stat"><strong>{{ $stats['total_students'] }}</strong><br><span class="muted">Students in scheduler DB</span></div>
            <div class="stat"><strong>{{ $stats['selected_session_students'] }}</strong><br><span class="muted">Students in selected session</span></div>
            <div class="stat"><strong>{{ $stats['selected_session_registrations'] }}</strong><br><span class="muted">Course registrations</span></div>
            <div class="stat"><strong>{{ $stats['selected_session_faculty_assignments'] }}</strong><br><span class="muted">Faculty-course mappings</span></div>
            <div class="stat"><strong>{{ $stats['generated_exams'] }}</strong><br><span class="muted">Generated exam rows</span></div>
        </div>
    </div>

    @if($selectedRule)
    <div class="grid grid-2">
        <div class="panel">
            <h2>Import workbook</h2>
            <p class="muted">Append/update keeps existing student registrations. Replace clears this selected session's registrations, generated datesheet, room allocation, seat plan, and roster first.</p>
            <form method="post" action="{{ route('scheduler.students.import') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <label>Student course workbook</label>
                <input type="file" name="workbook" accept=".xlsx,.xls" required>
                <label style="margin-top:8px">Import mode</label>
                <select name="import_mode">
                    <option value="append">Append/update existing students and registrations</option>
                    <option value="replace">Replace selected session student data first</option>
                </select>
                <button class="btn" type="submit" style="margin-top:8px">Import workbook</button>
            </form>
        </div>

        <div class="panel danger-zone">
            <h2>Delete student data</h2>
            <form method="post" action="{{ route('scheduler.students.clear') }}" onsubmit="return confirm('This will clear the selected session registrations and all generated datesheet/room/seat/roster data. Continue?')">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <input type="hidden" name="scope" value="selected_session">
                <p class="muted">Clears selected-session registrations, faculty-course mappings, generated datesheet, room allocation, seat plan, duty roster, and deletes students who are no longer attached to any session.</p>
                <button class="btn red" type="submit">Delete selected session student data</button>
            </form>
            <hr>
            <form method="post" action="{{ route('scheduler.students.clear') }}" onsubmit="return confirm('DANGER: This clears ALL scheduler students and registrations from every session, plus all generated datesheets, room allocations, seat plans, and rosters. Continue only if you are rebuilding all sessions.')">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule->id }}">
                <input type="hidden" name="scope" value="all_scheduler_students">
                <button class="btn red" type="submit">Delete all scheduler student data</button>
            </form>
        </div>
    </div>
    @endif

    <div class="panel">
        <h2>Find students</h2>
        <form method="get" action="{{ route('scheduler.students') }}" class="toolbar">
            <input type="hidden" name="rule_id" value="{{ $selectedRule?->id }}">
            <div>
                <label>Program</label>
                <select name="program_code" onchange="this.form.submit()" @disabled(!$selectedRule || $programOptions->isEmpty())>
                    @if($programOptions->isEmpty())
                        <option value="">No programs found</option>
                    @else
                        @foreach($programOptions as $program)
                            <option value="{{ $program }}" @selected($selectedProgram === $program)>{{ $program }}</option>
                        @endforeach
                    @endif
                </select>
            </div>
            <div>
                <label>Name or registration number</label>
                <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search student">
            </div>
            <div>
                <button class="btn secondary" type="submit">Apply filter</button>
                <a class="btn secondary" href="{{ route('scheduler.students', ['rule_id' => $selectedRule?->id, 'program_code' => $selectedProgram]) }}">Clear search</a>
            </div>
        </form>
        <p class="muted">Only one program is shown at a time. Click a student row to view enrolled courses.</p>
    </div>

    <div class="panel">
        <h2>Students in {{ $selectedProgram ?: 'selected program' }}</h2>
        <div style="overflow:auto">
            <table>
                <tr><th>Registration ID</th><th>Name</th><th>Program</th><th>Intake</th><th>Courses in session</th></tr>
                @forelse($students as $student)
                    <tr class="student-row" tabindex="0" role="button" data-student-id="{{ $student->id }}" data-registration="{{ $student->registration_id }}" data-name="{{ $student->name }}" data-program="{{ $student->program_code }}">
                        <td>{{ $student->registration_id }}</td>
                        <td>{{ $student->name }}</td>
                        <td>{{ $student->program_code }}</td>
                        <td>{{ $student->intake_code }}</td>
                        <td>{{ $student->selected_session_courses_count ?? 0 }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">{{ $selectedProgram ? 'No students found for this program/filter.' : 'Select a program to view students.' }}</td></tr>
                @endforelse
            </table>
        </div>
        <div class="pagination">{{ $students->links() }}</div>
    </div>
</div>

<div class="student-modal-backdrop" id="studentModalBackdrop" aria-hidden="true">
    <div class="student-modal" role="dialog" aria-modal="true" aria-labelledby="studentModalTitle">
        <div class="student-modal-head">
            <div>
                <h2 class="student-modal-title" id="studentModalTitle">Student courses</h2>
                <div class="muted" id="studentModalSubtitle"></div>
            </div>
            <button class="student-modal-close" type="button" id="studentModalClose">Close</button>
        </div>
        <div class="student-modal-body">
            <div id="studentModalCourses"></div>
        </div>
    </div>
</div>

<script>
    const studentCourses = @json($studentCourses);
    const modalBackdrop = document.getElementById('studentModalBackdrop');
    const modalTitle = document.getElementById('studentModalTitle');
    const modalSubtitle = document.getElementById('studentModalSubtitle');
    const modalCourses = document.getElementById('studentModalCourses');
    const modalClose = document.getElementById('studentModalClose');

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }

    function closeStudentModal() {
        modalBackdrop.classList.remove('open');
        modalBackdrop.setAttribute('aria-hidden', 'true');
    }

    function openStudentModal(row) {
        const studentId = row.dataset.studentId;
        const courses = studentCourses[studentId] || [];
        const name = row.dataset.name || 'Unnamed student';
        const registration = row.dataset.registration || '';
        const program = row.dataset.program || '';

        modalTitle.textContent = name;
        modalSubtitle.textContent = [registration, program].filter(Boolean).join(' | ');

        if (!courses.length) {
            modalCourses.innerHTML = '<div class="muted">No enrolled courses found for this student in the selected program/session.</div>';
        } else {
            let html = '<table><tr><th>Course Code</th><th>Course Title</th><th>Class</th><th>Instructor</th><th>Semester</th></tr>';
            for (const course of courses) {
                html += '<tr>'
                    + '<td><strong>' + escapeHtml(course.code) + '</strong></td>'
                    + '<td>' + escapeHtml(course.title) + '</td>'
                    + '<td>' + escapeHtml(course.class_label) + '</td>'
                    + '<td>' + escapeHtml(course.instructor_name) + '</td>'
                    + '<td>' + escapeHtml(course.semester_no ?? '') + '</td>'
                    + '</tr>';
            }
            html += '</table><div class="course-meta">' + courses.length + ' course(s)</div>';
            modalCourses.innerHTML = html;
        }

        modalBackdrop.classList.add('open');
        modalBackdrop.setAttribute('aria-hidden', 'false');
        modalClose.focus();
    }

    document.querySelectorAll('.student-row').forEach(function (row) {
        row.addEventListener('click', function () {
            openStudentModal(row);
        });
        row.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openStudentModal(row);
            }
        });
    });

    modalClose.addEventListener('click', closeStudentModal);
    modalBackdrop.addEventListener('click', function (event) {
        if (event.target === modalBackdrop) {
            closeStudentModal();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modalBackdrop.classList.contains('open')) {
            closeStudentModal();
        }
    });
</script>
</body>
</html>
