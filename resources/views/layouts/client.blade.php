<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    @include('partials._theme-init-script')
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Permanent portal link = credential akses - jangan pernah ke-index
         search engine atau bocor lewat Referer header ke situs lain. --}}
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', 'Portal Klien') | 523 Studio</title>

    <link rel="icon" href="{{ asset('images/favicon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..500,0..1&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/style.css">
    <script defer src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/id.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/index.js"></script>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        @include('partials._theme-tokens')
        body { font-family: 'Inter', sans-serif; background-color: var(--surface-page); color: var(--text-primary); }
        .font-display { font-family: 'Fraunces', serif; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined'; font-weight: normal; font-style: normal; }

        {{-- Flatpickr dark-mode override, sama persis dengan layouts/app.blade.php --}}
        .flatpickr-calendar {
            background: var(--surface-card);
            box-shadow: 0 1px 2px rgba(20,24,26,0.03), 0 20px 40px -12px rgba(20,24,26,0.15);
            border-radius: 12px; overflow: hidden;
        }
        .flatpickr-day, .monthSelect-month { color: var(--text-primary); }
        .flatpickr-day.prevMonthDay, .flatpickr-day.nextMonthDay { color: var(--text-idle); }
        .flatpickr-day.flatpickr-disabled, .flatpickr-day.flatpickr-disabled:hover { color: var(--icon-disabled); }
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
    </style>
    @stack('head')
</head>
<body class="min-h-screen" x-data="{
        theme: localStorage.getItem('theme') || 'system',
        themeIcons: { light: 'light_mode', dark: 'dark_mode', system: 'brightness_auto' },
        {{-- Client Portal TIDAK PAKAI Auth - tema di sini localStorage-only,
             tidak ada request ke /preferences/theme (route itu butuh 'auth'
             dan Client tidak pernah login). --}}
        setTheme(value) {
            this.theme = value;
            if (value === 'system') {
                document.documentElement.removeAttribute('data-theme');
            } else {
                document.documentElement.setAttribute('data-theme', value);
            }
            localStorage.setItem('theme', value);
        },
        cycleTheme() {
            const order = ['light', 'dark', 'system'];
            this.setTheme(order[(order.indexOf(this.theme) + 1) % order.length]);
        },
        themeLabels: { light: 'Tema: Terang', dark: 'Tema: Gelap', system: 'Tema: Ikut Sistem' },
    }">

    <script>
        {{-- Tooltip custom - SATU instance Alpine terpisah PER tombol (lihat
             catatan lebih lengkap di layouts/app.blade.php versi internal). --}}
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

    <header class="bg-[var(--surface-card)] border-b border-[var(--border)] px-4 sm:px-5 py-3.5 sticky top-0 z-10 flex items-center gap-2.5">
        <img src="{{ asset('images/logo.png') }}" alt="523 Studio" class="h-6 sm:h-7 w-auto shrink-0">
        <div class="min-w-0 flex-1">
            <h1 class="font-display text-sm sm:text-base font-semibold text-[var(--text-primary)] leading-tight truncate">523 Studio</h1>
            <p class="text-[11px] sm:text-xs text-[var(--text-muted)] leading-tight truncate">@yield('title', 'Portal Klien')</p>
        </div>

        <span x-data="tooltipHover(themeLabels[theme])" x-effect="text = themeLabels[theme]" class="contents">
            <button type="button" @click="cycleTheme()"
                @mouseenter="onEnter($event)" @mouseleave="onLeave()"
                aria-label="Ganti tema"
                class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--surface-page)] hover:text-[var(--text-primary)] transition-colors">
                <span class="material-symbols-outlined text-[19px]" x-text="themeIcons[theme]"></span>
            </button>
            @include('components.action-tooltip')
        </span>
    </header>

    <nav class="bg-[var(--surface-card)] border-b border-[var(--border)] px-2 sm:px-5 flex items-center gap-1 sticky top-[57px] sm:top-[65px] z-10 overflow-x-auto thin-autohide-scrollbar">
        @php
            $clientNavItems = [
                ['route' => 'client.portal.dashboard', 'label' => 'Dashboard'],
                ['route' => 'client.portal.calendar', 'label' => 'Kalender'],
                ['route' => 'client.portal.history', 'label' => 'Riwayat'],
                ['route' => 'client.portal.analytics', 'label' => 'Analytics'],
            ];
        @endphp
        @foreach ($clientNavItems as $navItem)
            @php $isActive = request()->routeIs($navItem['route'] . '*'); @endphp
            <a href="{{ route($navItem['route'], $portalToken) }}"
               class="shrink-0 text-sm font-medium px-3 py-3 border-b-2 whitespace-nowrap transition-colors
                   {{ $isActive ? 'border-[var(--brand)] text-[var(--brand)]' : 'border-transparent text-[var(--text-muted)] hover:text-[var(--text-primary)]' }}">
                {{ $navItem['label'] }}
            </a>
        @endforeach
    </nav>

    @if (session('status'))
        <div class="mx-4 sm:mx-5 mt-4 bg-[var(--brand-tint)] border border-[var(--brand-tint-border)] text-[var(--brand)] text-sm p-3 rounded-2xl font-medium">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mx-4 sm:mx-5 mt-4 bg-[var(--danger-tint)] border border-[var(--danger-border)] text-[var(--danger-text)] text-sm p-3 rounded-2xl font-medium">{{ $errors->first() }}</div>
    @endif

    @yield('content')

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof flatpickr === 'undefined') return;

            document.querySelectorAll('[data-flatpickr="month-combined"]').forEach(function (el) {
                var autosubmit = el.dataset.autosubmit === 'true';

                flatpickr(el, {
                    locale: 'id',
                    defaultDate: el.value || undefined,
                    plugins: [new monthSelectPlugin({ shorthand: false, dateFormat: 'Y-m', altFormat: 'F Y' })],
                    onChange: function () {
                        if (autosubmit) (el.form || el.closest('form'))?.submit();
                    },
                });
            });
        });
    </script>
</body>
</html>
