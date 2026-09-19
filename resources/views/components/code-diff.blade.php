@props([
    'diff' => '',
    'fileName' => null,
])

@php
    $lines = explode("\n", $diff ?? '');
    $additions = 0;
    $deletions = 0;
    foreach ($lines as $l) {
        if (str_starts_with($l, '+') && !str_starts_with($l, '+++')) $additions++;
        if (str_starts_with($l, '-') && !str_starts_with($l, '---')) $deletions++;
    }
@endphp

<div x-data="{ copied: false }" class="rounded-lg border border-[#1f1f1f] bg-[#0c0c0c] w-full overflow-hidden">
    <!-- Header Bar -->
    <div class="px-4 py-2.5 bg-[#121212] border-b border-[#1f1f1f] flex items-center justify-between text-xs">
        <div class="flex items-center space-x-3">
            <svg class="w-4 h-4 text-[#888888]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <span class="font-mono text-xs font-semibold text-white">{{ $fileName ?? 'patch.diff' }}</span>

            <!-- Diff Stats -->
            <div class="flex items-center space-x-1.5 font-mono text-xs pl-2">
                @if ($additions > 0)
                    <span class="text-[#00e599] font-semibold">+{{ $additions }}</span>
                @endif
                @if ($deletions > 0)
                    <span class="text-red-400 font-semibold">-{{ $deletions }}</span>
                @endif
            </div>
        </div>

        <button 
            type="button" 
            @click="navigator.clipboard.writeText({{ json_encode($diff) }}); copied = true; setTimeout(() => copied = false, 2000)" 
            class="px-2.5 py-1 rounded bg-[#181818] hover:bg-[#222222] border border-[#2a2a2a] text-[#cccccc] hover:text-white text-xs font-mono transition flex items-center space-x-1.5"
        >
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
            <span x-show="!copied">Copy diff</span>
            <span x-show="copied" x-cloak class="text-[#00e599]">Copied!</span>
        </button>
    </div>

    <!-- Monospaced Code Viewer -->
    <div class="overflow-x-auto max-h-[580px] font-mono text-xs leading-[20px] bg-[#0a0a0a]">
        <table class="w-full border-collapse">
            <tbody>
                @foreach ($lines as $index => $line)
                    @php
                        $isAddition = str_starts_with($line, '+') && !str_starts_with($line, '+++');
                        $isDeletion = str_starts_with($line, '-') && !str_starts_with($line, '---');
                        $isHunk = str_starts_with($line, '@@');
                        $isFileHeader = str_starts_with($line, '---') || str_starts_with($line, '+++') || str_starts_with($line, 'diff ');

                        if ($isAddition) {
                            $rowBg = 'bg-[#00e599]/10 text-[#00e599]';
                            $gutterBg = 'bg-[#00e599]/15 text-[#00e599] border-r border-[#00e599]/20';
                        } elseif ($isDeletion) {
                            $rowBg = 'bg-red-500/10 text-red-300';
                            $gutterBg = 'bg-red-500/15 text-red-400 border-r border-red-500/20';
                        } elseif ($isHunk) {
                            $rowBg = 'bg-[#141414] text-[#888888] font-semibold';
                            $gutterBg = 'bg-[#141414] text-[#666666] border-r border-[#222222]';
                        } elseif ($isFileHeader) {
                            $rowBg = 'bg-[#121212] text-[#888888]';
                            $gutterBg = 'bg-[#121212] text-[#555555] border-r border-[#222222]';
                        } else {
                            $rowBg = 'text-[#cccccc] hover:bg-[#121212]';
                            $gutterBg = 'bg-[#0c0c0c] text-[#555555] border-r border-[#1f1f1f]';
                        }
                    @endphp
                    <tr class="{{ $rowBg }}">
                        <!-- Line Number -->
                        <td class="w-12 px-2 text-right text-[11px] {{ $gutterBg }} select-none font-mono">
                            {{ $index + 1 }}
                        </td>
                        <!-- Code Line with exact indentation -->
                        <td class="px-3 whitespace-pre font-mono">
                            {{ $line === '' ? ' ' : $line }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
