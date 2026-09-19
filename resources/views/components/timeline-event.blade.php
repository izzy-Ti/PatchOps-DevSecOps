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

<div x-data="{ open: false }" class="relative pl-7 pb-6 border-l border-[#222222] last:border-l-0 last:pb-0">
    <!-- Glowing Node Dot -->
    <div class="absolute -left-[5px] top-1.5 w-2.5 h-2.5 rounded-full bg-[#00e599] shadow-[0_0_8px_rgba(0,229,153,0.6)]"></div>

    <!-- Event Card -->
    <div class="rounded-lg border border-[#1f1f1f] bg-[#0f0f0f] p-3.5 text-sm hover:border-[#2a2a2a] transition">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center space-x-2">
                <span class="font-mono text-xs font-semibold px-2 py-0.5 rounded bg-[#181818] border border-[#262626] text-[#00e599]">{{ $actorId ?: $actorType }}</span>
                <span class="text-white font-medium">{{ $humanTitle }}</span>
            </div>

            <div class="text-xs font-mono text-[#666666]">
                {{ \Carbon\Carbon::parse($timestamp)->diffForHumans() }}
            </div>
        </div>

        @if (!empty($metadata))
            <div class="mt-2.5 pt-2 border-t border-[#1a1a1a]">
                <button 
                    type="button" 
                    @click="open = !open" 
                    class="text-xs font-mono text-[#00e599] hover:underline flex items-center space-x-1"
                >
                    <span x-text="open ? 'Hide details' : 'View event payload'"></span>
                    <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <div x-show="open" x-cloak class="mt-2 p-3 rounded bg-[#080808] border border-[#1e1e1e] font-mono text-xs text-[#cccccc] overflow-x-auto max-h-56 whitespace-pre-wrap leading-relaxed">
{{ is_array($metadata) ? json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $metadata }}
                </div>
            </div>
        @endif
    </div>
</div>
