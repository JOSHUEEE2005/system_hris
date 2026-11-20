<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EMPLOYEE_COLUMNS = [
        'middle_name',
        'nationality',
        'full_name',
        'regularized_date',
        'birth_date',
        'immediate_supervisor',
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

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $columns = [
                'middle_name' => fn () => $table->string('middle_name', 100)->nullable(),
                'nationality' => fn () => $table->string('nationality', 100)->nullable(),
                'full_name' => fn () => $table->string('full_name', 255)->nullable(),
                'regularized_date' => fn () => $table->date('regularized_date')->nullable(),
                'birth_date' => fn () => $table->date('birth_date')->nullable(),
                'immediate_supervisor' => fn () => $table->string('immediate_supervisor', 200)->nullable(),
                'place_of_birth' => fn () => $table->string('place_of_birth', 255)->nullable(),
                'sss_number' => fn () => $table->string('sss_number', 50)->nullable(),
                'tin_number' => fn () => $table->string('tin_number', 50)->nullable(),
                'hdmf_number' => fn () => $table->string('hdmf_number', 50)->nullable(),
                'philhealth_number' => fn () => $table->string('philhealth_number', 50)->nullable(),
                'personal_email' => fn () => $table->string('personal_email', 150)->nullable(),
                'emergency_contact_person' => fn () => $table->string('emergency_contact_person', 200)->nullable(),
                'emergency_contact_number' => fn () => $table->string('emergency_contact_number', 30)->nullable(),
                'hmo_status' => fn () => $table->string('hmo_status', 40)->nullable(),
                'ecu' => fn () => $table->string('ecu', 100)->nullable(),
                'mobile_subscription' => fn () => $table->string('mobile_subscription', 30)->nullable(),
                'gpcci_mobile_number' => fn () => $table->string('gpcci_mobile_number', 30)->nullable(),
                'sick_leave_days' => fn () => $table->decimal('sick_leave_days', 6, 2)->nullable(),
                'birthday_leave_days' => fn () => $table->decimal('birthday_leave_days', 6, 2)->nullable(),
                'vacation_leave_days' => fn () => $table->decimal('vacation_leave_days', 6, 2)->nullable(),
                'business_card' => fn () => $table->boolean('business_card')->nullable(),
            ];

            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn('employees', $column)) {
                    $definition();
                }
            }
        });

        Schema::create('interns', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('intern_no', 30)->unique();
            $table->string('intern_name', 255);
            $table->string('designation', 150)->nullable();
            $table->string('email', 150)->nullable()->index();
            $table->date('birth_date')->nullable();
            $table->date('internship_start_date')->nullable();
            $table->date('internship_end_date')->nullable();
            $table->string('gender', 30)->nullable();
            $table->integer('department_id')->nullable();
            $table->string('position', 150)->nullable();
            $table->string('immediate_supervisor', 200)->nullable();
            $table->text('address')->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('personal_email', 150)->nullable();
            $table->string('university', 200)->nullable();
            $table->string('course', 200)->nullable();
            $table->string('degree_expected', 200)->nullable();
            $table->string('internship_status', 30)->default('Active');
            $table->integer('user_id')->nullable()->unique();
            $table->timestamps();

            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        $legacyInterns = DB::table('employees')
            ->where('employment_type', 'Internship')
            ->orderBy('id')
            ->get();

        foreach ($legacyInterns as $legacyIntern) {
            $userId = DB::table('users')->where('employee_id', $legacyIntern->id)->value('id');
            $departmentId = $legacyIntern->department_id;
            if ($departmentId && DB::table('departments')->where('id', $departmentId)->value('department_name') === 'Interns') {
                $departmentId = null;
            }

            DB::table('interns')->insert([
                'intern_no' => $legacyIntern->employee_no,
                'intern_name' => trim($legacyIntern->first_name.' '.$legacyIntern->last_name),
                'designation' => $legacyIntern->position,
                'email' => $legacyIntern->email,
                'birth_date' => null,
                'internship_start_date' => $legacyIntern->hire_date,
                'internship_end_date' => $legacyIntern->last_date,
                'gender' => $legacyIntern->gender,
                'department_id' => $departmentId,
                'position' => $legacyIntern->position,
                'immediate_supervisor' => $legacyIntern->preparer,
                'address' => $legacyIntern->address,
                'contact_number' => $legacyIntern->contact_number,
                'personal_email' => null,
                'university' => null,
                'course' => null,
                'degree_expected' => null,
                'internship_status' => in_array(strtolower((string) $legacyIntern->status), ['inactive', 'disabled'], true)
                    ? 'Finished'
                    : 'Active',
                'user_id' => $userId,
                'created_at' => $legacyIntern->created_at,
                'updated_at' => $legacyIntern->updated_at,
            ]);

            if ($userId) {
                DB::table('users')->where('id', $userId)->update(['department_id' => $departmentId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('interns');

        Schema::table('employees', function (Blueprint $table): void {
            foreach (self::EMPLOYEE_COLUMNS as $column) {
                if (Schema::hasColumn('employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
