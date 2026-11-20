<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_folders', function (Blueprint $table): void {
            $table->integer('id')->autoIncrement();
            $table->string('name', 100)->unique();
            $table->integer('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->integer('folder_id')->nullable()->after('department_id');
            $table->foreign('folder_id')->references('id')->on('contract_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropForeign(['folder_id']);
            $table->dropColumn('folder_id');
        });

        Schema::dropIfExists('contract_folders');
    }
};
