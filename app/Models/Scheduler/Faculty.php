<?php

namespace App\Models\Scheduler;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Faculty extends Model
{
    protected $table = 'scheduler_faculty';

    public $timestamps = false;

    protected $guarded = [];

    public function assignments(): HasMany
    {
        return $this->hasMany(CourseFacultyAssignment::class, 'faculty_id');
    }

    public function roomAllocations(): HasMany
    {
        return $this->hasMany(RoomAllocation::class, 'invigilator_id');
    }

    public function getRemainingDutiesAttribute(): int
    {
        return max(0, ((int) $this->max_duties) - ((int) $this->assigned_duties));
    }
}
