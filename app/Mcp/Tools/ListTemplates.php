<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_templates')]
#[Description('List all available one-click service templates that can be deployed via create_service_from_template. Returns slug (use as type), name, and description.')]
class ListTemplates extends Tool
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

        $search = $request->get('search');
        $args = $this->paginationArgs($request);

        $templates = get_service_templates()
            ->map(fn ($service, $key) => [
                'slug' => $key,
                'name' => data_get($service, 'name', $key),
                'description' => data_get($service, 'description', ''),
            ])
            ->when($search !== null && is_string($search) && $search !== '', function ($collection) use ($search) {
                $lower = strtolower($search);

                return $collection->filter(
                    fn ($t) => str_contains(strtolower((string) $t['name']), $lower)
                        || str_contains(strtolower((string) $t['slug']), $lower)
                        || str_contains(strtolower((string) $t['description']), $lower)
                );
            })
            ->values();

        $total = $templates->count();

        $page = $templates->slice($args['offset'], $args['per_page'])->values()->all();

        return $this->respond(
            $page,
            [],
            $this->paginationMeta('list_templates', $args, $total, $search ? ['search' => $search] : []),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Optional search term to filter templates by name or slug.'),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
