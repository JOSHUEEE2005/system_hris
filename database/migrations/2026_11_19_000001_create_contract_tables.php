<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contracts')) {
            Schema::create('contracts', function (Blueprint $table): void {
                $table->integer('id')->autoIncrement();
                $table->string('title', 200);
                $table->string('original_filename', 255);
                $table->string('storage_path', 500);
                $table->string('mime_type', 255);
                $table->unsignedBigInteger('file_size');
                $table->string('recipient_type', 20);
                $table->integer('department_id')->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('contract_recipients')) {
            Schema::create('contract_recipients', function (Blueprint $table): void {
                $table->integer('id')->autoIncrement();
                $table->integer('contract_id');
                $table->integer('user_id');
                $table->string('status', 20)->default('Pending');
                $table->timestamp('received_at')->nullable();
                $table->timestamps();

                $table->unique(['contract_id', 'user_id']);
                $table->index(['user_id', 'status']);
                $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_recipients');
        Schema::dropIfExists('contracts');
    }
};
