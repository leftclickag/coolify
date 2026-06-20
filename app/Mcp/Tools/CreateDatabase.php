<?php

namespace App\Mcp\Tools;

use App\Actions\Database\StartDatabase;
use App\Enums\NewDatabaseTypes;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_database')]
#[Description('Create a new standalone database. Supported types: postgresql, mysql, mariadb, mongodb, redis, keydb, dragonfly, clickhouse.')]
class CreateDatabase extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'write')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $typeRaw = $request->get('type');
        $serverUuid = $request->get('server_uuid');
        $projectUuid = $request->get('project_uuid');
        $environmentName = $request->get('environment_name');

        if (! is_string($typeRaw) || $typeRaw === '') {
            return Response::error('type argument is required.');
        }

        $type = NewDatabaseTypes::tryFrom($typeRaw);
        if (! $type) {
            $valid = implode(', ', array_column(NewDatabaseTypes::cases(), 'value'));

            return Response::error("type must be one of: {$valid}.");
        }

        if (! is_string($serverUuid) || $serverUuid === '') {
            return Response::error('server_uuid argument is required.');
        }

        if (! is_string($projectUuid) || $projectUuid === '') {
            return Response::error('project_uuid argument is required.');
        }

        if (! is_string($environmentName) || $environmentName === '') {
            return Response::error('environment_name argument is required.');
        }

        $project = Project::whereTeamId($teamId)->whereUuid($projectUuid)->first();
        if (! $project) {
            return Response::error("Project [{$projectUuid}] not found.");
        }

        $environment = $project->environments()->where('name', $environmentName)->first();
        if (! $environment) {
            return Response::error("Environment [{$environmentName}] not found.");
        }

        $server = Server::whereTeamId($teamId)->whereUuid($serverUuid)->first();
        if (! $server) {
            return Response::error("Server [{$serverUuid}] not found.");
        }

        $destinations = $server->destinations();
        if ($destinations->count() === 0) {
            return Response::error('Server has no destinations.');
        }

        $destination = $destinations->first();

        $otherData = [];
        if ($request->get('name')) {
            $otherData['name'] = $request->get('name');
        }

        $database = match ($type) {
            NewDatabaseTypes::POSTGRESQL => create_standalone_postgresql($environment->id, $destination, $otherData),
            NewDatabaseTypes::MYSQL => create_standalone_mysql($environment->id, $destination, $otherData),
            NewDatabaseTypes::MARIADB => create_standalone_mariadb($environment->id, $destination, $otherData),
            NewDatabaseTypes::MONGODB => create_standalone_mongodb($environment->id, $destination, $otherData),
            NewDatabaseTypes::REDIS => create_standalone_redis($environment->id, $destination, $otherData),
            NewDatabaseTypes::KEYDB => create_standalone_keydb($environment->id, $destination, $otherData),
            NewDatabaseTypes::DRAGONFLY => create_standalone_dragonfly($environment->id, $destination, $otherData),
            NewDatabaseTypes::CLICKHOUSE => create_standalone_clickhouse($environment->id, $destination, $otherData),
        };

        $instantDeploy = (bool) ($request->get('instant_deploy') ?? false);
        if ($instantDeploy) {
            StartDatabase::dispatch($database);
        }

        return $this->respond(
            [
                'uuid' => $database->uuid,
                'name' => $database->name,
                'type' => $type->value,
                'message' => $instantDeploy ? 'Database created and start dispatched.' : 'Database created.',
            ],
            [
                ['tool' => 'get_database', 'args' => ['uuid' => $database->uuid], 'hint' => 'Full database details'],
                ['tool' => 'control', 'args' => ['resource' => 'database', 'action' => 'start', 'uuid' => $database->uuid], 'hint' => 'Start database'],
            ],
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('Database type: postgresql, mysql, mariadb, mongodb, redis, keydb, dragonfly, or clickhouse.')->required(),
            'server_uuid' => $schema->string()->description('UUID of the server.')->required(),
            'project_uuid' => $schema->string()->description('UUID of the project.')->required(),
            'environment_name' => $schema->string()->description('Name of the environment.')->required(),
            'name' => $schema->string()->description('Optional name for the database.'),
            'instant_deploy' => $schema->boolean()->description('Start the database immediately after creation (default false).'),
        ];
    }
}
