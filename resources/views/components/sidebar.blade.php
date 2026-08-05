@php
    $menuGroups = [
        [
            'label' => 'Overview',
            'items' => [
                ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'grid_view'],
                ['label' => 'Analytics', 'route' => 'analytics', 'icon' => 'monitoring'],
            ],
        ],
        [
            'label' => 'Content',
            'items' => [
                ['label' => 'Content Plan', 'route' => 'content-plan.index', 'icon' => 'event_note'],
                ['label' => 'Production Workflow', 'route' => 'production-workflow.index', 'icon' => 'folder_open'],
                ['label' => 'Revision Log', 'route' => 'revision-log.index', 'icon' => 'history_edu'],
                ['label' => 'Publishing Tracker', 'route' => 'publishing-tracker.index', 'icon' => 'publish'],
            ],
        ],
        [
            'label' => 'Clients',
            'items' => [
                ['label' => 'Client Onboarding', 'route' => 'client-onboarding.index', 'icon' => 'apartment'],
                ['label' => 'Audience', 'route' => 'audience', 'icon' => 'groups'],
            ],
        ],
        [
            'label' => 'Team',
            'items' => [
                ['label' => 'Team Performance', 'route' => 'team-performance.index', 'icon' => 'diversity_3'],
                ['label' => 'User Management', 'route' => 'user-management.index', 'icon' => 'manage_accounts'],
            ],
        ],
        [
            'label' => 'Reports',
            'items' => [
                ['label' => 'Report', 'route' => 'report.index', 'icon' => 'description'],
            ],
        ],
        [
            'label' => 'System',
            'items' => [
                ['label' => 'Master Data', 'route' => 'master-data.index', 'icon' => 'database'],
                ['label' => 'Settings', 'route' => 'settings', 'icon' => 'tune'],
            ],
        ],
    ];
@endphp

<aside
    x-data="{ collapsed: (localStorage.getItem('sidebar-collapsed') === 'true') }"
    x-init="$watch('collapsed', value => localStorage.setItem('sidebar-collapsed', value))"
    :class="collapsed ? 'w-[76px]' : 'w-60'"
    class="relative shrink-0 bg-white flex flex-col h-screen sticky top-0 border-r border-[#eef0f4] transition-[width] duration-200 ease-out"
    style="overflow: visible;"
>

    <button
        type="button"
        @click="collapsed = !collapsed"
        :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        class="absolute top-8 -right-3 w-6 h-6 rounded-full bg-white border border-[#eef0f4] shadow-sm flex items-center justify-center text-[#9aa0a4] hover:text-[#044b46] hover:border-[#044b46] transition-colors duration-100"
        style="z-index: 30;"
    >
        <span class="material-symbols-outlined text-[15px] transition-transform duration-200" :class="collapsed && 'rotate-180'">
            chevron_left
        </span>
    </button>

    <div class="px-5 py-6 flex items-center gap-2.5 overflow-hidden" :class="collapsed && 'px-0 justify-center'">
        <div class="w-8 h-8 rounded-lg bg-[#044b46] flex items-center justify-center shrink-0">
            <span class="material-symbols-outlined text-white text-[16px]">water_drop</span>
        </div>
        <span class="font-display text-[19px] font-semibold text-[#14181a] whitespace-nowrap" x-show="!collapsed" x-cloak x-transition.opacity>523 Studio</span>
    </div>

    <nav class="flex-1 px-3 space-y-4 overflow-y-auto overflow-x-hidden">
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
                           :title="collapsed ? '{{ $item['label'] }}' : ''"
                           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-[13.5px] font-medium transition-colors duration-100
                               {{ $isActive ? 'bg-[#f0f5f4] text-[#044b46]' : 'text-[#5c6266] hover:bg-[#f7f8fc] hover:text-[#14181a]' }}"
                           :class="collapsed && 'justify-center px-0'">
                            <span class="material-symbols-outlined text-[19px] shrink-0 {{ $isActive ? 'text-[#044b46]' : 'text-[#9aa0a4]' }}">{{ $item['icon'] }}</span>
                            <span class="whitespace-nowrap" x-show="!collapsed" x-cloak x-transition.opacity>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    <div class="px-3 py-4 border-t border-[#eef0f4]">
        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button type="submit"
                    :title="collapsed ? 'Logout' : ''"
                    class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-[13.5px] font-medium text-[#b3423e] hover:bg-[#fdf2f1] transition-colors duration-100"
                    :class="collapsed && 'justify-center px-0'">
                <span class="material-symbols-outlined text-[19px] shrink-0">logout</span>
                <span class="whitespace-nowrap" x-show="!collapsed" x-cloak x-transition.opacity>Logout</span>
            </button>
        </form>
    </div>
</aside>