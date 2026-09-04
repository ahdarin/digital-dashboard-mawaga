@php
    $menuGroups = [
        [
            'label' => 'Ringkasan',
            'items' => [
                ['label' => 'Beranda', 'route' => 'profile.me', 'icon' => 'home', 'permission' => ['workflow', 'view']],
                ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'grid_view', 'permission' => ['dashboard', 'view']],
                ['label' => 'Performa', 'route' => 'analytics', 'icon' => 'monitoring', 'permission' => ['analytics', 'view']],
            ],
        ],
        [
            'label' => 'Konten',
            'items' => [
                ['label' => 'Rencana Konten', 'route' => 'content-plan.index', 'icon' => 'event_note', 'permission' => ['content_plan', 'view']],
                ['label' => 'Produksi', 'route' => 'production-workflow.index', 'icon' => 'folder_open', 'permission' => ['workflow', 'view']],
            ],
        ],
        [
            'label' => 'Tim',
            'items' => [
                ['label' => 'Performa Tim', 'route' => 'team-performance.index', 'icon' => 'diversity_3', 'permission' => ['team_performance', 'view']],
                ['label' => 'Kelola Pengguna', 'route' => 'user-management.index', 'icon' => 'manage_accounts', 'permission' => ['user_management', 'view']],
            ],
        ],
        [
            'label' => 'Klien',
            'items' => [
                // 'client,view' TIDAK cukup di sini. Permission itu memang
                // dibuka ke SEMUA role internal, tapi cuma buat halaman
                // DETAIL 1 client (client-management.show, discope ke
                // assignment). Daftar lengkapnya (client-management.index)
                // sengaja lebih ketat - lihat abort_unless() di
                // ClientManagementController@index - jadi kalau menu ini
                // cuma digerbang 'client,view', SMO/Copywriter/Content
                // Creator/Graphic Designer melihat menu "Kelola Klien" yang
                // langsung 403 begitu diklik. Syarat di bawah dijaga
                // identik dengan controller-nya.
                [
                    'label' => 'Kelola Klien',
                    'route' => 'client-management.index',
                    'icon' => 'apartment',
                    'permission' => ['client', 'view'],
                    'visible' => fn ($user) => $user->hasPermissionTo('client', 'manage') || $user->canSeeAllClients(),
                ],
            ],
        ],
        [
            'label' => 'Laporan',
            'items' => [
                ['label' => 'Laporan', 'route' => 'report.index', 'icon' => 'description', 'permission' => ['report', 'view']],
            ],
        ],
        [
            'label' => 'Sistem',
            'items' => [
                ['label' => 'Pengaturan', 'route' => 'settings', 'icon' => 'tune', 'permission' => ['settings', 'view']],
            ],
        ],
    ];

    $authUser = auth()->user();
    $menuGroups = collect($menuGroups)
        ->map(function ($group) use ($authUser) {
            $group['items'] = array_values(array_filter(
                $group['items'],
                fn ($item) => $authUser->hasPermissionTo(...$item['permission'])
                    && (! isset($item['visible']) || $item['visible']($authUser))
            ));
            return $group;
        })
        ->filter(fn ($group) => count($group['items']) > 0)
        ->values()
        ->all();

    $tooltipEnter = <<<'JS'
        effectiveCollapsed && (tooltip = {
            show: true,
            text: label,
            top: $event.currentTarget.getBoundingClientRect().top + $event.currentTarget.getBoundingClientRect().height / 2,
            left: $event.currentTarget.getBoundingClientRect().right + 12,
        })
    JS;
@endphp

<div x-show="sidebarOpen" x-cloak x-transition.opacity
    class="fixed inset-0 bg-[#14181a]/40 z-30 lg:hidden"
    @click="sidebarOpen = false"></div>

