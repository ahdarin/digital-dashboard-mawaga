<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', '523 Studio')</title>
    <link rel="icon" href="{{ asset('images/favicon.png') }}">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    {{-- Plugin Alpine WAJIB dimuat sebelum core alpine.js (bukan sesudahnya)
         - dipakai buat x-trap di modal biar fokus keyboard nggak lolos ke
         konten di belakang overlay. --}}
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/focus@3.x.x/dist/cdn.min.js"></script>
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

        /* Component layer (R3) - satu definisi tombol/input/badge dipakai di
           seluruh halaman, gantiin varian padding/radius/warna yang sebelumnya
           ditulis ulang tiap file (lihat audit UI-01/03/04/05). */
        .btn-primary, .btn-secondary, .btn-danger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            line-height: 1.25rem;
            border: 1px solid transparent;
            transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
            cursor: pointer;
        }
        .btn-primary:disabled, .btn-secondary:disabled, .btn-danger:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-primary {
            background: #044b46;
            color: #fff;
        }
        .btn-primary:hover:not(:disabled) { background: #033b37; }
        .btn-secondary {
            background: #fff;
            color: #5c6266;
            border-color: #eef0f4;
        }
        .btn-secondary:hover:not(:disabled) { background: #f7f8fc; }
        .btn-danger {
            background: #fff;
            color: #b3423e;
            border-color: #f5d9d7;
        }
        .btn-danger:hover:not(:disabled) { background: #fdf2f1; }
        .btn-primary:focus-visible, .btn-secondary:focus-visible, .btn-danger:focus-visible {
            outline: none;
            box-shadow: 0 0 0 2px #fff, 0 0 0 4px rgba(4,75,70,0.45);
        }

        .input {
            display: block;
            width: 100%;
            border: 1px solid #eef0f4;
            border-radius: 0.5rem;
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            background: #fff;
            color: #14181a;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .input:focus {
            outline: none;
            border-color: rgba(4,75,70,0.4);
            box-shadow: 0 0 0 2px rgba(4,75,70,0.15);
        }
        .input.input-error {
            border-color: #f5a19b;
        }
        .input.input-error:focus {
            border-color: #b3423e;
            box-shadow: 0 0 0 2px rgba(179,66,62,0.15);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: nowrap;
        }
        .badge-success { background: #f0f5f4; color: #0f7a5f; }
        .badge-danger  { background: #fdf2f1; color: #b3423e; }
        .badge-info    { background: #eef2fb; color: #3452a8; }
        .badge-warning { background: #fdf6ec; color: #8a6423; }
        .badge-neutral { background: #f2f3f6; color: #5c6266; }

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
        @keyframes marquee-scroll {
            from { transform: translateX(0); }
            to { transform: translateX(-50%); }
        }
        @media (prefers-reduced-motion: reduce) {
            .marquee-track {
                animation: none;
            }
        }

        /* Lebar sidebar collapsed diterapkan lewat class di <html> yang
           di-set sinkron sebelum Alpine jalan (lihat script di awal body) -
           tanpa ini, sidebar sempat kerender lebar penuh dulu baru menyusut
           begitu Alpine attach, keliatan "flick" tiap pindah halaman. */
        @media (min-width: 1024px) {
            html.sidebar-collapsed aside {
                width: 76px !important;
            }
        }
    </style>
</head>
<body class="min-h-screen">

    <script>
        if (localStorage.getItem('sidebar-collapsed') === 'true') {
            document.documentElement.classList.add('sidebar-collapsed');
        }
    </script>

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
        x-on:keydown.escape.window="show = false"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-[#14181a]/40" @click="show = false"></div>
        <div x-show="show" x-transition role="dialog" aria-modal="true" x-trap="show" class="relative bg-white rounded-2xl shadow-xl w-full max-w-sm max-h-[90vh] overflow-y-auto p-6">
            <p class="text-sm text-[#14181a] leading-relaxed mb-5" x-text="message"></p>
            <div class="flex items-center gap-3">
                <button type="button" @click="confirm()"
                    :class="danger ? 'btn-danger' : 'btn-primary'">
                    Ya, Lanjutkan
                </button>
                <button type="button" @click="show = false" class="btn-secondary">
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
                        // el.form (bukan cuma closest('form')) - elemen ini
                        // sering dipasang di luar tag <form> dan disambungkan
                        // lewat atribut form="id", yang cuma dikenali lewat
                        // property .form, bukan closest() yang murni DOM tree.
                        if (autosubmit) (el.form || el.closest('form'))?.submit();
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
                        // el.form (bukan cuma closest('form')) - elemen ini
                        // sering dipasang di luar tag <form> dan disambungkan
                        // lewat atribut form="id", yang cuma dikenali lewat
                        // property .form, bukan closest() yang murni DOM tree.
                        if (autosubmit) (el.form || el.closest('form'))?.submit();
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
                        // el.form (bukan cuma closest('form')) - elemen ini
                        // sering dipasang di luar tag <form> dan disambungkan
                        // lewat atribut form="id", yang cuma dikenali lewat
                        // property .form, bukan closest() yang murni DOM tree.
                        if (autosubmit) (el.form || el.closest('form'))?.submit();
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
                <div class="sticky top-0 z-30">
                    <x-topbar />
                </div>
            @endauth

            <main class="flex-1 min-w-0">
                @yield('content')
            </main>
        </div>

    </div>

</body>
</html>