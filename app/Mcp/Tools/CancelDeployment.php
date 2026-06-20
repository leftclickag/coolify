<?php

namespace App\Mcp\Tools;

use App\Enums\ApplicationDeploymentStatus;
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

#[Name('cancel_deployment')]
#[Description('Cancel a queued or in-progress deployment by deployment UUID.')]
class CancelDeployment extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'deploy')) {
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

        $cancellableStatuses = [
            ApplicationDeploymentStatus::QUEUED->value,
            ApplicationDeploymentStatus::IN_PROGRESS->value,
        ];

        if (! in_array($deployment->status, $cancellableStatuses, true)) {
            return Response::error("Deployment [{$uuid}] cannot be cancelled (current status: {$deployment->status}).");
        }

        $deployment->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);
        $deployment->addLogEntry('Deployment cancelled via MCP.');

        return $this->respond([
            'message' => 'Deployment cancelled.',
            'deployment_uuid' => $uuid,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Deployment UUID to cancel.')->required(),
        ];
    }
}
