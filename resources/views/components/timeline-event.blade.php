@props([
    'event' => null,
])

@php
    $action = $event['action'] ?? $event->action ?? 'operational.action';
    $actorType = strtolower($event['actor_type'] ?? $event->actor_type ?? 'system');
    $actorId = $event['actor_id'] ?? $event->actor_id ?? 'system';
    $timestamp = $event['timestamp'] ?? ($event->created_at?->toIso8601String() ?? now()->toIso8601String());
    $metadata = $event['metadata'] ?? $event->metadata ?? [];
    if (is_string($metadata)) {
        $decoded = json_decode($metadata, true);
        if (json_last_error() === JSON_ERROR_NONE) $metadata = $decoded;
    }

    $humanTitle = match($action) {
        'incident.ingested' => 'Vulnerability ingested from security scanner',
        'triage.completed' => 'Triage analysis completed and verified',
        'reproduction.succeeded' => 'Exploit reproduction confirmed in isolated sandbox',
        'patch.synthesized' => 'Deterministic remediation patch candidate synthesized',
        'quality_gate.passed' => 'All quality gate verification stages passed',
        'hitl.approved' => 'Human approval granted by SecOps operator',
        'pr.created' => 'GitHub Pull Request created on target branch',
        default => ucwords(str_replace('.', ' ', $action)),
    };
@endphp

<div x-data="{ open: false }" class="relative pl-8 pb-6 border-l-2 border-[#30363d] last:border-l-0 last:pb-0">
    <!-- Node Marker Dot (GitHub Timeline Style) -->
    <div class="absolute -left-[9px] top-1 w-4 h-4 rounded-full bg-[#161b22] border-2 border-[#30363d] flex items-center justify-center">
        @if ($actorType === 'agent')
            <span class="w-1.5 h-1.5 rounded-full bg-[#a371f7]"></span>
        @elseif (str_contains($action, 'passed') || str_contains($action, 'approved'))
            <span class="w-1.5 h-1.5 rounded-full bg-[#3fb950]"></span>
        @else
            <span class="w-1.5 h-1.5 rounded-full bg-[#58a6ff]"></span>
        @endif
    </div>

    <!-- Event Card -->
    <div class="rounded-md border border-[#30363d] bg-[#161b22] p-3 text-sm">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center space-x-2">
                <span class="font-semibold text-white">{{ $actorId ?: $actorType }}</span>
                <span class="text-[#848d97]">{{ $humanTitle }}</span>
            </div>

            <div class="text-xs text-[#848d97]">
                {{ \Carbon\Carbon::parse($timestamp)->diffForHumans() }}
            </div>
        </div>

        @if (!empty($metadata))
            <div class="mt-2 pt-2 border-t border-[#30363d]/60">
                <button 
                    type="button" 
                    @click="open = !open" 
                    class="text-xs text-[#58a6ff] hover:underline flex items-center space-x-1"
                >
                    <span x-text="open ? 'Hide details' : 'View event details'"></span>
                    <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-180': open }" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M12.78 5.22a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L3.22 6.28a.751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018L8 8.94l3.72-3.72a.75.75 0 0 1 1.06 0Z"/>
                    </svg>
                </button>

                <div x-show="open" x-cloak class="mt-2 p-3 rounded bg-[#0d1117] border border-[#30363d] font-mono text-xs text-[#c9d1d9] overflow-x-auto max-h-48 whitespace-pre-wrap">
{{ is_array($metadata) ? json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $metadata }}
                </div>
            </div>
        @endif
    </div>
</div>
