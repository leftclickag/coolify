<?php

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->where('id', 0)->delete();
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true]);
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
});

// ---------------------------------------------------------------------------
// Helpers (duplicated from McpEndpointTest; shared in future refactor)
// ---------------------------------------------------------------------------

function writeMcpCallTool(string $token, string $name, array $arguments = [])
{
    return test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => $name,
            'arguments' => (object) $arguments,
        ],
    ]);
}

function writeMcpListTools(string $token)
{
    return test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => (object) [],
    ]);
}

function writeMcpToolJson($response): array
{
    return json_decode($response->json('result.content.0.text'), true);
}

// ---------------------------------------------------------------------------
// Tool listing: all 33 tools should be registered
// ---------------------------------------------------------------------------

test('MCP tools list includes all new write tools', function () {
    $token = $this->user->createToken('mcp-write', ['read', 'write', 'deploy'])->plainTextToken;

    $response = writeMcpListTools($token);
    $response->assertOk();

    $toolNames = collect($response->json('result.tools'))->pluck('name')->all();

    expect($toolNames)->toContain(
        // Phase 1 – control
        'control',
        'deploy',
        'cancel_deployment',
        // Phase 2 – env vars
        'list_envs',
        'set_env',
        'delete_env',
        // Phase 3 – logs / deployments
        'get_logs',
        'list_deployments',
        'get_deployment',
        // Phase 4 – templates
        'list_templates',
        'create_service_from_template',
        // Phase 5 – lifecycle
        'create_application',
        'create_database',
        'delete_resource',
        // Phase 6 – projects
        'create_project',
        'update_project',
        'delete_project',
        'create_environment',
        'delete_environment',
        // Phase 7 – tasks / exec
        'list_scheduled_tasks',
        'create_scheduled_task',
        'delete_scheduled_task',
        'execute_command',
    );
});

// ---------------------------------------------------------------------------
// Permission guards: write/deploy tools must require appropriate ability
// ---------------------------------------------------------------------------

test('control requires deploy ability', function () {
    $token = $this->user->createToken('read-only', ['read'])->plainTextToken;

    $response = writeMcpCallTool($token, 'control', [
        'resource' => 'application',
        'action' => 'start',
        'uuid' => 'does-not-matter',
    ]);
    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('Missing required permissions');
});

test('set_env requires write ability', function () {
    $token = $this->user->createToken('read-only', ['read'])->plainTextToken;

    $response = writeMcpCallTool($token, 'set_env', [
        'resource' => 'application',
        'uuid' => 'does-not-matter',
        'key' => 'FOO',
        'value' => 'bar',
    ]);
    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('Missing required permissions');
});

test('execute_command requires deploy ability (write is insufficient)', function () {
    $token = $this->user->createToken('write', ['write'])->plainTextToken;

    $response = writeMcpCallTool($token, 'execute_command', [
        'uuid' => 'does-not-matter',
        'command' => 'echo hi',
    ]);
    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('Missing required permissions');
});

test('create_project requires write ability', function () {
    $token = $this->user->createToken('read-only', ['read'])->plainTextToken;

    $response = writeMcpCallTool($token, 'create_project', ['name' => 'test']);
    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('Missing required permissions');
});

