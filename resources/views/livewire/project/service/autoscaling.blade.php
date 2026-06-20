<div class="flex flex-col">
    <div class="flex items-center gap-2">
        <h2>Autoscaling</h2>
        <x-loading wire:loading wire:target="submit,scaleNow" />
    </div>
    <div class="pb-4 text-sm dark:text-neutral-400">
        Scale this service up or down manually, or automatically based on CPU and memory usage.
    </div>

    @if ($hasHostPorts)
        <x-callout type="warning" title="Host port bindings detected" class="mb-4">
            This service maps host ports ({{ $serviceApplication->ports }}). Docker cannot bind the same host
            port on more than one container, so scaling above 1 replica will fail. Remove the host port mappings
            from your compose file and route traffic through the proxy (Traefik) instead.
        </x-callout>
    @endif

    {{-- Manual scaling --}}
    <h3 class="pt-2">Manual Scaling</h3>
    <div class="pb-3 text-sm dark:text-neutral-400">
        Set the desired replica count and apply it immediately.
    </div>
    <div class="flex flex-col gap-2 items-end xl:flex-row">
        <x-forms.input canGate="update" :canResource="$serviceApplication" type="number" id="replicas"
            label="Replicas" placeholder="1" min="1" max="50" />
        <x-forms.button canGate="update" :canResource="$serviceApplication" wire:click="scaleNow"
            wire:loading.attr="disabled" wire:target="scaleNow">
            Apply Now
        </x-forms.button>
    </div>
    @if ($serviceApplication->autoscale_last_scaled_at)
        <div class="pt-2 text-xs dark:text-neutral-500">
            Last scaled {{ $serviceApplication->autoscale_last_scaled_at->diffForHumans() }}.
        </div>
    @endif

    {{-- Automatic scaling --}}
    <form wire:submit="submit" class="flex flex-col gap-2 pt-6">
        <div class="flex items-center gap-2">
            <h3>Automatic Scaling</h3>
            <x-forms.button canGate="update" :canResource="$serviceApplication" type="submit">
                Save
            </x-forms.button>
        </div>
        <div class="pb-2 text-sm dark:text-neutral-400">
            When enabled, Coolify checks CPU and memory usage every minute and scales replicas within the
            configured bounds.
        </div>

        <x-forms.checkbox canGate="update" :canResource="$serviceApplication" id="autoscaleEnabled"
            label="Enable Autoscaling"
            helper="Automatically scale this service based on CPU and/or memory thresholds." />

        <div class="flex flex-col gap-2 xl:flex-row">
            <x-forms.input canGate="update" :canResource="$serviceApplication" type="number"
                id="autoscaleMinReplicas" label="Minimum Replicas" placeholder="1" min="1" max="50"
                helper="The service will never scale below this number of replicas." />
            <x-forms.input canGate="update" :canResource="$serviceApplication" type="number"
                id="autoscaleMaxReplicas" label="Maximum Replicas" placeholder="5" min="1" max="50"
                helper="The service will never scale above this number of replicas." />
        </div>

        <div class="flex flex-col gap-2 xl:flex-row">
            <x-forms.input canGate="update" :canResource="$serviceApplication" type="number"
                id="autoscaleCpuThreshold" label="CPU Scale-Up Threshold (%)" placeholder="80" min="1" max="100"
                helper="Scale up when average CPU across replicas exceeds this percentage. Leave empty to ignore CPU." />
            <x-forms.input canGate="update" :canResource="$serviceApplication" type="number"
                id="autoscaleMemoryThreshold" label="Memory Scale-Up Threshold (%)" placeholder="80" min="1"
                max="100"
                helper="Scale up when average memory across replicas exceeds this percentage. Leave empty to ignore memory." />
        </div>

        <div class="xl:w-1/2 xl:pr-1">
            <x-forms.input canGate="update" :canResource="$serviceApplication" type="number"
                id="autoscaleCooldownSeconds" label="Cooldown (seconds)" placeholder="300" min="60" max="86400"
                helper="Minimum seconds between consecutive scaling events. Prevents rapid flapping. Default: 300 (5 minutes)." />
        </div>
    </form>

    <x-callout type="info" title="How autoscaling works" class="mt-6">
        <ul class="ml-4 list-disc">
            <li>Every minute, Coolify samples CPU and memory usage across all running replicas.</li>
            <li>If the average exceeds a threshold, one replica is added (up to the maximum).</li>
            <li>If the average drops below 50% of the threshold, one replica is removed (down to the minimum).</li>
            <li>Scale events are separated by the cooldown period to prevent flapping.</li>
            <li>When replicas are above 1, Docker Compose auto-numbers the containers instead of using a fixed name.</li>
        </ul>
    </x-callout>
</div>
