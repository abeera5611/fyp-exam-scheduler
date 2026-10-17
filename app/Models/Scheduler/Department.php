<?php

namespace App\Models\Scheduler;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $table = 'scheduler_department';

    public $timestamps = false;

    protected $guarded = [];

    public function faculties(): HasMany
    {
        return $this->hasMany(Faculty::class, 'department', 'name');
    }
}
