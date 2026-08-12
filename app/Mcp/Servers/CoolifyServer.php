<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\ExplainFailedDeploy;
use App\Mcp\Prompts\TroubleshootApplication;
use App\Mcp\Resources\ApplicationResource;
use App\Mcp\Resources\InfrastructureOverviewResource;
use App\Mcp\Tools\CancelDeployment;
use App\Mcp\Tools\Control;
use App\Mcp\Tools\CoolifyHelp;
use App\Mcp\Tools\CreateApplication;
use App\Mcp\Tools\CreateDatabase;
use App\Mcp\Tools\CreateEnvironment;
use App\Mcp\Tools\CreateProject;
use App\Mcp\Tools\CreateScheduledTask;
use App\Mcp\Tools\CreateServiceFromTemplate;
use App\Mcp\Tools\DeleteEnv;
use App\Mcp\Tools\DeleteEnvironment;
use App\Mcp\Tools\DeleteProject;
use App\Mcp\Tools\DeleteResource;
use App\Mcp\Tools\DeleteScheduledTask;
use App\Mcp\Tools\Deploy;
use App\Mcp\Tools\ExecuteCommand;
use App\Mcp\Tools\GetApplication;
use App\Mcp\Tools\GetCurrentTeam;
use App\Mcp\Tools\GetDatabase;
use App\Mcp\Tools\GetDeployment;
use App\Mcp\Tools\GetDestination;
use App\Mcp\Tools\GetEnvironment;
use App\Mcp\Tools\GetInfrastructureOverview;
use App\Mcp\Tools\GetLogs;
use App\Mcp\Tools\GetProject;
use App\Mcp\Tools\GetServer;
use App\Mcp\Tools\GetServerDomains;
use App\Mcp\Tools\GetServerResources;
use App\Mcp\Tools\GetService;
use App\Mcp\Tools\GetServiceApplication;
use App\Mcp\Tools\GetServiceDatabase;
use App\Mcp\Tools\ListApplicationPreviews;
use App\Mcp\Tools\ListApplications;
use App\Mcp\Tools\ListBackupExecutions;
use App\Mcp\Tools\ListDatabaseBackups;
use App\Mcp\Tools\ListDatabases;
use App\Mcp\Tools\ListDeployments;
use App\Mcp\Tools\ListDestinations;
use App\Mcp\Tools\ListEnvKeys;
use App\Mcp\Tools\ListEnvs;
use App\Mcp\Tools\ListGithubApps;
use App\Mcp\Tools\ListGithubBranches;
use App\Mcp\Tools\ListGithubRepositories;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListResources;
use App\Mcp\Tools\ListResourceTags;
use App\Mcp\Tools\ListScheduledTaskExecutions;
use App\Mcp\Tools\ListScheduledTasks;
use App\Mcp\Tools\ListServers;
use App\Mcp\Tools\ListServiceApplications;
use App\Mcp\Tools\ListServiceDatabases;
use App\Mcp\Tools\ListServices;
use App\Mcp\Tools\ListSharedEnvKeys;
use App\Mcp\Tools\ListStorages;
use App\Mcp\Tools\ListTags;
use App\Mcp\Tools\ListTeamMembers;
use App\Mcp\Tools\ListTemplates;
use App\Mcp\Tools\ListUnhealthyResources;
use App\Mcp\Tools\SearchResources;
use App\Mcp\Tools\SetEnv;
use App\Mcp\Tools\UpdateProject;
use Laravel\Mcp\Server;

class CoolifyServer extends Server
{
    protected string $name = 'Coolify';

    protected string $version = '0.2.0';

    /**
     * Return all registered tools in a single tools/list page (default package limit is 15).
     */
    public int $maxPaginationLength = 100;

    public int $defaultPaginationLength = 100;

    protected string $instructions = <<<'MD'
Coolify MCP for the authenticated team token. Every tool enforces team ownership.

Start here (prefer these before deep get_*):
1. coolify_help — tool catalog by intent (overview|search|debug|deploy|essentials).
2. get_infrastructure_overview — counts + health_hints.
3. search_resources — fuzzy name/UUID/domain when type is unknown.
4. list_unhealthy_resources sample_only=true — cheap "what's broken?" sample + counts.

Then: list_*/get_* for details. Debug is DB-first:
- list_deployments → get_deployment(include_log_summary=true)
- list_env_keys / list_shared_env_keys (names only, preferred for diagnostics)
- list_envs is the fork legacy full env-value tool; only call it when the operator explicitly needs values and the token policy permits it
- get_logs only if status is running; on failure use reason + next_tools (do not loop)

Lifecycle (requires token ability **deploy**):
- control (start|stop|restart; stop needs confirm=true)
- deploy, cancel_deployment, execute_command

Management (requires token ability **write**):
- create_project / update_project / delete_project
- create_environment / delete_environment
- create_application, create_database, delete_resource
- set_env / delete_env
- create_service_from_template
- create_scheduled_task / delete_scheduled_task

Prompts: troubleshoot_application, explain_failed_deploy.
Resources: coolify://overview, coolify://application/{uuid}.

Responses: `{ data, _actions?, _pagination? }`. Configuration snapshots and full deploy logs are never returned. Optional deploy log summaries are best-effort redacted only.
MD;

    protected array $tools = [
        CoolifyHelp::class,

        // Discovery / overview
        GetInfrastructureOverview::class,
        SearchResources::class,
        ListUnhealthyResources::class,
        GetCurrentTeam::class,
        ListTeamMembers::class,

        // Infrastructure
        ListServers::class,
        GetServer::class,
        GetServerDomains::class,
        GetServerResources::class,
        ListDestinations::class,
        GetDestination::class,

        // Projects / environments / resources
        ListProjects::class,
        CreateProject::class,
        GetProject::class,
        UpdateProject::class,
        DeleteProject::class,
        GetEnvironment::class,
        CreateEnvironment::class,
        DeleteEnvironment::class,
        ListResources::class,

        // Applications
        ListApplications::class,
        CreateApplication::class,
        GetApplication::class,
        ListApplicationPreviews::class,

        // Databases
        ListDatabases::class,
        CreateDatabase::class,
        GetDatabase::class,
        ListDatabaseBackups::class,
        ListBackupExecutions::class,

        // Services
        ListServices::class,
        GetService::class,
        ListServiceApplications::class,
        GetServiceApplication::class,
        ListServiceDatabases::class,
        GetServiceDatabase::class,
        ListTemplates::class,
        CreateServiceFromTemplate::class,

        // Deployments / logs / lifecycle
        ListDeployments::class,
        GetDeployment::class,
        GetLogs::class,
        Control::class,
        Deploy::class,
        CancelDeployment::class,
        DeleteResource::class,
        ExecuteCommand::class,

        // Environment variables
        ListEnvKeys::class,
        ListSharedEnvKeys::class,
        ListEnvs::class,
        SetEnv::class,
        DeleteEnv::class,

        // Resource details
        ListStorages::class,
        ListResourceTags::class,

        // Scheduled tasks
        ListScheduledTasks::class,
        ListScheduledTaskExecutions::class,
        CreateScheduledTask::class,
        DeleteScheduledTask::class,

        // Tags / integrations
        ListTags::class,
        ListGithubApps::class,
        ListGithubRepositories::class,
        ListGithubBranches::class,
    ];

    protected array $resources = [
        InfrastructureOverviewResource::class,
        ApplicationResource::class,
    ];

    protected array $prompts = [
        TroubleshootApplication::class,
        ExplainFailedDeploy::class,
    ];
}
