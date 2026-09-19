<x-layouts.app title="Security Incidents">
    <x-slot:breadcrumb>Incidents</x-slot:breadcrumb>

    <!-- Page Header & Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Security Incidents</h1>
            <p class="text-xs text-[#8e8e93] mt-1">Autonomous remediation telemetry, quality gates, and human approval boundary.</p>
        </div>

        <div class="flex items-center space-x-2 flex-wrap gap-y-2">
            <!-- GitHub Connection & Sync Button -->
            <form method="POST" action="{{ route('incidents.github.sync') }}">
                @csrf
                <button type="submit" class="px-3.5 py-1.5 rounded-md bg-[#141414] hover:bg-[#1c1c1c] border border-[#2a2a2a] text-xs font-medium text-white transition flex items-center space-x-1.5 shadow-sm" title="Pull live security alerts from GitHub Dependabot">
                    <svg class="w-3.5 h-3.5 text-[#00e599]" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M8 0c4.42 0 8 3.58 8 8a8.013 8.013 0 0 1-5.45 7.59c-.4.08-.55-.17-.55-.38 0-.27.01-1.13.01-2.2 0-.75-.25-1.23-.54-1.48 1.78-.2 3.65-.88 3.65-3.95 0-.88-.31-1.59-.82-2.15.08-.2.36-1.02-.08-2.12 0 0-.67-.22-2.2.82-.64-.18-1.32-.27-2-.27-.68 0-1.36.09-2 .27-1.53-1.03-2.2-.82-2.2-.82-.44 1.1-.16 1.92-.08 2.12-.51.56-.82 1.28-.82 2.15 0 3.06 1.86 3.75 3.64 3.95-.23.2-.44.55-.51 1.07-.46.21-1.61.55-2.33-.66-.15-.24-.6-.83-1.23-.82-.67.01-.27.38.01.53.34.19.73.9.82 1.13.16.45.68 1.31 2.69.94 0 .67.01 1.3.01 1.49 0 .21-.15.45-.55.38A7.995 7.995 0 0 1 0 8c0-4.42 3.58-8 8-8Z"/>
                    </svg>
                    <span>Sync GitHub</span>
                </button>
            </form>

            <a href="{{ route('incidents.index', ['status' => 'awaiting_approval']) }}" class="px-3.5 py-1.5 rounded-md bg-[#141414] hover:bg-[#1c1c1c] border border-[#2a2a2a] text-xs font-medium text-white transition flex items-center space-x-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                <span>Awaiting Review</span>
                @if (($awaitingApprovalCount ?? 0) > 0)
                    <span class="ml-1 px-1.5 py-0.2 rounded-full text-[10px] font-mono bg-amber-400/20 text-amber-300">{{ $awaitingApprovalCount }}</span>
                @endif
            </a>

            <a href="{{ route('incidents.index') }}" class="px-3.5 py-1.5 rounded-md bg-[#00e599] hover:bg-[#00c784] text-black font-semibold text-xs transition shadow-[0_0_12px_rgba(0,229,153,0.25)] flex items-center space-x-1.5">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                <span>Refresh</span>
            </a>
        </div>
    </div>

    <!-- Telemetry Summary Metric Bar (Neon Console Style) -->
    <div class="rounded-lg border border-[#1e1e1e] bg-[#0c0c0c] p-4 mb-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 divide-y md:divide-y-0 md:divide-x divide-[#1e1e1e]">
            <!-- Active Incidents -->
            <div class="pr-4 pt-2 md:pt-0">
                <div class="flex items-center space-x-1.5 text-xs text-[#8e8e93]">
                    <span>Active Incidents</span>
                </div>
                <div class="mt-1 flex items-baseline space-x-2">
                    <span class="text-2xl font-bold font-mono text-white">{{ $activeCount ?? 0 }}</span>
                    <span class="text-[11px] text-[#666666]">in progress</span>
                </div>
            </div>

            <!-- Awaiting Human Review -->
            <div class="px-0 md:px-4 pt-2 md:pt-0">
                <div class="flex items-center space-x-1.5 text-xs text-[#8e8e93]">
                    <span>Awaiting Human Review</span>
                    @if (($awaitingApprovalCount ?? 0) > 0)
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                    @endif
                </div>
                <div class="mt-1 flex items-baseline space-x-2">
                    <span class="text-2xl font-bold font-mono {{ ($awaitingApprovalCount ?? 0) > 0 ? 'text-amber-300' : 'text-white' }}">{{ $awaitingApprovalCount ?? 0 }}</span>
                    <span class="text-[11px] text-[#666666]">pending sign-off</span>
                </div>
            </div>

            <!-- Remediated -->
            <div class="px-0 md:px-4 pt-2 md:pt-0">
                <div class="flex items-center space-x-1.5 text-xs text-[#8e8e93]">
                    <span>Remediated</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-[#00e599]"></span>
                </div>
                <div class="mt-1 flex items-baseline space-x-2">
                    <span class="text-2xl font-bold font-mono text-[#00e599]">{{ $remediatedCount ?? 0 }}</span>
                    <span class="text-[11px] text-[#666666]">verified &amp; merged</span>
                </div>
            </div>

            <!-- Gate Pass Rate -->
            <div class="pl-0 md:pl-4 pt-2 md:pt-0">
                <div class="flex items-center space-x-1.5 text-xs text-[#8e8e93]">
                    <span>Quality Gate Pass Rate</span>
                </div>
                <div class="mt-1 flex items-baseline space-x-2">
                    <span class="text-2xl font-bold font-mono text-white">{{ $passRate ?? 0 }}%</span>
                    <span class="text-[11px] text-[#666666]">
                        @if (isset($totalChecks) && $totalChecks > 0)
                            {{ $passedChecks }}/{{ $totalChecks }} checks
                        @else
                            checks active
                        @endif
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Section Title & Filter Toolbar -->
    <div class="space-y-3 mb-4">
        <div class="flex items-center justify-between text-xs text-[#8e8e93]">
            <h2 class="text-base font-semibold text-white">
                {{ $incidents->total() }} {{ Str::plural('Incident', $incidents->total()) }}
            </h2>
            <span>Showing recent items</span>
        </div>

        <!-- Filter Bar -->
        <form method="GET" action="{{ route('incidents.index') }}" class="flex flex-col sm:flex-row gap-2.5">
            <!-- Search Query Input -->
            <div class="relative flex-1">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-[#666666]">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
                <input 
                    type="text" 
                    name="search" 
                    value="{{ request('search') }}" 
                    placeholder="Search incidents, CVEs, or repositories..." 
                    class="w-full pl-9 pr-3 py-1.5 rounded-md bg-[#0f0f0f] border border-[#222222] text-xs text-white placeholder-[#666666] focus:outline-none focus:border-[#00e599] transition"
                >
            </div>

            <!-- Status Filter -->
            <div class="w-full sm:w-44">
                <select name="status" class="w-full px-3 py-1.5 rounded-md bg-[#0f0f0f] border border-[#222222] text-xs text-[#cccccc] focus:outline-none focus:border-[#00e599] transition">
                    <option value="">Status: All</option>
                    @foreach (\App\Enums\IncidentStatus::cases() as $statusCase)
                        <option value="{{ $statusCase->value }}" {{ request('status') === $statusCase->value ? 'selected' : '' }}>
                            {{ ucwords(strtolower(str_replace('_', ' ', $statusCase->value))) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Severity Filter -->
            <div class="w-full sm:w-36">
                <select name="severity" class="w-full px-3 py-1.5 rounded-md bg-[#0f0f0f] border border-[#222222] text-xs text-[#cccccc] focus:outline-none focus:border-[#00e599] transition">
                    <option value="">Severity: All</option>
                    <option value="critical" {{ request('severity') === 'critical' ? 'selected' : '' }}>Critical</option>
                    <option value="high" {{ request('severity') === 'high' ? 'selected' : '' }}>High</option>
                    <option value="medium" {{ request('severity') === 'medium' ? 'selected' : '' }}>Medium</option>
                    <option value="low" {{ request('severity') === 'low' ? 'selected' : '' }}>Low</option>
                </select>
            </div>

            <!-- Buttons -->
            <button type="submit" class="px-4 py-1.5 rounded-md bg-[#181818] hover:bg-[#222222] border border-[#2a2a2a] text-white text-xs font-semibold transition shrink-0">
                Filter
            </button>

            @if (request()->hasAny(['search', 'status', 'severity', 'repository']))
                <a href="{{ route('incidents.index') }}" class="px-3 py-1.5 rounded-md bg-transparent hover:bg-[#181818] text-[#8e8e93] hover:text-white text-xs font-medium transition text-center shrink-0 border border-transparent">
                    Clear
                </a>
            @endif
        </form>
    </div>

    <!-- Modern Neon Console Table -->
    <div class="rounded-lg border border-[#1e1e1e] bg-[#0c0c0c] overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <!-- Table Header -->
                <thead class="bg-[#121212] border-b border-[#1e1e1e] text-[#777777] font-semibold uppercase tracking-wider text-[11px]">
                    <tr>
                        <th class="py-3 px-4">Incident</th>
                        <th class="py-3 px-4">Title &amp; Vulnerability</th>
                        <th class="py-3 px-4">Repository</th>
                        <th class="py-3 px-4">Severity</th>
                        <th class="py-3 px-4">Status</th>
                        <th class="py-3 px-4">Created</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>

                <!-- Table Body -->
                <tbody class="divide-y divide-[#181818]">
                    @forelse ($incidents as $incident)
                        @php
                            $isAwaiting = strtoupper($incident->status?->value ?? (string) $incident->status) === 'AWAITING_APPROVAL';
                            $sev = strtolower($incident->severity?->value ?? (string) $incident->severity);
                        @endphp
                        <tr class="hover:bg-[#141414] transition group">
                            <!-- Incident ID -->
                            <td class="py-3.5 px-4 font-mono font-medium text-white whitespace-nowrap">
                                <a href="{{ $isAwaiting ? route('incidents.approval', $incident) : route('incidents.show', $incident) }}" class="hover:text-[#00e599] transition">
                                    {{ $incident->incident_number }}
                                </a>
                            </td>

                            <!-- Title & CVE -->
                            <td class="py-3.5 px-4 min-w-[280px]">
                                <div class="space-y-1">
                                    <a href="{{ $isAwaiting ? route('incidents.approval', $incident) : route('incidents.show', $incident) }}" class="font-medium text-white hover:text-[#00e599] transition line-clamp-1">
                                        {{ $incident->title }}
                                    </a>
                                    <div class="flex items-center space-x-2 text-[11px] font-mono text-[#777777]">
                                        <span class="text-[#00e599]">{{ $incident->cve_identifier }}</span>
                                        <span>•</span>
                                        <span>iteration {{ $incident->patch_iterations ?? 1 }}/3</span>
                                    </div>
                                </div>
                            </td>

                            <!-- Repository -->
                            <td class="py-3.5 px-4 font-mono text-[#a1a1aa] whitespace-nowrap">
                                {{ $incident->repository }}
                            </td>

                            <!-- Severity -->
                            <td class="py-3.5 px-4 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded text-[11px] font-mono uppercase font-semibold bg-[#00e599]/10 text-[#00e599] border border-[#00e599]/30">
                                    {{ $sev }}
                                </span>
                            </td>

                            <!-- Status -->
                            <td class="py-3.5 px-4 whitespace-nowrap">
                                <x-status-badge :status="$incident->status" size="sm" />
                            </td>

                            <!-- Created -->
                            <td class="py-3.5 px-4 text-[#777777] font-mono text-[11px] whitespace-nowrap">
                                {{ $incident->created_at?->diffForHumans() ?? 'recently' }}
                            </td>

                            <!-- Actions -->
                            <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                @if ($isAwaiting)
                                    <a href="{{ route('incidents.approval', $incident) }}" class="inline-flex items-center space-x-1 px-3 py-1 rounded bg-[#00e599] hover:bg-[#00c784] text-black font-semibold text-xs transition shadow-[0_0_10px_rgba(0,229,153,0.25)]">
                                        <span>Review &amp; Approve</span>
                                        <span>&rarr;</span>
                                    </a>
                                @else
                                    <a href="{{ route('incidents.show', $incident) }}" class="inline-flex items-center px-3 py-1 rounded bg-[#161616] hover:bg-[#202020] border border-[#262626] text-[#cccccc] hover:text-white text-xs font-medium transition">
                                        View details
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 px-4 text-center text-[#777777]">
                                <div class="max-w-sm mx-auto space-y-2">
                                    <svg class="w-8 h-8 mx-auto text-[#444444]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <p class="font-semibold text-sm text-white">No incidents found</p>
                                    <p class="text-xs">No active or historical security incidents match your current filter parameters.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination Bar -->
        @if ($incidents->hasPages())
            <div class="p-3 bg-[#101010] border-t border-[#1e1e1e]">
                {{ $incidents->links() }}
            </div>
        @endif
    </div>

</x-layouts.app>
