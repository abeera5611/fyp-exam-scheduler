<?php

use App\Http\Controllers\Scheduler\SchedulerOpsController;
use App\Http\Controllers\Scheduler\SchedulerUiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Scheduler routes
|--------------------------------------------------------------------------
|
| /scheduler remains the setup/dashboard page. The operational boards are
| intentionally split into separate pages so the datesheet, seat plan,
| duty roster, and room-course invigilator grid can be managed independently.
|
*/

Route::middleware(['web'])->prefix('scheduler')->name('scheduler.')->group(function () {
    Route::get('/home', [SchedulerUiController::class, 'home'])->name('home');
    Route::get('/', [SchedulerUiController::class, 'index'])->name('index');
    Route::get('/datesheet', [SchedulerUiController::class, 'datesheet'])->name('datesheet');
    Route::get('/seat-plan', [SchedulerUiController::class, 'seatPlan'])->name('seat-plan');
    Route::get('/duty-roster', [SchedulerUiController::class, 'dutyRoster'])->name('duty-roster');
    Route::get('/room-grid', [SchedulerUiController::class, 'roomGrid'])->name('room-grid');
    Route::get('/faculty', [SchedulerUiController::class, 'faculty'])->name('faculty');
    Route::get('/faculty-availability', [SchedulerUiController::class, 'facultyAvailability'])->name('faculty-availability');
    Route::get('/courses', [SchedulerUiController::class, 'courses'])->name('courses');
    Route::get('/merge-courses', [SchedulerUiController::class, 'mergeCourses'])->name('merge-courses');
    Route::get('/ignore-courses', [SchedulerUiController::class, 'ignoreCourses'])->name('ignore-courses');
    Route::get('/students', [SchedulerUiController::class, 'students'])->name('students');

    Route::post('/rules', [SchedulerUiController::class, 'storeRule'])->name('rules.store');
    Route::post('/rooms', [SchedulerUiController::class, 'storeRoom'])->name('rooms.store');
    Route::post('/faculty', [SchedulerUiController::class, 'storeFaculty'])->name('faculty.store');
    Route::post('/departments', [SchedulerUiController::class, 'storeDepartment'])->name('departments.store');
    Route::delete('/departments/{department}', [SchedulerUiController::class, 'deleteDepartment'])->name('departments.delete');
    Route::post('/designations', [SchedulerUiController::class, 'storeDesignation'])->name('designations.store');
    Route::delete('/designations/{designation}', [SchedulerUiController::class, 'deleteDesignation'])->name('designations.delete');
    Route::post('/faculty/bulk', [SchedulerUiController::class, 'bulkUpdateFaculty'])->name('faculty.bulk');
    Route::post('/faculty-availability', [SchedulerUiController::class, 'storeFacultyAvailability'])->name('faculty-availability.store');
    Route::post('/courses/bulk', [SchedulerUiController::class, 'bulkUpdateCourses'])->name('courses.bulk');
    Route::post('/courses/import-titles', [SchedulerUiController::class, 'importCourseTitles'])->name('courses.import-titles');
    Route::post('/merge-courses', [SchedulerUiController::class, 'storeMergeCourses'])->name('merge-courses.store');
    Route::post('/ignore-courses', [SchedulerUiController::class, 'storeIgnoreCourses'])->name('ignore-courses.store');
    Route::post('/students/clear', [SchedulerUiController::class, 'clearStudents'])->name('students.clear');
    Route::post('/students/import-workbook', [SchedulerUiController::class, 'importStudentsWorkbook'])->name('students.import');
    Route::post('/import-workbook', [SchedulerUiController::class, 'importWorkbook'])->name('import');
    Route::post('/generate-datesheet', [SchedulerUiController::class, 'generateDateSheet'])->name('generate');
    Route::post('/rename-slot', [SchedulerUiController::class, 'renameSlot'])->name('rename-slot');
    Route::post('/allocate-rooms', [SchedulerUiController::class, 'allocateRooms'])->name('allocate');
    Route::post('/allocate-rooms-all', [SchedulerUiController::class, 'allocateRoomsAll'])->name('allocate-all');
    Route::post('/generate-seating', [SchedulerUiController::class, 'generateSeating'])->name('seating');
    Route::post('/generate-seating-all', [SchedulerUiController::class, 'generateSeatingAll'])->name('seating-all');
    Route::post('/assign-invigilators', [SchedulerUiController::class, 'assignInvigilators'])->name('invigilators');
    Route::post('/export-datesheet', [SchedulerUiController::class, 'exportDateSheet'])->name('export');
    Route::get('/export-operations', [SchedulerUiController::class, 'exportOperations'])->name('export-operations');
});

Route::middleware(['web'])->prefix('scheduler-api')->name('scheduler.api.')->group(function () {
    Route::post('/generate-datesheet', [SchedulerOpsController::class, 'generateDateSheet'])->name('generate');
    Route::post('/allocate-rooms', [SchedulerOpsController::class, 'allocateRooms'])->name('allocate');
    Route::post('/allocate-rooms-all', [SchedulerOpsController::class, 'allocateRoomsAll'])->name('allocate-all');
    Route::post('/generate-seating', [SchedulerOpsController::class, 'generateSeating'])->name('seating');
    Route::post('/generate-seating-all', [SchedulerOpsController::class, 'generateSeatingAll'])->name('seating-all');
    Route::post('/assign-invigilators', [SchedulerOpsController::class, 'assignInvigilators'])->name('invigilators');
    Route::post('/export-datesheet', [SchedulerOpsController::class, 'exportDateSheet'])->name('export');

    Route::get('/room-board', [SchedulerOpsController::class, 'roomBoard'])->name('room-board');
    Route::get('/seat-plan-board', [SchedulerOpsController::class, 'seatPlanBoard'])->name('seat-plan-board');
    Route::get('/duty-roster', [SchedulerOpsController::class, 'dutyRoster'])->name('duty-roster');
    Route::get('/room-course-grid', [SchedulerOpsController::class, 'roomCourseGrid'])->name('room-course-grid');

    Route::get('/cluster-details', [SchedulerOpsController::class, 'clusterDetails'])->name('cluster-details');
    Route::get('/class-day-clash-report', [SchedulerOpsController::class, 'classDayClashReport'])->name('class-day-clash-report');
    Route::post('/validate-cluster-move', [SchedulerOpsController::class, 'validateClusterMove'])->name('validate-cluster-move');
    Route::post('/reschedule-cluster', [SchedulerOpsController::class, 'rescheduleCluster'])->name('reschedule-cluster');
    Route::post('/validate-cluster-swap', [SchedulerOpsController::class, 'validateClusterSwap'])->name('validate-cluster-swap');
    Route::post('/swap-clusters', [SchedulerOpsController::class, 'swapClusters'])->name('swap-clusters');
    Route::post('/move-cluster', [SchedulerOpsController::class, 'moveCluster'])->name('move-cluster');
    Route::post('/move-allocation', [SchedulerOpsController::class, 'moveAllocation'])->name('move-allocation');
    Route::post('/move-seat-assignments', [SchedulerOpsController::class, 'moveSeatAssignments'])->name('move-seat-assignments');
    Route::post('/move-seat-row', [SchedulerOpsController::class, 'moveSeatRow'])->name('move-seat-row');
    Route::post('/move-invigilator', [SchedulerOpsController::class, 'moveInvigilator'])->name('move-invigilator');
    Route::post('/assign-invigilator', [SchedulerOpsController::class, 'assignInvigilator'])->name('assign-invigilator');
    Route::post('/remove-invigilator', [SchedulerOpsController::class, 'removeInvigilator'])->name('remove-invigilator');
});
