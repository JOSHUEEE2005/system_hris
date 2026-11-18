<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'Chamber Services',
            'Dual Training',
            'Government Affairs',
            'Trade Promotions',
            'Communications',
            'Internal Org',
            'Interns',
        ] as $department) {
            DB::table('departments')->insertOrIgnore([
                'department_name' => $department,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('departments')->whereIn('department_name', [
            'Chamber Services',
            'Dual Training',
            'Government Affairs',
            'Trade Promotions',
            'Communications',
            'Internal Org',
            'Interns',
        ])->delete();
    }
};
