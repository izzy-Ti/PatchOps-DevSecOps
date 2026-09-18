<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Security Incidents' }} — PatchOps</title>

    <!-- Tailwind CSS (v3 CDN with GitHub Dark color palette) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        gh: {
                            canvas: '#0d1117',
                            surface: '#161b22',
                            subtle: '#010409',
                            overlay: '#1c2128',
                            border: '#30363d',
                            borderSubtle: '#21262d',
                            text: '#e6edf3',
                            muted: '#848d97',
                            blue: '#2f81f7',
                            blueHover: '#58a6ff',
                            green: '#238636',
                            greenHover: '#2ea043',
                            greenText: '#3fb950',
                            red: '#da3633',
                            redText: '#f85149',
                            purple: '#8957e5',
                            purpleText: '#d2a8ff',
                            amber: '#d29922',
                            amberText: '#e3b341',
                            btnBg: '#21262d',
                            btnHover: '#30363d',
                        }
                    },
                    fontFamily: {
                        sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', '"Noto Sans"', 'Helvetica', 'Arial', 'sans-serif'],
                        mono: ['ui-monospace', 'SFMono-Regular', '"SF Mono"', 'Menlo', 'Consolas', '"Liberation Mono"', 'monospace'],
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
            background-color: #0d1117;
            color: #e6edf3;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans", Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        /* Clean, subtle scrollbars */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #0d1117;
        }
        ::-webkit-scrollbar-thumb {
            background: #30363d;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #484f58;
        }
    </style>
