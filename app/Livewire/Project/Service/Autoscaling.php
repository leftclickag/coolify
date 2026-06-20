<?php

namespace App\Livewire\Project\Service;

use App\Actions\Service\ScaleServiceApplication;
use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Autoscaling extends Component
{
    use AuthorizesRequests;

    public ServiceApplication $serviceApplication;

    public Service $service;

    public bool $hasHostPorts = false;

    public ?int $runningReplicas = null;

    public bool $replicaStatusLoaded = false;

    #[Validate('required|integer|min:1|max:50')]
    public int $replicas = 1;

    #[Validate('required|boolean')]
    public bool $autoscaleEnabled = false;

    #[Validate('required|integer|min:1|max:50')]
    public int $autoscaleMinReplicas = 1;

    #[Validate('required|integer|min:1|max:50')]
    public int $autoscaleMaxReplicas = 5;

    #[Validate('nullable|integer|min:1|max:100')]
    public ?int $autoscaleCpuThreshold = null;

    #[Validate('nullable|integer|min:1|max:100')]
    public ?int $autoscaleMemoryThreshold = null;

    #[Validate('required|integer|min:60|max:86400')]
    public int $autoscaleCooldownSeconds = 300;

    public function mount(ServiceApplication $serviceApplication): void
    {
        $this->serviceApplication = $serviceApplication;
        $this->service = $serviceApplication->service;
        $this->hasHostPorts = $this->detectHostPorts();
        $this->syncData();
    }

    private function detectHostPorts(): bool
    {
        $ports = $this->serviceApplication->ports;
        if (blank($ports)) {
            return false;
        }

        foreach (explode(',', $ports) as $port) {
            if (str_contains(explode('/', trim($port))[0], ':')) {
                return true;
            }
        }

        return false;
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->serviceApplication->replicas = $this->replicas;
            $this->serviceApplication->autoscale_enabled = $this->autoscaleEnabled;
            $this->serviceApplication->autoscale_min_replicas = $this->autoscaleMinReplicas;
            $this->serviceApplication->autoscale_max_replicas = $this->autoscaleMaxReplicas;
            $this->serviceApplication->autoscale_cpu_threshold = $this->autoscaleCpuThreshold;
            $this->serviceApplication->autoscale_memory_threshold = $this->autoscaleMemoryThreshold;
            $this->serviceApplication->autoscale_cooldown_seconds = $this->autoscaleCooldownSeconds;
        } else {
            $this->replicas = (int) ($this->serviceApplication->replicas ?? 1);
            $this->autoscaleEnabled = (bool) $this->serviceApplication->autoscale_enabled;
            $this->autoscaleMinReplicas = (int) ($this->serviceApplication->autoscale_min_replicas ?? 1);
            $this->autoscaleMaxReplicas = (int) ($this->serviceApplication->autoscale_max_replicas ?? 5);
            $this->autoscaleCpuThreshold = $this->serviceApplication->autoscale_cpu_threshold
                ? (int) $this->serviceApplication->autoscale_cpu_threshold
                : null;
            $this->autoscaleMemoryThreshold = $this->serviceApplication->autoscale_memory_threshold
                ? (int) $this->serviceApplication->autoscale_memory_threshold
                : null;
            $this->autoscaleCooldownSeconds = (int) ($this->serviceApplication->autoscale_cooldown_seconds ?? 300);
        }
    }

    public function submit(): void
    {
        try {
            $this->authorize('update', $this->serviceApplication);
            $this->validate();

            if ($this->autoscaleMinReplicas > $this->autoscaleMaxReplicas) {
                $this->dispatch('error', 'Minimum replicas cannot exceed maximum replicas.');

                return;
            }

            $this->syncData(true);
            $this->serviceApplication->save();
            $this->dispatch('success', 'Autoscaling settings saved.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function scaleNow(): void
    {
        try {
            $this->authorize('update', $this->serviceApplication);
            $this->validateOnly('replicas');

            ScaleServiceApplication::run($this->serviceApplication, $this->replicas);

            $this->serviceApplication->refresh();
            $this->syncData();
            $this->loadReplicaStatus();
            $this->dispatch('success', "Scaled to {$this->replicas} replica(s). The service is updating.");
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Count the running replicas of this service application on the server using
     * Docker Compose labels. Invoked async via wire:init so the page loads fast.
     */
    public function loadReplicaStatus(): void
    {
        $this->replicaStatusLoaded = true;

        try {
            $server = $this->service->destination->server;
            if (! $server->isFunctional()) {
                $this->runningReplicas = null;

                return;
            }

            $name = $this->serviceApplication->name;
            $uuid = $this->service->uuid;

            $output = instant_remote_process([
                "docker ps --filter label=com.docker.compose.service={$name} --filter label=com.docker.compose.project={$uuid} --filter status=running -q | wc -l",
            ], $server, false);

            $this->runningReplicas = (int) trim((string) $output);
        } catch (\Throwable $e) {
            $this->runningReplicas = null;
        }
    }

    public function render()
    {
        return view('livewire.project.service.autoscaling');
    }
}
