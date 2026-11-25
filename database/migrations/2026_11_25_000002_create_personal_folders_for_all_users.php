<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (DB::table('users')->get(['id', 'name']) as $user) {
            DB::table('contract_folders')->insertOrIgnore([
                'name' => mb_substr(trim((string) $user->name) ?: 'User '.$user->id, 0, 100),
                'created_by' => null,
                'user_id' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $employeesAndInterns = DB::table('users')
            ->select('users.id')
            ->where(function ($query): void {
                $query->whereNotNull('employee_id')
                    ->orWhereExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('interns')
                            ->whereColumn('interns.user_id', 'users.id');
                    })
                    ->orWhereExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('user_roles')
                            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                            ->whereColumn('user_roles.user_id', 'users.id')
                            ->where('roles.slug', 'employee');
                    });
            });

        DB::table('contract_folders')
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', $employeesAndInterns)
            ->delete();
    }
};
