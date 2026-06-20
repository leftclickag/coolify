<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_deployments')]
#[Description('List recent deployments for an application. Returns deployment UUID, status, created_at, and commit info.')]
class ListDeployments extends Tool
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

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return Response::error("Application [{$uuid}] not found.");
        }

        $args = $this->paginationArgs($request);

        $query = ApplicationDeploymentQueue::where('application_id', $application->id)->latest();

        $total = (clone $query)->count();

        $deployments = $query
            ->skip($args['offset'])
            ->take($args['per_page'])
            ->get()
            ->map(fn ($d) => [
                'deployment_uuid' => $d->deployment_uuid,
                'status' => $d->status,
                'created_at' => $d->created_at?->toISOString(),
                'commit' => $d->commit,
                'branch' => $d->application?->git_branch,
                'force_rebuild' => (bool) $d->force_rebuild,
                'restart_only' => (bool) $d->restart_only,
                'is_api' => (bool) $d->is_api,
            ])
            ->values()
            ->all();

        return $this->respond(
            $deployments,
            [],
            $this->paginationMeta('list_deployments', $args, $total, ['uuid' => $uuid]),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Application UUID.')->required(),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
