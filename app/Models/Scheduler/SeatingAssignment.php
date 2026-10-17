<?php

namespace App\Models\Scheduler;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatingAssignment extends Model
{
    protected $table = 'scheduler_seatingassignment';

    public $timestamps = false;

    protected $guarded = [];

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(RoomAllocation::class, 'allocation_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
