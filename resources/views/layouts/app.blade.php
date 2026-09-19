<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Security Incidents' }} — PatchOps</title>
    <link rel="icon" type="image/png" href="{{ asset('logo.png') }}">

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <!-- Tailwind CSS (Neon Dark Palette: Black/Charcoal + Neon Mint Accent) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            DEFAULT: '#00e599',
                            hover: '#00c784',
                            glow: 'rgba(0, 229, 153, 0.15)',
                            subtle: 'rgba(0, 229, 153, 0.08)',
                        },
                        dark: {
                            bg: '#080808',
                            surface: '#0f0f0f',
                            card: '#141414',
                            hover: '#1a1a1a',
                            border: '#222222',
                            borderLight: '#2e2e2e',
                            text: '#f3f4f6',
                            muted: '#8e8e93',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
                        mono: ['JetBrains Mono', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas', 'monospace'],
                    }
                }
            }
        }
    </script>

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>

    <style>
        [x-cloak] { display: none !important; }
        body {
            background-color: #080808;
            color: #f3f4f6;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #080808;
        }
        ::-webkit-scrollbar-thumb {
            background: #262626;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #3a3a3a;
        }
    </style>
</head>
<body class="min-h-screen bg-[#080808] text-[#f3f4f6] flex flex-row overflow-x-hidden selection:bg-[#00e599] selection:text-black">

    <!-- Left Sidebar (Full Height, Developer Console Style like Neon) -->
    <aside class="w-64 shrink-0 bg-[#0c0c0c] border-r border-[#1e1e1e] flex flex-col justify-between min-h-screen sticky top-0 h-screen z-40 hidden md:flex">
        <div class="flex flex-col flex-1">
            <!-- Brand Logo -->
            <div class="h-14 px-5 flex items-center space-x-3 border-b border-[#1a1a1a]">
                <a href="{{ route('incidents.index') }}" class="flex items-center space-x-3">
                    <img src="{{ asset('logo.png') }}" alt="PatchOps Logo" class="w-8 h-8 rounded object-contain shrink-0">
                    <div class="flex flex-col">
                        <span class="font-bold text-sm tracking-wider text-white flex items-center gap-1.5">
                            PATCHOPS
                            <span class="text-[10px] font-mono px-1.5 py-0.2 rounded bg-[#00e599]/10 text-[#00e599] border border-[#00e599]/20 font-medium">DEVSECOPS</span>
                        </span>
                    </div>
                </a>
            </div>

            <!-- Organization Label -->
            <div class="px-5 pt-5 pb-2">
                <span class="text-[10px] font-semibold tracking-wider text-[#666666] uppercase">Workspace</span>
                <div class="mt-1 flex items-center justify-between text-xs font-medium text-white px-2.5 py-1.5 rounded bg-[#141414] border border-[#222222]">
                    <span class="truncate">Production Incidents</span>
                    <span class="w-2 h-2 rounded-full bg-[#00e599]"></span>
                </div>
            </div>

            <!-- Navigation Links -->
            <nav class="px-3 py-3 space-y-1 text-sm font-medium">
                <a href="{{ route('incidents.index') }}" class="flex items-center justify-between px-3 py-2 rounded-md transition {{ request()->routeIs('incidents.index') && !request()->has('status') ? 'bg-[#181818] text-white border border-[#282828] shadow-sm' : 'text-[#8e8e93] hover:text-white hover:bg-[#141414]' }}">
                    <div class="flex items-center space-x-3">
                        <svg class="w-4 h-4 {{ request()->routeIs('incidents.index') && !request()->has('status') ? 'text-[#00e599]' : 'text-[#666666]' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span>Incidents</span>
                    </div>
                    <span class="text-xs font-mono text-[#666666]">{{ \App\Models\Incident::count() }}</span>
                </a>

                <a href="{{ route('incidents.index', ['status' => 'awaiting_approval']) }}" class="flex items-center justify-between px-3 py-2 rounded-md transition {{ request()->query('status') === 'awaiting_approval' ? 'bg-[#181818] text-white border border-[#282828]' : 'text-[#8e8e93] hover:text-white hover:bg-[#141414]' }}">
                    <div class="flex items-center space-x-3">
                        <svg class="w-4 h-4 {{ request()->query('status') === 'awaiting_approval' ? 'text-[#00e599]' : 'text-[#666666]' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>Reviews</span>
                    </div>
                    @php
                        $pendingReviews = \App\Models\Incident::where('status', 'awaiting_approval')->count();
                    @endphp
                    @if ($pendingReviews > 0)
                        <span class="px-2 py-0.5 rounded-full text-xs font-mono font-semibold bg-[#00e599]/15 text-[#00e599] border border-[#00e599]/30">{{ $pendingReviews }}</span>
                    @endif
                </a>

                <a href="/api/v1/metrics" target="_blank" class="flex items-center justify-between px-3 py-2 rounded-md text-[#8e8e93] hover:text-white hover:bg-[#141414] transition">
                    <div class="flex items-center space-x-3">
                        <svg class="w-4 h-4 text-[#666666]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                        </svg>
                        <span>API Metrics</span>
                    </div>
                    <svg class="w-3.5 h-3.5 text-[#555555]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                </a>
            </nav>
        </div>

        <!-- Sidebar Bottom Footer -->
        <div class="p-4 border-t border-[#1a1a1a] space-y-3">
            <div class="flex items-center justify-between text-xs text-[#8e8e93]">
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-2 rounded-full bg-[#00e599] animate-pulse"></span>
                    <span>System Online</span>
                </div>
                <span class="font-mono text-[10px] text-[#555555]">{{ config('app.env', 'prod') }}</span>
            </div>

            <!-- GitHub Connection Status -->
            <div class="flex items-center justify-between text-[11px] font-mono px-2.5 py-1.5 rounded bg-[#121212] border border-[#1f1f1f]">
                <div class="flex items-center space-x-1.5 truncate">
                    <svg class="w-3.5 h-3.5 text-[#00e599] shrink-0" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M8 0c4.42 0 8 3.58 8 8a8.013 8.013 0 0 1-5.45 7.59c-.4.08-.55-.17-.55-.38 0-.27.01-1.13.01-2.2 0-.75-.25-1.23-.54-1.48 1.78-.2 3.65-.88 3.65-3.95 0-.88-.31-1.59-.82-2.15.08-.2.36-1.02-.08-2.12 0 0-.67-.22-2.2.82-.64-.18-1.32-.27-2-.27-.68 0-1.36.09-2 .27-1.53-1.03-2.2-.82-2.2-.82-.44 1.1-.16 1.92-.08 2.12-.51.56-.82 1.28-.82 2.15 0 3.06 1.86 3.75 3.64 3.95-.23.2-.44.55-.51 1.07-.46.21-1.61.55-2.33-.66-.15-.24-.6-.83-1.23-.82-.67.01-.27.38.01.53.34.19.73.9.82 1.13.16.45.68 1.31 2.69.94 0 .67.01 1.3.01 1.49 0 .21-.15.45-.55.38A7.995 7.995 0 0 1 0 8c0-4.42 3.58-8 8-8Z"/>
                    </svg>
                    <span class="truncate text-[#cccccc]">{{ config('services.github.repository', 'GitHub') }}</span>
                </div>
                @if (config('services.github.token'))
                    <span class="w-1.5 h-1.5 rounded-full bg-[#00e599] shrink-0 shadow-[0_0_6px_rgba(0,229,153,0.6)]" title="GitHub Connected"></span>
                @else
                    <span class="text-[9px] text-amber-400 font-bold shrink-0" title="Token missing in .env">NO TOKEN</span>
                @endif
            </div>

            <div class="flex items-center space-x-3 px-2.5 py-2 rounded bg-[#121212] border border-[#1f1f1f]">
                <div class="w-6 h-6 rounded bg-[#1e1e1e] border border-[#2a2a2a] text-[#00e599] flex items-center justify-center font-bold text-xs">
                    S
                </div>
                <div class="flex flex-col min-w-0">
                    <span class="text-xs font-semibold text-white truncate">secops</span>
                    <span class="text-[10px] text-[#666666] truncate">SecOps Lead</span>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content Layout (Full Width Canvas) -->
    <div class="flex-1 flex flex-col min-w-0 min-h-screen bg-[#080808]">

        <!-- Top Navigation Header -->
        <header class="h-14 bg-[#0c0c0c]/80 backdrop-blur border-b border-[#1e1e1e] px-6 flex items-center justify-between sticky top-0 z-30 w-full">
            <!-- Left: Breadcrumb / Mobile toggle with Logo -->
            <div class="flex items-center space-x-3">
                <a href="{{ route('incidents.index') }}" class="flex items-center space-x-2 text-xs font-mono text-[#8e8e93] hover:text-white transition">
                    <img src="{{ asset('logo.png') }}" alt="PatchOps" class="w-5 h-5 object-contain rounded">
                    <span class="font-semibold text-white">PatchOps</span>
                </a>
                <span class="text-xs text-[#444444]">/</span>
                <span class="text-xs font-medium text-white">
                    {{ $breadcrumb ?? 'Incidents' }}
                </span>
            </div>

            <!-- Right: Live Active Count & Actions -->
            <div class="flex items-center space-x-3">
                <div class="flex items-center space-x-2 px-3 py-1 rounded-full bg-[#141414] border border-[#222222] text-xs">
                    <span class="w-1.5 h-1.5 rounded-full bg-[#00e599]"></span>
                    <span class="text-[#8e8e93]">Active:</span>
                    <span class="font-mono font-semibold text-white">{{ $globalActiveCount ?? \App\Models\Incident::whereNotIn('status', ['remediated', 'resolved', 'closed', 'failed'])->count() }}</span>
                </div>

                <a href="{{ route('incidents.index', ['status' => 'awaiting_approval']) }}" class="hidden sm:inline-flex items-center space-x-1.5 px-3 py-1 rounded bg-[#00e599] hover:bg-[#00c784] text-black font-semibold text-xs transition shadow-[0_0_10px_rgba(0,229,153,0.2)]">
                    <span>Review Queue</span>
                    <span>&rarr;</span>
                </a>
            </div>
        </header>

        <!-- Flash Notices -->
        @if (session('success'))
            <div class="bg-[#00e599]/10 border-b border-[#00e599]/30 text-[#00e599] px-6 py-2.5 text-xs font-mono flex items-center justify-between w-full">
                <div class="flex items-center space-x-2">
                    <svg class="w-4 h-4 text-[#00e599]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>{{ session('success') }}</span>
                </div>
            </div>
        @endif

        @if (session('error') || $errors->any())
            <div class="bg-red-500/10 border-b border-red-500/30 text-red-400 px-6 py-2.5 text-xs font-mono flex items-center justify-between w-full">
                <div class="flex items-center space-x-2">
                    <svg class="w-4 h-4 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span>{{ session('error') ?? $errors->first() }}</span>
                </div>
            </div>
        @endif

        <!-- Main Full Width Canvas -->
        <main class="flex-1 w-full px-6 lg:px-8 py-6">
            {{ $slot }}
        </main>

        <!-- Minimalist Footer -->
        <footer class="border-t border-[#1a1a1a] py-4 px-6 lg:px-8 text-xs text-[#666666] flex flex-col sm:flex-row items-center justify-between gap-3 w-full bg-[#080808]">
            <div class="flex items-center space-x-2.5">
                <img src="{{ asset('logo.png') }}" alt="PatchOps Logo" class="w-4 h-4 object-contain rounded">
                <span class="font-medium text-[#888888]">PatchOps Autonomous DevSecOps</span>
            </div>
            <div class="flex items-center space-x-4">
                <a href="{{ route('incidents.index') }}" class="hover:text-white transition">Incidents</a>
                <a href="/api/v1/metrics" target="_blank" class="hover:text-white transition">Metrics</a>
                <span class="text-[#444444]">•</span>
                <span>Protected by QualityGate Engine</span>
            </div>
        </footer>

    </div>

</body>
</html>
