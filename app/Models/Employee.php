<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    protected $fillable = [
        'employee_no',
        'first_name',
        'middle_name',
        'last_name',
        'full_name',
        'nationality',
        'email',
        'contact_number',
        'address',
        'gender',
        'birth_date',
        'hire_date',
        'regularized_date',
        'period',
        'preparer',
        'assigned_it',
        'immediate_supervisor',
        'last_date',
        'employment_status',
        'employment_type',
        'department_id',
        'position',
        'salary',
        'status',
        'place_of_birth',
        'sss_number',
        'tin_number',
        'hdmf_number',
        'philhealth_number',
        'personal_email',
        'emergency_contact_person',
        'emergency_contact_number',
        'hmo_status',
        'ecu',
        'mobile_subscription',
        'gpcci_mobile_number',
        'sick_leave_days',
        'birthday_leave_days',
        'vacation_leave_days',
        'business_card',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'regularized_date' => 'date',
        'birth_date' => 'date',
        'last_date' => 'date',
        'salary' => 'decimal:2',
        'sick_leave_days' => 'decimal:2',
        'birthday_leave_days' => 'decimal:2',
        'vacation_leave_days' => 'decimal:2',
        'business_card' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
