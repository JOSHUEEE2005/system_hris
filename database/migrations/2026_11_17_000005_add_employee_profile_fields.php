<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'last_date')) {
                $table->date('last_date')->nullable()->after('hire_date');
            }
            if (! Schema::hasColumn('employees', 'employment_status')) {
                $table->string('employment_status', 30)->nullable()->after('last_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasColumn('employees', 'employment_status')) {
                $table->dropColumn('employment_status');
            }
            if (Schema::hasColumn('employees', 'last_date')) {
                $table->dropColumn('last_date');
            }
        });
    }
};