test('delete_project requires write ability', function () {
    $project = Project::create(['name' => 'Test', 'team_id' => $this->team->id]);
    $token = $this->user->createToken('read-only', ['read'])->plainTextToken;

    $response = writeMcpCallTool($token, 'delete_project', ['uuid' => $project->uuid]);
    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// create_project
// ---------------------------------------------------------------------------

test('create_project creates a project scoped to the token team', function () {
    $token = $this->user->createToken('write', ['write'])->plainTextToken;

    $response = writeMcpCallTool($token, 'create_project', [
        'name' => 'My New Project',
        'description' => 'A test project',
    ]);
    $response->assertOk();

    $body = writeMcpToolJson($response);
    expect($body)->not->toHaveKey('error');
    expect($body['data'])->toHaveKey('uuid');
    expect($body['data']['name'])->toBe('My New Project');

    $this->assertDatabaseHas('projects', [
        'name' => 'My New Project',
        'team_id' => $this->team->id,
    ]);
});

test('create_project returns error for missing name', function () {
    $token = $this->user->createToken('write', ['write'])->plainTextToken;

    $response = writeMcpCallTool($token, 'create_project', []);
    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// update_project
// ---------------------------------------------------------------------------

test('update_project renames a project', function () {
    $project = Project::create(['name' => 'Old', 'team_id' => $this->team->id]);
    $token = $this->user->createToken('write', ['write'])->plainTextToken;

    $response = writeMcpCallTool($token, 'update_project', [
        'uuid' => $project->uuid,
        'name' => 'Renamed',
    ]);
    $response->assertOk();

    $body = writeMcpToolJson($response);
    expect($body['data']['name'])->toBe('Renamed');

    $this->assertDatabaseHas('projects', ['uuid' => $project->uuid, 'name' => 'Renamed']);
});

test('update_project cannot touch another team project', function () {
    $otherTeam = Team::factory()->create();
    $project = Project::create(['name' => 'Theirs', 'team_id' => $otherTeam->id]);
    $token = $this->user->createToken('write', ['write'])->plainTextToken;

    $response = writeMcpCallTool($token, 'update_project', [
        'uuid' => $project->uuid,
        'name' => 'Stolen',
    ]);
    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// delete_project
// ---------------------------------------------------------------------------

test('delete_project deletes an empty project', function () {
    $project = Project::create(['name' => 'Empty', 'team_id' => $this->team->id]);
    $token = $this->user->createToken('write', ['write'])->plainTextToken;

    $response = writeMcpCallTool($token, 'delete_project', ['uuid' => $project->uuid]);
    $response->assertOk();

    $body = writeMcpToolJson($response);
    expect($body['data']['message'])->toContain('deleted');

    $this->assertDatabaseMissing('projects', ['uuid' => $project->uuid]);
});

test('delete_project refuses to delete a project belonging to another team', function () {
    $otherTeam = Team::factory()->create();
    $project = Project::create(['name' => 'Theirs', 'team_id' => $otherTeam->id]);
    $token = $this->user->createToken('write', ['write'])->plainTextToken;

    $response = writeMcpCallTool($token, 'delete_project', ['uuid' => $project->uuid]);
    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
    $this->assertDatabaseHas('projects', ['uuid' => $project->uuid]);
});

// ---------------------------------------------------------------------------
// create_environment / delete_environment
// ---------------------------------------------------------------------------

test('create_environment adds an environment to a project', function () {
    $project = Project::create(['name' => 'Proj', 'team_id' => $this->team->id]);
    $token = $this->user->createToken('write', ['write'])->plainTextToken;

    $response = writeMcpCallTool($token, 'create_environment', [
        'project_uuid' => $project->uuid,
        'name' => 'staging',
    ]);
    $response->assertOk();

    $body = writeMcpToolJson($response);
    expect($body['data'])->toHaveKey('uuid');

    $this->assertDatabaseHas('environments', [
        'name' => 'staging',
        'project_id' => $project->id,
    ]);
});

test('delete_environment removes an empty environment', function () {
    $project = Project::create(['name' => 'Proj', 'team_id' => $this->team->id]);
    $env = Environment::create(['name' => 'staging', 'project_id' => $project->id]);
    $token = $this->user->createToken('write', ['write'])->plainTextToken;

    $response = writeMcpCallTool($token, 'delete_environment', [
        'project_uuid' => $project->uuid,
        'environment_name' => 'staging',
    ]);
    $response->assertOk();

    $body = writeMcpToolJson($response);
    expect($body['data']['message'])->toContain('deleted');

    $this->assertDatabaseMissing('environments', ['id' => $env->id]);
});

// ---------------------------------------------------------------------------
// list_templates
// ---------------------------------------------------------------------------

test('list_templates returns available one-click templates', function () {
    $token = $this->user->createToken('read', ['read'])->plainTextToken;

    $response = writeMcpCallTool($token, 'list_templates');
    $response->assertOk();

    $body = writeMcpToolJson($response);
    expect($body)->toHaveKey('data');
    expect($body['data'])->not->toBeEmpty();

    $first = $body['data'][0];
    expect($first)->toHaveKeys(['slug', 'name']);
});

test('list_templates filters by search query', function () {
    $token = $this->user->createToken('read', ['read'])->plainTextToken;

    $response = writeMcpCallTool($token, 'list_templates', ['search' => 'wordpress']);
    $response->assertOk();

    $body = writeMcpToolJson($response);
    $slugs = collect($body['data'])->pluck('slug')->all();
    expect($slugs)->toContain('wordpress');
});

// ---------------------------------------------------------------------------
// list_envs + set_env + delete_env (unit-style using an application)
// ---------------------------------------------------------------------------

test('set_env creates a new env var for an application', function () {
    $project = Project::create(['name' => 'App', 'team_id' => $this->team->id]);
    $env = Environment::create(['name' => 'production', 'project_id' => $project->id]);
    $server = \App\Models\Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->destinations()->first()
        ?? \App\Models\StandaloneDocker::factory()->for($server)->create();

    $application = \App\Models\Application::factory()->create([
        'environment_id' => $env->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $token = $this->user->createToken('write', ['write'])->plainTextToken;

    $response = writeMcpCallTool($token, 'set_env', [
        'resource' => 'application',
        'uuid' => $application->uuid,
        'key' => 'HELLO',
        'value' => 'world',
    ]);
    $response->assertOk();

    $body = writeMcpToolJson($response);
    expect($body['data']['key'])->toBe('HELLO');
    expect($body['data'])->toHaveKey('uuid');

    $this->assertDatabaseHas('environment_variables', [
        'key' => 'HELLO',
        'value' => 'world',
        'resourceable_id' => $application->id,
    ]);
});

test('set_env updates an existing env var', function () {
    $project = Project::create(['name' => 'App', 'team_id' => $this->team->id]);
    $env = Environment::create(['name' => 'production', 'project_id' => $project->id]);
    $server = \App\Models\Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->destinations()->first()
        ?? \App\Models\StandaloneDocker::factory()->for($server)->create();

    $application = \App\Models\Application::factory()->create([
        'environment_id' => $env->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    EnvironmentVariable::create([
        'key' => 'PORT',
        'value' => '3000',
        'resourceable_id' => $application->id,
        'resourceable_type' => $application->getMorphClass(),
    ]);

    $token = $this->user->createToken('write', ['write'])->plainTextToken;

    $response = writeMcpCallTool($token, 'set_env', [
        'resource' => 'application',
        'uuid' => $application->uuid,
        'key' => 'PORT',
        'value' => '8080',
    ]);
    $response->assertOk();

    $body = writeMcpToolJson($response);
    expect($body['data']['key'])->toBe('PORT');
    expect($response->json('result.isError'))->not->toBeTrue();

    $this->assertDatabaseHas('environment_variables', [
        'key' => 'PORT',
        'value' => '8080',
        'resourceable_id' => $application->id,
    ]);
    $this->assertDatabaseMissing('environment_variables', [
        'key' => 'PORT',
        'value' => '3000',
        'resourceable_id' => $application->id,
    ]);
});

test('list_envs returns env vars for an application', function () {
    $project = Project::create(['name' => 'App', 'team_id' => $this->team->id]);
    $env = Environment::create(['name' => 'production', 'project_id' => $project->id]);
    $server = \App\Models\Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->destinations()->first()
        ?? \App\Models\StandaloneDocker::factory()->for($server)->create();

    $application = \App\Models\Application::factory()->create([
        'environment_id' => $env->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    EnvironmentVariable::create([
        'key' => 'DB_HOST',
        'value' => 'localhost',
        'resourceable_id' => $application->id,
        'resourceable_type' => $application->getMorphClass(),
    ]);

    $token = $this->user->createToken('read', ['read'])->plainTextToken;

    $response = writeMcpCallTool($token, 'list_envs', [
        'resource' => 'application',
        'uuid' => $application->uuid,
    ]);
    $response->assertOk();

    $body = writeMcpToolJson($response);
    expect($body)->toHaveKey('data');

    $keys = collect($body['data'])->pluck('key')->all();
    expect($keys)->toContain('DB_HOST');
});

test('delete_env removes an env var by uuid', function () {
    $project = Project::create(['name' => 'App', 'team_id' => $this->team->id]);
    $env = Environment::create(['name' => 'production', 'project_id' => $project->id]);
    $server = \App\Models\Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->destinations()->first()
        ?? \App\Models\StandaloneDocker::factory()->for($server)->create();

    $application = \App\Models\Application::factory()->create([
        'environment_id' => $env->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $envVar = EnvironmentVariable::create([
        'key' => 'TO_DELETE',
        'value' => 'yes',
        'resourceable_id' => $application->id,
        'resourceable_type' => $application->getMorphClass(),
    ]);

    $token = $this->user->createToken('write', ['write'])->plainTextToken;

    $response = writeMcpCallTool($token, 'delete_env', ['uuid' => $envVar->uuid]);
    $response->assertOk();

    $body = writeMcpToolJson($response);
    expect($body['data']['message'])->toContain('deleted');

    $this->assertDatabaseMissing('environment_variables', ['uuid' => $envVar->uuid]);
});

test('delete_env refuses to delete env var belonging to another team', function () {
    $otherTeam = Team::factory()->create();
    $project = Project::create(['name' => 'Other', 'team_id' => $otherTeam->id]);
    $env = Environment::create(['name' => 'production', 'project_id' => $project->id]);
    $server = \App\Models\Server::factory()->create(['team_id' => $otherTeam->id]);
    $destination = $server->destinations()->first()
        ?? \App\Models\StandaloneDocker::factory()->for($server)->create();

    $application = \App\Models\Application::factory()->create([
        'environment_id' => $env->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $envVar = EnvironmentVariable::create([
        'key' => 'SECRET',
        'value' => 'shh',
        'resourceable_id' => $application->id,
        'resourceable_type' => $application->getMorphClass(),
    ]);

    $token = $this->user->createToken('write', ['write'])->plainTextToken;

    $response = writeMcpCallTool($token, 'delete_env', ['uuid' => $envVar->uuid]);
    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
    $this->assertDatabaseHas('environment_variables', ['uuid' => $envVar->uuid]);
});
