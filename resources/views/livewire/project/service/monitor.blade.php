<div class="flex flex-col" wire:poll.3000ms="checkForFreshData">

    {{-- Header --}}
    <div class="flex items-center gap-3 pb-4">
        <h2>Monitor</h2>
        @if ($pollPending)
            <x-loading />
            <span class="text-xs dark:text-neutral-500">Fetching…</span>
        @elseif ($polledAt)
            <span class="text-xs dark:text-neutral-500">
                Updated {{ \Carbon\Carbon::parse($polledAt)->diffForHumans() }}
            </span>
        @endif
        <div class="ml-auto flex items-center gap-3">
            <span class="text-xs dark:text-neutral-500">Auto-refreshing every 3s</span>
            <x-forms.button wire:click="requestRefresh" wire:loading.attr="disabled" wire:target="requestRefresh">
                Refresh Now
            </x-forms.button>
        </div>
    </div>

    {{-- No data yet --}}
    @if (! $polledAt && ! $pollPending)
        <div class="py-12 text-center">
            <p class="text-sm dark:text-neutral-500 pb-4">
                No data yet. Click Refresh to fetch live container stats from the server.
            </p>
            <x-forms.button wire:click="requestRefresh">Fetch Now</x-forms.button>
        </div>
    @elseif ($pollPending && empty($containers))
        <div class="py-12 text-center text-sm dark:text-neutral-500">
            Fetching container data in the background…
        </div>
    @else

        {{-- Summary cards --}}
        <div class="flex flex-wrap gap-3 pb-6">
            <div class="flex flex-col px-4 py-3 border rounded-sm dark:border-coolgray-300 min-w-[7rem]">
                <span class="text-xs dark:text-neutral-500">Total</span>
                <span class="text-2xl font-bold tabular-nums">{{ $summary['total'] }}</span>
            </div>
            <div class="flex flex-col px-4 py-3 border rounded-sm dark:border-coolgray-300 min-w-[7rem]">
                <span class="text-xs text-success">Running</span>
                <span class="text-2xl font-bold tabular-nums text-success">{{ $summary['running'] }}</span>
            </div>
            @if ($summary['starting'] > 0)
                <div class="flex flex-col px-4 py-3 border rounded-sm dark:border-coolgray-300 min-w-[7rem]">
                    <span class="text-xs text-warning">Starting</span>
                    <span class="text-2xl font-bold tabular-nums text-warning">{{ $summary['starting'] }}</span>
                </div>
            @endif
            @if ($summary['stopped'] > 0)
                <div class="flex flex-col px-4 py-3 border rounded-sm dark:border-coolgray-300 min-w-[7rem]">
                    <span class="text-xs text-error">Stopped</span>
                    <span class="text-2xl font-bold tabular-nums text-error">{{ $summary['stopped'] }}</span>
                </div>
            @endif
        </div>

        @if (empty($containers))
            <x-callout type="info" title="No containers found">
                No containers are running or were recently run for this service stack.
            </x-callout>
        @else
            @php $byService = collect($containers)->groupBy('service'); @endphp

            @foreach ($byService as $serviceName => $replicas)
                <div class="mb-6">
                    <div class="flex items-center gap-2 pb-2">
                        <h3 class="text-base">{{ $serviceName }}</h3>
                        <span class="text-xs dark:text-neutral-500">
                            {{ $replicas->count() }} {{ Str::plural('replica', $replicas->count()) }}
                        </span>
                        @foreach ($traefikServices as $ts)
                            @if (str_contains($ts['name'], $serviceName))
                                <span class="text-xs px-1.5 py-0.5 rounded
                                    {{ $ts['servers_up'] === $ts['servers_total'] ? 'dark:bg-success/20 text-success' : 'dark:bg-warning/20 text-warning' }}">
                                    Traefik {{ $ts['servers_up'] }}/{{ $ts['servers_total'] }} up
                                </span>
                            @endif
                        @endforeach
                    </div>

                    <div class="w-full overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b dark:border-coolgray-300 text-left">
                                    <th class="pb-2 pr-4 font-medium dark:text-neutral-400 whitespace-nowrap">#</th>
                                    <th class="pb-2 pr-4 font-medium dark:text-neutral-400 whitespace-nowrap">Container</th>
                                    <th class="pb-2 pr-4 font-medium dark:text-neutral-400 whitespace-nowrap">Status</th>
                                    <th class="pb-2 pr-4 font-medium dark:text-neutral-400 whitespace-nowrap">CPU</th>
                                    <th class="pb-2 pr-4 font-medium dark:text-neutral-400 whitespace-nowrap">Memory</th>
                                    <th class="pb-2 pr-4 font-medium dark:text-neutral-400 whitespace-nowrap">Net I/O</th>
                                    <th class="pb-2 font-medium dark:text-neutral-400 whitespace-nowrap">PIDs</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($replicas as $c)
                                    <tr class="border-b dark:border-coolgray-300/50 hover:dark:bg-coolgray-100/30 transition-colors">
                                        <td class="py-2.5 pr-4 tabular-nums dark:text-neutral-400">{{ $c['replica'] }}</td>
                                        <td class="py-2.5 pr-4 font-mono text-xs dark:text-neutral-300 whitespace-nowrap">{{ $c['name'] }}</td>

                                        {{-- Status --}}
                                        <td class="py-2.5 pr-4 whitespace-nowrap">
                                            <div class="flex items-center gap-1.5">
                                                <span class="inline-block w-1.5 h-1.5 rounded-full
                                                    {{ $c['state'] === 'running' ? 'bg-success' : ($c['state'] === 'restarting' || $c['state'] === 'created' ? 'bg-warning' : 'bg-error') }}"></span>
                                                <span class="{{ $c['state'] === 'running' ? 'text-success' : ($c['state'] === 'restarting' || $c['state'] === 'created' ? 'text-warning' : 'text-error') }}">
                                                    {{ ucfirst($c['state']) }}
                                                </span>
                                                @if ($c['health'])
                                                    <span class="text-xs dark:text-neutral-500">({{ $c['health'] }})</span>
                                                @endif
                                            </div>
                                        </td>

                                        {{-- CPU bar --}}
                                        <td class="py-2.5 pr-4">
                                            @if ($c['state'] === 'running')
                                                <div class="flex items-center gap-2 min-w-[5rem]">
                                                    <div class="flex-1 h-1.5 rounded-full dark:bg-coolgray-300 overflow-hidden">
                                                        <div class="h-full rounded-full transition-all {{ $c['cpu'] > 80 ? 'bg-error' : ($c['cpu'] > 50 ? 'bg-warning' : 'bg-success') }}"
                                                            style="width: {{ max($c['cpu'] > 0 ? 2 : 0, min(100, $c['cpu'])) }}%"></div>
                                                    </div>
                                                    <span class="text-xs tabular-nums w-12 text-right">{{ number_format($c['cpu'], 2) }}%</span>
                                                </div>
                                            @else
                                                <span class="dark:text-neutral-600">—</span>
                                            @endif
                                        </td>

                                        {{-- MEM bar --}}
                                        <td class="py-2.5 pr-4">
                                            @if ($c['state'] === 'running')
                                                <div class="flex items-center gap-2 min-w-[10rem]">
                                                    <div class="flex-1 h-1.5 rounded-full dark:bg-coolgray-300 overflow-hidden">
                                                        <div class="h-full rounded-full transition-all {{ $c['mem_percent'] > 80 ? 'bg-error' : ($c['mem_percent'] > 50 ? 'bg-warning' : 'bg-success') }}"
                                                            style="width: {{ max($c['mem_percent'] > 0 ? 2 : 0, min(100, $c['mem_percent'])) }}%"></div>
                                                    </div>
                                                    <span class="text-xs tabular-nums dark:text-neutral-400 whitespace-nowrap">
                                                        {{ $c['mem_usage'] !== '—' ? $c['mem_usage'] : ($c['_stats_found'] ? '0B' : 'no data') }}
                                                        <span class="dark:text-neutral-600">({{ number_format($c['mem_percent'], 2) }}%)</span>
                                                    </span>
                                                </div>
                                            @else
                                                <span class="dark:text-neutral-600">—</span>
                                            @endif
                                        </td>

                                        <td class="py-2.5 pr-4 text-xs dark:text-neutral-400 tabular-nums whitespace-nowrap">
                                            {{ $c['net_io'] !== '—' ? $c['net_io'] : ($c['_stats_found'] ? '0B / 0B' : 'no data') }}
                                        </td>
                                        <td class="py-2.5 text-xs tabular-nums dark:text-neutral-400">
                                            @if ($c['_stats_found'])
                                                {{ $c['pids'] ?: 0 }}
                                            @else
                                                <span class="dark:text-neutral-600">no data</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach

            {{-- Traefik --}}
            @if (! empty($traefikServices))
                <div class="pt-2">
                    <h3 class="text-base pb-2">Traefik Routing</h3>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b dark:border-coolgray-300 text-left">
                                <th class="pb-2 pr-4 font-medium dark:text-neutral-400">Service</th>
                                <th class="pb-2 pr-4 font-medium dark:text-neutral-400">Status</th>
                                <th class="pb-2 font-medium dark:text-neutral-400">Backends</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($traefikServices as $ts)
                                <tr class="border-b dark:border-coolgray-300/50">
                                    <td class="py-2.5 pr-4 font-mono text-xs dark:text-neutral-300">{{ $ts['name'] }}</td>
                                    <td class="py-2.5 pr-4 {{ $ts['status'] === 'enabled' ? 'text-success' : 'text-error' }}">{{ ucfirst($ts['status']) }}</td>
                                    <td class="py-2.5 tabular-nums {{ $ts['servers_up'] < $ts['servers_total'] ? 'text-warning' : 'text-success' }}">
                                        {{ $ts['servers_up'] }} / {{ $ts['servers_total'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    @endif
</div>
