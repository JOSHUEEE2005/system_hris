<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['department_name'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function interns(): HasMany
    {
        return $this->hasMany(Intern::class);
    }
}
