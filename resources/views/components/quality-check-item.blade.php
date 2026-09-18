@props([
    'check' => null,
])

@php
    $passed = (bool) ($check->status === 'PASSED' || ($check->exit_code === 0 && empty($check->failed)));
    $exitCode = $check->exit_code ?? ($passed ? 0 : 1);
    $checkName = ucwords(str_replace('_', ' ', $check->check_type ?? $check->check_name ?? $check->name ?? 'Quality Gate Verification'));
    $duration = $check->duration_ms ?? 0;
    $command = $check->command ?? null;
    $stdout = $check->stdout ?? null;
    $stderr = $check->stderr ?? null;
@endphp

<div x-data="{ expanded: false }" class="rounded-md border border-[#30363d] bg-[#161b22] overflow-hidden hover:border-[#484f58] transition">
    <!-- Clickable Header (Like GitHub Actions Check Step) -->
    <button 
        type="button" 
        @click="expanded = !expanded" 
        class="w-full px-4 py-3 flex items-center justify-between text-left focus:outline-none"
    >
        <div class="flex items-center space-x-3">
            <!-- GitHub Checkmark / Error Icon -->
            @if ($passed)
                <svg class="w-5 h-5 text-[#3fb950] shrink-0" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M8 16A8 8 0 1 1 8 0a8 8 0 0 1 0 16Zm3.78-9.72a.751.751 0 0 0-.018-1.042.751.751 0 0 0-1.042-.018L6.75 9.19 5.28 7.72a.751.751 0 0 0-1.042.018.751.751 0 0 0-.018 1.042l2 2a.75.75 0 0 0 1.06 0Z"/>
                </svg>
            @else
                <svg class="w-5 h-5 text-[#f85149] shrink-0" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M2.343 13.657A8 8 0 1 1 13.657 2.343 8 8 0 0 1 2.343 13.657ZM6.03 4.97a.75.75 0 0 0-1.06 1.06L6.94 8 4.97 9.97a.749.749 0 0 0 .326 1.275.749.749 0 0 0 .734-.215L8 9.06l1.97 1.97a.749.749 0 0 0 1.275-.326.749.749 0 0 0-.215-.734L9.06 8l1.97-1.97a.749.749 0 0 0-.326-1.275.749.749 0 0 0-.734.215L8 6.94 6.03 4.97Z"/>
                </svg>
            @endif

            <div>
                <span class="font-medium text-sm text-[#e6edf3]">{{ $checkName }}</span>
                @if ($command)
                    <p class="text-xs font-mono text-[#848d97] truncate max-w-lg mt-0.5">$ {{ $command }}</p>
                @endif
            </div>
        </div>

        <div class="flex items-center space-x-3 shrink-0">
            <!-- Duration Badge -->
            @if ($duration > 0)
                <span class="text-xs text-[#848d97]">
                    {{ $duration > 1000 ? round($duration / 1000, 2) . 's' : round($duration) . 'ms' }}
                </span>
            @endif

            <!-- Exit status pill -->
            <span class="px-2 py-0.5 rounded text-xs font-medium border {{ $passed ? 'bg-[#238636]/15 text-[#3fb950] border-[#3fb950]/30' : 'bg-[#da3633]/15 text-[#f85149] border-[#f85149]/30' }}">
                exit {{ $exitCode }}
            </span>

            <!-- Expand Chevron -->
            <svg 
                class="w-4 h-4 text-[#848d97] transition-transform duration-200" 
                :class="{ 'rotate-180': expanded }" 
                viewBox="0 0 16 16" 
                fill="currentColor"
            >
                <path d="M12.78 5.22a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L3.22 6.28a.751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018L8 8.94l3.72-3.72a.75.75 0 0 1 1.06 0Z"/>
            </svg>
        </div>
    </button>

    <!-- Expandable Logs Drawer (GitHub Actions Log Viewer) -->
    <div x-show="expanded" x-cloak class="border-t border-[#30363d] bg-[#0d1117] p-4 text-xs font-mono space-y-3">
        @if ($command)
            <div>
                <div class="text-[#848d97] text-xs font-medium mb-1">Command executed:</div>
                <div class="p-2.5 rounded bg-[#161b22] border border-[#30363d] text-[#e6edf3] select-all overflow-x-auto">
                    {{ $command }}
                </div>
            </div>
        @endif

        @if (!empty($stdout))
            <div>
                <div class="text-[#848d97] text-xs font-medium mb-1">Standard output:</div>
                <pre class="p-2.5 rounded bg-[#161b22] border border-[#30363d] text-[#c9d1d9] max-h-48 overflow-y-auto whitespace-pre-wrap leading-relaxed">{{ $stdout }}</pre>
            </div>
        @endif

        @if (!empty($stderr))
            <div>
                <div class="text-[#f85149] text-xs font-medium mb-1">Standard error:</div>
                <pre class="p-2.5 rounded bg-[#3a1d1d]/30 border border-[#da3633]/40 text-[#f85149] max-h-48 overflow-y-auto whitespace-pre-wrap leading-relaxed">{{ $stderr }}</pre>
            </div>
        @endif

        @if (empty($stdout) && empty($stderr))
            <p class="text-[#848d97] italic">No console output recorded for this check.</p>
        @endif
    </div>
</div>
