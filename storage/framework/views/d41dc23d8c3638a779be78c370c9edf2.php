<?php
    $ruleQuery = $selectedRule ? ['rule_id' => $selectedRule->id] : [];
    $navItems = [
        ['label' => 'Home', 'route' => 'scheduler.home', 'icon' => 'home'],
        ['label' => 'Setup', 'route' => 'scheduler.index', 'icon' => 'settings'],
        ['label' => 'Datesheet Board', 'route' => 'scheduler.datesheet', 'icon' => 'calendar'],
        ['label' => 'Seat Plan', 'route' => 'scheduler.seat-plan', 'icon' => 'seats'],
        ['label' => 'Duty Roster', 'route' => 'scheduler.duty-roster', 'icon' => 'clipboard'],
        ['label' => 'Room-Course Grid', 'route' => 'scheduler.room-grid', 'icon' => 'building'],
        ['label' => 'Faculty', 'route' => 'scheduler.faculty', 'icon' => 'user'],
        ['label' => 'Faculty Availability', 'route' => 'scheduler.faculty-availability', 'icon' => 'clock'],
        ['label' => 'Courses', 'route' => 'scheduler.courses', 'icon' => 'book'],
        ['label' => 'Course Merge Builder', 'route' => 'scheduler.merge-courses', 'icon' => 'merge'],
        ['label' => 'Ignored Courses', 'route' => 'scheduler.ignore-courses', 'icon' => 'ban'],
        ['label' => 'Students', 'route' => 'scheduler.students', 'icon' => 'users'],
    ];
