<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'standalone_postgresqls',
        'standalone_mysqls',
        'standalone_mariadbs',
        'standalone_redis',
        'standalone_clickhouses',
        'standalone_dragonflies',
        'standalone_keydbs',
        'standalone_mongodbs',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'health_check_enabled')) {
                    $table->boolean('health_check_enabled')->default(true);
                }
                if (! Schema::hasColumn($tableName, 'health_check_interval')) {
                    $table->integer('health_check_interval')->default(15);
                }
                if (! Schema::hasColumn($tableName, 'health_check_timeout')) {
                    $table->integer('health_check_timeout')->default(5);
                }
                if (! Schema::hasColumn($tableName, 'health_check_retries')) {
                    $table->integer('health_check_retries')->default(5);
                }
                if (! Schema::hasColumn($tableName, 'health_check_start_period')) {
                    $table->integer('health_check_start_period')->default(5);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn([
                    'health_check_enabled',
                    'health_check_interval',
                    'health_check_timeout',
                    'health_check_retries',
                    'health_check_start_period',
                ]);
            });
        }
    }
};
