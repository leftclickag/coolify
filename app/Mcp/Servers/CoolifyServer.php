<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CancelDeployment;
use App\Mcp\Tools\Control;
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
use App\Mcp\Tools\GetDatabase;
use App\Mcp\Tools\GetDeployment;
use App\Mcp\Tools\GetInfrastructureOverview;
use App\Mcp\Tools\GetLogs;
use App\Mcp\Tools\GetServer;
use App\Mcp\Tools\GetService;
use App\Mcp\Tools\ListApplications;
use App\Mcp\Tools\ListDatabases;
use App\Mcp\Tools\ListDeployments;
use App\Mcp\Tools\ListEnvs;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListScheduledTasks;
use App\Mcp\Tools\ListServers;
use App\Mcp\Tools\ListServices;
use App\Mcp\Tools\ListTemplates;
use App\Mcp\Tools\SetEnv;
use App\Mcp\Tools\UpdateProject;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Coolify')]
#[Version('0.2.0')]
#[Instructions(<<<'MD'
MCP server for Coolify — read, deploy, and manage all resources scoped to the authenticated team token.

## Getting started
1. `get_infrastructure_overview` — single call returns all servers, projects with resource counts, and aggregates. Start here.
2. `list_servers` / `list_projects` / `list_applications` / `list_databases` / `list_services` — paginated summary listings (default 50 per page, cap 100).
3. `get_server` / `get_application` / `get_database` / `get_service` — full details for a single UUID.

## Deployments
- `deploy` — trigger a new deployment; returns `deployment_uuid`.
- `get_deployment` — poll build logs and status by `deployment_uuid`.
- `list_deployments` — recent deployments for an application.
- `cancel_deployment` — cancel a queued or running deployment.

## Control
- `control` — start / stop / restart any application, service, or database.

## Environment variables
- `list_envs` — list env vars for an application, service, or database (includes values).
- `set_env` — create or update an env var.
- `delete_env` — delete an env var by UUID.

## Logs
- `get_logs` — runtime container logs (application or service).

## Service templates
- `list_templates` — browse all one-click service templates.
- `create_service_from_template` — deploy a template (e.g. wordpress, plausible, n8n).

## Resource lifecycle
- `create_application` — create an app from a public Git repo.
- `create_database` — create a standalone database.
- `delete_resource` — irreversibly delete an application, service, or database.

## Projects & environments
- `create_project` / `update_project` / `delete_project`
- `create_environment` / `delete_environment`

## Scheduled tasks
- `list_scheduled_tasks` / `create_scheduled_task` / `delete_scheduled_task`

## Execute
- `execute_command` — run a shell command inside a running application container.

## Response envelope
Every response is `{ data, _actions?, _pagination? }`. `_actions` suggests the next tool + args; `_pagination.next` is the args to call again for the next page.
MD)]
class CoolifyServer extends Server
{
    protected array $tools = [
        // Read
        GetInfrastructureOverview::class,
        ListServers::class,
        GetServer::class,
        ListProjects::class,
        ListApplications::class,
        GetApplication::class,
        ListDatabases::class,
        GetDatabase::class,
        ListServices::class,
        GetService::class,

        // Deployments
        Deploy::class,
        GetDeployment::class,
        ListDeployments::class,
        CancelDeployment::class,

        // Control
        Control::class,

        // Environment variables
        ListEnvs::class,
        SetEnv::class,
        DeleteEnv::class,

        // Logs
        GetLogs::class,

        // Service templates
        ListTemplates::class,
        CreateServiceFromTemplate::class,

        // Resource lifecycle
        CreateApplication::class,
        CreateDatabase::class,
        DeleteResource::class,

        // Projects & environments
        CreateProject::class,
        UpdateProject::class,
        DeleteProject::class,
        CreateEnvironment::class,
        DeleteEnvironment::class,

        // Scheduled tasks
        ListScheduledTasks::class,
        CreateScheduledTask::class,
        DeleteScheduledTask::class,

        // Execute
        ExecuteCommand::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
