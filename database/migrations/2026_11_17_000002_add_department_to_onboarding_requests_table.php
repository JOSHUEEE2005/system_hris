<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('onboarding_requests', 'department_id')) {
            Schema::table('onboarding_requests', function (Blueprint $table): void {
                $table->integer('department_id')->nullable()->after('contact_number');
                $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('onboarding_requests', 'department_id')) {
            Schema::table('onboarding_requests', function (Blueprint $table): void {
                $table->dropForeign(['department_id']);
                $table->dropColumn('department_id');
            });
        }
    }
};
