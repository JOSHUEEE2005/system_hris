<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['permission_key' => 'contracts.view'],
            [
                'description' => 'View contracts assigned to the signed-in user',
                'updated_at' => now(),
            ],
        );

        $roleId = DB::table('roles')->where('slug', 'employee')->value('id');
        $permissionId = DB::table('permissions')->where('permission_key', 'contracts.view')->value('id');

        if ($roleId && $permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'employee')->value('id');
        $permissionId = DB::table('permissions')->where('permission_key', 'contracts.view')->value('id');

        if ($roleId && $permissionId) {
            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->delete();
        }
    }
};
