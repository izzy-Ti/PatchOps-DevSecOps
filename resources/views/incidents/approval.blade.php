<x-layouts.app title="Review: {{ $incident->incident_number }}">
    <x-slot:breadcrumb>
        <a href="{{ route('incidents.index') }}" class="hover:text-[#00e599] transition">Incidents</a> / 
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
        $sha = $incident->vulnerable_commit_sha ? substr($incident->vulnerable_commit_sha, 0, 7) : '—';
    @endphp

    <div x-data="{ 
        approveModal: false, 
        rejectModal: false, 
        rejectionReason: '',
        branchName: 'patchops/fix-{{ strtolower($cve) }}-{{ strtolower($incident->incident_number) }}',
        activeTab: 'diff'
    }" class="space-y-6 w-full">

        <!-- Header -->
        <div class="space-y-3 border-b border-[#1e1e1e] pb-5">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="space-y-1">
                    <div class="flex items-center space-x-3 flex-wrap gap-y-1">
                        <span class="inline-flex items-center space-x-1.5 px-2.5 py-0.5 rounded-full text-xs font-mono font-semibold bg-amber-400/15 text-amber-300 border border-amber-400/30">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                            <span>Awaiting Review</span>
                        </span>

                        <h1 class="text-xl font-bold text-white">
                            {{ $incident->title }}
                            <span class="text-[#777777] font-normal font-mono">#{{ $incident->incident_number }}</span>
                        </h1>
                    </div>

                    <div class="text-xs font-mono text-[#8e8e93] flex items-center space-x-2 flex-wrap gap-y-1 pt-1">
                        <span class="font-semibold text-white">patchops-bot</span>
                        <span>wants to merge remediation patch into</span>
                        <code class="px-1.5 py-0.5 rounded bg-[#161616] text-white border border-[#262626]">{{ $incident->base_branch ?? 'main' }}</code>
                        <span>from</span>
                        <code class="px-1.5 py-0.5 rounded bg-[#161616] text-[#00e599] border border-[#262626]">{{ $repo }}</code>
                        <span>•</span>
                        <span>Iteration {{ $iterations }} of 3</span>
                    </div>
                </div>

                <!-- Top Review Action Buttons -->
                <div class="flex items-center space-x-2.5 shrink-0">
                    <button 
                        type="button" 
                        @click="rejectModal = true" 
                        class="px-4 py-1.5 rounded-md bg-[#161616] hover:bg-[#202020] border border-[#2a2a2a] text-red-400 hover:text-red-300 text-xs font-semibold transition"
                    >
                        Reject Candidate
                    </button>

                    <button 
                        type="button" 
                        @click="approveModal = true" 
                        class="px-4 py-1.5 rounded-md bg-[#00e599] hover:bg-[#00c784] text-black text-xs font-semibold transition flex items-center space-x-1.5 shadow-[0_0_12px_rgba(0,229,153,0.3)]"
                    >
                        <svg class="w-3.5 h-3.5" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M13.78 4.22a.75.75 0 0 1 0 1.06l-7.25 7.25a.75.75 0 0 1-1.06 0L2.22 9.28a.751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018L6 10.94l6.72-6.72a.75.75 0 0 1 1.06 0Z"/>
                        </svg>
                        <span>Approve Patch &amp; Open PR</span>
                    </button>
                </div>
            </div>

            <!-- Navigation Tabs -->
            <div class="flex items-center space-x-2 pt-3 text-xs font-medium border-b border-[#1e1e1e] pb-px">
                <button 
                    type="button" 
                    @click="activeTab = 'diff'"
                    :class="activeTab === 'diff' ? 'border-[#00e599] text-white font-semibold' : 'border-transparent text-[#777777] hover:text-white'"
                    class="px-4 py-2 border-b-2 transition flex items-center space-x-2"
                >
                    <span>Candidate Patch Unified Diff</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono bg-[#181818] text-[#8e8e93]">1</span>
                </button>

                <button 
                    type="button" 
                    @click="activeTab = 'checks'"
                    :class="activeTab === 'checks' ? 'border-[#00e599] text-white font-semibold' : 'border-transparent text-[#777777] hover:text-white'"
                    class="px-4 py-2 border-b-2 transition flex items-center space-x-2"
                >
                    <span class="w-1.5 h-1.5 rounded-full bg-[#00e599]"></span>
                    <span>Quality checks</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono bg-[#181818] text-[#8e8e93]">{{ $checks->where('exit_code', 0)->count() }} passed</span>
                </button>

                <button 
                    type="button" 
                    @click="activeTab = 'exploit'"
                    :class="activeTab === 'exploit' ? 'border-[#00e599] text-white font-semibold' : 'border-transparent text-[#777777] hover:text-white'"
                    class="px-4 py-2 border-b-2 transition"
                >
                    <span>Behavior Inversion Verification</span>
                </button>
            </div>
        </div>

        <!-- TAB 1: Unified Diff -->
        <div x-show="activeTab === 'diff'" class="space-y-6">
            <div class="flex items-center justify-between text-xs text-[#8e8e93]">
                <h2 class="text-sm font-semibold text-white">Candidate Patch Unified Diff</h2>
                <span class="font-mono">Automated repair candidate generated by PatchSynthesizer</span>
            </div>

            @if ($patch && !empty($patch->diff))
                <x-code-diff :diff="$patch->diff" fileName="{{ $patch->file_path ?? 'patch.diff' }}" />
            @else
                <div class="rounded-lg border border-[#1e1e1e] bg-[#0c0c0c] p-8 text-center text-xs text-[#777777]">
                    No unified patch diff registered for this incident.
                </div>
            @endif

            <!-- Review Actions Card -->
            <div class="rounded-lg border border-[#1e1e1e] bg-[#0c0c0c] p-4 space-y-3">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-[#888888]">Review summary &amp; PR creation</h3>
                <p class="text-xs text-[#cccccc] leading-relaxed">
                    The deterministic Quality Gate executed all stage checks in isolated sandboxes and verified the candidate patch. Approving will automatically create a branch and open a real GitHub pull request.
                </p>

                <div class="flex items-center space-x-3 pt-2">
                    <button 
                        type="button" 
                        @click="approveModal = true" 
                        class="px-4 py-2 rounded-md bg-[#00e599] hover:bg-[#00c784] text-black text-xs font-semibold transition"
                    >
                        Approve Patch &amp; Open PR
                    </button>

                    <button 
                        type="button" 
                        @click="rejectModal = true" 
                        class="px-4 py-2 rounded-md bg-[#161616] hover:bg-[#202020] border border-[#2a2a2a] text-[#cccccc] text-xs font-medium transition"
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
                    <h2 class="text-sm font-semibold text-white">Quality gate verification results</h2>
                    <p class="text-xs text-[#777777]">Executed inside isolated Docker MCP sandbox with process exit code 0.</p>
                </div>
                <span class="px-2.5 py-1 rounded-full text-xs font-mono font-semibold bg-[#00e599]/15 text-[#00e599] border border-[#00e599]/30">
                    {{ $checks->where('exit_code', 0)->count() }} / {{ $checks->count() }} checks passed
                </span>
            </div>

            <div class="space-y-2">
                @forelse ($checks as $check)
                    <x-quality-check-item :check="$check" />
                @empty
                    <div class="rounded-lg border border-[#1e1e1e] bg-[#0c0c0c] p-8 text-center text-xs text-[#777777]">
                        No checks recorded for this quality gate run.
                    </div>
                @endforelse
            </div>
        </div>

        <!-- TAB 3: Exploit Verification -->
        <div x-show="activeTab === 'exploit'" class="space-y-6">
            <div class="rounded-lg border border-[#1e1e1e] bg-[#0c0c0c] p-4 space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-[#888888]">Behavior Inversion Verification</h3>
                <p class="text-xs text-[#8e8e93]">
                    Comparison of the exploit payload execution before the patch vs after the patch is applied.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Before Patch -->
                    <div class="space-y-2">
                        <div class="flex items-center space-x-1.5 text-xs font-semibold text-red-400 font-mono">
                            <span class="w-2 h-2 rounded-full bg-red-400"></span>
                            <span>BEFORE PATCH (Vulnerable)</span>
                        </div>
                        <pre class="p-3 rounded-md bg-[#101010] border border-red-500/30 font-mono text-xs text-red-300 leading-relaxed overflow-x-auto whitespace-pre-wrap">{{ $reproMeta['reproduction_output'] ?? 'No reproduction output recorded.' }}</pre>
                    </div>

                    <!-- After Patch -->
                    <div class="space-y-2">
                        <div class="flex items-center space-x-1.5 text-xs font-semibold text-[#00e599] font-mono">
                            <span class="w-2 h-2 rounded-full bg-[#00e599]"></span>
                            <span>AFTER PATCH (Mitigated)</span>
                        </div>
                        <pre class="p-3 rounded-md bg-[#101010] border border-[#00e599]/30 font-mono text-xs text-[#00e599] leading-relaxed overflow-x-auto whitespace-pre-wrap">{{ $reproMeta['mitigation_output'] ?? 'No mitigation output recorded.' }}</pre>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approval Modal -->
        <div x-show="approveModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div @click.away="approveModal = false" class="w-full max-w-lg rounded-xl border border-[#262626] bg-[#0f0f0f] p-6 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-[#1e1e1e] pb-3">
                    <h3 class="text-sm font-semibold text-white">Approve and create pull request</h3>
                    <button @click="approveModal = false" class="text-[#666666] hover:text-white transition">
                        <svg class="w-4 h-4" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.749.749 0 0 1 1.275.326.749.749 0 0 1-.215.734L9.06 8l3.22 3.22a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215L8 9.06l-3.22 3.22a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z"/>
                        </svg>
                    </button>
                </div>

                @if ($patch)
                    <form method="POST" action="{{ route('incidents.patches.approve', ['incident' => $incident, 'patch' => $patch]) }}" class="space-y-4">
                        @csrf
                        <div>
                            <label class="block text-xs font-medium text-[#8e8e93] mb-1">Target PR branch</label>
                            <input 
                                type="text" 
                                name="branch_name" 
                                x-model="branchName" 
                                class="w-full px-3 py-1.5 rounded-md bg-[#161616] border border-[#2a2a2a] text-xs font-mono text-white focus:outline-none focus:border-[#00e599]"
                            >
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-[#8e8e93] mb-1">Approval comment (optional)</label>
                            <textarea 
                                name="comment" 
                                rows="3" 
                                placeholder="Verification confirmed. Merging candidate fix." 
                                class="w-full px-3 py-1.5 rounded-md bg-[#161616] border border-[#2a2a2a] text-xs text-white placeholder-[#666666] focus:outline-none focus:border-[#00e599]"
                            ></textarea>
                        </div>

                        <div class="flex items-center justify-end space-x-2 pt-2 border-t border-[#1e1e1e]">
                            <button type="button" @click="approveModal = false" class="px-3.5 py-1.5 rounded-md bg-[#1a1a1a] hover:bg-[#222222] border border-[#2a2a2a] text-xs text-[#cccccc]">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-1.5 rounded-md bg-[#00e599] hover:bg-[#00c784] text-black text-xs font-semibold transition">
                                Confirm &amp; create PR
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <!-- Rejection Modal -->
        <div x-show="rejectModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div @click.away="rejectModal = false" class="w-full max-w-lg rounded-xl border border-[#262626] bg-[#0f0f0f] p-6 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-[#1e1e1e] pb-3">
                    <h3 class="text-sm font-semibold text-red-400">Request revision / reject patch</h3>
                    <button @click="rejectModal = false" class="text-[#666666] hover:text-white transition">
                        <svg class="w-4 h-4" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.749.749 0 0 1 1.275.326.749.749 0 0 1-.215.734L9.06 8l3.22 3.22a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215L8 9.06l-3.22 3.22a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z"/>
                        </svg>
                    </button>
                </div>

                @if ($patch)
                    <form method="POST" action="{{ route('incidents.patches.reject', ['incident' => $incident, 'patch' => $patch]) }}" class="space-y-4">
                        @csrf
                        <div>
                            <label class="block text-xs font-medium text-[#8e8e93] mb-1">Feedback reason for PatchSynthesizer repair loop</label>
                            <textarea 
                                name="reason" 
                                rows="3" 
                                required 
                                placeholder="Explain why this candidate patch is rejected so the agent can synthesize a refined repair..." 
                                class="w-full px-3 py-1.5 rounded-md bg-[#161616] border border-[#2a2a2a] text-xs text-white placeholder-[#666666] focus:outline-none focus:border-red-500"
                            ></textarea>
                        </div>

                        <div class="flex items-center justify-end space-x-2 pt-2 border-t border-[#1e1e1e]">
                            <button type="button" @click="rejectModal = false" class="px-3.5 py-1.5 rounded-md bg-[#1a1a1a] hover:bg-[#222222] border border-[#2a2a2a] text-xs text-[#cccccc]">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-1.5 rounded-md bg-red-600 hover:bg-red-500 text-white text-xs font-semibold transition">
                                Reject &amp; trigger repair
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

    </div>

</x-layouts.app>
