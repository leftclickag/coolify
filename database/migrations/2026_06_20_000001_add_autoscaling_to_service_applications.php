<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_applications', function (Blueprint $table) {
            $table->unsignedInteger('replicas')->default(1)->after('is_migrated');
            $table->boolean('autoscale_enabled')->default(false)->after('replicas');
            $table->unsignedInteger('autoscale_min_replicas')->default(1)->after('autoscale_enabled');
            $table->unsignedInteger('autoscale_max_replicas')->default(5)->after('autoscale_min_replicas');
            $table->unsignedSmallInteger('autoscale_cpu_threshold')->nullable()->after('autoscale_max_replicas');
            $table->unsignedSmallInteger('autoscale_memory_threshold')->nullable()->after('autoscale_cpu_threshold');
            $table->unsignedInteger('autoscale_cooldown_seconds')->default(300)->after('autoscale_memory_threshold');
            $table->timestamp('autoscale_last_scaled_at')->nullable()->after('autoscale_cooldown_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('service_applications', function (Blueprint $table) {
            $table->dropColumn([
                'replicas',
                'autoscale_enabled',
                'autoscale_min_replicas',
                'autoscale_max_replicas',
                'autoscale_cpu_threshold',
                'autoscale_memory_threshold',
                'autoscale_cooldown_seconds',
                'autoscale_last_scaled_at',
            ]);
        });
    }
};
