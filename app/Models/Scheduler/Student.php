<?php

namespace App\Models\Scheduler;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $table = 'scheduler_student';

    public $timestamps = false;

    protected $guarded = [];

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'scheduler_course_students', 'student_id', 'course_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(CourseRegistration::class, 'student_id');
    }
}
