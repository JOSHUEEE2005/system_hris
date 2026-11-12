<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'users.manage' => 'Manage user accounts',
            'roles.manage' => 'Manage roles and permissions',
            'departments.manage' => 'Manage departments',
            'employees.manage' => 'Manage employee records',
            'onboarding.review' => 'Review onboarding requests',
            'audit.view' => 'View administrative audit history',
            'attendance.view' => 'View attendance records',
            'attendance.manage' => 'Manage attendance records',
            'leave_requests.view' => 'View leave requests',
            'leave_requests.approve' => 'Approve or reject leave requests',
            'leave_requests.manage' => 'Manage leave requests',
            'profile.self_view' => 'View own employee profile',
            'profile.self_update' => 'Edit permitted fields on own profile',
            'attendance.self_view' => 'View own attendance',
            'leave_requests.self_manage' => 'Submit and view own leave requests',
        ];

        foreach ($permissions as $key => $description) {
            Permission::updateOrCreate(['permission_key' => $key], ['description' => $description]);
        }

        $superAdmin = Role::updateOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super Admin', 'description' => 'Full system administration'],
        );
        $hrManager = Role::updateOrCreate(
            ['slug' => 'hr-manager'],
            ['name' => 'HR Manager', 'description' => 'Manage employee records and onboarding'],
        );
        $employee = Role::updateOrCreate(
            ['slug' => 'employee'],
            ['name' => 'Employee', 'description' => 'Employee self-service access'],
        );

        $superAdmin->permissions()->sync(Permission::all()->modelKeys());
        $hrManager->permissions()->sync(
            Permission::whereIn('permission_key', [
                'departments.manage',
                'employees.manage',
                'onboarding.review',
                'attendance.view',
                'attendance.manage',
                'leave_requests.view',
                'leave_requests.approve',
                'leave_requests.manage',
            ])->pluck('id'),
        );
        $employee->permissions()->sync(
            Permission::whereIn('permission_key', [
                'profile.self_view',
                'profile.self_update',
                'attendance.self_view',
                'leave_requests.self_manage',
            ])->pluck('id'),
        );
    }
}
