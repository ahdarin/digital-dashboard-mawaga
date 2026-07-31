@php
    $menuItems = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'dashboard'],
        ['label' => 'Projects', 'route' => 'production-workflow.index', 'icon' => 'folder'],
        ['label' => 'Publishing Tracker', 'route' => 'publishing-tracker.index', 'icon' => 'campaign'],
        ['label' => 'Revision Log', 'route' => 'revision-log.index', 'icon' => 'rate_review'],
        ['label' => 'Analytics', 'route' => 'analytics', 'icon' => 'bar_chart'],
        ['label' => 'AI Advisor', 'route' => 'ai-advisor', 'icon' => 'insights'],
        ['label' => 'Client', 'route' => 'client-onboarding.index', 'icon' => 'group'],
        ['label' => 'Team', 'route' => 'team-performance.index', 'icon' => 'groups'],
        ['label' => 'Report', 'route' => 'report.index', 'icon' => 'description'],
        ['label' => 'Settings', 'route' => 'settings', 'icon' => 'settings'],
    ];
@endphp

<aside class="w-[260px] shrink-0 bg-white flex flex-col h-screen sticky top-0 border-r border-gray-100">

    {{-- Brand --}}
    <div class="px-6 py-6 flex items-center gap-2.5">
        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#044b46] to-[#0a8f76] flex items-center justify-center shadow-[0_4px_14px_rgba(4,75,70,0.35)]">
            <span class="material-symbols-outlined text-white text-[18px]">water_drop</span>
        </div>
        <span class="text-lg font-extrabold text-[#191c1c]">523 Studio</span>
    </div>

    {{-- Menu --}}
    <nav class="flex-1 px-4 space-y-1 overflow-y-auto">
        @foreach ($menuItems as $item)
            @php
                $isActive = Route::has($item['route']) && request()->routeIs($item['route']);
            @endphp

            <a href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
                class="relative flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold transition-all duration-150
                    {{ $isActive
                        ? 'bg-gradient-to-r from-[#044b46] to-[#0a6b5c] text-white shadow-[0_6px_16px_rgba(4,75,70,0.28)]'
                        : 'text-gray-500 hover:bg-[#f4f9f7] hover:text-[#044b46]' }}"
            >
                <span class="material-symbols-outlined text-[20px]">{{ $item['icon'] }}</span>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach

        @if (auth()->user()->hasPermissionTo('user_management', 'manage'))
            <a href="{{ route('user-management.index') }}"
                class="relative flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold transition-all duration-150
                    {{ request()->routeIs('user-management.*') || request()->routeIs('user-client-assignment.*')
                        ? 'bg-gradient-to-r from-[#044b46] to-[#0a6b5c] text-white shadow-[0_6px_16px_rgba(4,75,70,0.28)]'
                        : 'text-gray-500 hover:bg-[#f4f9f7] hover:text-[#044b46]' }}"
            >
                <span class="material-symbols-outlined text-[20px]">manage_accounts</span>
                <span>User Management</span>
            </a>
        @endif
    </nav>

    {{-- Logout --}}
    <div class="px-4 py-5 border-t border-gray-100">
        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button type="submit"
                    class="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl text-sm font-semibold text-rose-600 bg-gradient-to-r from-rose-50 to-rose-100/60 hover:from-rose-100 hover:to-rose-100 transition-colors duration-150 shadow-[0_2px_8px_rgba(244,63,94,0.12)]">
                <span class="material-symbols-outlined text-[20px]">logout</span>
                Logout
            </button>
        </form>
    </div>
</aside>