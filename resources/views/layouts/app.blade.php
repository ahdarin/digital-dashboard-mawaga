<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    @include('partials._theme-init-script')
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

        @include('partials._theme-tokens')

        /* Tema Flatpickr disamakan sama brand - bukan oranye bawaan.
           Elemen di bawah dari CSS bawaan library (CDN, bukan punya kita),
           jadi selain warna aksen, kartu popup-nya sendiri (background,
           warna teks tanggal biasa, nav bulan, dropdown bulan/tahun) juga
           HARUS ditimpa manual - kalau nggak dia tetap kartu putih/teks
           gelap bawaan library, nggak ikut gelap sama sekali di dark mode
           walau input & elemen lain di halaman udah gelap semua. */
        .flatpickr-calendar {
            background: var(--surface-card);
            box-shadow: 0 1px 2px rgba(20,24,26,0.03), 0 20px 40px -12px rgba(20,24,26,0.15);
            border-radius: 12px; overflow: hidden;
        }
        .flatpickr-day, .monthSelect-month { color: var(--text-primary); }
        .flatpickr-day.prevMonthDay, .flatpickr-day.nextMonthDay { color: var(--text-idle); }
        .flatpickr-day.flatpickr-disabled, .flatpickr-day.flatpickr-disabled:hover { color: var(--icon-disabled); }
        {{-- .selected/.startRange/.endRange teksnya SELALU putih dari CSS
             bawaan library (nggak bisa ditimpa var teks kita) - makanya bg-nya
             wajib pakai --brand-solid (konstan di kedua tema), BUKAN --brand
             (yang sengaja diterangkan buat dark mode) - kalau pakai --brand,
             teks putih bawaan itu jadi nyaris nggak kebaca. Sama logika
             dengan --brand-solid di tombol/avatar - lihat memori dark-mode-plan. --}}
        .flatpickr-day.selected, .flatpickr-day.selected:hover,
        .flatpickr-day.startRange, .flatpickr-day.endRange,
        .flatpickr-day.startRange:hover, .flatpickr-day.endRange:hover,
        .monthSelect-month.selected, .monthSelect-month.selected:hover {
            background: var(--brand-solid); border-color: var(--brand-solid);
        }
        .flatpickr-day.inRange, .monthSelect-month.inRange {
            background: var(--brand-tint); border-color: var(--brand-tint); box-shadow: -5px 0 0 var(--brand-tint), 5px 0 0 var(--brand-tint);
            color: var(--text-primary);
        }
        .flatpickr-day:hover, .monthSelect-month:hover { background: var(--border); }
        .flatpickr-day.today { border-color: var(--brand); }
        .flatpickr-day.today:hover { background: var(--brand); color: var(--surface-card); }
        .flatpickr-months .flatpickr-month, .flatpickr-current-month .flatpickr-monthDropdown-months,
        .flatpickr-weekdays, span.flatpickr-weekday { background: var(--surface-card); color: var(--text-primary); }
        .flatpickr-current-month input.cur-year { color: var(--text-primary); }
        .flatpickr-current-month .flatpickr-monthDropdown-months .flatpickr-monthDropdown-month {
            background: var(--surface-card); color: var(--text-primary);
        }
        .flatpickr-months .flatpickr-prev-month, .flatpickr-months .flatpickr-next-month { color: var(--text-muted); fill: var(--text-muted); }
        .flatpickr-months .flatpickr-prev-month:hover, .flatpickr-months .flatpickr-next-month:hover { color: var(--brand); fill: var(--brand); }
        .flatpickr-months { border-radius: 12px 12px 0 0; }
        span.flatpickr-weekday { color: var(--text-muted); }
        .numInputWrapper:hover { background: var(--surface-page); }
        .numInputWrapper span.arrowUp:after { border-bottom-color: var(--text-muted); }
        .numInputWrapper span.arrowDown:after { border-top-color: var(--text-muted); }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--surface-page);
            color: var(--text-primary);
        }
        .font-display {
            font-family: 'Fraunces', serif;
            font-optical-sizing: auto;
        }

        /* Card standar - flat, border tipis, shadow super halus */
        .card {
            background: var(--surface-card);
            border: 1px solid var(--border);
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
            background: var(--brand);
            color: var(--surface-card);
        }
        .btn-primary:hover:not(:disabled) { background: var(--brand-dark); }
        .btn-secondary {
            background: var(--surface-card);
            color: var(--text-secondary);
            border-color: var(--border);
        }
        .btn-secondary:hover:not(:disabled) { background: var(--surface-page); }
        .btn-danger {
            background: var(--surface-card);
            color: var(--danger-text);
            border-color: var(--danger-border);
        }
        .btn-danger:hover:not(:disabled) { background: var(--danger-tint); }
        .btn-primary:focus-visible, .btn-secondary:focus-visible, .btn-danger:focus-visible {
            outline: none;
            box-shadow: 0 0 0 2px var(--surface-card), 0 0 0 4px rgba(4,75,70,0.45);
        }

        .input {
            display: block;
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            background: var(--surface-card);
            color: var(--text-primary);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .input:focus {
            outline: none;
            border-color: rgba(4,75,70,0.4);
            box-shadow: 0 0 0 2px rgba(4,75,70,0.15);
        }
        .input.input-error {
            border-color: var(--field-error-border);
        }
        .input.input-error:focus {
            border-color: var(--danger-text);
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
            width: max-content;
            max-width: 100%;
        }
        .badge-success { background: var(--success-tint); color: var(--success-text); }
        .badge-danger  { background: var(--danger-tint); color: var(--danger-text); }
        .badge-info    { background: var(--info-tint); color: var(--info-text); }
        .badge-warning { background: var(--warning-tint); color: var(--warning-text); }
        .badge-neutral { background: var(--surface-muted); color: var(--text-secondary); }

        #top-loading-bar {
            position: fixed; top: 0; left: 0; height: 2.5px; width: 0%;
            background: var(--brand); z-index: 9999;
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
            scrollbar-color: var(--text-idle) transparent;
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
            background-color: var(--text-idle);
        }
        .thin-autohide-scrollbar::-webkit-scrollbar-thumb:hover {
            background-color: var(--text-placeholder);
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
            const hide = () => bar.classList.remove('loading');
            // Buat baris tabel/kartu yang navigasi lewat onclick="window.location=..."
            // (bukan <a> asli, misal baris tabel Kelola Klien) - supaya loading
            // bar tetap muncul, bukan cuma link <a> biasa.
            window.navigateTo = function (url) {
                show();
                window.location = url;
            };
            // Dipakai submission berbasis fetch() (mis. modal drag-drop kanban)
            // yang tidak memicu event 'submit' asli di bawah - halaman tetap
            // sama (SPA-ish), jadi show/hide harus dipanggil manual.
            window.showTopLoadingBar = show;
            window.hideTopLoadingBar = hide;
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
        <div x-show="show" x-transition role="dialog" aria-modal="true" x-trap="show" class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-sm max-h-[90vh] overflow-y-auto p-6">
            <p class="text-sm text-[var(--text-primary)] leading-relaxed mb-5" x-text="message"></p>
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

            // Auto-format SAAT diketik - mirip format ribuan uang (ketik
            // angka, pemisah "/"/":" muncul sendiri di posisi yang benar),
            // BUKAN input bersegmen yang perlu diklik per-bagian. Begitu
            // jumlah digit sudah pas (dd/mm/yyyy, atau + hh:mm), langsung
            // di-set ke instance flatpickr lewat setDate() - parsing
            // dilakukan sendiri di sini (posisi digit sudah pasti, bukan
            // format bebas) daripada mengandalkan parser bawaan flatpickr
            // yang bisa ambigu untuk format custom kayak gini.
            var attachTypingMask = function (instance, withTime) {
                var target = instance.altInput || instance.input;
                if (!target || target._maskAttached) return;
                target._maskAttached = true;

                var digitCount = withTime ? 12 : 8; // ddmmyyyy(hhmm)

                target.addEventListener('input', function () {
                    var digits = target.value.replace(/\D/g, '').slice(0, digitCount);
                    var out = '';
                    for (var i = 0; i < digits.length; i++) {
                        if (i === 2 || i === 4) out += '/';
                        if (withTime && i === 8) out += ' ';
                        if (withTime && i === 10) out += ':';
                        out += digits[i];
                    }
                    target.value = out;

                    if (digits.length === digitCount) {
                        var day = Number(digits.slice(0, 2));
                        var month = Number(digits.slice(2, 4));
                        var year = Number(digits.slice(4, 8));
                        var hour = withTime ? Number(digits.slice(8, 10)) : 0;
                        var minute = withTime ? Number(digits.slice(10, 12)) : 0;
                        var date = new Date(year, month - 1, day, hour, minute);
                        // Validasi tanggal beneran ada (bukan cuma format pas) -
                        // mis. 31/02 harus ditolak, bukan "diloncat maju" ke
                        // Maret diam-diam kayak default parsing JS Date.
                        var valid = date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
                            && (!withTime || (date.getHours() === hour && date.getMinutes() === minute));
                        if (valid) instance.setDate(date, true);
                    }
                });
            };

            root.querySelectorAll('[data-flatpickr="date"]').forEach(function (el) {
                if (el._flatpickr) return;
                var autosubmit = el.dataset.autosubmit === 'true';
                flatpickr(el, {
                    dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y', locale: 'id', allowInput: true,
                    onReady: function (selectedDates, dateStr, instance) {
                        instance.altInput.placeholder = 'dd/mm/yyyy';
                        attachTypingMask(instance, false);
                    },
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
                    altInput: true, altFormat: 'd/m/Y H:i', locale: 'id', allowInput: true,
                    onReady: function (selectedDates, dateStr, instance) {
                        instance.altInput.placeholder = 'dd/mm/yyyy hh:mm';
                        attachTypingMask(instance, true);
                    },
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
                    // data-max opsional (format "Y-m") - dipakai filter yang
                    // tidak boleh memilih bulan depan (mis. AI Strategy Analysis,
                    // retrospective saja). Tidak diset = tidak ada batas atas,
                    // sama seperti sebelumnya.
                    maxDate: el.dataset.max ? (el.dataset.max + '-01') : undefined,
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

            // Rentang tanggal SATU kontrol visual (mode: 'range'), tapi
            // backend tetap terima 2 field terpisah (date_from/date_to) -
            // disinkronkan lewat data-from-input/data-to-input (selector
            // CSS ke hidden input masing-masing), pola sama seperti "month"
            // di atas. Auto-submit HANYA saat rentang genap 2 tanggal
            // (start DAN end sudah dipilih, ANALYTICS PERIOD FILTER FINAL
            // Langkah 4) - baru tanggal pertama dipilih SENGAJA belum
            // submit, supaya user bisa lanjut pilih tanggal kedua tanpa
            // form ke-reset di tengah jalan.
            root.querySelectorAll('[data-flatpickr="range"]').forEach(function (el) {
                if (el._flatpickr) return;
                var fromInput = document.querySelector(el.dataset.fromInput);
                var toInput = document.querySelector(el.dataset.toInput);
                var autosubmit = el.dataset.autosubmit === 'true';
                var initial = (fromInput && fromInput.value && toInput && toInput.value)
                    ? [fromInput.value, toInput.value] : undefined;
                var isoDate = function (d) {
                    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                };

                flatpickr(el, {
                    mode: 'range', dateFormat: 'Y-m-d', altInput: true, altFormat: 'd M Y',
                    rangeSeparator: ' - ', locale: 'id', defaultDate: initial,
                    maxDate: el.dataset.maxDate || undefined,
                    onReady: function (selectedDates, dateStr, instance) {
                        instance.altInput.placeholder = el.dataset.placeholder || 'Pilih rentang tanggal';
                    },
                    onChange: function (selectedDates) {
                        // Rentang belum lengkap (baru start) - JANGAN
                        // submit, tunggu tanggal kedua dipilih.
                        if (selectedDates.length < 2) return;
                        if (fromInput) { fromInput.value = isoDate(selectedDates[0]); notifyAlpine(fromInput); }
                        if (toInput) { toInput.value = isoDate(selectedDates[1]); notifyAlpine(toInput); }
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

    <script>
        {{-- Tooltip custom aksi (gaya sama seperti tooltip sidebar saat
             collapse, tapi muncul DI ATAS elemen bukan di samping) - SATU
             instance Alpine terpisah PER tombol (x-data="tooltipHover('...')"),
             bukan satu state dibagi ke banyak tombol sekaligus. Kalau
             dibagi, pindah hover antar tombol yang berdekatan bisa membuat
             tooltip lama sempat kelihatan sekilas di posisi lama sebelum
             pindah ke posisi baru (transisi fade-out tombol lama tumpang
             tindih dengan fade-in tombol baru pada elemen DOM yang sama). --}}
        function tooltipHover(text) {
            return {
                show: false,
                top: 0,
                left: 0,
                text: text,
                onEnter(event) {
                    const rect = event.currentTarget.getBoundingClientRect();
                    this.top = rect.top - 8;
                    this.left = rect.left + rect.width / 2;
                    this.show = true;
                },
                onLeave() { this.show = false; },
            };
        }
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