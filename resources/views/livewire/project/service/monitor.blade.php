<div class="flex flex-col" wire:init="loadData"
    @if ($autoRefresh) wire:poll.10000ms="loadData" @endif>

    {{-- Header --}}
    <div class="flex items-center gap-3 pb-4">
        <h2>Monitor</h2>
        <x-loading wire:loading wire:target="loadData" />
        @if ($lastUpdated)
            <span class="text-xs dark:text-neutral-500">Updated {{ $lastUpdated }}</span>
        @endif
        <div class="ml-auto flex items-center gap-3">
            <label class="flex items-center gap-1.5 text-xs dark:text-neutral-400 cursor-pointer select-none">
                <input type="checkbox" wire:model.live="autoRefresh" class="checkbox checkbox-xs" />
                Auto-refresh (10s)
            </label>
            <x-forms.button wire:click="loadData" wire:loading.attr="disabled" wire:target="loadData">
                Refresh
            </x-forms.button>
        </div>
    </div>

    @if ($error)
        <x-callout type="danger" title="Error">{{ $error }}</x-callout>
    @endif

    @if (! $loaded)
        <div class="py-12 text-center dark:text-neutral-500 text-sm">Loading container data…</div>
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
                No containers are running or were recently run for this service.
                Deploy the service first.
            </x-callout>
        @else

            {{-- Container table --}}
            @php
                $byService = collect($containers)->groupBy('service');
            @endphp

            @foreach ($byService as $serviceName => $replicas)
                <div class="mb-6">
                    <div class="flex items-center gap-2 pb-2">
                        <h3 class="text-base">{{ $serviceName }}</h3>
                        <span class="text-xs dark:text-neutral-500">{{ $replicas->count() }} {{ Str::plural('replica', $replicas->count()) }}</span>
                        @if (count($traefikServices) > 0)
                            @foreach ($traefikServices as $tSvc)
                                @if (str_contains($tSvc['name'], $serviceName))
                                    <span class="text-xs px-1.5 py-0.5 rounded
                                        {{ $tSvc['servers_up'] === $tSvc['servers_total'] ? 'dark:bg-success/20 text-success' : 'dark:bg-warning/20 text-warning' }}">
                                        Traefik: {{ $tSvc['servers_up'] }}/{{ $tSvc['servers_total'] }} up
                                    </span>
                                @endif
                            @endforeach
                        @endif
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
                                        {{-- Replica # --}}
                                        <td class="py-2.5 pr-4 tabular-nums dark:text-neutral-400">
                                            {{ $c['replica'] }}
                                        </td>

                                        {{-- Name --}}
                                        <td class="py-2.5 pr-4 font-mono text-xs dark:text-neutral-300 whitespace-nowrap">
                                            {{ $c['name'] }}
                                        </td>

                                        {{-- Status badge --}}
                                        <td class="py-2.5 pr-4 whitespace-nowrap">
                                            @php
                                                $stateColor = match ($c['state']) {
                                                    'running' => 'text-success',
                                                    'created', 'restarting' => 'text-warning',
                                                    default => 'text-error',
                                                };
                                            @endphp
                                            <div class="flex items-center gap-1.5">
                                                <span class="inline-block w-1.5 h-1.5 rounded-full {{ $c['state'] === 'running' ? 'bg-success' : ($c['state'] === 'created' || $c['state'] === 'restarting' ? 'bg-warning' : 'bg-error') }}"></span>
                                                <span class="{{ $stateColor }}">{{ ucfirst($c['state']) }}</span>
                                                @if ($c['health'])
                                                    <span class="text-xs dark:text-neutral-500">({{ $c['health'] }})</span>
                                                @endif
                                            </div>
                                        </td>

                                        {{-- CPU --}}
                                        <td class="py-2.5 pr-4 tabular-nums">
                                            @if ($c['state'] === 'running')
                                                <div class="flex items-center gap-2 min-w-[5rem]">
                                                    <div class="flex-1 h-1.5 rounded-full dark:bg-coolgray-300 overflow-hidden">
                                                        <div class="h-full rounded-full transition-all
                                                            {{ $c['cpu'] > 80 ? 'bg-error' : ($c['cpu'] > 50 ? 'bg-warning' : 'bg-success') }}"
                                                            style="width: {{ min(100, $c['cpu']) }}%"></div>
                                                    </div>
                                                    <span class="text-xs tabular-nums w-10 text-right">{{ number_format($c['cpu'], 1) }}%</span>
                                                </div>
                                            @else
                                                <span class="dark:text-neutral-600">—</span>
                                            @endif
                                        </td>

                                        {{-- Memory --}}
                                        <td class="py-2.5 pr-4">
                                            @if ($c['state'] === 'running')
                                                <div class="flex items-center gap-2 min-w-[8rem]">
                                                    <div class="flex-1 h-1.5 rounded-full dark:bg-coolgray-300 overflow-hidden">
                                                        <div class="h-full rounded-full transition-all
                                                            {{ $c['mem_percent'] > 80 ? 'bg-error' : ($c['mem_percent'] > 50 ? 'bg-warning' : 'bg-success') }}"
                                                            style="width: {{ min(100, $c['mem_percent']) }}%"></div>
                                                    </div>
                                                    <span class="text-xs tabular-nums dark:text-neutral-400 whitespace-nowrap">{{ $c['mem_usage'] }}</span>
                                                </div>
                                            @else
                                                <span class="dark:text-neutral-600">—</span>
                                            @endif
                                        </td>

                                        {{-- Net I/O --}}
                                        <td class="py-2.5 pr-4 text-xs dark:text-neutral-400 whitespace-nowrap tabular-nums">
                                            {{ $c['net_io'] !== '—' ? $c['net_io'] : '—' }}
                                        </td>

                                        {{-- PIDs --}}
                                        <td class="py-2.5 text-xs tabular-nums dark:text-neutral-400">
                                            {{ $c['pids'] ?: '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach

            {{-- Traefik routing info --}}
            @if (! empty($traefikServices))
                <div class="pt-2">
                    <h3 class="text-base pb-2">Traefik Routing</h3>
                    <div class="w-full overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b dark:border-coolgray-300 text-left">
                                    <th class="pb-2 pr-4 font-medium dark:text-neutral-400">Service</th>
                                    <th class="pb-2 pr-4 font-medium dark:text-neutral-400">Status</th>
                                    <th class="pb-2 font-medium dark:text-neutral-400">Backends up / total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($traefikServices as $ts)
                                    <tr class="border-b dark:border-coolgray-300/50">
                                        <td class="py-2.5 pr-4 font-mono text-xs dark:text-neutral-300">{{ $ts['name'] }}</td>
                                        <td class="py-2.5 pr-4">
                                            <span class="{{ $ts['status'] === 'enabled' ? 'text-success' : 'text-error' }}">
                                                {{ ucfirst($ts['status']) }}
                                            </span>
                                        </td>
                                        <td class="py-2.5 tabular-nums">
                                            <span class="{{ $ts['servers_up'] < $ts['servers_total'] ? 'text-warning' : 'text-success' }}">
                                                {{ $ts['servers_up'] }} / {{ $ts['servers_total'] }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @elseif ($loaded && empty($traefikServices))
                <div class="pt-2 text-xs dark:text-neutral-600">
                    Traefik API not available or no services for this stack are registered.
                    Traefik routes traffic to all healthy running replicas automatically.
                </div>
            @endif

        @endif
    @endif
</div>
