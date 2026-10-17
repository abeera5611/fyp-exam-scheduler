<?php

namespace App\Models\Scheduler;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $table = 'scheduler_course';

    public $timestamps = false;

    protected $guarded = [];

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'scheduler_course_students', 'course_id', 'student_id');
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'course_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(CourseRegistration::class, 'course_id');
    }

    public function facultyAssignments(): HasMany
    {
        return $this->hasMany(CourseFacultyAssignment::class, 'course_id');
    }
}
