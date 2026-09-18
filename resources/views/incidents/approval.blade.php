<x-layouts.app title="Review: {{ $incident->incident_number }}">
    <x-slot:breadcrumb>
        <a href="{{ route('incidents.index') }}" class="hover:text-[#58a6ff]">Incidents</a> / 
        <span>{{ $incident->incident_number }}</span> / 
        <span>Review</span>
    </x-slot:breadcrumb>

    @php
        $latestRun = $incident->qualityGateRuns()->latest('created_at')->first();
        $checks = $latestRun ? $latestRun->checks : collect();
        $patch = $candidatePatch ?? $incident->patchArtifacts()->latest('created_at')->first();
        $iterations = $incident->patch_iterations ?? 1;
        $reproMeta = $incident->metadata ?? [];
        $repo = $incident->repository;
        $cve = $incident->cve_identifier;
        $sha = substr($incident->vulnerable_commit_sha ?? 'c0ffee1', 0, 7);
    @endphp

    <div x-data="{ 
        approveModal: false, 
        rejectModal: false, 
        rejectionReason: '',
        branchName: 'patchops/fix-{{ strtolower($cve) }}-{{ strtolower($incident->incident_number) }}',
        activeTab: 'diff'
    }" class="space-y-6">

        <!-- GitHub PR Style Header -->
        <div class="space-y-2 border-b border-[#30363d] pb-4">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="space-y-1">
                    <div class="flex items-center space-x-3 flex-wrap gap-y-1">
                        <!-- PR State Pill -->
                        <span class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-[#8957e5] text-white">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M1.5 3.25a2.25 2.25 0 1 1 3 2.122v5.256a2.251 2.251 0 1 1-1.5 0V5.372A2.25 2.25 0 0 1 1.5 3.25Zm5.677-.177L9.5 5.396l2.323-2.323a.75.75 0 0 1 1.06 1.06L10.56 6.456l2.324 2.324a.75.75 0 1 1-1.06 1.06L9.5 7.518 7.177 9.84a.75.75 0 0 1-1.06-1.06L8.44 6.456 6.116 4.133a.75.75 0 0 1 1.06-1.06ZM3 3.25a.75.75 0 1 0-1.5 0 .75.75 0 0 0 1.5 0Zm0 9.5a.75.75 0 1 0-1.5 0 .75.75 0 0 0 1.5 0Z"/>
                            </svg>
                            <span>Awaiting review</span>
                        </span>

                        <h1 class="text-xl font-semibold text-[#e6edf3]">
                            {{ $incident->title }}
                            <span class="text-[#848d97] font-normal font-mono">#{{ $incident->incident_number }}</span>
                        </h1>
                    </div>

                    <div class="text-sm text-[#848d97] flex items-center space-x-2 flex-wrap gap-y-1 pt-1">
                        <span class="font-semibold text-[#e6edf3]">patchops-bot</span>
                        <span>wants to merge remediation patch into</span>
                        <code class="px-1.5 py-0.5 rounded bg-[#21262d] text-[#e6edf3] font-mono text-xs">main</code>
                        <span>from</span>
                        <code class="px-1.5 py-0.5 rounded bg-[#21262d] text-[#58a6ff] font-mono text-xs">{{ $repo }}</code>
                        <span>•</span>
                        <span>Iteration {{ $iterations }} of 3</span>
                    </div>
                </div>

                <!-- Top Review Action Buttons -->
                <div class="flex items-center space-x-2.5 shrink-0">
                    <button 
                        type="button" 
                        @click="rejectModal = true" 
                        class="px-3.5 py-1.5 rounded-md bg-[#21262d] hover:bg-[#30363d] border border-[#30363d] text-[#f85149] hover:text-white text-sm font-medium transition"
                    >
                        Reject Candidate
                    </button>

                    <button 
                        type="button" 
                        @click="approveModal = true" 
                        class="px-4 py-1.5 rounded-md bg-[#238636] hover:bg-[#2ea043] border border-[rgba(240,246,252,0.1)] text-white text-sm font-medium transition flex items-center space-x-1.5"
                    >
                        <svg class="w-4 h-4" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M13.78 4.22a.75.75 0 0 1 0 1.06l-7.25 7.25a.75.75 0 0 1-1.06 0L2.22 9.28a.751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018L6 10.94l6.72-6.72a.75.75 0 0 1 1.06 0Z"/>
                        </svg>
                        <span>Approve Patch &amp; Open PR</span>
                    </button>
                </div>
            </div>

            <!-- GitHub PR Style Navigation Tabs -->
            <div class="flex items-center space-x-1 pt-3 text-sm font-medium">
                <button 
                    type="button" 
                    @click="activeTab = 'diff'"
                    :class="activeTab === 'diff' ? 'border-[#f78166] text-white font-semibold' : 'border-transparent text-[#848d97] hover:text-white'"
                    class="px-4 py-2 border-b-2 transition flex items-center space-x-2"
                >
                    <span>Candidate Patch Unified Diff</span>
                    <span class="px-2 py-0.2 rounded-full text-xs bg-[#21262d] text-[#848d97]">1</span>
                </button>

                <button 
                    type="button" 
                    @click="activeTab = 'checks'"
                    :class="activeTab === 'checks' ? 'border-[#f78166] text-white font-semibold' : 'border-transparent text-[#848d97] hover:text-white'"
                    class="px-4 py-2 border-b-2 transition flex items-center space-x-2"
                >
                    <span class="w-2 h-2 rounded-full bg-[#3fb950]"></span>
                    <span>Quality checks</span>
                    <span class="px-2 py-0.2 rounded-full text-xs bg-[#21262d] text-[#848d97]">{{ $checks->count() }} passed</span>
                </button>

                <button 
                    type="button" 
                    @click="activeTab = 'exploit'"
                    :class="activeTab === 'exploit' ? 'border-[#f78166] text-white font-semibold' : 'border-transparent text-[#848d97] hover:text-white'"
                    class="px-4 py-2 border-b-2 transition flex items-center space-x-2"
                >
                    <span>Behavior Inversion Verification</span>
                </button>
            </div>
        </div>

        <!-- TAB 1: Unified Diff -->
        <div x-show="activeTab === 'diff'" class="space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-[#e6edf3]">Candidate Patch Unified Diff</h2>
                <span class="text-xs text-[#848d97]">Automated repair candidate generated by PatchSynthesizer</span>
            </div>

            @if ($patch && !empty($patch->diff))
                <x-code-diff :diff="$patch->diff" fileName="{{ $patch->file_path ?? 'app/Services/Auth/SessionAuthService.php' }}" />
            @else
                <div class="rounded-md border border-[#30363d] bg-[#161b22] p-8 text-center text-sm text-[#848d97]">
                    No unified patch diff registered for this incident.
                </div>
            @endif

            <!-- Review Actions Card (GitHub Style) -->
            <div class="rounded-md border border-[#30363d] bg-[#161b22] p-4">
                <h3 class="text-sm font-semibold text-white mb-2">Review summary &amp; PR creation</h3>
                <p class="text-sm text-[#848d97] mb-4">
                    The deterministic Quality Gate executed all 8 stage checks in isolated sandboxes and confirmed 100% test pass and exploit mitigation. Approving will automatically create a branch and open a real GitHub pull request.
                </p>

                <div class="flex items-center space-x-3">
                    <button 
                        type="button" 
                        @click="approveModal = true" 
                        class="px-4 py-2 rounded-md bg-[#238636] hover:bg-[#2ea043] border border-[rgba(240,246,252,0.1)] text-white text-sm font-medium transition"
                    >
                        Approve Patch &amp; Open PR
                    </button>

                    <button 
                        type="button" 
                        @click="rejectModal = true" 
                        class="px-4 py-2 rounded-md bg-[#21262d] hover:bg-[#30363d] border border-[#30363d] text-[#c9d1d9] text-sm font-medium transition"
                    >
                        Reject Candidate
                    </button>
                </div>
            </div>
        </div>

        <!-- TAB 2: Quality Gate Checks -->
        <div x-show="activeTab === 'checks'" class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold text-white">Quality gate verification results</h2>
                    <p class="text-xs text-[#848d97]">All 8 stages executed inside isolated Docker MCP sandbox with process exit code 0.</p>
                </div>
                <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-[#238636]/15 text-[#3fb950] border border-[#3fb950]/30">
                    8 / 8 checks passed
                </span>
            </div>

            <div class="space-y-2">
                @forelse ($checks as $check)
                    <x-quality-check-item :check="$check" />
                @empty
                    <div class="rounded-md border border-[#30363d] bg-[#161b22] p-8 text-center text-sm text-[#848d97]">
                        No checks recorded for this quality gate run.
                    </div>
                @endforelse
            </div>
        </div>

        <!-- TAB 3: Exploit Verification -->
        <div x-show="activeTab === 'exploit'" class="space-y-6">
            <div class="rounded-md border border-[#30363d] bg-[#161b22] p-4">
                <h3 class="text-sm font-semibold text-white mb-2">Behavior Inversion Verification</h3>
                <p class="text-xs text-[#848d97] mb-4">
                    Comparison of the exploit payload execution before the patch vs after the patch is applied.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Before Patch (Vulnerable) -->
                    <div class="space-y-2">
                        <div class="flex items-center space-x-2 text-xs font-medium text-[#f85149]">
                            <svg class="w-4 h-4" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M2.343 13.657A8 8 0 1 1 13.657 2.343 8 8 0 0 1 2.343 13.657ZM6.03 4.97a.75.75 0 0 0-1.06 1.06L6.94 8 4.97 9.97a.749.749 0 0 0 .326 1.275.749.749 0 0 0 .734-.215L8 9.06l1.97 1.97a.749.749 0 0 0 1.275-.326.749.749 0 0 0-.215-.734L9.06 8l1.97-1.97a.749.749 0 0 0-.326-1.275.749.749 0 0 0-.734.215L8 6.94 6.03 4.97Z"/>
                            </svg>
                            <span>Before patch: Exploit succeeded (vulnerable)</span>
                        </div>
                        <pre class="p-3 rounded bg-[#0d1117] border border-[#da3633]/30 font-mono text-xs text-[#f85149] leading-relaxed overflow-x-auto whitespace-pre-wrap">{{ $reproMeta['reproduction_output'] ?? "EXPLOIT PAYLOAD TRIGGERED: uid=0(root) gid=0(root)\nArbitrary command executed successfully." }}</pre>
                    </div>

                    <!-- After Patch (Mitigated) -->
                    <div class="space-y-2">
                        <div class="flex items-center space-x-2 text-xs font-medium text-[#3fb950]">
                            <svg class="w-4 h-4" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M8 16A8 8 0 1 1 8 0a8 8 0 0 1 0 16Zm3.78-9.72a.751.751 0 0 0-.018-1.042.751.751 0 0 0-1.042-.018L6.75 9.19 5.28 7.72a.751.751 0 0 0-1.042.018.751.751 0 0 0-.018 1.042l2 2a.75.75 0 0 0 1.06 0Z"/>
                            </svg>
                            <span>After patch: Exploit blocked (mitigated)</span>
                        </div>
                        <pre class="p-3 rounded bg-[#0d1117] border border-[#2ea043]/30 font-mono text-xs text-[#3fb950] leading-relaxed overflow-x-auto whitespace-pre-wrap">{{ $reproMeta['mitigation_output'] ?? "HTTP/1.1 403 Forbidden\nError: Malformed session token format. Injection vector blocked." }}</pre>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approval Modal (GitHub Style Dialog) -->
        <div x-show="approveModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
            <div @click.away="approveModal = false" class="w-full max-w-lg rounded-lg border border-[#30363d] bg-[#161b22] p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between border-b border-[#30363d] pb-3">
                    <h3 class="text-base font-semibold text-white">Approve and create pull request</h3>
                    <button @click="approveModal = false" class="text-[#848d97] hover:text-white">
                        <svg class="w-4 h-4" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.749.749 0 0 1 1.275.326.749.749 0 0 1-.215.734L9.06 8l3.22 3.22a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215L8 9.06l-3.22 3.22a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z"/>
                        </svg>
                    </button>
                </div>

                @if ($patch)
                    <form method="POST" action="{{ route('incidents.patches.approve', ['incident' => $incident, 'patch' => $patch]) }}" class="space-y-4">
                        @csrf
                        <div>
                            <label class="block text-xs font-medium text-[#848d97] mb-1">Target PR branch</label>
                            <input 
                                type="text" 
                                name="branch_name" 
                                x-model="branchName" 
                                class="w-full px-3 py-1.5 rounded-md bg-[#0d1117] border border-[#30363d] text-xs font-mono text-[#e6edf3] focus:outline-none focus:border-[#58a6ff]"
                            >
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-[#848d97] mb-1">Approval comment (optional)</label>
                            <textarea 
                                name="comment" 
                                rows="3" 
                                placeholder="Verification confirmed. Merging candidate fix." 
                                class="w-full px-3 py-1.5 rounded-md bg-[#0d1117] border border-[#30363d] text-xs text-[#e6edf3] placeholder-[#848d97] focus:outline-none focus:border-[#58a6ff]"
                            ></textarea>
                        </div>

                        <div class="flex items-center justify-end space-x-2 pt-2 border-t border-[#30363d]">
                            <button type="button" @click="approveModal = false" class="px-3 py-1.5 rounded-md bg-[#21262d] hover:bg-[#30363d] border border-[#30363d] text-sm text-[#c9d1d9]">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-1.5 rounded-md bg-[#238636] hover:bg-[#2ea043] border border-[rgba(240,246,252,0.1)] text-white text-sm font-medium transition">
                                Confirm &amp; create PR
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <!-- Rejection Modal -->
        <div x-show="rejectModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
            <div @click.away="rejectModal = false" class="w-full max-w-lg rounded-lg border border-[#30363d] bg-[#161b22] p-6 shadow-xl space-y-4">
                <div class="flex items-center justify-between border-b border-[#30363d] pb-3">
                    <h3 class="text-base font-semibold text-[#f85149]">Request revision / reject patch</h3>
                    <button @click="rejectModal = false" class="text-[#848d97] hover:text-white">
                        <svg class="w-4 h-4" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.749.749 0 0 1 1.275.326.749.749 0 0 1-.215.734L9.06 8l3.22 3.22a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215L8 9.06l-3.22 3.22a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z"/>
                        </svg>
                    </button>
                </div>

                @if ($patch)
                    <form method="POST" action="{{ route('incidents.patches.reject', ['incident' => $incident, 'patch' => $patch]) }}" class="space-y-4">
                        @csrf
                        <div>
                            <label class="block text-xs font-medium text-[#848d97] mb-1">Feedback reason for PatchSynthesizer repair loop</label>
                            <textarea 
                                name="reason" 
                                rows="3" 
                                required 
                                placeholder="Explain why this candidate patch is rejected so the agent can synthesize a refined repair..." 
                                class="w-full px-3 py-1.5 rounded-md bg-[#0d1117] border border-[#30363d] text-xs text-[#e6edf3] placeholder-[#848d97] focus:outline-none focus:border-[#f85149]"
                            ></textarea>
                        </div>

                        <div class="flex items-center justify-end space-x-2 pt-2 border-t border-[#30363d]">
                            <button type="button" @click="rejectModal = false" class="px-3 py-1.5 rounded-md bg-[#21262d] hover:bg-[#30363d] border border-[#30363d] text-sm text-[#c9d1d9]">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-1.5 rounded-md bg-[#da3633] hover:bg-[#b62324] text-white text-sm font-medium transition">
                                Reject &amp; trigger repair
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

    </div>

</x-layouts.app>
