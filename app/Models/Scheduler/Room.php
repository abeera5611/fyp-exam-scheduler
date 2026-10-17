<?php

namespace App\Models\Scheduler;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    protected $table = 'scheduler_room';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'contingency_only' => 'boolean',
        'allow_split' => 'boolean',
        'is_waiting_room' => 'boolean',
    ];

    protected $appends = [
        'checkerboard_capacity',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(RoomAllocation::class, 'room_id');
    }

    public function getCheckerboardCapacityAttribute(): int
    {
        $rows = (int) ($this->rows ?? 0);
        $cols = (int) ($this->cols ?? 0);
        $seats = 0;

        for ($r = 1; $r <= $rows; $r++) {
            for ($c = 1; $c <= $cols; $c++) {
                if ((($r + $c) % 2) === 0) {
                    $seats++;
                }
            }
        }

        return $seats;
    }

    public function effectiveCapacity(): int
    {
        $declared = (int) $this->capacity;
        if ($declared > 0) {
            return $declared;
        }

        $grid = ((int) $this->rows) * ((int) $this->cols);

        return max(0, $grid);
    }

    public function looksLikeWaitingRoom(): bool
    {
        if ((bool) ($this->is_waiting_room ?? false)) {
            return true;
        }

        $text = strtolower(trim(preg_replace('/\s+/', ' ', ((string) $this->room_id) . ' ' . ((string) $this->room_name))));
        if ($text === '') {
            return false;
        }

        return str_contains($text, 'waiting room') || str_contains($text, 'waitingroom');
    }

    public function isAllocatableExamRoom(): bool
    {
        return !$this->looksLikeWaitingRoom() && !(bool) $this->contingency_only;
    }

    public function isUsableNonWaitingRoom(): bool
    {
        return !$this->looksLikeWaitingRoom();
    }
}
