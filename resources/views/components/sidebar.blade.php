@php
    $menuGroups = [
        [
            'label' => 'Overview',
            'items' => [
                ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'grid_view'],
                ['label' => 'Analytics', 'route' => 'analytics', 'icon' => 'monitoring'],
                ['label' => 'AI Advisor', 'route' => 'ai-advisor', 'icon' => 'auto_awesome'],
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

<aside class="w-60 shrink-0 bg-white flex flex-col h-screen sticky top-0 border-r border-[#eef0f4]">

    <div class="px-5 py-6 flex items-center gap-2.5">
        <div class="w-8 h-8 rounded-lg bg-[#044b46] flex items-center justify-center">
            <span class="material-symbols-outlined text-white text-[16px]">water_drop</span>
        </div>
        <span class="font-display text-[19px] font-semibold text-[#14181a]">523 Studio</span>
    </div>

    <nav class="flex-1 px-3 space-y-4 overflow-y-auto">
        @foreach ($menuGroups as $group)
            <div>
                <p class="px-3 mb-1 text-[10.5px] font-semibold tracking-wide uppercase text-[#9aa0a4]">
                    {{ $group['label'] }}
                </p>
                <div class="space-y-0.5">
                    @foreach ($group['items'] as $item)
                        @php $isActive = Route::has($item['route']) && request()->routeIs($item['route']); @endphp
                        <a href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
                           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-[13.5px] font-medium transition-colors duration-100
                               {{ $isActive ? 'bg-[#f0f5f4] text-[#044b46]' : 'text-[#5c6266] hover:bg-[#f7f8fc] hover:text-[#14181a]' }}">
                            <span class="material-symbols-outlined text-[19px] {{ $isActive ? 'text-[#044b46]' : 'text-[#9aa0a4]' }}">{{ $item['icon'] }}</span>
                            {{ $item['label'] }}
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
                    class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-[13.5px] font-medium text-[#b3423e] hover:bg-[#fdf2f1] transition-colors duration-100">
                <span class="material-symbols-outlined text-[19px]">logout</span>
                Logout
            </button>
        </form>
    </div>
</aside>