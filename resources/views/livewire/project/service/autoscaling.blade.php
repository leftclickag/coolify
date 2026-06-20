<div>
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} > Autoscaling | Coolify
    </x-slot>

    <div class="flex items-center gap-2 pb-4">
        <h2>Autoscaling</h2>
        <x-loading wire:loading wire:target="submit,scaleNow" />
    </div>
    <div class="pb-2 text-sm text-neutral-500">
        Scale Docker Compose service containers up or down manually or automatically based on CPU and memory usage.
    </div>

    {{-- Manual Replica Control --}}
    <div class="box-without-bg p-4 mb-6">
        <h3 class="mb-3">Manual Scaling</h3>
        <div class="text-sm text-neutral-500 pb-3">
            Set the desired replica count and apply it immediately. The service compose file will be updated and containers will scale without recreation of existing instances.
        </div>
        <div class="flex items-end gap-3">
            <div class="w-32">
                <x-forms.input
                    canGate="update"
                    :canResource="$serviceApplication"
                    type="number"
                    id="replicas"
                    label="Replicas"
                    placeholder="1"
                    min="1"
                    max="50"
                />
            </div>
            <x-forms.button
                canGate="update"
                :canResource="$serviceApplication"
                wire:click="scaleNow"
                wire:loading.attr="disabled"
                wire:target="scaleNow"
            >
                Apply Now
            </x-forms.button>
        </div>
        @if ($serviceApplication->autoscale_last_scaled_at)
            <div class="mt-2 text-xs text-neutral-400">
                Last scaled: {{ $serviceApplication->autoscale_last_scaled_at->diffForHumans() }}
            </div>
        @endif
    </div>

    {{-- Autoscaling Configuration --}}
    <form wire:submit="submit">
        <div class="box-without-bg p-4">
            <div class="flex items-center gap-2 mb-1">
                <h3>Automatic Scaling</h3>
            </div>
            <div class="text-sm text-neutral-500 pb-4">
                When enabled, Coolify checks CPU and memory usage every minute and scales replicas within the configured bounds.
            </div>

            <div class="flex flex-col gap-4">
                <x-forms.checkbox
                    canGate="update"
                    :canResource="$serviceApplication"
                    id="autoscaleEnabled"
                    label="Enable Autoscaling"
                    helper="Automatically scale this service based on CPU and/or memory thresholds."
                />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-forms.input
                        canGate="update"
                        :canResource="$serviceApplication"
                        type="number"
                        id="autoscaleMinReplicas"
                        label="Minimum Replicas"
                        placeholder="1"
                        min="1"
                        max="50"
                        helper="The service will never scale below this number of replicas."
                    />
                    <x-forms.input
                        canGate="update"
                        :canResource="$serviceApplication"
                        type="number"
                        id="autoscaleMaxReplicas"
                        label="Maximum Replicas"
                        placeholder="5"
                        min="1"
                        max="50"
                        helper="The service will never scale above this number of replicas."
                    />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-forms.input
                        canGate="update"
                        :canResource="$serviceApplication"
                        type="number"
                        id="autoscaleCpuThreshold"
                        label="CPU Scale-Up Threshold (%)"
                        placeholder="80"
                        min="1"
                        max="100"
                        helper="Scale up when average CPU across replicas exceeds this percentage. Leave empty to ignore CPU."
                    />
                    <x-forms.input
                        canGate="update"
                        :canResource="$serviceApplication"
                        type="number"
                        id="autoscaleMemoryThreshold"
                        label="Memory Scale-Up Threshold (%)"
                        placeholder="80"
                        min="1"
                        max="100"
                        helper="Scale up when average memory across replicas exceeds this percentage. Leave empty to ignore memory."
                    />
                </div>

                <div class="sm:w-64">
                    <x-forms.input
                        canGate="update"
                        :canResource="$serviceApplication"
                        type="number"
                        id="autoscaleCooldownSeconds"
                        label="Cooldown (seconds)"
                        placeholder="300"
                        min="60"
                        max="86400"
                        helper="Minimum seconds between consecutive scaling events. Prevents rapid flapping. Default: 300 (5 minutes)."
                    />
                </div>
            </div>
        </div>

        <div class="mt-4">
            <x-forms.button canGate="update" :canResource="$serviceApplication" type="submit">
                Save Autoscaling Settings
            </x-forms.button>
        </div>
    </form>

    <div class="mt-6 p-4 box-without-bg text-sm text-neutral-500">
        <p class="font-medium text-neutral-400 mb-1">How autoscaling works</p>
        <ul class="list-disc ml-4 space-y-1">
            <li>Every minute, Coolify samples CPU and memory usage across all running replicas of this service.</li>
            <li>If the average exceeds a threshold, one replica is added (up to the maximum).</li>
            <li>If the average drops below 50% of the threshold across all replicas, one replica is removed (down to the minimum).</li>
            <li>Scale events are separated by the cooldown period to prevent flapping.</li>
            <li>When replicas &gt; 1, the service will no longer use a fixed <code>container_name</code>. Docker Compose will auto-number containers.</li>
        </ul>
    </div>
</div>
