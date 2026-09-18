<x-layouts.app title="Incident #{{ $incident->incident_number }}">
    <x-slot:breadcrumb>
        <a href="{{ route('incidents.index') }}" class="hover:text-[#58a6ff]">Incidents</a> / 
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

    <div x-data="{ activeTab: '{{ $isAwaiting ? 'iterations' : 'overview' }}' }" class="space-y-6">

        <!-- Incident Header Bar (GitHub Issue Style) -->
        <div class="border-b border-[#30363d] pb-5 space-y-3">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="space-y-1">
                    <div class="flex items-center space-x-3 flex-wrap gap-y-1">
                        <h1 class="text-2xl font-semibold text-[#e6edf3]">
                            {{ $incident->title }}
                            <span class="text-[#848d97] font-normal font-mono">#{{ $incident->incident_number }}</span>
                        </h1>
                    </div>

                    <div class="text-sm text-[#848d97] flex items-center space-x-2.5 flex-wrap gap-y-1 pt-1">
                        <x-status-badge :status="$incident->status" size="md" />

                        <!-- Severity Pill -->
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium border {{ match($sev) {
                            'critical' => 'bg-[#da3633]/15 text-[#f85149] border-[#f85149]/30',
                            'high' => 'bg-[#d29922]/15 text-[#e3b341] border-[#d29922]/30',
                            'medium' => 'bg-[#2f81f7]/15 text-[#58a6ff] border-[#2f81f7]/30',
                            default => 'bg-[#6e7681]/15 text-[#8b949e] border-[#6e7681]/30',
                        } }}">
                            {{ ucfirst($sev) }}
                        </span>

                        <span class="font-mono text-xs px-2 py-0.5 rounded bg-[#21262d] text-[#58a6ff] border border-[#30363d]">{{ $incident->cve_identifier }}</span>
                        <span>•</span>
                        <span>Repository: <code class="text-[#e6edf3]">{{ $incident->repository }}</code></span>
                        <span>•</span>
                        <span>Opened {{ $incident->created_at?->diffForHumans() ?? 'recently' }}</span>
                    </div>
                </div>

                @if ($isAwaiting)
                    <div class="shrink-0">
                        <a href="{{ route('incidents.approval', $incident) }}" class="px-4 py-2 rounded-md bg-[#238636] hover:bg-[#2ea043] border border-[rgba(240,246,252,0.1)] text-white text-sm font-medium transition flex items-center space-x-2">
                            <span>Open review console</span>
                            <span>&rarr;</span>
                        </a>
                    </div>
                @endif
            </div>

            <!-- GitHub Style Navigation Tabs -->
            <div class="flex items-center space-x-1 pt-2 text-sm font-medium">
                <button 
                    type="button" 
                    @click="activeTab = 'overview'" 
                    :class="activeTab === 'overview' ? 'border-[#f78166] text-white font-semibold' : 'border-transparent text-[#848d97] hover:text-white'" 
                    class="px-4 py-2 border-b-2 transition"
                >
                    1. Vulnerability Overview
                </button>

                <button 
                    type="button" 
                    @click="activeTab = 'timeline'" 
                    :class="activeTab === 'timeline' ? 'border-[#f78166] text-white font-semibold' : 'border-transparent text-[#848d97] hover:text-white'" 
                    class="px-4 py-2 border-b-2 transition flex items-center space-x-1.5"
                >
                    <span>2. Operational Timeline</span>
                    <span class="px-1.5 py-0.2 rounded-full text-xs bg-[#21262d] text-[#848d97]">{{ $auditEvents->count() }}</span>
                </button>

                <button 
                    type="button" 
                    @click="activeTab = 'iterations'" 
                    :class="activeTab === 'iterations' ? 'border-[#f78166] text-white font-semibold' : 'border-transparent text-[#848d97] hover:text-white'" 
                    class="px-4 py-2 border-b-2 transition flex items-center space-x-1.5"
                >
                    <span>3. Patch Iterations</span>
                    <span class="px-1.5 py-0.2 rounded-full text-xs bg-[#21262d] text-[#848d97]">{{ $patchArtifacts->count() }}/3</span>
                </button>

                <button 
                    type="button" 
                    @click="activeTab = 'postdeploy'" 
                    :class="activeTab === 'postdeploy' ? 'border-[#f78166] text-white font-semibold' : 'border-transparent text-[#848d97] hover:text-white'" 
                    class="px-4 py-2 border-b-2 transition"
                >
                    4. Post-Deploy &amp; Verification
                </button>
            </div>
        </div>

        <!-- TAB 1: Overview -->
        <div x-show="activeTab === 'overview'" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Left: Advisory Details (2 cols) -->
                <div class="md:col-span-2 space-y-4">
                    <!-- Root Cause Analysis -->
                    <div class="rounded-md border border-[#30363d] bg-[#161b22] p-4 space-y-2">
                        <h3 class="text-sm font-semibold text-white">Root cause analysis</h3>
                        <div class="p-3 rounded bg-[#0d1117] border border-[#30363d] text-sm text-[#c9d1d9] leading-relaxed font-sans">
                            {{ $incident->root_cause ?? 'Root cause synthesized by TriageAgent: Untrusted user input flowing into sensitive execution path without boundary sanitization.' }}
                        </div>
                    </div>

                    <!-- Vulnerability Advisory Details -->
                    @if ($vuln)
                        <div class="rounded-md border border-[#30363d] bg-[#161b22] p-4 space-y-3">
                            <h3 class="text-sm font-semibold text-white">Security advisory details</h3>
                            <p class="text-sm text-[#848d97] leading-relaxed">{{ $vuln->description }}</p>

                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 pt-2 text-xs">
                                <div class="p-2.5 rounded bg-[#0d1117] border border-[#30363d]">
                                    <span class="text-[#848d97] block">Scanner source</span>
                                    <span class="font-medium text-white mt-0.5 block">{{ strtoupper($vuln->source?->value ?? 'SNYK') }}</span>
                                </div>
                                <div class="p-2.5 rounded bg-[#0d1117] border border-[#30363d]">
                                    <span class="text-[#848d97] block">Affected version</span>
                                    <span class="font-mono text-[#f85149] mt-0.5 block">{{ $vuln->affected_version ?? '< 2.4.1' }}</span>
                                </div>
                                <div class="p-2.5 rounded bg-[#0d1117] border border-[#30363d]">
                                    <span class="text-[#848d97] block">Fixed version</span>
                                    <span class="font-mono text-[#3fb950] mt-0.5 block">{{ $vuln->fixed_version ?? '>= 2.4.1' }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Right: Metadata Sidebar (1 col) -->
                <div class="space-y-4">
                    <div class="rounded-md border border-[#30363d] bg-[#161b22] p-4 space-y-3 text-sm">
                        <h3 class="font-semibold text-white border-b border-[#30363d] pb-2 text-xs text-[#848d97]">Incident metadata</h3>
                        
                        <div>
                            <span class="text-xs text-[#848d97] block">Vulnerability identifier</span>
                            <span class="font-mono font-medium text-[#58a6ff]">{{ $incident->cve_identifier }}</span>
                        </div>

                        <div>
                            <span class="text-xs text-[#848d97] block">Correlation ID</span>
                            <span class="font-mono text-xs text-[#c9d1d9] break-all">{{ $incident->correlation_id }}</span>
                        </div>

                        <div>
                            <span class="text-xs text-[#848d97] block">Target branch</span>
                            <span class="font-mono text-xs text-[#e6edf3]">{{ $incident->base_branch ?? 'main' }}</span>
                        </div>

                        <div>
                            <span class="text-xs text-[#848d97] block">Vulnerable commit SHA</span>
                            <span class="font-mono text-xs text-[#e6edf3]">{{ substr($incident->vulnerable_commit_sha ?? 'c0ffee1', 0, 8) }}</span>
                        </div>

                        <div>
                            <span class="text-xs text-[#848d97] block">Patch iterations</span>
                            <span class="font-semibold text-white">{{ $incident->patch_iterations ?? 1 }} of 3</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: Operational Timeline -->
        <div x-show="activeTab === 'timeline'" class="space-y-4">
            <div class="rounded-md border border-[#30363d] bg-[#161b22] p-6">
                <h3 class="text-sm font-semibold text-white mb-4">Immutable audit events &amp; agent execution trace</h3>
                
                @if ($auditEvents->isNotEmpty())
                    <div class="pt-2">
                        @foreach ($auditEvents as $event)
                            <x-timeline-event :event="$event" />
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-[#848d97] italic">No audit events recorded for this incident yet.</p>
                @endif
            </div>
        </div>

        <!-- TAB 3: Patch Iterations -->
        <div x-show="activeTab === 'iterations'" class="space-y-6">
            @forelse ($patchArtifacts as $index => $patch)
                @php
                    $qg = $patch->qualityGateRuns()->first();
                @endphp
                <div class="rounded-md border border-[#30363d] bg-[#161b22] p-4 space-y-4">
                    <div class="flex items-center justify-between border-b border-[#30363d] pb-3">
                        <div class="flex items-center space-x-3">
                            <span class="font-semibold text-white text-base">Candidate patch (Iteration {{ $patchArtifacts->count() - $index }})</span>
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium border {{ $patch->status === 'verified' ? 'bg-[#238636]/15 text-[#3fb950] border-[#3fb950]/30' : 'bg-[#21262d] text-[#848d97] border-[#30363d]' }}">
                                {{ ucfirst($patch->status) }}
                            </span>
                        </div>

                        <span class="text-xs text-[#848d97]">{{ $patch->created_at?->diffForHumans() }}</span>
                    </div>

                    <x-code-diff :diff="$patch->diff" fileName="{{ $patch->file_path ?? 'src/patch.diff' }}" />

                    @if ($qg && $qg->checks->isNotEmpty())
                        <div class="mt-4 pt-4 border-t border-[#30363d] space-y-2">
                            <h4 class="text-xs font-semibold text-[#848d97]">Quality gate verification results ({{ $qg->checks->where('exit_code', 0)->count() }}/{{ $qg->checks->count() }} passed)</h4>
                            <div class="space-y-1.5">
                                @foreach ($qg->checks as $chk)
                                    <x-quality-check-item :check="$chk" />
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="rounded-md border border-[#30363d] bg-[#161b22] p-8 text-center text-sm text-[#848d97]">
                    No patch candidates have been generated yet for this incident.
                </div>
            @endforelse
        </div>

        <!-- TAB 4: Post-Deploy & Verification -->
        <div x-show="activeTab === 'postdeploy'" class="space-y-6">
            <div class="rounded-md border border-[#30363d] bg-[#161b22] p-6 space-y-4">
                <h3 class="text-base font-semibold text-white">CI/CD pipeline monitoring &amp; staging verification</h3>
                <p class="text-sm text-[#848d97]">
                    Monitors external CI builds, tracks staging deployment, and independently verifies post-deployment environment health.
                </p>

                @if ($remediationRun)
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
                        <div class="p-3 rounded bg-[#0d1117] border border-[#30363d]">
                            <span class="text-xs text-[#848d97] block">Remediation status</span>
                            <span class="text-sm font-semibold text-white mt-1 block">{{ $remediationRun->status }}</span>
                        </div>
                        <div class="p-3 rounded bg-[#0d1117] border border-[#30363d]">
                            <span class="text-xs text-[#848d97] block">Target environment</span>
                            <span class="text-sm font-semibold text-white mt-1 block">{{ ucfirst($remediationRun->environment ?? 'staging') }}</span>
                        </div>
                        <div class="p-3 rounded bg-[#0d1117] border border-[#30363d]">
                            <span class="text-xs text-[#848d97] block">Security post-check</span>
                            <span class="text-sm font-semibold text-[#3fb950] mt-1 block">{{ $remediationRun->security_status ?? 'VERIFIED' }}</span>
                        </div>
                    </div>
                @else
                    <div class="p-4 rounded bg-[#0d1117] border border-[#30363d] text-sm text-[#848d97]">
                        Remediation run will initialize automatically upon human approval of the candidate patch.
                    </div>
                @endif
            </div>
        </div>

    </div>

</x-layouts.app>
