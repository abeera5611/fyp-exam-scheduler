<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Course Management</title>
    <style>
        .toolbar{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.toolbar>div{min-width:180px}.code{font-weight:700;color:#111827}.title-input{min-width:320px}.stats{display:flex;gap:10px;flex-wrap:wrap}.stat{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}.pagination{margin-top:12px}.pagination a,.pagination span{margin-right:4px}
    </style>
</head>
<body>
@include('scheduler.partials.nav')
<div class="wrap">
    <h1>Course Management</h1>
    @if(session('status')) <div class="status">{{ session('status') }}</div> @endif

    <div class="panel">
        <div class="stats">
            <div class="stat"><strong>{{ $totalCourses }}</strong><br><span class="muted">Total courses</span></div>
            <div class="stat"><strong>{{ $selectedRule ? $selectedRule->session_code : 'No session' }}</strong><br><span class="muted">Selected session</span></div>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="panel">
            <h2>Upload course title CSV</h2>
            <p class="muted">Use this to replace imported short/dirty names with real course titles. Accepted columns: <strong>course_code, course_title</strong> or <strong>code, title</strong>. A two-column CSV without header also works.</p>
            <form method="post" action="{{ route('scheduler.courses.import-titles') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="rule_id" value="{{ $selectedRule?->id }}">
                <label>CSV file</label>
                <input type="file" name="course_titles_csv" accept=".csv,.txt" required>
                <button class="btn" type="submit" style="margin-top:8px">Import course titles</button>
            </form>
        </div>

        <div class="panel">
            <h2>Filter courses</h2>
            <form method="get" action="{{ route('scheduler.courses') }}" class="toolbar">
                <input type="hidden" name="rule_id" value="{{ $selectedRule?->id }}">
                <div><label>Search</label><input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="CSC303 or title"></div>
                <div><label>Registered in selected session</label>
                    <select name="registered">
                        <option value="" @selected(($filters['registered'] ?? '') === '')>All courses</option>
                        <option value="yes" @selected(($filters['registered'] ?? '') === 'yes')>Only registered</option>
                    </select>
                </div>
                <div><button class="btn secondary" type="submit">Apply filters</button></div>
            </form>
        </div>
    </div>

    <div class="panel">
        <h2>Manual title update</h2>
        <form method="post" action="{{ route('scheduler.courses.bulk') }}">
            @csrf
            <input type="hidden" name="rule_id" value="{{ $selectedRule?->id }}">
            <div style="overflow:auto">
                <table>
                    <tr><th>Course Code</th><th>Course Title</th><th>Selected-session registrations</th></tr>
                    @forelse($courses as $course)
                        <tr>
                            <td class="code">{{ $course->code }}<input type="hidden" name="courses[{{ $course->id }}][id]" value="{{ $course->id }}"></td>
                            <td><input class="title-input" name="courses[{{ $course->id }}][title]" value="{{ $course->title }}"></td>
                            <td>{{ $course->selected_session_registrations_count ?? 0 }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="muted">No courses found.</td></tr>
                    @endforelse
                </table>
            </div>
            <button class="btn green" type="submit" style="margin-top:10px">Save visible course titles</button>
        </form>
        <div class="pagination">{{ $courses->links() }}</div>
    </div>
</div>
</body>
</html>
