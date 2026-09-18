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

<div x-data="{ copied: false }" class="rounded-md border border-[#30363d] bg-[#0d1117] overflow-hidden">
    <!-- GitHub File Bar -->
    <div class="px-4 py-2 bg-[#161b22] border-b border-[#30363d] flex items-center justify-between text-xs text-[#848d97]">
        <div class="flex items-center space-x-2.5">
            <!-- File icon -->
            <svg class="w-4 h-4 text-[#848d97]" viewBox="0 0 16 16" fill="currentColor">
                <path d="M2 1.75C2 .784 2.784 0 3.75 0h6.586c.464 0 .909.184 1.237.513l2.914 2.914c.329.328.513.773.513 1.237v9.586A1.75 1.75 0 0 1 13.25 16h-9.5A1.75 1.75 0 0 1 2 14.25Zm1.75-.25a.25.25 0 0 0-.25.25v12.5c0 .138.112.25.25.25h9.5a.25.25 0 0 0 .25-.25V6h-2.75A1.75 1.75 0 0 1 9 4.25V1.5Zm6.75.062V4.25c0 .138.112.25.25.25h2.688l-.011-.013-2.914-2.914-.013-.011Z"/>
            </svg>
            <span class="font-mono text-sm font-semibold text-[#e6edf3]">{{ $fileName ?? 'patch.diff' }}</span>

            <!-- Diff Stats: +4 -2 -->
            <div class="flex items-center space-x-1 pl-2 font-mono text-xs">
                @if ($additions > 0)
                    <span class="text-[#3fb950] font-semibold">+{{ $additions }}</span>
                @endif
                @if ($deletions > 0)
                    <span class="text-[#f85149] font-semibold">-{{ $deletions }}</span>
                @endif
            </div>
        </div>

        <button 
            type="button" 
            @click="navigator.clipboard.writeText({{ json_encode($diff) }}); copied = true; setTimeout(() => copied = false, 2000)" 
            class="px-2.5 py-1 rounded bg-[#21262d] hover:bg-[#30363d] border border-[#30363d] text-[#c9d1d9] hover:text-white text-xs font-medium transition flex items-center space-x-1.5"
        >
            <svg class="w-3.5 h-3.5 text-[#848d97]" viewBox="0 0 16 16" fill="currentColor">
                <path d="M0 6.75C0 5.784.784 5 1.75 5h1.5a.75.75 0 0 1 0 1.5h-1.5a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-1.5a.75.75 0 0 1 1.5 0v1.5A1.75 1.75 0 0 1 9.25 16h-7.5A1.75 1.75 0 0 1 0 14.25Z"/>
                <path d="M5 1.75C5 .784 5.784 0 6.75 0h7.5C15.216 0 16 .784 16 1.75v7.5A1.75 1.75 0 0 1 14.25 11h-7.5A1.75 1.75 0 0 1 5 9.25Zm1.75-.25a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-7.5a.25.25 0 0 0-.25-.25Z"/>
            </svg>
            <span x-show="!copied">Copy</span>
            <span x-show="copied" x-cloak class="text-[#3fb950]">Copied!</span>
        </button>
    </div>

    <!-- Monospaced Code Viewer (GitHub Diff Table) -->
    <div class="overflow-x-auto max-h-[580px] font-mono text-xs leading-[20px]">
        <table class="w-full border-collapse">
            <tbody>
                @foreach ($lines as $index => $line)
                    @php
                        $isAddition = str_starts_with($line, '+') && !str_starts_with($line, '+++');
                        $isDeletion = str_starts_with($line, '-') && !str_starts_with($line, '---');
                        $isHunk = str_starts_with($line, '@@');
                        $isFileHeader = str_starts_with($line, '---') || str_starts_with($line, '+++') || str_starts_with($line, 'diff ');

                        if ($isAddition) {
                            $rowBg = 'bg-[#1f3526]/50 text-[#e6edf3]';
                            $gutterBg = 'bg-[#1f3526] text-[#3fb950] border-r border-[#2ea043]/30 select-none';
                        } elseif ($isDeletion) {
                            $rowBg = 'bg-[#3a1d1d]/50 text-[#e6edf3]';
                            $gutterBg = 'bg-[#3a1d1d] text-[#f85149] border-r border-[#da3633]/30 select-none';
                        } elseif ($isHunk) {
                            $rowBg = 'bg-[#161b22] text-[#848d97] font-medium';
                            $gutterBg = 'bg-[#161b22] text-[#848d97] border-r border-[#30363d] select-none';
                        } elseif ($isFileHeader) {
                            $rowBg = 'bg-[#161b22] text-[#848d97] font-medium';
                            $gutterBg = 'bg-[#161b22] text-[#6e7681] border-r border-[#30363d] select-none';
                        } else {
                            $rowBg = 'text-[#e6edf3] hover:bg-[#161b22]/70';
                            $gutterBg = 'bg-[#0d1117] text-[#6e7681] border-r border-[#30363d] select-none';
                        }
                    @endphp
                    <tr class="{{ $rowBg }}">
                        <!-- Line Number -->
                        <td class="w-12 px-2 text-right text-[11px] {{ $gutterBg }}">
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
