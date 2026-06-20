<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_docker_services', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('human_name')->nullable();
            $table->longText('description')->nullable();
            $table->string('image')->nullable();
            $table->enum('type', ['application', 'database'])->default('application');
            $table->string('status')->default('exited');
            $table->boolean('exclude_from_status')->default(false);
            $table->timestamp('last_online_at')->nullable();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_docker_services');
    }
};
