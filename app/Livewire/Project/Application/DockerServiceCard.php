<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ApplicationDockerService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DockerServiceCard extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public ApplicationDockerService $dockerService;

    public function getListeners(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        $team = $user->currentTeam();
        if (! $team) {
            return [];
        }

        return [
            "echo-private:team.{$team->id},ApplicationStatusChanged" => 'refreshResource',
        ];
    }

    public function refreshResource(): void
    {
        $this->dockerService->refresh();
    }

    public function restart(): void
    {
        try {
            $this->authorize('update', $this->application);
            $this->dockerService->restart();
            $this->dispatch('success', 'Container restarted successfully.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render(): View
    {
        return view('livewire.project.application.docker-service-card');
    }
}
