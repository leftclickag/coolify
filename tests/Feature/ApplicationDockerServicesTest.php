<?php

use App\Models\Application;
use App\Models\ApplicationDockerService;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('syncApplicationDockerServices', function () {
    beforeEach(function () {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);

        $this->application = Application::factory()->create([
            'environment_id' => $environment->id,
            'build_pack' => 'dockercompose',
            'compose_parsing_version' => '3',
        ]);
    });

    test('creates a child record per compose service', function () {
        $this->application->docker_compose_raw = <<<'YAML'
services:
  web:
    image: nginx:alpine
  api:
    image: node:20
YAML;
        $this->application->save();

        syncApplicationDockerServices($this->application);

        $services = ApplicationDockerService::where('application_id', $this->application->id)->get();

        expect($services)->toHaveCount(2);
        expect($services->pluck('name')->sort()->values()->toArray())->toBe(['api', 'web']);
    });

    test('correctly marks database images as type database', function () {
        $this->application->docker_compose_raw = <<<'YAML'
services:
  app:
    image: nginx:alpine
  db:
    image: postgres:16
YAML;
        $this->application->save();

        syncApplicationDockerServices($this->application);

        $app = ApplicationDockerService::where('application_id', $this->application->id)
            ->where('name', 'app')->first();
        $db = ApplicationDockerService::where('application_id', $this->application->id)
            ->where('name', 'db')->first();

        expect($app->type)->toBe('application');
        expect($db->type)->toBe('database');
    });

    test('removes child records for services no longer in compose', function () {
        $this->application->docker_compose_raw = <<<'YAML'
services:
  web:
    image: nginx:alpine
  worker:
    image: php:8.4
YAML;
        $this->application->save();

        syncApplicationDockerServices($this->application);
        expect(ApplicationDockerService::where('application_id', $this->application->id)->count())->toBe(2);

        // Remove worker from compose.
        $this->application->docker_compose_raw = <<<'YAML'
services:
  web:
    image: nginx:alpine
YAML;
        $this->application->save();

        syncApplicationDockerServices($this->application);

        $remaining = ApplicationDockerService::where('application_id', $this->application->id)->get();
        expect($remaining)->toHaveCount(1);
        expect($remaining->first()->name)->toBe('web');
    });

    test('updates image when compose changes', function () {
        $this->application->docker_compose_raw = <<<'YAML'
services:
  web:
    image: nginx:alpine
YAML;
        $this->application->save();
        syncApplicationDockerServices($this->application);

        $this->application->docker_compose_raw = <<<'YAML'
services:
  web:
    image: nginx:1.27
YAML;
        $this->application->save();
        syncApplicationDockerServices($this->application);

        $service = ApplicationDockerService::where('application_id', $this->application->id)
            ->where('name', 'web')->first();

        expect($service->image)->toBe('nginx:1.27');
    });

    test('is a no-op when build_pack is not dockercompose', function () {
        $this->application->build_pack = 'nixpacks';
        $this->application->docker_compose_raw = <<<'YAML'
services:
  web:
    image: nginx:alpine
YAML;
        $this->application->save();

        syncApplicationDockerServices($this->application);

        expect(ApplicationDockerService::where('application_id', $this->application->id)->count())->toBe(0);
    });

    test('is a no-op when docker_compose_raw is blank', function () {
        $this->application->docker_compose_raw = null;
        $this->application->save();

        syncApplicationDockerServices($this->application);

        expect(ApplicationDockerService::where('application_id', $this->application->id)->count())->toBe(0);
    });

    test('restores soft-deleted records when a service reappears', function () {
        $this->application->docker_compose_raw = <<<'YAML'
services:
  web:
    image: nginx:alpine
YAML;
        $this->application->save();
        syncApplicationDockerServices($this->application);

        // Remove it.
        $this->application->docker_compose_raw = <<<'YAML'
services:
  api:
    image: node:20
YAML;
        $this->application->save();
        syncApplicationDockerServices($this->application);

        expect(ApplicationDockerService::where('application_id', $this->application->id)->count())->toBe(1);
        expect(ApplicationDockerService::withTrashed()->where('application_id', $this->application->id)->count())->toBe(2);

        // Re-add web.
        $this->application->docker_compose_raw = <<<'YAML'
services:
  web:
    image: nginx:alpine
  api:
    image: node:20
YAML;
        $this->application->save();
        syncApplicationDockerServices($this->application);

        expect(ApplicationDockerService::where('application_id', $this->application->id)->count())->toBe(2);
    });
});

describe('ApplicationDockerService model', function () {
    test('isApplication returns true for application type', function () {
        $service = new ApplicationDockerService(['type' => 'application']);
        expect($service->isApplication())->toBeTrue();
        expect($service->isDatabase())->toBeFalse();
    });

    test('isDatabase returns true for database type', function () {
        $service = new ApplicationDockerService(['type' => 'database']);
        expect($service->isDatabase())->toBeTrue();
        expect($service->isApplication())->toBeFalse();
    });
});
