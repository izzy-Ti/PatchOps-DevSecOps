<x-layouts.app title="Incident #{{ $incident->incident_number }}">
    <x-slot:breadcrumb>
        <a href="{{ route('incidents.index') }}" class="hover:text-[#00e599] transition">Incidents</a> / 
        <span>{{ $incident->incident_number }}</span>
    </x-slot:breadcrumb>

    @php
        $vuln = $incident->vulnerability;
        $auditEvents = $incident->auditEvents()->orderBy('created_at', 'asc')->get();
        $patchArtifacts = $incident->patchArtifacts()->orderBy('created_at', 'desc')->get();
        $qualityRuns = $incident->qualityGateRuns()->with('checks')->orderBy('created_at', 'desc')->get();
        $remediationRun = $incident->latestRemediationRun;
        $verificationChecks = $remediationRun ? $remediationRun->checks : collect();
        $isAwaiting = strtoupper($incident->status?->value ?? (string) $incident->status) === 'AWAITING_APPROVAL';
        $sev = strtolower($incident->severity?->value ?? (string) $incident->severity);
    @endphp

    <div x-data="{ activeTab: '{{ $isAwaiting ? 'iterations' : 'overview' }}' }" class="space-y-6 w-full">

        <!-- Incident Header Bar -->
        <div class="border-b border-[#1e1e1e] pb-5 space-y-4">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="space-y-1.5">
                    <h1 class="text-2xl font-bold text-white tracking-tight">
                        {{ $incident->title }}
                        <span class="font-mono text-[#777777] font-normal text-lg">#{{ $incident->incident_number }}</span>
                    </h1>

                    <div class="text-xs font-mono text-[#8e8e93] flex items-center space-x-2.5 flex-wrap gap-y-1">
                        <x-status-badge :status="$incident->status" size="md" />

                        <!-- Severity Pill -->
                        <span class="px-2 py-0.5 rounded text-[11px] font-mono uppercase font-semibold bg-[#00e599]/10 text-[#00e599] border border-[#00e599]/30">
                            {{ $sev }}
                        </span>

                        <span class="px-2 py-0.5 rounded bg-[#141414] border border-[#262626] text-[#00e599]">{{ $incident->cve_identifier }}</span>
                        <span>•</span>
                        <span>Repo: <code class="text-white">{{ $incident->repository }}</code></span>
                        <span>•</span>
                        <span>Opened {{ $incident->created_at?->diffForHumans() ?? 'recently' }}</span>
                    </div>
                </div>

                @if ($isAwaiting)
                    <div class="shrink-0">
                        <a href="{{ route('incidents.approval', $incident) }}" class="inline-flex items-center space-x-2 px-4 py-2 rounded-md bg-[#00e599] hover:bg-[#00c784] text-black font-semibold text-xs transition shadow-[0_0_12px_rgba(0,229,153,0.3)]">
                            <span>Open review console</span>
                            <span>&rarr;</span>
                        </a>
                    </div>
                @endif
            </div>

            <!-- Navigation Tabs -->
            <div class="flex items-center space-x-2 pt-2 text-xs font-medium border-b border-[#1e1e1e] pb-px">
                <button 
                    type="button" 
                    @click="activeTab = 'overview'" 
                    :class="activeTab === 'overview' ? 'border-[#00e599] text-white font-semibold' : 'border-transparent text-[#777777] hover:text-white'" 
                    class="px-4 py-2.5 border-b-2 transition"
                >
                    1. Vulnerability Overview
                </button>

                <button 
                    type="button" 
                    @click="activeTab = 'timeline'" 
                    :class="activeTab === 'timeline' ? 'border-[#00e599] text-white font-semibold' : 'border-transparent text-[#777777] hover:text-white'" 
                    class="px-4 py-2.5 border-b-2 transition flex items-center space-x-1.5"
                >
                    <span>2. Operational Timeline</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono bg-[#181818] text-[#888888]">{{ $auditEvents->count() }}</span>
                </button>

                <button 
                    type="button" 
                    @click="activeTab = 'iterations'" 
                    :class="activeTab === 'iterations' ? 'border-[#00e599] text-white font-semibold' : 'border-transparent text-[#777777] hover:text-white'" 
                    class="px-4 py-2.5 border-b-2 transition flex items-center space-x-1.5"
                >
                    <span>3. Patch Iterations</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono bg-[#181818] text-[#888888]">{{ $patchArtifacts->count() }}/3</span>
                </button>

                <button 
                    type="button" 
                    @click="activeTab = 'postdeploy'" 
                    :class="activeTab === 'postdeploy' ? 'border-[#00e599] text-white font-semibold' : 'border-transparent text-[#777777] hover:text-white'" 
                    class="px-4 py-2.5 border-b-2 transition"
                >
                    4. Post-Deploy &amp; Verification
                </button>
            </div>
        </div>

        <!-- TAB 1: Overview -->
        <div x-show="activeTab === 'overview'" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Left: Advisory Details -->
                <div class="md:col-span-2 space-y-4">
                    <!-- Root Cause Analysis -->
                    <div class="rounded-lg border border-[#1e1e1e] bg-[#0c0c0c] p-4 space-y-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-[#888888]">Root cause analysis</h3>
                        <div class="p-3.5 rounded-md bg-[#121212] border border-[#222222] text-xs text-[#cccccc] leading-relaxed font-sans">
                            {{ $incident->root_cause ?? 'No root cause recorded.' }}
                        </div>
                    </div>

                    <!-- Vulnerability Advisory Details -->
                    @if ($vuln)
                        <div class="rounded-lg border border-[#1e1e1e] bg-[#0c0c0c] p-4 space-y-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-[#888888]">Security advisory details</h3>
                            <p class="text-xs text-[#cccccc] leading-relaxed">{{ $vuln->description }}</p>

                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 pt-2 text-xs font-mono">
                                <div class="p-2.5 rounded bg-[#121212] border border-[#222222]">
                                    <span class="text-[#777777] block text-[11px]">Scanner source</span>
                                    <span class="font-medium text-white mt-0.5 block">{{ strtoupper($vuln->source?->value ?? ($vuln->source ?? '—')) }}</span>
                                </div>
                                <div class="p-2.5 rounded bg-[#121212] border border-[#222222]">
                                    <span class="text-[#777777] block text-[11px]">Affected version</span>
                                    <span class="font-medium text-red-400 mt-0.5 block">{{ $vuln->affected_version ?? '—' }}</span>
                                </div>
                                <div class="p-2.5 rounded bg-[#121212] border border-[#222222]">
                                    <span class="text-[#777777] block text-[11px]">Fixed version</span>
                                    <span class="font-medium text-[#00e599] mt-0.5 block">{{ $vuln->fixed_version ?? '—' }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Right: Metadata Sidebar -->
                <div class="space-y-4">
                    <div class="rounded-lg border border-[#1e1e1e] bg-[#0c0c0c] p-4 space-y-3 text-xs font-mono">
                        <h3 class="font-semibold text-white border-b border-[#1e1e1e] pb-2 text-[11px] uppercase tracking-wider text-[#888888]">Incident metadata</h3>
                        
                        <div>
                            <span class="text-[11px] text-[#777777] block">Vulnerability identifier</span>
                            <span class="font-medium text-[#00e599]">{{ $incident->cve_identifier }}</span>
                        </div>

                        <div>
                            <span class="text-[11px] text-[#777777] block">Correlation ID</span>
                            <span class="text-[11px] text-[#aaaaaa] break-all">{{ $incident->correlation_id }}</span>
                        </div>

                        <div>
                            <span class="text-[11px] text-[#777777] block">Target branch</span>
                            <span class="text-white">{{ $incident->base_branch ?? '—' }}</span>
                        </div>

                        <div>
                            <span class="text-[11px] text-[#777777] block">Vulnerable commit SHA</span>
                            <span class="text-white">{{ $incident->vulnerable_commit_sha ? substr($incident->vulnerable_commit_sha, 0, 8) : '—' }}</span>
                        </div>

                        <div>
                            <span class="text-[11px] text-[#777777] block">Patch iterations</span>
                            <span class="font-semibold text-white">{{ $incident->patch_iterations ?? 0 }} of 3</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: Operational Timeline -->
        <div x-show="activeTab === 'timeline'" class="space-y-4">
            <div class="rounded-lg border border-[#1e1e1e] bg-[#0c0c0c] p-6">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-[#888888] mb-4">Immutable audit events &amp; agent execution trace</h3>
                
                @if ($auditEvents->isNotEmpty())
                    <div class="pt-2">
                        @foreach ($auditEvents as $event)
                            <x-timeline-event :event="$event" />
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-[#777777] italic">No audit events recorded for this incident yet.</p>
                @endif
            </div>
        </div>

        <!-- TAB 3: Patch Iterations -->
        <div x-show="activeTab === 'iterations'" class="space-y-6">
            @forelse ($patchArtifacts as $index => $patch)
                @php
                    $qg = $patch->qualityGateRuns()->first();
                @endphp
                <div class="rounded-lg border border-[#1e1e1e] bg-[#0c0c0c] p-4 space-y-4">
                    <div class="flex items-center justify-between border-b border-[#1e1e1e] pb-3">
                        <div class="flex items-center space-x-3">
                            <span class="font-semibold text-white text-sm">Candidate patch (Iteration {{ $patchArtifacts->count() - $index }})</span>
                            <span class="px-2 py-0.5 rounded text-[11px] font-mono uppercase font-semibold border {{ $patch->status === 'verified' ? 'bg-[#00e599]/10 text-[#00e599] border-[#00e599]/30' : 'bg-[#181818] text-[#8e8e93] border-[#2a2a2a]' }}">
                                {{ $patch->status }}
                            </span>
                        </div>

                        <span class="text-xs font-mono text-[#777777]">{{ $patch->created_at?->diffForHumans() }}</span>
                    </div>

                    <x-code-diff :diff="$patch->diff" fileName="{{ $patch->file_path ?? 'patch.diff' }}" />

                    @if ($qg && $qg->checks->isNotEmpty())
                        <div class="mt-4 pt-4 border-t border-[#1e1e1e] space-y-2">
                            <h4 class="text-xs font-semibold uppercase tracking-wider text-[#888888]">Quality gate verification results ({{ $qg->checks->where('exit_code', 0)->count() }}/{{ $qg->checks->count() }} passed)</h4>
                            <div class="space-y-1.5">
                                @foreach ($qg->checks as $chk)
                                    <x-quality-check-item :check="$chk" />
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="rounded-lg border border-[#1e1e1e] bg-[#0c0c0c] p-8 text-center text-xs text-[#777777]">
                    No patch candidates have been generated yet for this incident.
                </div>
            @endforelse
        </div>

        <!-- TAB 4: Post-Deploy & Verification -->
        <div x-show="activeTab === 'postdeploy'" class="space-y-6">
            <div class="rounded-lg border border-[#1e1e1e] bg-[#0c0c0c] p-6 space-y-4">
                <h3 class="text-sm font-semibold text-white">CI/CD pipeline monitoring &amp; staging verification</h3>
                <p class="text-xs text-[#8e8e93]">
                    Monitors external CI builds, tracks staging deployment, and independently verifies post-deployment environment health.
                </p>

                @if ($remediationRun)
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2 font-mono text-xs">
                        <div class="p-3 rounded-md bg-[#121212] border border-[#222222]">
                            <span class="text-[11px] text-[#777777] block">Remediation status</span>
                            <span class="text-xs font-semibold text-white mt-1 block">{{ $remediationRun->status }}</span>
                        </div>
                        <div class="p-3 rounded-md bg-[#121212] border border-[#222222]">
                            <span class="text-[11px] text-[#777777] block">Target environment</span>
                            <span class="text-xs font-semibold text-white mt-1 block">{{ $remediationRun->environment ? ucfirst($remediationRun->environment) : '—' }}</span>
                        </div>
                        <div class="p-3 rounded-md bg-[#121212] border border-[#222222]">
                            <span class="text-[11px] text-[#777777] block">Security post-check</span>
                            <span class="text-xs font-semibold text-[#00e599] mt-1 block">{{ $remediationRun->security_status ?? '—' }}</span>
                        </div>
                    </div>
                @else
                    <div class="p-4 rounded-md bg-[#121212] border border-[#222222] text-xs text-[#777777]">
                        Remediation run will initialize automatically upon human approval of the candidate patch.
                    </div>
                @endif
            </div>
        </div>

    </div>

</x-layouts.app>
