<?php

namespace App\Mcp\Tools;

use App\Enums\BuildPackTypes;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

#[Name('create_application')]
#[Description('Create a new application from a public git repository. For private repos, use the Coolify UI or API directly.')]
class CreateApplication extends Tool
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

        $projectUuid = $request->get('project_uuid');
        $serverUuid = $request->get('server_uuid');
        $environmentName = $request->get('environment_name');
        $gitRepository = $request->get('git_repository');

        if (! is_string($projectUuid) || $projectUuid === '') {
            return Response::error('project_uuid argument is required.');
        }

        if (! is_string($serverUuid) || $serverUuid === '') {
            return Response::error('server_uuid argument is required.');
        }

        if (! is_string($environmentName) || $environmentName === '') {
            return Response::error('environment_name argument is required.');
        }

        if (! is_string($gitRepository) || $gitRepository === '') {
            return Response::error('git_repository argument is required.');
        }

        $gitBranch = $request->get('git_branch') ?? 'main';
        $buildPackRaw = $request->get('build_pack') ?? 'nixpacks';

        $buildPack = BuildPackTypes::tryFrom($buildPackRaw);
        if (! $buildPack) {
            return Response::error("build_pack must be one of: nixpacks, railpack, dockerfile, dockercompose.");
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

        try {
            $repoUrl = Url::fromString($gitRepository);
        } catch (\Exception) {
            return Response::error('git_repository is not a valid URL.');
        }

        $gitHost = $repoUrl->getHost();
        $repoPath = trim($repoUrl->getSegment(1).'/'.$repoUrl->getSegment(2));

        $name = $request->get('name') ?? generate_application_name($gitRepository, $gitBranch, new Cuid2);
        $portsExposes = $request->get('ports_exposes');

        $application = new Application;
        $application->name = $name;
        $application->git_repository = $repoPath;
        $application->git_branch = $gitBranch;
        $application->build_pack = $buildPack->value;
        $application->environment_id = $environment->id;
        $application->destination_id = $destination->id;
        $application->destination_type = $destination->getMorphClass();
        $application->status = 'exited';

        if ($portsExposes !== null) {
            $application->ports_exposes = $portsExposes;
        }

        if ($gitHost === 'github.com') {
            $application->source_type = GithubApp::class;
            $application->source_id = GithubApp::find(0)?->id;
        }

        $application->save();

        $instantDeploy = (bool) ($request->get('instant_deploy') ?? false);
        $deploymentUuid = null;

        if ($instantDeploy) {
            $deploymentUuid = new Cuid2;
            $result = queue_application_deployment(
                $application,
                $deploymentUuid->toString(),
                is_api: true,
            );

            if (data_get($result, 'status') === 'queue_full') {
                $deploymentUuid = null;
            }
        }

        $responseData = [
            'uuid' => $application->uuid,
            'name' => $application->name,
            'message' => 'Application created.',
        ];

        if ($deploymentUuid) {
            $responseData['deployment_uuid'] = $deploymentUuid->toString();
            $responseData['message'] = 'Application created and deployment queued.';
        }

        return $this->respond(
            $responseData,
            [
                ['tool' => 'get_application', 'args' => ['uuid' => $application->uuid], 'hint' => 'Full application details'],
            ],
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_uuid' => $schema->string()->description('UUID of the project.')->required(),
            'server_uuid' => $schema->string()->description('UUID of the server to deploy on.')->required(),
            'environment_name' => $schema->string()->description('Name of the environment (e.g. production).')->required(),
            'git_repository' => $schema->string()->description('Public Git repository URL (e.g. https://github.com/user/repo).')->required(),
            'git_branch' => $schema->string()->description('Branch to deploy (default: main).'),
            'build_pack' => $schema->string()->description('Build pack: nixpacks, railpack, dockerfile, or dockercompose (default: nixpacks).'),
            'name' => $schema->string()->description('Optional name for the application.'),
            'ports_exposes' => $schema->string()->description('Port(s) the app listens on, e.g. "3000" or "3000,8080".'),
            'instant_deploy' => $schema->boolean()->description('Trigger a deployment immediately after creation (default false).'),
        ];
    }
}
