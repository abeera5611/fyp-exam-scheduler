<?php

namespace App\Models\Scheduler;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomAllocation extends Model
{
    protected $table = 'scheduler_roomallocation';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_duty_only' => 'boolean',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function invigilator(): BelongsTo
    {
        return $this->belongsTo(Faculty::class, 'invigilator_id');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(SeatingAssignment::class, 'allocation_id');
    }
}
