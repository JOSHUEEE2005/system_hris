<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Intern extends Model
{
    protected $fillable = [
        'intern_no',
        'intern_name',
        'designation',
        'email',
        'birth_date',
        'internship_start_date',
        'internship_end_date',
        'gender',
        'department_id',
        'position',
        'immediate_supervisor',
        'address',
        'contact_number',
        'personal_email',
        'university',
        'course',
        'degree_expected',
        'internship_status',
        'user_id',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'internship_start_date' => 'date',
        'internship_end_date' => 'date',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