</head>
<body class="min-h-screen bg-[#0d1117] text-[#e6edf3] flex flex-col selection:bg-[#264f78] selection:text-white">

    <!-- GitHub-Style Header -->
    <header class="sticky top-0 z-50 bg-[#161b22] border-b border-[#30363d]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-14">
                
                <!-- Left: Logo, Name & Repository Breadcrumb -->
                <div class="flex items-center space-x-3">
                    <a href="{{ route('incidents.index') }}" class="flex items-center space-x-2.5 text-[#e6edf3] hover:text-[#58a6ff] transition">
                        <!-- GitHub-Style Shield Icon -->
                        <svg class="w-6 h-6 text-[#e6edf3]" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M7.467.133a1.748 1.748 0 0 1 1.066 0l5.25 1.68A1.75 1.75 0 0 1 15 3.48V7c0 1.566-.32 3.182-1.303 4.682-.983 1.498-2.585 2.813-5.032 3.855a1.697 1.697 0 0 1-1.33 0c-2.447-1.042-4.049-2.357-5.032-3.855C1.32 10.182 1 8.566 1 7V3.48a1.75 1.75 0 0 1 1.217-1.667l5.25-1.68ZM8 1.533 2.75 3.213a.25.25 0 0 0-.174.238V7c0 1.34.26 2.682 1.056 3.896.794 1.212 2.11 2.324 4.368 3.303V1.533Z" />
                        </svg>
                        <span class="font-semibold text-base tracking-tight text-white">PatchOps</span>
                    </a>

                    <span class="text-[#848d97] text-sm">/</span>

                    <div class="flex items-center space-x-2 text-sm text-[#848d97]">
                        <a href="{{ route('incidents.index') }}" class="hover:text-[#58a6ff] text-[#e6edf3] font-medium transition">
                            {{ $breadcrumb ?? 'Incidents' }}
                        </a>
                    </div>
                </div>

                <!-- Center Navigation Tabs -->
                <nav class="hidden md:flex items-center space-x-1 text-sm font-medium text-[#848d97]">
                    <a href="{{ route('incidents.index') }}" class="px-3 py-1.5 rounded-md transition {{ request()->routeIs('incidents.index') && !request()->has('status') ? 'text-white bg-[#21262d]' : 'hover:text-white hover:bg-[#21262d]/50' }}">
                        Incidents
                    </a>
                    <a href="{{ route('incidents.index', ['status' => 'awaiting_approval']) }}" class="px-3 py-1.5 rounded-md transition flex items-center space-x-1.5 {{ request()->query('status') === 'awaiting_approval' ? 'text-white bg-[#21262d]' : 'hover:text-white hover:bg-[#21262d]/50' }}">
                        <span>Reviews</span>
                        @php
                            $pendingReviews = \App\Models\Incident::where('status', 'awaiting_approval')->count();
                        @endphp
                        @if ($pendingReviews > 0)
                            <span class="px-1.5 py-0.2 rounded-full text-xs bg-[#d29922]/20 text-[#e3b341] border border-[#d29922]/30 font-semibold">{{ $pendingReviews }}</span>
                        @endif
                    </a>
                    <a href="/api/v1/metrics" target="_blank" class="px-3 py-1.5 rounded-md transition hover:text-white hover:bg-[#21262d]/50 flex items-center space-x-1">
                        <span>API Metrics</span>
                        <svg class="w-3.5 h-3.5 text-[#848d97]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                    </a>
                </nav>

                <!-- Right: Status Pill & Environment -->
                <div class="flex items-center space-x-3 text-xs">
                    <!-- Live Status Counter Pill -->
                    <div class="hidden sm:flex items-center space-x-2 px-2.5 py-1 rounded-full bg-[#21262d] border border-[#30363d] text-[#e6edf3]">
                        <span class="w-2 h-2 rounded-full bg-[#3fb950]"></span>
                        <span class="text-[#848d97]">Active:</span>
                        <span class="font-semibold text-white">{{ $globalActiveCount ?? \App\Models\Incident::whereNotIn('status', ['remediated', 'resolved', 'closed', 'failed'])->count() }}</span>
                    </div>

                    <!-- Environment Tag -->
                    <span class="px-2 py-0.5 rounded-full bg-[#21262d] border border-[#30363d] text-[#848d97] font-medium">
                        {{ strtolower(config('app.env', 'production')) }}
                    </span>

                    <!-- User Profile Avatar -->
                    <div class="flex items-center space-x-2 pl-2 border-l border-[#30363d]">
                        <div class="w-6 h-6 rounded-full bg-[#238636] text-white flex items-center justify-center font-semibold text-xs">
                            S
                        </div>
                        <span class="hidden lg:inline text-[#e6edf3] text-xs font-medium">secops</span>
                    </div>
                </div>

            </div>
        </div>
    </header>

    <!-- GitHub Style Flash Notices -->
    @if (session('success'))
        <div class="bg-[#1f3526] border-b border-[#2ea043]/40 text-[#3fb950] px-4 py-2.5 text-sm flex items-center">
            <div class="max-w-7xl mx-auto w-full flex items-center space-x-2">
                <svg class="w-4 h-4 shrink-0 text-[#3fb950]" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M13.78 4.22a.75.75 0 0 1 0 1.06l-7.25 7.25a.75.75 0 0 1-1.06 0L2.22 9.28a.751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018L6 10.94l6.72-6.72a.75.75 0 0 1 1.06 0Z"/>
                </svg>
                <span class="font-medium">{{ session('success') }}</span>
            </div>
        </div>
    @endif

    @if (session('error') || $errors->any())
        <div class="bg-[#3a1d1d] border-b border-[#f85149]/40 text-[#f85149] px-4 py-2.5 text-sm flex items-center">
            <div class="max-w-7xl mx-auto w-full flex items-center space-x-2">
                <svg class="w-4 h-4 shrink-0 text-[#f85149]" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13ZM0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8Zm9 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM6.75 4.75a.75.75 0 0 1 1.5 0v3.5a.75.75 0 0 1-1.5 0v-3.5Z"/>
                </svg>
                <span class="font-medium">{{ session('error') ?? $errors->first() }}</span>
            </div>
        </div>
    @endif

    <!-- Main Content Canvas -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {{ $slot }}
    </main>

    <!-- GitHub Style Simple Footer -->
    <footer class="border-t border-[#30363d] py-6 text-xs text-[#848d97] mt-auto">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center space-x-3">
                <svg class="w-5 h-5 text-[#848d97]" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M8 0c4.42 0 8 3.58 8 8a8.013 8.013 0 0 1-5.45 7.59c-.4.08-.55-.17-.55-.38 0-.27.01-1.13.01-2.2 0-.75-.25-1.23-.54-1.48 1.78-.2 3.65-.88 3.65-3.95 0-.88-.31-1.59-.82-2.15.08-.2.36-1.02-.08-2.12 0 0-.67-.22-2.2.82-.64-.18-1.32-.27-2-.27-.68 0-1.36.09-2 .27-1.53-1.03-2.2-.82-2.2-.82-.44 1.1-.16 1.92-.08 2.12-.51.56-.82 1.28-.82 2.15 0 3.06 1.86 3.75 3.64 3.95-.23.2-.44.55-.51 1.07-.46.21-1.61.55-2.33-.66-.15-.24-.6-.83-1.23-.82-.67.01-.27.38.01.53.34.19.73.9.82 1.13.16.45.68 1.31 2.69.94 0 .67.01 1.3.01 1.49 0 .21-.15.45-.55.38A7.995 7.995 0 0 1 0 8c0-4.42 3.58-8 8-8Z"/>
                </svg>
                <span>PatchOps Autonomous Remediation</span>
            </div>
            <div class="flex items-center space-x-5 text-[#848d97]">
                <a href="{{ route('incidents.index') }}" class="hover:text-[#58a6ff] transition">Incidents</a>
                <a href="/api/v1/metrics" target="_blank" class="hover:text-[#58a6ff] transition">Metrics</a>
                <span class="text-[#3fb950] flex items-center space-x-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-[#3fb950]"></span>
                    <span>All systems operational</span>
                </span>
            </div>
        </div>
    </footer>

</body>
</html>
