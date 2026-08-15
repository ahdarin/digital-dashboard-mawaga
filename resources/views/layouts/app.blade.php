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

    {{-- Flatpickr - satu library kalender dipakai konsisten di seluruh
         form/filter tanggal, bulan, dan rentang tanggal. --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/style.css">
    <script defer src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/id.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/index.js"></script>
    <style>
        [x-cloak] { display: none !important; }

        /* Tema Flatpickr disamakan sama brand (#044b46) - bukan oranye bawaan */
        .flatpickr-day.selected, .flatpickr-day.selected:hover,
        .flatpickr-day.startRange, .flatpickr-day.endRange,
        .flatpickr-day.startRange:hover, .flatpickr-day.endRange:hover,
        .monthSelect-month.selected, .monthSelect-month.selected:hover {
            background: #044b46; border-color: #044b46;
        }
        .flatpickr-day.inRange, .monthSelect-month.inRange {
            background: #f0f5f4; border-color: #f0f5f4; box-shadow: -5px 0 0 #f0f5f4, 5px 0 0 #f0f5f4;
        }
        .flatpickr-day:hover, .monthSelect-month:hover { background: #eef0f4; }
        .flatpickr-day.today { border-color: #044b46; }
        .flatpickr-day.today:hover { background: #044b46; color: #fff; }
        .flatpickr-months .flatpickr-month, .flatpickr-current-month .flatpickr-monthDropdown-months,
        .flatpickr-weekdays, span.flatpickr-weekday { background: #fff; color: #14181a; }
        .flatpickr-current-month input.cur-year { color: #14181a; }
        .flatpickr-calendar { box-shadow: 0 1px 2px rgba(20,24,26,0.03), 0 20px 40px -12px rgba(20,24,26,0.15); border-radius: 12px; overflow: hidden; }
        .flatpickr-months { border-radius: 12px 12px 0 0; }

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
    {{-- Inisialisasi Flatpickr global - satu library dipakai konsisten di
         seluruh form/filter tanggal. Elemen ditandai lewat atribut
         data-flatpickr, dipanggil ulang lewat window.initFlatpickrs(root)
         tiap kali ada markup baru disuntik manual (bukan lewat Blade). --}}
    <script>
        window.initFlatpickrs = function (root) {
            root = root || document;
            if (typeof flatpickr === 'undefined') return;

            // dispatch input+change biar x-model Alpine yang nempel di
            // elemen aslinya ikut ke-update (flatpickr nulis value elemen
            // asli secara langsung, nggak lewat event DOM biasa).
            var notifyAlpine = function (el) {
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            };

            root.querySelectorAll('[data-flatpickr="date"]').forEach(function (el) {
                if (el._flatpickr) return;
                var autosubmit = el.dataset.autosubmit === 'true';
                flatpickr(el, {
                    dateFormat: 'Y-m-d', altInput: true, altFormat: 'd M Y', locale: 'id', allowInput: true,
                    onChange: function () {
                        notifyAlpine(el);
                        if (autosubmit) el.closest('form')?.submit();
                    },
                });
            });

            root.querySelectorAll('[data-flatpickr="datetime"]').forEach(function (el) {
                if (el._flatpickr) return;
                flatpickr(el, {
                    enableTime: true, time_24hr: true, dateFormat: 'Y-m-d H:i',
                    altInput: true, altFormat: 'd M Y, H:i', locale: 'id', allowInput: true,
                    onChange: function () { notifyAlpine(el); },
                });
            });

            // Bulan+tahun sebagai satu picker, tapi backend-nya masih 2 field
            // terpisah (month, year) - disinkronkan lewat 2 hidden input yang
            // namanya dikasih lewat data-month-input / data-year-input.
            root.querySelectorAll('[data-flatpickr="month"]').forEach(function (el) {
                if (el._flatpickr) return;
                var monthInput = document.querySelector(el.dataset.monthInput);
                var yearInput = document.querySelector(el.dataset.yearInput);
                var autosubmit = el.dataset.autosubmit === 'true';
                var initial = (monthInput && yearInput && monthInput.value && yearInput.value)
                    ? new Date(Number(yearInput.value), Number(monthInput.value) - 1, 1)
                    : new Date();

                flatpickr(el, {
                    locale: 'id',
                    defaultDate: initial,
                    plugins: [new monthSelectPlugin({ shorthand: false, dateFormat: 'Y-m', altFormat: 'F Y' })],
                    onChange: function (selectedDates) {
                        if (!selectedDates[0]) return;
                        if (monthInput) monthInput.value = selectedDates[0].getMonth() + 1;
                        if (yearInput) yearInput.value = selectedDates[0].getFullYear();
                        if (autosubmit) el.closest('form')?.submit();
                    },
                });
            });

            // Sama kayak "month" di atas, tapi backend-nya nerima SATU field
            // gabungan format Y-m (bukan month & year terpisah).
            root.querySelectorAll('[data-flatpickr="month-combined"]').forEach(function (el) {
                if (el._flatpickr) return;
                var autosubmit = el.dataset.autosubmit === 'true';

                flatpickr(el, {
                    locale: 'id',
                    defaultDate: el.value || undefined,
                    plugins: [new monthSelectPlugin({ shorthand: false, dateFormat: 'Y-m', altFormat: 'F Y' })],
                    onChange: function () {
                        if (autosubmit) el.closest('form')?.submit();
                    },
                });
            });
        };
        document.addEventListener('DOMContentLoaded', function () { window.initFlatpickrs(); });
    </script>

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