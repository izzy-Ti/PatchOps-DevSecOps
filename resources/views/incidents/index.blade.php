<x-layouts.app title="Security Incidents">
    <x-slot:breadcrumb>Incidents</x-slot:breadcrumb>

    <!-- Top KPI Stat Cards (GitHub Style) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Active Incidents -->
        <div class="bg-[#161b22] border border-[#30363d] rounded-md p-4">
            <div class="flex items-center justify-between text-xs text-[#848d97]">
                <span>Active incidents</span>
                <span class="w-2 h-2 rounded-full bg-[#58a6ff]"></span>
            </div>
            <div class="mt-2 flex items-baseline space-x-2">
                <span class="text-2xl font-semibold text-[#e6edf3]">{{ $activeCount ?? 0 }}</span>
                <span class="text-xs text-[#848d97]">in progress</span>
            </div>
        </div>

        <!-- Awaiting Human Review -->
        <div class="bg-[#161b22] border {{ ($awaitingApprovalCount ?? 0) > 0 ? 'border-[#d29922]/60 bg-[#d29922]/5' : 'border-[#30363d]' }} rounded-md p-4">
            <div class="flex items-center justify-between text-xs {{ ($awaitingApprovalCount ?? 0) > 0 ? 'text-[#e3b341]' : 'text-[#848d97]' }}">
                <span>Awaiting Human Review</span>
                <span class="w-2 h-2 rounded-full {{ ($awaitingApprovalCount ?? 0) > 0 ? 'bg-[#d29922]' : 'bg-[#6e7681]' }}"></span>
            </div>
            <div class="mt-2 flex items-baseline space-x-2">
                <span class="text-2xl font-semibold {{ ($awaitingApprovalCount ?? 0) > 0 ? 'text-[#e3b341]' : 'text-[#e6edf3]' }}">{{ $awaitingApprovalCount ?? 0 }}</span>
                <span class="text-xs text-[#848d97]">gated at HITL boundary</span>
            </div>
        </div>

        <!-- Remediated -->
        <div class="bg-[#161b22] border border-[#30363d] rounded-md p-4">
            <div class="flex items-center justify-between text-xs text-[#848d97]">
                <span>Remediated</span>
                <span class="w-2 h-2 rounded-full bg-[#3fb950]"></span>
            </div>
            <div class="mt-2 flex items-baseline space-x-2">
                <span class="text-2xl font-semibold text-[#3fb950]">{{ $remediatedCount ?? 0 }}</span>
                <span class="text-xs text-[#848d97]">verified & merged</span>
            </div>
        </div>

        <!-- Gate Pass Rate -->
        <div class="bg-[#161b22] border border-[#30363d] rounded-md p-4">
            <div class="flex items-center justify-between text-xs text-[#848d97]">
                <span>Quality gate pass rate</span>
                <span class="w-2 h-2 rounded-full bg-[#a371f7]"></span>
            </div>
            <div class="mt-2 flex items-baseline space-x-2">
                <span class="text-2xl font-semibold text-[#e6edf3]">{{ $passRate ?? '96.4' }}%</span>
                <span class="text-xs text-[#848d97]">passed 8/8 checks</span>
            </div>
        </div>
    </div>

    <!-- GitHub Search & Filter Bar -->
    <div class="bg-[#161b22] border border-[#30363d] rounded-md p-3 mb-4">
        <form method="GET" action="{{ route('incidents.index') }}" class="flex flex-col md:flex-row gap-2.5">
            <!-- Search Query Input with Magnifier Icon -->
            <div class="relative flex-1">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="w-4 h-4 text-[#848d97]" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M10.68 11.74a6 6 0 0 1-7.922-8.982 6 6 0 0 1 8.982 7.922l3.04 3.04a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215ZM11.5 7a4.5 4.5 0 1 0-9 0 4.5 4.5 0 0 0 9 0Z"/>
                    </svg>
                </div>
                <input 
                    type="text" 
                    name="search" 
                    value="{{ request('search') }}" 
                    placeholder="Search incidents, CVEs, or repositories..." 
                    class="w-full pl-9 pr-3 py-1.5 rounded-md bg-[#0d1117] border border-[#30363d] text-sm text-[#e6edf3] placeholder-[#848d97] focus:outline-none focus:border-[#58a6ff] focus:ring-1 focus:ring-[#58a6ff]"
                >
            </div>

            <!-- Status Filter Dropdown -->
            <div class="w-full md:w-48">
                <select name="status" class="w-full px-3 py-1.5 rounded-md bg-[#0d1117] border border-[#30363d] text-sm text-[#e6edf3] focus:outline-none focus:border-[#58a6ff]">
                    <option value="">Status: All</option>
                    @foreach (\App\Enums\IncidentStatus::cases() as $statusCase)
                        <option value="{{ $statusCase->value }}" {{ request('status') === $statusCase->value ? 'selected' : '' }}>
                            Status: {{ ucwords(strtolower(str_replace('_', ' ', $statusCase->value))) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Severity Filter Dropdown -->
            <div class="w-full md:w-40">
                <select name="severity" class="w-full px-3 py-1.5 rounded-md bg-[#0d1117] border border-[#30363d] text-sm text-[#e6edf3] focus:outline-none focus:border-[#58a6ff]">
                    <option value="">Severity: All</option>
                    <option value="critical" {{ request('severity') === 'critical' ? 'selected' : '' }}>Critical</option>
                    <option value="high" {{ request('severity') === 'high' ? 'selected' : '' }}>High</option>
                    <option value="medium" {{ request('severity') === 'medium' ? 'selected' : '' }}>Medium</option>
                    <option value="low" {{ request('severity') === 'low' ? 'selected' : '' }}>Low</option>
                </select>
            </div>

            <!-- GitHub Green Button -->
            <button type="submit" class="px-4 py-1.5 rounded-md bg-[#238636] hover:bg-[#2ea043] border border-[rgba(240,246,252,0.1)] text-white text-sm font-medium transition shrink-0">
                Filter
            </button>

            @if (request()->hasAny(['search', 'status', 'severity', 'repository']))
                <a href="{{ route('incidents.index') }}" class="px-3 py-1.5 rounded-md bg-[#21262d] hover:bg-[#30363d] border border-[#30363d] text-[#c9d1d9] text-sm font-medium transition text-center shrink-0">
                    Clear
                </a>
            @endif
        </form>
    </div>

    <!-- GitHub Style Incident List (Like GitHub Issues / PRs) -->
    <div class="rounded-md border border-[#30363d] bg-[#161b22] overflow-hidden">
        <!-- List Header Bar -->
        <div class="px-4 py-3 bg-[#161b22] border-b border-[#30363d] flex items-center justify-between text-xs text-[#848d97]">
            <div class="flex items-center space-x-4">
                <span class="font-semibold text-[#e6edf3]">
                    {{ $incidents->total() }} total {{ Str::plural('incident', $incidents->total()) }}
                </span>
                @if (request()->filled('status'))
                    <span>• Filtered by <span class="text-[#e6edf3]">{{ ucwords(str_replace('_', ' ', request('status'))) }}</span></span>
                @endif
            </div>
            <div class="text-xs text-[#848d97]">
                Sorted by most recent
            </div>
        </div>

        <!-- Incident Rows -->
        <div class="divide-y divide-[#30363d]">
            @forelse ($incidents as $incident)
                @php
                    $isAwaiting = strtoupper($incident->status?->value ?? (string) $incident->status) === 'AWAITING_APPROVAL';
                    $isRemediated = strtoupper($incident->status?->value ?? (string) $incident->status) === 'REMEDIATED';
                    $sev = strtolower($incident->severity?->value ?? (string) $incident->severity);
                @endphp
                <div class="p-4 hover:bg-[#1c2128] transition flex items-start justify-between gap-4">
                    <div class="flex items-start space-x-3 flex-1 min-w-0">
                        <!-- Leading Status Icon -->
                        <div class="mt-0.5 shrink-0">
                            @if ($isRemediated)
                                <svg class="w-5 h-5 text-[#3fb950]" viewBox="0 0 16 16" fill="currentColor">
                                    <path d="M8 16A8 8 0 1 1 8 0a8 8 0 0 1 0 16Zm3.78-9.72a.751.751 0 0 0-.018-1.042.751.751 0 0 0-1.042-.018L6.75 9.19 5.28 7.72a.751.751 0 0 0-1.042.018.751.751 0 0 0-.018 1.042l2 2a.75.75 0 0 0 1.06 0Z"/>
                                </svg>
                            @elseif ($isAwaiting)
                                <svg class="w-5 h-5 text-[#d2a8ff]" viewBox="0 0 16 16" fill="currentColor">
                                    <path d="M1.5 3.25a2.25 2.25 0 1 1 3 2.122v5.256a2.251 2.251 0 1 1-1.5 0V5.372A2.25 2.25 0 0 1 1.5 3.25Zm5.677-.177L9.5 5.396l2.323-2.323a.75.75 0 0 1 1.06 1.06L10.56 6.456l2.324 2.324a.75.75 0 1 1-1.06 1.06L9.5 7.518 7.177 9.84a.75.75 0 0 1-1.06-1.06L8.44 6.456 6.116 4.133a.75.75 0 0 1 1.06-1.06ZM3 3.25a.75.75 0 1 0-1.5 0 .75.75 0 0 0 1.5 0Zm0 9.5a.75.75 0 1 0-1.5 0 .75.75 0 0 0 1.5 0Z"/>
                                </svg>
                            @else
                                <svg class="w-5 h-5 text-[#58a6ff]" viewBox="0 0 16 16" fill="currentColor">
                                    <path d="M8 9.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/>
                                    <path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Z"/>
                                </svg>
                            @endif
                        </div>

                        <!-- Main Content -->
                        <div class="space-y-1 flex-1 min-w-0">
                            <div class="flex items-center space-x-2 flex-wrap gap-y-1">
                                <a href="{{ $isAwaiting ? route('incidents.approval', $incident) : route('incidents.show', $incident) }}" class="text-base font-semibold text-[#e6edf3] hover:text-[#58a6ff] transition">
                                    {{ $incident->title }}
                                </a>

                                <!-- Severity Tag -->
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium border {{ match($sev) {
                                    'critical' => 'bg-[#da3633]/15 text-[#f85149] border-[#f85149]/30',
                                    'high' => 'bg-[#d29922]/15 text-[#e3b341] border-[#d29922]/30',
                                    'medium' => 'bg-[#2f81f7]/15 text-[#58a6ff] border-[#2f81f7]/30',
                                    default => 'bg-[#6e7681]/15 text-[#8b949e] border-[#6e7681]/30',
                                } }}">
                                    {{ ucfirst($sev) }}
                                </span>

                                <x-status-badge :status="$incident->status" size="sm" />
                            </div>

                            <!-- Metadata Subline (like GitHub issues) -->
                            <div class="text-xs text-[#848d97] flex items-center space-x-2 flex-wrap gap-y-1">
                                <span class="font-mono text-[#c9d1d9] font-medium">{{ $incident->incident_number }}</span>
                                <span>•</span>
                                <span class="font-mono text-[#58a6ff]">{{ $incident->cve_identifier }}</span>
                                <span>•</span>
                                <span>{{ $incident->repository }}</span>
                                <span>•</span>
                                <span>opened {{ $incident->created_at?->diffForHumans() ?? 'recently' }}</span>
                                <span>•</span>
                                <span>iteration {{ $incident->patch_iterations ?? 1 }}/3</span>
                            </div>
                        </div>
                    </div>

                    <!-- Right Actions -->
                    <div class="shrink-0 flex items-center space-x-2">
                        @if ($isAwaiting)
                            <a href="{{ route('incidents.approval', $incident) }}" class="px-3 py-1.5 rounded-md bg-[#238636] hover:bg-[#2ea043] border border-[rgba(240,246,252,0.1)] text-white text-xs font-medium transition flex items-center space-x-1">
                                <span>Review &amp; Approve</span>
                                <span>&rarr;</span>
                            </a>
                        @else
                            <a href="{{ route('incidents.show', $incident) }}" class="px-3 py-1.5 rounded-md bg-[#21262d] hover:bg-[#30363d] border border-[#30363d] text-[#c9d1d9] hover:text-white text-xs font-medium transition">
                                View details
                            </a>
                        @endif
                    </div>
                </div>
            @empty
                <div class="p-12 text-center text-[#848d97]">
                    <svg class="w-10 h-10 mx-auto text-[#6e7681] mb-3" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M8 9.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/>
                        <path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Z"/>
                    </svg>
                    <p class="text-base font-semibold text-[#e6edf3]">No incidents found</p>
                    <p class="text-xs text-[#848d97] mt-1">Try clearing your search query or selecting a different filter.</p>
                </div>
            @endforelse
        </div>

        <!-- Pagination Bar -->
        @if ($incidents->hasPages())
            <div class="p-3 bg-[#161b22] border-t border-[#30363d]">
                {{ $incidents->links() }}
            </div>
        @endif
    </div>

</x-layouts.app>
