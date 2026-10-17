<?php

return [
    'templates' => [
        'midterm' => storage_path('app/scheduler/templates/midterm_template.xlsx'),
        'terminal' => storage_path('app/scheduler/templates/terminal_template.xlsx'),
    ],
    'exports' => [
        'path' => storage_path('app/scheduler/exports'),
        'logo_path' => public_path('images/comsats-logo.jpg'),
        'exam_header' => 'Terminal Exam SP26',
    ],
    'imports' => [
        'path' => storage_path('app/scheduler/imports'),
    ],
];
