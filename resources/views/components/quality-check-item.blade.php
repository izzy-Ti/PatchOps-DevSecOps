@props([
    'check' => null,
])

@php
    $passed = (bool) ($check->status === 'PASSED' || ($check->exit_code === 0 && empty($check->failed)));
    $exitCode = $check->exit_code ?? ($passed ? 0 : 1);
    $checkName = ucwords(str_replace('_', ' ', $check->check_type ?? $check->check_name ?? $check->name ?? 'Quality Gate Check'));
    $duration = $check->duration_ms ?? 0;
    $command = $check->command ?? null;
    $stdout = $check->stdout ?? null;
    $stderr = $check->stderr ?? null;
@endphp

<div x-data="{ expanded: false }" class="rounded-lg border border-[#1f1f1f] bg-[#0e0e0e] overflow-hidden hover:border-[#2f2f2f] transition">
    <!-- Clickable Header -->
    <button 
        type="button" 
        @click="expanded = !expanded" 
        class="w-full px-4 py-3 flex items-center justify-between text-left focus:outline-none hover:bg-[#141414] transition"
    >
        <div class="flex items-center space-x-3 min-w-0">
            @if ($passed)
                <div class="w-5 h-5 rounded-full bg-[#00e599]/15 border border-[#00e599]/40 flex items-center justify-center shrink-0">
                    <svg class="w-3 h-3 text-[#00e599]" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M13.78 4.22a.75.75 0 0 1 0 1.06l-7.25 7.25a.75.75 0 0 1-1.06 0L2.22 9.28a.751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018L6 10.94l6.72-6.72a.75.75 0 0 1 1.06 0Z"/>
                    </svg>
                </div>
            @else
                <div class="w-5 h-5 rounded-full bg-red-500/15 border border-red-500/40 flex items-center justify-center shrink-0">
                    <svg class="w-3 h-3 text-red-400" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.749.749 0 0 1 1.275.326.749.749 0 0 1-.215.734L9.06 8l3.22 3.22a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215L8 9.06l-3.22 3.22a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z"/>
                    </svg>
                </div>
            @endif

            <div class="min-w-0">
                <span class="font-medium text-sm text-[#e6edf3]">{{ $checkName }}</span>
                @if ($command)
                    <p class="text-xs font-mono text-[#777777] truncate max-w-xl mt-0.5">$ {{ $command }}</p>
                @endif
            </div>
        </div>

        <div class="flex items-center space-x-3 shrink-0 text-xs font-mono">
            @if ($duration > 0)
                <span class="text-[#666666]">
                    {{ $duration > 1000 ? round($duration / 1000, 2) . 's' : round($duration) . 'ms' }}
                </span>
            @endif

            <span class="px-2 py-0.5 rounded border text-[11px] {{ $passed ? 'bg-[#00e599]/10 text-[#00e599] border-[#00e599]/20' : 'bg-red-500/10 text-red-400 border-red-500/20' }}">
                exit {{ $exitCode }}
            </span>

            <svg class="w-4 h-4 text-[#666666] transition-transform duration-200" :class="{ 'rotate-180': expanded }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </div>
    </button>

    <!-- Expandable Logs Drawer -->
    <div x-show="expanded" x-cloak class="border-t border-[#1a1a1a] bg-[#080808] p-4 text-xs font-mono space-y-3">
        @if ($command)
            <div>
                <div class="text-[#888888] text-[11px] uppercase tracking-wider font-semibold mb-1">Command</div>
                <div class="p-2.5 rounded bg-[#101010] border border-[#222222] text-[#f3f4f6] select-all overflow-x-auto">
                    {{ $command }}
                </div>
            </div>
        @endif

        @if (!empty($stdout))
            <div>
                <div class="text-[#888888] text-[11px] uppercase tracking-wider font-semibold mb-1">Standard Output</div>
                <pre class="p-2.5 rounded bg-[#101010] border border-[#222222] text-[#cccccc] max-h-56 overflow-y-auto whitespace-pre-wrap leading-relaxed">{{ $stdout }}</pre>
            </div>
        @endif

        @if (!empty($stderr))
            <div>
                <div class="text-red-400 text-[11px] uppercase tracking-wider font-semibold mb-1">Standard Error</div>
                <pre class="p-2.5 rounded bg-red-950/20 border border-red-500/30 text-red-300 max-h-56 overflow-y-auto whitespace-pre-wrap leading-relaxed">{{ $stderr }}</pre>
            </div>
        @endif

        @if (empty($stdout) && empty($stderr))
            <p class="text-[#666666] italic">No console output recorded for this check.</p>
        @endif
    </div>
</div>
