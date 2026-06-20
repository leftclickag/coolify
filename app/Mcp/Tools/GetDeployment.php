<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_deployment')]
#[Description('Get full details of a specific deployment including its build logs. Use list_deployments to find deployment UUIDs.')]
class GetDeployment extends Tool
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

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $uuid)->first();
        if (! $deployment) {
            return Response::error("Deployment [{$uuid}] not found.");
        }

        $serverIds = Server::whereTeamId($teamId)->pluck('id');
        if (! $serverIds->contains($deployment->server_id)) {
            return Response::error("Deployment [{$uuid}] not found.");
        }

        return $this->respond([
            'deployment_uuid' => $deployment->deployment_uuid,
            'status' => $deployment->status,
            'commit' => $deployment->commit,
            'commit_message' => $deployment->commitMessage(),
            'force_rebuild' => (bool) $deployment->force_rebuild,
            'restart_only' => (bool) $deployment->restart_only,
            'is_api' => (bool) $deployment->is_api,
            'is_webhook' => (bool) $deployment->is_webhook,
            'application_name' => $deployment->application_name,
            'server_name' => $deployment->server_name,
            'deployment_url' => $deployment->deployment_url,
            'created_at' => $deployment->created_at?->toISOString(),
            'updated_at' => $deployment->updated_at?->toISOString(),
            'finished_at' => $deployment->finished_at?->toISOString(),
            'logs' => $deployment->logs,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Deployment UUID (not application UUID). Use list_deployments to find deployment UUIDs.')->required(),
        ];
    }
}