?>
<style>
    :root{--sidebar-width:252px}
    body{font-family:Arial,Helvetica,sans-serif;background:#f6f7fb;margin:0;color:#1f2937;padding-left:var(--sidebar-width);transition:padding-left .18s ease}
    body.scheduler-nav-collapsed{--sidebar-width:76px}
    .wrap{max-width:1450px;margin:0 auto;padding:18px}
    .sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-width);background:#111827;color:#fff;z-index:1000;display:flex;flex-direction:column;border-right:1px solid rgba(255,255,255,.08);transition:width .18s ease}
    .sidebar-head{display:flex;align-items:center;gap:10px;padding:12px 12px 10px;border-bottom:1px solid rgba(255,255,255,.08)}
    .sidebar-brand{display:flex;align-items:center;gap:10px;color:#fff;text-decoration:none;font-weight:800;min-width:0;flex:1}
    .sidebar-brand img{width:42px;height:42px;object-fit:contain;background:#fff;border-radius:6px;padding:3px;flex:0 0 auto}
    .sidebar-brand span{line-height:1.15;white-space:normal;overflow:visible;text-overflow:clip}
    .sidebar-brand .brand-line{display:block}
    .sidebar-toggle{width:34px;height:34px;border:1px solid rgba(255,255,255,.22);border-radius:8px;background:rgba(255,255,255,.06);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto}
    .sidebar-toggle svg,.nav-icon svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
    .sidebar-nav{padding:10px 8px;overflow:auto;display:flex;flex-direction:column;gap:4px}
    .sidebar-link{display:flex;align-items:center;gap:12px;min-height:42px;padding:0 10px;border-radius:8px;color:#e5e7eb;text-decoration:none;font-weight:700}
    .sidebar-link:hover,.sidebar-link.active{background:#1f2937;color:#fff;text-decoration:none}
    .nav-icon{width:24px;height:24px;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
    .nav-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-session{margin-top:auto;padding:12px;border-top:1px solid rgba(255,255,255,.08)}
    .sidebar-session span{display:block;margin-bottom:6px;color:#d1d5db;font-size:12px;font-weight:800}
    .sidebar-session select{color:#111827}
    body.scheduler-nav-collapsed .sidebar-brand span,
    body.scheduler-nav-collapsed .nav-label,
    body.scheduler-nav-collapsed .sidebar-session{display:none}
    body.scheduler-nav-collapsed .sidebar-head{justify-content:center;padding-left:8px;padding-right:8px;flex-wrap:wrap}
    body.scheduler-nav-collapsed .sidebar-brand{flex:0 0 auto}
    body.scheduler-nav-collapsed .sidebar-link{justify-content:center;padding:0}
    body.scheduler-nav-collapsed .sidebar-toggle svg{transform:rotate(180deg)}
    .panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin:14px 0;box-shadow:0 1px 3px rgba(0,0,0,.05)}
    .grid{display:grid;gap:12px}
    .grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}
    .grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
    label{display:block;font-size:12px;font-weight:700;margin-bottom:4px;color:#374151}
    input,select,textarea{width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:8px;font-size:13px;background:#fff}
    textarea{min-height:76px}
    .btn{border:0;border-radius:8px;background:#2563eb;color:#fff;padding:8px 12px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
    .btn.secondary{background:#4b5563}
    .btn.green{background:#047857}
    .btn.red{background:#b91c1c}
    .btn.small{font-size:12px;padding:5px 8px}
    .muted{color:#6b7280;font-size:12px}
    .status{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:8px;padding:10px;margin:10px 0}
    .warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:10px;margin:10px 0}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #e5e7eb;padding:8px;font-size:13px;vertical-align:top}
    th{background:#f3f4f6;text-align:left}
    @media (max-width: 900px){:root{--sidebar-width:76px}.grid-2,.grid-3{grid-template-columns:1fr}body{padding-left:76px}.sidebar-brand span,.nav-label,.sidebar-session{display:none}.sidebar-head{justify-content:center;padding-left:8px;padding-right:8px;flex-wrap:wrap}.sidebar-brand{flex:0 0 auto}.sidebar-link{justify-content:center;padding:0}}
</style>
<script>
    if (localStorage.getItem('schedulerSidebarCollapsed') === '1') {
        document.body.classList.add('scheduler-nav-collapsed');
    }
</script>
<aside class="sidebar" aria-label="Scheduler navigation">
    <div class="sidebar-head">
        <a class="sidebar-brand" href="<?php echo e(route('scheduler.home', $ruleQuery)); ?>" aria-label="COMSATS University scheduler home">
            <img src="<?php echo e(asset('images/comsats-logo.jpg')); ?>" alt="COMSATS University logo">
            <span><span class="brand-line">CUI</span><span class="brand-line">ExamFlow</span></span>
        </a>
        <button class="sidebar-toggle" type="button" aria-label="Collapse navigation" title="Collapse navigation" onclick="document.body.classList.toggle('scheduler-nav-collapsed'); localStorage.setItem('schedulerSidebarCollapsed', document.body.classList.contains('scheduler-nav-collapsed') ? '1' : '0'); this.setAttribute('aria-label', document.body.classList.contains('scheduler-nav-collapsed') ? 'Expand navigation' : 'Collapse navigation'); this.setAttribute('title', document.body.classList.contains('scheduler-nav-collapsed') ? 'Expand navigation' : 'Collapse navigation');">
            <svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
    </div>

    <nav class="sidebar-nav">
        <?php $__currentLoopData = $navItems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <a class="sidebar-link <?php echo e(request()->routeIs($item['route']) ? 'active' : ''); ?>" href="<?php echo e(route($item['route'], $ruleQuery)); ?>" title="<?php echo e($item['label']); ?>">
                <span class="nav-icon" aria-hidden="true">
                    <?php switch($item['icon']):
                        case ('home'): ?>
                            <svg viewBox="0 0 24 24"><path d="M3 11 12 3l9 8"/><path d="M5 10v11h14V10"/><path d="M9 21v-6h6v6"/></svg>
                            <?php break; ?>
                        <?php case ('settings'): ?>
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1l2-1.5-2-3.4-2.4 1a7 7 0 0 0-1.8-1L14.4 3h-4l-.4 3a7 7 0 0 0-1.8 1l-2.4-1-2 3.4 2 1.5a7 7 0 0 0 0 2.2l-2 1.5 2 3.4 2.4-1a7 7 0 0 0 1.8 1l.4 3h4l.4-3a7 7 0 0 0 1.8-1l2.4 1 2-3.4-2-1.5a7 7 0 0 0 .1-1Z"/></svg>
                            <?php break; ?>
                        <?php case ('calendar'): ?>
                            <svg viewBox="0 0 24 24"><path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/></svg>
                            <?php break; ?>
                        <?php case ('seats'): ?>
                            <svg viewBox="0 0 24 24"><path d="M7 8h10"/><path d="M7 12h10"/><path d="M5 20V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v14"/><path d="M4 20h16"/></svg>
                            <?php break; ?>
                        <?php case ('clipboard'): ?>
                            <svg viewBox="0 0 24 24"><rect x="6" y="4" width="12" height="18" rx="2"/><path d="M9 4a3 3 0 0 1 6 0"/><path d="M9 12h6"/><path d="M9 16h4"/></svg>
                            <?php break; ?>
                        <?php case ('building'): ?>
                            <svg viewBox="0 0 24 24"><path d="M4 22V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v18"/><path d="M17 8h1a2 2 0 0 1 2 2v12"/><path d="M8 6h4"/><path d="M8 10h4"/><path d="M8 14h4"/><path d="M8 18h4"/></svg>
                            <?php break; ?>
                        <?php case ('user'): ?>
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M5.5 21a6.5 6.5 0 0 1 13 0"/></svg>
                            <?php break; ?>
                        <?php case ('clock'): ?>
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            <?php break; ?>
                        <?php case ('book'): ?>
                            <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5z"/></svg>
                            <?php break; ?>
                        <?php case ('merge'): ?>
                            <svg viewBox="0 0 24 24"><path d="M6 3v6a6 6 0 0 0 6 6h6"/><path d="M18 9l3 3-3 3"/><path d="M6 21v-6a6 6 0 0 1 6-6h2"/></svg>
                            <?php break; ?>
                        <?php case ('ban'): ?>
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6 18.4 18.4"/></svg>
                            <?php break; ?>
                        <?php case ('users'): ?>
                            <svg viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M17 11a3 3 0 1 0 0-6"/><path d="M22 21a5 5 0 0 0-5-5"/></svg>
                            <?php break; ?>
                    <?php endswitch; ?>
                </span>
                <span class="nav-label"><?php echo e($item['label']); ?></span>
            </a>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </nav>

    <?php if(isset($rules)): ?>
        <form class="sidebar-session" method="get" action="<?php echo e(url()->current()); ?>">
            <span>Session</span>
            <select name="rule_id" onchange="this.form.submit()">
                <?php $__currentLoopData = $rules; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $rule): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($rule->id); ?>" <?php if($selectedRule && $selectedRule->id === $rule->id): echo 'selected'; endif; ?>>
                        #<?php echo e($rule->id); ?> <?php echo e($rule->session_code); ?> <?php echo e($rule->exam_title ?: $rule->session_label); ?>

                    </option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </select>
        </form>
    <?php endif; ?>
</aside>
<?php /**PATH /var/www/html/resources/views/scheduler/partials/nav.blade.php ENDPATH**/ ?>