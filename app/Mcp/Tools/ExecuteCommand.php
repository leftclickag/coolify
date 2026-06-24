<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('execute_command')]
#[Description('Execute a command inside a running application container. Returns stdout output. Use with caution.')]
class ExecuteCommand extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        // Running arbitrary shell inside a container is the most powerful action in the
        // tool set — require the highest task-scoped ability rather than plain 'write'.
        if ($error = $this->ensureAbility($request, 'deploy')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $uuid = $request->get('uuid');
        $command = $request->get('command');

        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        if (! is_string($command) || trim($command) === '') {
            return Response::error('command argument is required.');
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return Response::error("Application [{$uuid}] not found.");
        }

        $server = $application->destination->server;
        $containers = getCurrentApplicationContainerStatus($server, $application->id);

        if ($containers->isEmpty()) {
            return Response::error('No running containers found for this application.');
        }

        $container = $containers->first();
        $containerName = data_get($container, 'Names');

        if (! $containerName) {
            return Response::error('Could not determine container name.');
        }

        $output = instant_remote_process(
            ['docker exec '.$containerName.' sh -c '.escapeshellarg($command)],
            $server,
        );

        return $this->respond([
            'output' => $output,
            'container' => $containerName,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Application UUID.')->required(),
            'command' => $schema->string()->description('Shell command to execute inside the container.')->required(),
        ];
    }
}
