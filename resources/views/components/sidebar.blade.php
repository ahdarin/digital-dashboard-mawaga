@php
    $menuGroups = [
        [
            'label' => 'Overview',
            'items' => [
                ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'grid_view', 'permission' => ['dashboard', 'view']],
                ['label' => 'Analytics', 'route' => 'analytics', 'icon' => 'monitoring', 'permission' => ['analytics', 'view']],
            ],
        ],
        [
            'label' => 'Content',
            'items' => [
                ['label' => 'Content Plan', 'route' => 'content-plan.index', 'icon' => 'event_note', 'permission' => ['content_plan', 'view']],
                ['label' => 'Production Workflow', 'route' => 'production-workflow.index', 'icon' => 'folder_open', 'permission' => ['workflow', 'view']],
                ['label' => 'Revision Log', 'route' => 'revision-log.index', 'icon' => 'history_edu', 'permission' => ['workflow', 'view']],
                ['label' => 'Publishing Tracker', 'route' => 'publishing-tracker.index', 'icon' => 'publish', 'permission' => ['workflow', 'view']],
            ],
        ],
        [
            'label' => 'Clients',
            'items' => [
                ['label' => 'Client Management', 'route' => 'client-management.index', 'icon' => 'apartment', 'permission' => ['client', 'manage']],
            ],
        ],
        [
            'label' => 'Team',
            'items' => [
                ['label' => 'Team Performance', 'route' => 'team-performance.index', 'icon' => 'diversity_3', 'permission' => ['team_performance', 'view']],
                ['label' => 'User Management', 'route' => 'user-management.index', 'icon' => 'manage_accounts', 'permission' => ['user_management', 'manage']],
            ],
        ],
        [
            'label' => 'Reports',
            'items' => [
                ['label' => 'Report', 'route' => 'report.index', 'icon' => 'description', 'permission' => ['report', 'view']],
            ],
        ],
        [
            'label' => 'System',
            'items' => [
                ['label' => 'Master Data', 'route' => 'master-data.index', 'icon' => 'database', 'permission' => ['master_data', 'manage']],
                ['label' => 'Settings', 'route' => 'settings', 'icon' => 'tune', 'permission' => ['settings', 'manage']],
            ],
        ],
    ];

    $authUser = auth()->user();
    $menuGroups = collect($menuGroups)
        ->map(function ($group) use ($authUser) {
            $group['items'] = array_values(array_filter(
                $group['items'],
                fn ($item) => $authUser->hasPermissionTo(...$item['permission'])
            ));
            return $group;
        })
        ->filter(fn ($group) => count($group['items']) > 0)
        ->values()
        ->all();

    $tooltipEnter = <<<'JS'
        collapsed && (tooltip = {
            show: true,
            text: label,
            top: $event.currentTarget.getBoundingClientRect().top + $event.currentTarget.getBoundingClientRect().height / 2,
            left: $event.currentTarget.getBoundingClientRect().right + 12,
        })
    JS;
@endphp

<aside x-data="{ collapsed: (localStorage.getItem('sidebar-collapsed') === 'true'), tooltip: { show: false, text: '', top: 0, left: 0 } }"
    x-init="$watch('collapsed', value => { localStorage.setItem('sidebar-collapsed', value); if (!value) tooltip.show = false })"
    :class="collapsed ? 'w-[76px]' : 'w-60'"
    class="relative shrink-0 bg-white flex flex-col h-screen sticky top-0 border-r border-[#eef0f4] transition-[width] duration-200 ease-out"
    style="overflow: visible;">

    <button type="button" @click="collapsed = true" title="Collapse sidebar" x-show="!collapsed" x-cloak
        x-transition.opacity
        class="absolute top-8 right-2 w-6 h-6 rounded-full bg-[#f2f3f6] border border-[#dadfe0] shadow-sm flex items-center justify-center text-[#5c6266] hover:bg-[#f0f5f4] hover:text-[#044b46] hover:border-[#044b46] transition-colors duration-100"
        style="z-index: 30;">
        <span class="material-symbols-outlined text-[15px]">
            chevron_left
        </span>
    </button>

    <div class="px-5 py-6 flex items-center overflow-hidden" :class="collapsed && 'px-0 justify-center'">
        <img src="{{ asset('images/logo.png') }}" alt="523 Studio" class="h-7 w-auto shrink-0" x-show="!collapsed"
            x-cloak x-transition.opacity>
        <img src="{{ asset('images/logo.png') }}" alt="523 Studio" class="h-5 w-auto shrink-0 cursor-pointer hover:opacity-70 transition-opacity"
            x-show="collapsed" x-cloak x-transition.opacity @click="collapsed = false" title="Expand sidebar">
    </div>

    <nav class="flex-1 px-3 space-y-4 overflow-y-auto overflow-x-hidden thin-autohide-scrollbar">
        @foreach ($menuGroups as $group)
            <div>
                <p class="px-3 mb-1 text-[10.5px] font-semibold tracking-wide uppercase text-[#9aa0a4] whitespace-nowrap"
                    x-show="!collapsed" x-cloak x-transition.opacity>
                    {{ $group['label'] }}
                </p>
                <div class="space-y-0.5">
                    @foreach ($group['items'] as $item)
                        @php $isActive = Route::has($item['route']) && request()->routeIs($item['route']); @endphp
                        <a href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
                            aria-label="{{ $item['label'] }}"
                            x-data="{ label: {{ Illuminate\Support\Js::from($item['label']) }} }"
                            @mouseenter="{{ $tooltipEnter }}"
                            @mouseleave="tooltip.show = false"
                            class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-[13.5px] font-medium transition-colors duration-100
                                       {{ $isActive ? 'bg-[#f0f5f4] text-[#044b46]' : 'text-[#5c6266] hover:bg-[#f7f8fc] hover:text-[#14181a]' }}"
                            :class="collapsed && 'justify-center px-0'">
                            <span
                                class="material-symbols-outlined text-[19px] shrink-0 {{ $isActive ? 'text-[#044b46]' : 'text-[#9aa0a4]' }}">{{ $item['icon'] }}</span>
                            <span class="whitespace-nowrap" x-show="!collapsed" x-cloak
                                x-transition.opacity>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    <div class="px-3 pt-6 pb-3 border-t border-[#eef0f4]">
        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button type="submit" aria-label="Logout"
                x-data="{ label: 'Logout' }"
                @mouseenter="{{ $tooltipEnter }}"
                @mouseleave="tooltip.show = false"
                class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-[13.5px] font-medium text-[#b3423e] hover:bg-[#fdf2f1] transition-colors duration-100"
                :class="collapsed && 'justify-center px-0'">
                <span class="material-symbols-outlined text-[19px] shrink-0">logout</span>
                <span class="whitespace-nowrap" x-show="!collapsed" x-cloak x-transition.opacity>Logout</span>
            </button>
        </form>
    </div>

    <template x-teleport="body">
        <div x-show="tooltip.show" x-cloak x-transition.opacity.duration.100ms
            class="pointer-events-none fixed z-[100] whitespace-nowrap"
            :style="`top: ${tooltip.top}px; left: ${tooltip.left}px; transform: translateY(-50%);`">
            <div class="relative bg-[#044b46] text-white text-xs font-medium px-2.5 py-1.5 rounded-md shadow-lg">
                <span x-text="tooltip.text"></span>
                <span class="absolute top-1/2 left-0 -translate-x-full -translate-y-1/2 w-0 h-0 border-y-[5px] border-y-transparent border-r-[6px] border-r-[#044b46]"></span>
            </div>
        </div>
    </template>
</aside>
