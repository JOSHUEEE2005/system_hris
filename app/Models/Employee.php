<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    protected $fillable = [
        'employee_no',
        'first_name',
        'last_name',
        'email',
        'contact_number',
        'address',
        'gender',
        'hire_date',
        'department_id',
        'position',
        'salary',
        'status',
    ];

    protected function casts(): array
    {
        return ['hire_date' => 'date', 'salary' => 'decimal:2'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
