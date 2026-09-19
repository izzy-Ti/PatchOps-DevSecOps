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

    $badgeClass = 'bg-[#00e599]/10 text-[#00e599] border-[#00e599]/30';
    $dotClass = 'bg-[#00e599]';

    $sizeClasses = match($size) {
        'sm' => 'px-2 py-0.5 text-[11px]',
        'lg' => 'px-3 py-1 text-sm',
        default => 'px-2.5 py-0.5 text-xs',
    };
@endphp

<span class="inline-flex items-center space-x-1.5 rounded-full font-mono font-medium border {{ $sizeClasses }} {{ $badgeClass }}">
    <span class="w-1.5 h-1.5 rounded-full {{ $dotClass }} shrink-0"></span>
    <span>{{ $displayLabel }}</span>
</span>