<aside x-data="{
        collapsed: (localStorage.getItem('sidebar-collapsed') === 'true'),
        isDesktop: window.matchMedia('(min-width: 1024px)').matches,
        get effectiveCollapsed() { return this.collapsed && this.isDesktop },
        tooltip: { show: false, text: '', top: 0, left: 0 },
        theme: localStorage.getItem('theme') || 'system',
        themeLabels: { light: 'Tema: Terang', dark: 'Tema: Gelap', system: 'Tema: Ikut Sistem' },
        themeIcons: { light: 'light_mode', dark: 'dark_mode', system: 'brightness_auto' },
        setTheme(value) {
            this.theme = value;
            if (value === 'system') {
                document.documentElement.removeAttribute('data-theme');
            } else {
                document.documentElement.setAttribute('data-theme', value);
            }
            localStorage.setItem('theme', value);
            fetch('{{ route('preferences.theme') }}', {
                method: 'PATCH',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ theme: value }),
            }).catch(() => {});
        },
        cycleTheme() {
            const order = ['light', 'dark', 'system'];
            this.setTheme(order[(order.indexOf(this.theme) + 1) % order.length]);
        },
    }"
    x-init="
        window.matchMedia('(min-width: 1024px)').addEventListener('change', (e) => { isDesktop = e.matches });
        $watch('collapsed', value => {
            localStorage.setItem('sidebar-collapsed', value);
            document.documentElement.classList.toggle('sidebar-collapsed', value);
            if (!value) tooltip.show = false;
        });
    "
    :class="[effectiveCollapsed && 'lg:w-[76px]', sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0']"
    class="fixed inset-y-0 left-0 z-40 lg:sticky lg:top-0 lg:z-auto shrink-0 w-60 bg-[var(--surface-card)] flex flex-col h-screen border-r border-[var(--border)] transition-[width,transform] duration-200 ease-out"
    style="overflow: visible;">

    <button type="button" @click="collapsed = true" title="Collapse sidebar" x-show="!effectiveCollapsed" x-cloak
        x-transition.opacity
        class="hidden lg:flex absolute top-8 right-2 w-6 h-6 rounded-full bg-[var(--surface-muted)] border border-[var(--border-strong)] shadow-sm items-center justify-center text-[var(--text-secondary)] hover:bg-[var(--brand-tint)] hover:text-[var(--brand)] hover:border-[var(--brand)] transition-colors duration-100"
        style="z-index: 30;">
        <span class="material-symbols-outlined text-[15px]">
            chevron_left
        </span>
    </button>

    <button type="button" @click="sidebarOpen = false" title="Close menu" aria-label="Tutup menu"
        class="lg:hidden absolute top-4 right-2 w-11 h-11 rounded-full bg-[var(--surface-muted)] flex items-center justify-center text-[var(--text-secondary)]"
        style="z-index: 30;">
        <span class="material-symbols-outlined text-[18px]">close</span>
    </button>

    <div class="px-5 py-6 flex items-center overflow-hidden" :class="effectiveCollapsed && 'lg:px-0 lg:justify-center'">
        <img src="{{ asset('images/logo.png') }}" alt="523 Studio" class="h-7 w-auto shrink-0" x-show="!effectiveCollapsed"
            x-cloak x-transition.opacity>
        <img src="{{ asset('images/logo.png') }}" alt="523 Studio" class="h-5 w-auto shrink-0 cursor-pointer hover:opacity-70 transition-opacity"
            x-show="effectiveCollapsed" x-cloak x-transition.opacity @click="collapsed = false" title="Expand sidebar">
    </div>

    <nav class="flex-1 px-3 space-y-4 overflow-y-auto overflow-x-hidden thin-autohide-scrollbar">
        @foreach ($menuGroups as $group)
            <div>
                <p class="px-3 mb-1 text-[10.5px] font-semibold tracking-wide uppercase text-[var(--text-muted)] whitespace-nowrap"
                    x-show="!effectiveCollapsed" x-cloak x-transition.opacity>
                    {{ $group['label'] }}
                </p>
                <div class="space-y-0.5">
                    @foreach ($group['items'] as $item)
                        @php
                            $isActive = Route::has($item['route']) && request()->routeIs($item['route']);
                        @endphp
                        <a href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
                            aria-label="{{ $item['label'] }}"
                            x-data="{ label: {{ Illuminate\Support\Js::from($item['label']) }} }"
                            @mouseenter="{{ $tooltipEnter }}"
                            @mouseleave="tooltip.show = false"
                            class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-[13.5px] font-medium transition-colors duration-100
                                       {{ $isActive ? 'bg-[var(--brand-tint)] text-[var(--brand)]' : 'text-[var(--text-secondary)] hover:bg-[var(--surface-page)] hover:text-[var(--text-primary)]' }}"
                            :class="effectiveCollapsed && 'justify-center px-0'">
                            <span
                                class="material-symbols-outlined text-[19px] shrink-0 {{ $isActive ? 'text-[var(--brand)]' : 'text-[var(--text-muted)]' }}">{{ $item['icon'] }}</span>
                            <span class="whitespace-nowrap" x-show="!effectiveCollapsed" x-cloak
                                x-transition.opacity>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                    @if ($group['label'] === 'Konten' && isset($clientOptions))
                        @include('partials.urgent-content-modal', ['urgentTriggerStyle' => 'sidebar'])
                    @endif
                </div>
            </div>
        @endforeach
    </nav>

    <div class="px-3 pt-4 border-t border-[var(--border)]">
        {{-- Pemilih tema (Tahap C) - segmented control pas sidebar kebuka,
             satu tombol yang gilir 3 pilihan pas sidebar dilipat (76px
             kesempitan buat 3 tombol berdampingan). --}}
        <div class="flex items-center h-9 bg-[var(--surface-muted)] rounded-lg p-1" x-show="!effectiveCollapsed" x-cloak x-transition.opacity>
            <button type="button" @click="setTheme('light')" aria-label="Tema Terang" :aria-pressed="theme === 'light'"
                class="flex-1 flex items-center justify-center h-full rounded-md transition-colors"
                :class="theme === 'light' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'">
                <span class="material-symbols-outlined text-[16px]">light_mode</span>
            </button>
            <button type="button" @click="setTheme('dark')" aria-label="Tema Gelap" :aria-pressed="theme === 'dark'"
                class="flex-1 flex items-center justify-center h-full rounded-md transition-colors"
                :class="theme === 'dark' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'">
                <span class="material-symbols-outlined text-[16px]">dark_mode</span>
            </button>
            <button type="button" @click="setTheme('system')" aria-label="Ikut Sistem" :aria-pressed="theme === 'system'"
                class="flex-1 flex items-center justify-center h-full rounded-md transition-colors"
                :class="theme === 'system' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'">
                <span class="material-symbols-outlined text-[16px]">brightness_auto</span>
            </button>
        </div>

        <button type="button" @click="cycleTheme()" aria-label="Ganti tema" x-show="effectiveCollapsed" x-cloak
            @mouseenter="tooltip = { show: true, text: themeLabels[theme], top: $event.currentTarget.getBoundingClientRect().top + $event.currentTarget.getBoundingClientRect().height / 2, left: $event.currentTarget.getBoundingClientRect().right + 12 }"
            @mouseleave="tooltip.show = false"
            class="w-full flex items-center justify-center py-2 rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-page)] hover:text-[var(--text-primary)] transition-colors duration-100">
            <span class="material-symbols-outlined text-[19px]" x-text="themeIcons[theme]"></span>
        </button>
    </div>

    <div class="px-3 pt-3 pb-3">
        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button type="submit" aria-label="Logout"
                x-data="{ label: 'Keluar' }"
                @mouseenter="{{ $tooltipEnter }}"
                @mouseleave="tooltip.show = false"
                class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-[13.5px] font-medium text-[var(--danger-text)] hover:bg-[var(--danger-tint)] transition-colors duration-100"
                :class="effectiveCollapsed && 'justify-center px-0'">
                <span class="material-symbols-outlined text-[19px] shrink-0">logout</span>
                <span class="whitespace-nowrap" x-show="!effectiveCollapsed" x-cloak x-transition.opacity>Keluar</span>
            </button>
        </form>
    </div>

    <template x-teleport="body">
        <div x-show="tooltip.show" x-cloak x-transition.opacity.duration.100ms
            class="pointer-events-none fixed z-[100] whitespace-nowrap"
            :style="`top: ${tooltip.top}px; left: ${tooltip.left}px; transform: translateY(-50%);`">
            <div class="relative bg-[var(--brand-solid)] text-white text-xs font-medium px-2.5 py-1.5 rounded-md shadow-lg">
                <span x-text="tooltip.text"></span>
                <span class="absolute top-1/2 left-0 -translate-x-full -translate-y-1/2 w-0 h-0 border-y-[5px] border-y-transparent border-r-[6px] border-r-[var(--brand)]"></span>
            </div>
        </div>
    </template>
</aside>
