<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('interns', 'period')) {
            Schema::table('interns', function (Blueprint $table): void {
                $table->string('period', 100)->nullable()->after('internship_start_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('interns', 'period')) {
            Schema::table('interns', function (Blueprint $table): void {
                $table->dropColumn('period');
            });
        }
    }
};
