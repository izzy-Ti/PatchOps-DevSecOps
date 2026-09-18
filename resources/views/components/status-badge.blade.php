@props([
    'status' => 'received',
    'size' => 'md', // sm, md, lg
])

@php
    $statusString = strtoupper($status instanceof \BackedEnum ? $status->value : (string) $status);
    $displayLabel = ucwords(strtolower(str_replace('_', ' ', $statusString)));

    $isAwaiting = in_array($statusString, ['AWAITING_APPROVAL']);
    $isRemediated = in_array($statusString, ['REMEDIATED', 'RESOLVED', 'MERGED', 'DEPLOYED', 'PASSED']);
    $isFailed = in_array($statusString, ['FAILED', 'ESCALATED', 'TRIAGED_NOT_REPRODUCIBLE', 'INFRA_FAILED']);
    $isRunning = in_array($statusString, ['TRIAGING', 'REPRODUCING', 'PATCHING', 'VALIDATING', 'CI_RUNNING', 'DEPLOYING', 'POST_DEPLOY_VERIFY']);

    if ($isRemediated) {
        $colorClasses = 'bg-[#238636]/15 text-[#3fb950] border-[#3fb950]/30';
        $dotClass = 'bg-[#3fb950]';
    } elseif ($isAwaiting) {
        $colorClasses = 'bg-[#d29922]/15 text-[#e3b341] border-[#d29922]/40';
        $dotClass = 'bg-[#d29922]';
    } elseif ($isFailed) {
        $colorClasses = 'bg-[#da3633]/15 text-[#f85149] border-[#f85149]/30';
        $dotClass = 'bg-[#f85149]';
    } elseif ($isRunning) {
        $colorClasses = 'bg-[#2f81f7]/15 text-[#58a6ff] border-[#2f81f7]/35';
        $dotClass = 'bg-[#58a6ff]';
    } else {
        $colorClasses = 'bg-[#6e7681]/15 text-[#8b949e] border-[#6e7681]/30';
        $dotClass = 'bg-[#8b949e]';
    }

    $sizeClasses = match($size) {
        'sm' => 'px-2 py-0.5 text-xs',
        'lg' => 'px-3 py-1 text-sm',
        default => 'px-2.5 py-0.5 text-xs',
    };
@endphp

<span class="inline-flex items-center space-x-1.5 rounded-full font-medium border {{ $sizeClasses }} {{ $colorClasses }}">
    <span class="w-1.5 h-1.5 rounded-full {{ $dotClass }} shrink-0"></span>
    <span>{{ $displayLabel }}</span>
</span>
