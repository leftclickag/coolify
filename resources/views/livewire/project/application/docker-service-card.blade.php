<div @class([
    'border-l border-dashed border-red-500' => str($dockerService->status)->contains(['exited']),
    'border-l border-dashed border-success' => str($dockerService->status)->contains(['running']),
    'border-l border-dashed border-warning' => str($dockerService->status)->contains(['starting', 'restarting']),
    'flex gap-2 box-without-bg-without-border dark:bg-coolgray-100 bg-white dark:hover:text-neutral-300 group',
])>
    <div class="flex flex-row w-full">
        <div class="flex flex-col flex-1">
            <div class="pb-2">
                @if ($dockerService->human_name)
                    {{ Str::headline($dockerService->human_name) }}
                @else
                    {{ Str::headline($dockerService->name) }}
                @endif
                @if ($dockerService->image)
                    <span class="text-xs">({{ $dockerService->image }})</span>
                @endif
            </div>
            @if ($dockerService->description)
                <span class="text-xs">{{ Str::limit($dockerService->description, 60) }}</span>
            @endif
            <div class="pt-2 text-xs">{{ formatContainerStatus($dockerService->status) }}</div>
        </div>
        <div class="flex items-center px-4">
            @if (str($dockerService->status)->contains('running'))
                @can('update', $application)
                    <x-modal-confirmation
                        :title="$dockerService->isDatabase() ? 'Confirm Database Restart?' : 'Confirm Container Restart?'"
                        buttonTitle="Restart"
                        submitAction="restart"
                        :actions="$dockerService->isDatabase()
                            ? [
                                'This database container will be unavailable during the restart.',
                                'Any in-progress queries or writes may be lost.',
                            ]
                            : [
                                'This container will be unavailable during the restart.',
                                'Any in-flight requests may fail.',
                            ]"
                        :confirmWithText="false"
                        :confirmWithPassword="false"
                        :step2ButtonText="$dockerService->isDatabase() ? 'Restart Database Container' : 'Restart Container'" />
                @endcan
            @endif
        </div>
    </div>
</div>
