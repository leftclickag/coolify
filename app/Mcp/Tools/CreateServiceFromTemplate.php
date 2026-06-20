<?php

namespace App\Mcp\Tools;

use App\Actions\Service\StartService;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_service_from_template')]
#[Description('Deploy a one-click service from a template (e.g. wordpress, plausible, n8n, gitea). Get available template slugs from list_templates.')]
class CreateServiceFromTemplate extends Tool
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

        $type = $request->get('type');
        $serverUuid = $request->get('server_uuid');
        $projectUuid = $request->get('project_uuid');
        $environmentName = $request->get('environment_name');

        if (! is_string($type) || $type === '') {
            return Response::error('type argument is required.');
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

        $services = get_service_templates();
        if (! $services->has($type)) {
            return Response::error("Template [{$type}] not found. Use list_templates to see available templates.");
        }

        $project = Project::whereTeamId($teamId)->whereUuid($projectUuid)->first();
        if (! $project) {
            return Response::error("Project [{$projectUuid}] not found.");
        }

        $environment = $project->environments()->where('name', $environmentName)->first();
        if (! $environment) {
            return Response::error("Environment [{$environmentName}] not found in project [{$projectUuid}].");
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

        $oneClickService = data_get($services, "{$type}.compose");
        $oneClickDotEnvs = data_get($services, "{$type}.envs", null);

        if (! $oneClickService) {
            return Response::error("Template [{$type}] has no compose definition.");
        }

        $dockerComposeRaw = base64_decode($oneClickService);

        try {
            validateDockerComposeForInjection($dockerComposeRaw);
        } catch (\Exception $e) {
            return Response::error('Template validation failed: '.$e->getMessage());
        }

        $customName = $request->get('name');
        $servicePayload = [
            'name' => $customName ?? "{$type}-".str()->random(10),
            'docker_compose_raw' => $dockerComposeRaw,
            'environment_id' => $environment->id,
            'service_type' => $type,
            'server_id' => $server->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ];

        if (in_array($type, NEEDS_TO_CONNECT_TO_PREDEFINED_NETWORK, true)) {
            $servicePayload['connect_to_docker_network'] = true;
        }

        $service = Service::create($servicePayload);
        $service->name = $customName ?? "{$type}-{$service->uuid}";
        $service->save();

        if ($oneClickDotEnvs) {
            $dotEnvLines = str(base64_decode($oneClickDotEnvs))
                ->split('/\r\n|\r|\n/')
                ->filter(fn ($v) => ! empty($v));

            $dotEnvLines->each(function ($line) use ($service) {
                $key = str()->before($line, '=');
                $value = str(str()->after($line, '='));
                $generatedValue = $value;

                if ($value->contains('SERVICE_')) {
                    $command = $value->after('SERVICE_')->beforeLast('_');
                    $generatedValue = generateEnvValue($command->value(), $service);
                }

                EnvironmentVariable::create([
                    'key' => $key,
                    'value' => $generatedValue,
                    'resourceable_id' => $service->id,
                    'resourceable_type' => $service->getMorphClass(),
                    'is_preview' => false,
                ]);
            });
        }

        $service->parse(isNew: true);
        applyServiceApplicationPrerequisites($service);

        $instantDeploy = (bool) ($request->get('instant_deploy') ?? false);
        if ($instantDeploy) {
            StartService::dispatch($service);
        }

        return $this->respond(
            [
                'uuid' => $service->uuid,
                'name' => $service->name,
                'message' => $instantDeploy ? 'Service created and start dispatched.' : 'Service created.',
                'domains' => $service->applications()->pluck('fqdn')->filter()->sort()->values()->all(),
            ],
            [
                ['tool' => 'get_service', 'args' => ['uuid' => $service->uuid], 'hint' => 'Full service details'],
                ['tool' => 'control', 'args' => ['resource' => 'service', 'action' => 'start', 'uuid' => $service->uuid], 'hint' => 'Start service'],
            ],
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('Template slug (e.g. wordpress, plausible, n8n). Use list_templates to find slugs.')->required(),
            'server_uuid' => $schema->string()->description('UUID of the server to deploy on.')->required(),
            'project_uuid' => $schema->string()->description('UUID of the project.')->required(),
            'environment_name' => $schema->string()->description('Name of the environment (e.g. production, staging).')->required(),
            'name' => $schema->string()->description('Optional custom name for the service.'),
            'instant_deploy' => $schema->boolean()->description('Start the service immediately after creation (default false).'),
        ];
    }
}
