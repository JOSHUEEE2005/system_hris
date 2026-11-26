<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_recipients', function (Blueprint $table): void {
            $table->timestamp('opened_at')->nullable()->after('status');
        });

        DB::table('contract_recipients')
            ->where('status', 'Received')
            ->update([
                'status' => 'Opened',
                'opened_at' => DB::raw('received_at'),
            ]);
    }

    public function down(): void
    {
        DB::table('contract_recipients')
            ->where('status', 'Opened')
            ->update([
                'status' => 'Received',
                'received_at' => DB::raw('COALESCE(received_at, opened_at)'),
            ]);

        Schema::table('contract_recipients', function (Blueprint $table): void {
            $table->dropColumn('opened_at');
        });
    }
};
