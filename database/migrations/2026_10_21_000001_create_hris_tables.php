<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->string('department_name', 100)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->string('employee_no', 20)->unique();
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->string('email', 150)->nullable()->unique();
                $table->string('contact_number', 20)->nullable();
                $table->text('address')->nullable();
                $table->string('gender', 20)->nullable();
                $table->date('hire_date')->nullable();
                $table->integer('department_id')->nullable();
                $table->string('position', 100)->nullable();
                $table->decimal('salary', 12, 2)->nullable();
                $table->string('status', 20)->default('Active');
                $table->timestamps();
                $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            });
        }

        $this->ensureUserColumns();

        if (! Schema::hasTable('attendance')) {
            Schema::create('attendance', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->integer('employee_id');
                $table->date('attendance_date');
                $table->time('time_in')->nullable();
                $table->time('time_out')->nullable();
                $table->timestamps();
                $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
                $table->unique(['employee_id', 'attendance_date']);
            });
        }

        if (! Schema::hasTable('leave_requests')) {
            Schema::create('leave_requests', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->integer('employee_id');
                $table->string('leave_type', 50)->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->text('reason')->nullable();
                $table->string('status', 20)->default('Pending');
                $table->timestamps();
                $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->string('name', 100)->unique();
                $table->string('slug', 100)->unique();
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->string('permission_key', 100)->unique();
                $table->string('description')->nullable();
                $table->timestamps();
            });
        } else {
            $this->ensureTimestamps('permissions');
        }

        if (! Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->integer('role_id');
                $table->integer('permission_id');
                $table->primary(['role_id', 'permission_id']);
                $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete()->cascadeOnUpdate();
                $table->foreign('permission_id')->references('id')->on('permissions')->restrictOnDelete()->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('user_roles')) {
            Schema::create('user_roles', function (Blueprint $table) {
                $table->integer('user_id');
                $table->integer('role_id');
                $table->primary(['user_id', 'role_id']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()->cascadeOnUpdate();
                $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete()->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('email_otps')) {
            Schema::create('email_otps', function (Blueprint $table) {
                $table->char('challenge_id', 36)->primary();
                $table->string('email', 150);
                $table->string('purpose', 30)->default('registration');
                $table->char('otp_hash', 64);
                $table->char('registration_token_hash', 64)->nullable()->unique();
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->dateTime('expires_at');
                $table->dateTime('verified_at')->nullable();
                $table->dateTime('registration_token_expires_at')->nullable();
                $table->dateTime('consumed_at')->nullable();
                $table->dateTime('invalidated_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
                $table->index(['email', 'purpose', 'created_at'], 'idx_email_otps_lookup');
            });
        } else {
            $this->ensureUpdatedAt('email_otps');
        }

        if (! Schema::hasTable('onboarding_requests')) {
            Schema::create('onboarding_requests', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->integer('user_id')->unique();
                $table->string('contact_number', 20)->nullable();
                $table->string('status', 20)->default('Pending');
                $table->integer('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()->cascadeOnUpdate();
                $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete()->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('actor_user_id')->nullable();
                $table->string('event_key', 100);
                $table->string('entity_type', 100);
                $table->string('entity_id', 64)->nullable();
                $table->json('details')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
                $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete()->cascadeOnUpdate();
                $table->index(['entity_type', 'entity_id'], 'idx_audit_logs_entity');
                $table->index(['actor_user_id', 'created_at'], 'idx_audit_logs_actor_created');
            });
        } else {
            $this->ensureUpdatedAt('audit_logs');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_requests');
    }

    private function ensureUserColumns(): void
    {
        if (! Schema::hasTable('users')) {
            throw new RuntimeException('The users table must exist before the HRIS migration runs.');
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'name')) {
                $table->string('name')->nullable();
            }
            if (! Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'remember_token')) {
                $table->rememberToken();
            }
            if (! Schema::hasColumn('users', 'employee_id')) {
                $table->integer('employee_id')->nullable()->unique();
            }
        });

        if (! collect(Schema::getForeignKeys('users'))->contains(
            fn (array $foreignKey): bool => in_array('employee_id', $foreignKey['columns'], true),
        )) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreign('employee_id')->references('id')->on('employees')
                    ->restrictOnDelete()->cascadeOnUpdate();
            });
        }
    }

    private function ensureTimestamps(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (! Schema::hasColumn($tableName, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn($tableName, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function ensureUpdatedAt(string $tableName): void
    {
        if (! Schema::hasColumn($tableName, 'updated_at')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->timestamp('updated_at')->nullable();
            });
        }
    }
};
