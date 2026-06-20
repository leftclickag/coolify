<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_envs')]
#[Description('List environment variables for an application, service, or database. Returns key, uuid, value, is_preview, is_literal, is_multiline, is_shown_once, is_buildtime, is_runtime.')]
class ListEnvs extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $resourceType = $request->get('resource');
        $uuid = $request->get('uuid');

        if (! in_array($resourceType, ['application', 'service', 'database'], true)) {
            return Response::error('resource must be one of: application, service, database.');
        }

        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        $resource = $this->findResource($resourceType, $uuid, $teamId);
        if (! $resource) {
            return Response::error(ucfirst($resourceType)." [{$uuid}] not found.");
        }

        $envs = $resource->environment_variables
            ->map(fn ($env) => [
                'uuid' => $env->uuid,
                'key' => $env->key,
                'value' => $env->value,
                'is_preview' => (bool) $env->is_preview,
                'is_literal' => (bool) $env->is_literal,
                'is_multiline' => (bool) $env->is_multiline,
                'is_shown_once' => (bool) $env->is_shown_once,
                'is_buildtime' => (bool) $env->is_buildtime,
                'is_runtime' => (bool) $env->is_runtime,
            ])
            ->values()
            ->all();

        return $this->respond($envs);
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
        ];
    }
}
