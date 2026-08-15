<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', '523 Studio')</title>
    <link rel="icon" href="{{ asset('images/favicon.png') }}">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..500,0..1&display=swap" rel="stylesheet">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        [x-cloak] { display: none !important; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f7f8fc;
            color: #14181a;
        }
        .font-display {
            font-family: 'Fraunces', serif;
            font-optical-sizing: auto;
        }

        /* Card standar - flat, border tipis, shadow super halus */
        .card {
            background: #fff;
            border: 1px solid #eef0f4;
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(20,24,26,0.03);
        }

        #top-loading-bar {
            position: fixed; top: 0; left: 0; height: 2.5px; width: 0%;
            background: #044b46; z-index: 9999;
            transition: width 0.4s ease, opacity 0.3s ease; opacity: 0;
        }
        #top-loading-bar.loading {
            width: 70%; opacity: 1;
            transition: width 8s cubic-bezier(0.1,0.5,0.1,1), opacity 0.2s ease;
        }

        /* Scrollbar tipis, transparan, muncul saat hover — dipakai di sidebar, task list profile, dan kanban */
        .thin-autohide-scrollbar {
            scrollbar-width: thin;
            scrollbar-color: transparent transparent;
        }
        .thin-autohide-scrollbar:hover {
            scrollbar-color: #c3c7cb transparent;
        }
        .thin-autohide-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .thin-autohide-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }
        .thin-autohide-scrollbar::-webkit-scrollbar-thumb {
            background-color: transparent;
            border-radius: 9999px;
        }
        .thin-autohide-scrollbar:hover::-webkit-scrollbar-thumb {
            background-color: #c3c7cb;
        }
        .thin-autohide-scrollbar::-webkit-scrollbar-thumb:hover {
            background-color: #9aa0a4;
        }

        /* Running text (marquee) - dipakai buat reminder di Dashboard welcome
           banner. Trik standar: konten di-duplikat 2x berdampingan dalam satu
           track, lalu track-nya digeser -50% - begitu salinan pertama abis
           keluar, salinan kedua udah pas di posisi awal, jadi looping-nya
           keliatan mulus/nyambung tanpa "lompat". */
        .marquee-track {
            display: flex;
            width: max-content;
            animation: marquee-scroll linear infinite;
            animation-duration: var(--marquee-duration, 14s);
        }
        .marquee-track:hover {
            animation-play-state: paused;
        }
        @keyframes marquee-scroll {
            from { transform: translateX(0); }
            to { transform: translateX(-50%); }
        }
        @media (prefers-reduced-motion: reduce) {
            .marquee-track {
                animation: none;
            }
        }
    </style>
</head>
<body class="min-h-screen">

    <div id="top-loading-bar"></div>
    <script>
        (function () {
            const bar = document.getElementById('top-loading-bar');
            const show = () => bar.classList.add('loading');
            document.addEventListener('click', function (e) {
                const link = e.target.closest('a');
                if (!link) return;
                if (link.target === '_blank' || link.hasAttribute('download')) return;
                if (link.getAttribute('href')?.startsWith('#')) return;
                if (link.origin !== window.location.origin) return;
                show();
            });
            document.addEventListener('submit', function (e) {
                if (e.target.tagName === 'FORM' && !e.defaultPrevented) show();
            });
            window.addEventListener('pageshow', function (e) {
                if (e.persisted) bar.classList.remove('loading');
            });
        })();
    </script>

    <div class="flex min-h-screen" x-data="{ sidebarOpen: false }"
        x-init="$watch('sidebarOpen', value => { document.documentElement.style.overflow = value ? 'hidden' : '' })">

        @auth
            <x-sidebar />
        @endauth

        <div class="flex-1 flex flex-col min-w-0">
            @auth
                <div class="sticky top-0 z-10">
                    <x-topbar />
                </div>
            @endauth

            <main class="flex-1 min-w-0">
                @yield('content')
            </main>
        </div>

    </div>

</body>