<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'period')) {
                $table->string('period', 100)->nullable()->after('hire_date');
            }
            if (! Schema::hasColumn('employees', 'preparer')) {
                $table->string('preparer', 150)->nullable()->after('period');
            }
            if (! Schema::hasColumn('employees', 'assigned_it')) {
                $table->string('assigned_it', 150)->nullable()->after('preparer');
            }
            if (! Schema::hasColumn('employees', 'employment_type')) {
                $table->string('employment_type', 20)->default('Employment')->after('assigned_it');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            foreach (['period', 'preparer', 'assigned_it', 'employment_type'] as $column) {
                if (Schema::hasColumn('employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
