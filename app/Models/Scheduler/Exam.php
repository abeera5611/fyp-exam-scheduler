<?php

namespace App\Models\Scheduler;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Exam extends Model
{
    protected $table = 'scheduler_exam';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DateSheetRule::class, 'rule_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RoomAllocation::class, 'exam_id');
    }

    public function examStudents(): HasMany
    {
        return $this->hasMany(ExamStudent::class, 'exam_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'scheduler_exam_student', 'exam_id', 'student_id')
            ->withPivot('source_class_label');
    }
}
