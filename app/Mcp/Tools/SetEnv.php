<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('set_env')]
#[Description('Create or update (upsert) an environment variable for an application, service, or database. If the key already exists, it is updated; otherwise it is created.')]
class SetEnv extends Tool
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

        $resourceType = $request->get('resource');
        $uuid = $request->get('uuid');
        $key = $request->get('key');

        if (! in_array($resourceType, ['application', 'service', 'database'], true)) {
            return Response::error('resource must be one of: application, service, database.');
        }

        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        if (! is_string($key) || $key === '') {
            return Response::error('key argument is required.');
        }

        $value = $request->get('value');

        $resource = $this->findResource($resourceType, $uuid, $teamId);
        if (! $resource) {
            return Response::error(ucfirst($resourceType)." [{$uuid}] not found.");
        }

        $existingEnv = $resource->environment_variables->where('key', $key)->first();

        $flags = [
            'is_preview' => (bool) ($request->get('is_preview') ?? ($existingEnv?->is_preview ?? false)),
            'is_literal' => (bool) ($request->get('is_literal') ?? ($existingEnv?->is_literal ?? false)),
            'is_multiline' => (bool) ($request->get('is_multiline') ?? ($existingEnv?->is_multiline ?? false)),
            'is_buildtime' => (bool) ($request->get('is_buildtime') ?? ($existingEnv?->is_buildtime ?? true)),
            'is_runtime' => (bool) ($request->get('is_runtime') ?? ($existingEnv?->is_runtime ?? true)),
        ];

        if ($existingEnv) {
            $existingEnv->value = $value;
            foreach ($flags as $flag => $flagValue) {
                $existingEnv->{$flag} = $flagValue;
            }
            $existingEnv->save();
            $envUuid = $existingEnv->uuid;
            $action = 'updated';
        } else {
            $newEnv = EnvironmentVariable::create(array_merge([
                'key' => $key,
                'value' => $value,
                'resourceable_type' => $resource->getMorphClass(),
                'resourceable_id' => $resource->id,
            ], $flags));
            $envUuid = $newEnv->uuid;
            $action = 'created';
        }

        return $this->respond([
            'message' => "Environment variable {$action}.",
            'uuid' => $envUuid,
            'key' => $key,
        ]);
    }

    private function findResource(string $type, string $uuid, int $teamId): mixed
    {
        return match ($type) {
            'application' => Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first(),
            'service' => Service::whereRelation('environment.project.team', 'id', $teamId)->where('uuid', $uuid)->first(),
            'database' => queryDatabaseByUuidWithinTeam($uuid, $teamId),
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('Resource type: application, service, or database.')->required(),
            'uuid' => $schema->string()->description('UUID of the resource.')->required(),
            'key' => $schema->string()->description('Environment variable key.')->required(),
            'value' => $schema->string()->description('Environment variable value.')->required(),
            'is_preview' => $schema->boolean()->description('Only used for preview deployments.'),
            'is_literal' => $schema->boolean()->description('Treat value as a literal string (no variable interpolation).'),
            'is_multiline' => $schema->boolean()->description('Value spans multiple lines.'),
            'is_buildtime' => $schema->boolean()->description('Available at build time (default true).'),
            'is_runtime' => $schema->boolean()->description('Available at runtime (default true).'),
        ];
    }
}
