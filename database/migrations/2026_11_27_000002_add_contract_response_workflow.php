<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->string('response_mode', 30)->default('as_is');
        });

        Schema::table('contract_recipients', function (Blueprint $table): void {
            $table->string('returned_storage_path', 500)->nullable();
            $table->string('returned_filename', 255)->nullable();
            $table->string('returned_mime_type', 255)->nullable();
            $table->unsignedBigInteger('returned_file_size')->nullable();
            $table->text('review_feedback')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contract_recipients', function (Blueprint $table): void {
            $table->dropColumn([
                'returned_storage_path',
                'returned_filename',
                'returned_mime_type',
                'returned_file_size',
                'review_feedback',
                'submitted_at',
                'reviewed_at',
            ]);
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn('response_mode');
        });
    }
};
