<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'contracts.view' => 'View contracts assigned to the signed-in user',
            'contracts.manage' => 'Upload and distribute employee contracts',
        ] as $permissionKey => $description) {
            DB::table('permissions')->updateOrInsert(
                ['permission_key' => $permissionKey],
                ['description' => $description, 'updated_at' => now()],
            );
        }

        $rolePermissions = [
            'super-admin' => ['contracts.view', 'contracts.manage'],
            'hr-manager' => ['contracts.view', 'contracts.manage'],
            'employee' => ['contracts.view'],
        ];

        foreach ($rolePermissions as $roleSlug => $permissionKeys) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) {
                continue;
            }

            $permissionIds = DB::table('permissions')
                ->whereIn('permission_key', $permissionKeys)
                ->pluck('id');

            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('permission_key', ['contracts.view', 'contracts.manage'])
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('permission_key', ['contracts.view', 'contracts.manage'])->delete();
    }
};
