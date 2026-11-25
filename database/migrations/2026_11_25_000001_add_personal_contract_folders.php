<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_folders', function (Blueprint $table): void {
            $table->dropUnique('contract_folders_name_unique');
            $table->integer('user_id')->nullable()->after('created_by')->unique();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $users = DB::table('users')
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
            })
            ->get(['id', 'name']);

        $now = now();
        foreach ($users as $user) {
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
        DB::table('contract_folders')->whereNotNull('user_id')->delete();

        Schema::table('contract_folders', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
            $table->unique('name');
        });
    }
};
