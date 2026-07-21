<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Mawaga Intel')</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;600;700;900&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-[#f8faf8] text-[#191c1c] min-h-screen">

    <div class="flex min-h-screen">

        {{-- SIDEBAR --}}
        @auth
            <aside class="w-64 sticky top-0 h-screen shrink-0">
                <x-sidebar />
            </aside>
        @endauth

        {{-- CONTENT --}}
        <div class="flex-1 flex flex-col min-w-0">

            {{-- TOPBAR --}}
            @auth
                <div class="sticky top-0 z-10">
                    <x-topbar />
                </div>
            @endauth

            {{-- PAGE --}}
            <main class="flex-1">
                @yield('content')
            </main>

        </div>

    </div>

</body>