<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Scheduler Home</title>
    <style>
        body{font-family:Arial,Helvetica,sans-serif;background:#f6f7fb;margin:0;color:#1f2937;padding-bottom:56px}
        .wrap{max-width:1450px;margin:0 auto;padding:24px 18px}
        .muted{color:#6b7280;font-size:12px}
        .home-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}
        .home-title{display:flex;align-items:center;gap:14px}
        .home-title img{width:72px;height:72px;object-fit:contain;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:6px}
        .home-title h1{margin:0}
        .home-title p{margin:4px 0 0}
        .home-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
        .home-tile{display:flex;align-items:center;gap:14px;min-height:112px;padding:18px;border:1px solid #dbe3ef;border-radius:8px;background:#fff;color:#111827;text-decoration:none;box-shadow:0 1px 3px rgba(0,0,0,.05)}
        .home-tile:hover{border-color:#2563eb;box-shadow:0 8px 22px rgba(37,99,235,.14);transform:translateY(-1px)}
        .home-icon{width:56px;height:56px;border-radius:8px;background:#eff6ff;color:#1d4ed8;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
        .home-icon svg{width:32px;height:32px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
        .home-tile strong{display:block;font-size:17px;margin-bottom:4px}
        .home-tile span{display:block;color:#6b7280;font-size:12px;line-height:1.35}
        .home-tile.green .home-icon{background:#ecfdf5;color:#047857}
        .home-tile.amber .home-icon{background:#fffbeb;color:#b45309}
        .home-tile.red .home-icon{background:#fef2f2;color:#b91c1c}
        .home-tile.gray .home-icon{background:#f3f4f6;color:#4b5563}
        .site-footer{position:fixed;left:var(--sidebar-width);right:0;bottom:0;z-index:900;padding:8px 18px;text-align:center;color:#d1d5db;font-size:12px;line-height:1.5;background:#111827;border-top:1px solid rgba(255,255,255,.08);transition:left .18s ease}
        .site-footer a{color:#fff;font-weight:700;text-decoration:none}
        .site-footer a:hover{text-decoration:underline}
        @media (max-width: 700px){.home-title img{width:56px;height:56px}.home-grid{grid-template-columns:1fr}.home-tile{min-height:92px}}
    </style>
</head>
<body>
@include('scheduler.partials.nav')

@php
    $ruleQuery = $selectedRule ? ['rule_id' => $selectedRule->id] : [];
    $tiles = [
        ['label' => 'Setup', 'text' => 'Exam session, rooms, imports, and generation controls.', 'route' => 'scheduler.index', 'tone' => '', 'icon' => 'settings'],
        ['label' => 'Datesheet Board', 'text' => 'Plan and adjust papers across dates and slots.', 'route' => 'scheduler.datesheet', 'tone' => 'green', 'icon' => 'calendar'],
        ['label' => 'Seat Plan', 'text' => 'Room allocation, seating board, and seat-plan exports.', 'route' => 'scheduler.seat-plan', 'tone' => 'amber', 'icon' => 'seats'],
        ['label' => 'Duty Roster', 'text' => 'Generate and manage invigilator duties.', 'route' => 'scheduler.duty-roster', 'tone' => 'green', 'icon' => 'clipboard'],
        ['label' => 'Room-Course Grid', 'text' => 'Review room-wise papers and invigilators.', 'route' => 'scheduler.room-grid', 'tone' => '', 'icon' => 'building'],
        ['label' => 'Faculty', 'text' => 'Manage faculty, departments, designations, and quotas.', 'route' => 'scheduler.faculty', 'tone' => '', 'icon' => 'user'],
        ['label' => 'Faculty Availability', 'text' => 'Mark unavailable dates and slots before roster generation.', 'route' => 'scheduler.faculty-availability', 'tone' => 'amber', 'icon' => 'clock'],
        ['label' => 'Courses', 'text' => 'Review imported courses and course metadata.', 'route' => 'scheduler.courses', 'tone' => '', 'icon' => 'book'],
        ['label' => 'Course Merge Builder', 'text' => 'Build manual course merge groups.', 'route' => 'scheduler.merge-courses', 'tone' => 'green', 'icon' => 'merge'],
        ['label' => 'Ignored Courses', 'text' => 'Exclude course offerings from scheduling.', 'route' => 'scheduler.ignore-courses', 'tone' => 'red', 'icon' => 'ban'],
        ['label' => 'Students', 'text' => 'Import, update, and clear student registrations.', 'route' => 'scheduler.students', 'tone' => '', 'icon' => 'users'],
    ];
@endphp

<div class="wrap">
    <div class="home-head">
        <div class="home-title">
            <img src="{{ asset('images/comsats-logo.jpg') }}" alt="COMSATS University logo">
            <div>
                <h1>CUI ExamFlow</h1>
                <p class="muted">COMSATS University Islamabad, Attock Campus</p>
            </div>
        </div>
        @if($selectedRule)
            <div class="muted">Current session: #{{ $selectedRule->id }} {{ $selectedRule->session_code }} {{ $selectedRule->exam_title ?: $selectedRule->session_label }}</div>
        @endif
    </div>

    <div class="home-grid">
        @foreach($tiles as $tile)
            <a class="home-tile {{ $tile['tone'] }}" href="{{ route($tile['route'], $ruleQuery) }}">
                <div class="home-icon" aria-hidden="true">
                    @switch($tile['icon'])
                        @case('settings')
                            <svg viewBox="0 0 24 24"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1A2 2 0 1 1 4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.6-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.3 7A2 2 0 1 1 7.1 4.2l.1.1a1.7 1.7 0 0 0 1.9.3h.1a1.7 1.7 0 0 0 .9-1.6V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1A2 2 0 1 1 19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.6.9h.1a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.6 1Z"/></svg>
                            @break
                        @case('calendar')
                            <svg viewBox="0 0 24 24"><path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/></svg>
                            @break
                        @case('seats')
                            <svg viewBox="0 0 24 24"><path d="M7 8h10"/><path d="M7 12h10"/><path d="M5 20V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v14"/><path d="M4 20h16"/></svg>
                            @break
                        @case('clipboard')
                            <svg viewBox="0 0 24 24"><rect x="6" y="4" width="12" height="18" rx="2"/><path d="M9 4a3 3 0 0 1 6 0"/><path d="M9 12h6"/><path d="M9 16h4"/></svg>
                            @break
                        @case('building')
                            <svg viewBox="0 0 24 24"><path d="M4 22V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v18"/><path d="M17 8h1a2 2 0 0 1 2 2v12"/><path d="M8 6h4"/><path d="M8 10h4"/><path d="M8 14h4"/><path d="M8 18h4"/></svg>
                            @break
                        @case('user')
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M5.5 21a6.5 6.5 0 0 1 13 0"/></svg>
                            @break
                        @case('clock')
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            @break
                        @case('book')
                            <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5z"/></svg>
                            @break
                        @case('merge')
                            <svg viewBox="0 0 24 24"><path d="M6 3v6a6 6 0 0 0 6 6h6"/><path d="M18 9l3 3-3 3"/><path d="M6 21v-6a6 6 0 0 1 6-6h2"/></svg>
                            @break
                        @case('ban')
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6 18.4 18.4"/></svg>
                            @break
                        @case('users')
                            <svg viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M17 11a3 3 0 1 0 0-6"/><path d="M22 21a5 5 0 0 0-5-5"/></svg>
                            @break
                    @endswitch
                </div>
                <div>
                    <strong>{{ $tile['label'] }}</strong>
                    <span>{{ $tile['text'] }}</span>
                </div>
            </a>
        @endforeach
    </div>
</div>
<footer class="site-footer">
    <div>Copyright; Exam Section CUI Attock Campus</div>
    <div>developed by <a href="https://muhammadkamran.dev" target="_blank" rel="noopener">Muhammad Kamran</a></div>
</footer>
</body>
</html>
