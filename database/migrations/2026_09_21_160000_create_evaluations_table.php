<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('situation');
            $table->string('locale', 8)->default('en');
            $table->json('conditions');
            $table->string('status', 24)->default('pending');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->string('model')->nullable();
            $table->string('engine', 32)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
