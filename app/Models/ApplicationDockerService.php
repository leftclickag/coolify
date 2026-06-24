<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApplicationDockerService extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'human_name',
        'description',
        'image',
        'type',
        'status',
        'exclude_from_status',
        'last_online_at',
        'application_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $service) {
            if ($service->isDirty('status')) {
                $service->last_online_at = now();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'exclude_from_status' => 'boolean',
            'last_online_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function isApplication(): bool
    {
        return $this->type === 'application';
    }

    public function isDatabase(): bool
    {
        return $this->type === 'database';
    }

    public function restart(): void
    {
        $server = $this->application->destination->server;
        $workdir = $this->application->workdir();
        $projectName = $this->application->uuid;
        $serviceName = $this->name;

        instant_remote_process([
            "docker compose --project-name {$projectName} --project-directory {$workdir} restart {$serviceName}",
        ], $server);
    }
}
