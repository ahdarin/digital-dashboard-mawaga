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

    {{-- Modal konfirmasi aplikasi - pengganti confirm() bawaan browser di
         seluruh sistem, supaya tampilannya konsisten dengan brand (bukan
         dialog native OS) dan bisa di-styling untuk aksi berbahaya. Dipicu
         lewat window.appConfirm(form, message, {danger}) yang dipasang di
         atribut onsubmit form manapun. --}}
    <div x-data="{
            show: false, message: '', danger: false, onConfirm: null,
            open(detail) { this.message = detail.message; this.danger = detail.danger; this.onConfirm = detail.onConfirm; this.show = true },
            confirm() { this.show = false; this.onConfirm && this.onConfirm() },
        }"
        x-init="window.addEventListener('app-confirm', (e) => open(e.detail))"
        x-show="show" x-cloak x-transition
        class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-[#14181a]/40" @click="show = false"></div>
        <div x-show="show" x-transition class="relative bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">
            <p class="text-sm text-[#14181a] leading-relaxed mb-5" x-text="message"></p>
            <div class="flex items-center gap-3">
                <button type="button" @click="confirm()"
                    :class="danger ? 'bg-[#b3423e] hover:bg-[#96352f]' : 'bg-[#044b46] hover:bg-[#033b37]'"
                    class="text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
                    Ya, Lanjutkan
                </button>
                <button type="button" @click="show = false" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">
                    Batal
                </button>
            </div>
        </div>
    </div>
    <script>
        window.appConfirm = function (form, message, opts) {
            opts = opts || {};
            if (form.dataset.confirmBypass === '1') {
                delete form.dataset.confirmBypass;
                return true;
            }
            window.dispatchEvent(new CustomEvent('app-confirm', {
                detail: {
                    message: message,
                    danger: !!opts.danger,
                    onConfirm: function () {
                        form.dataset.confirmBypass = '1';
                        form.requestSubmit();
                    },
                },
            }));
            return false;
        };
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