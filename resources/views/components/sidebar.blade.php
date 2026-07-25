@php
    $menuItems = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'dashboard'],
        ['label' => 'Projects', 'route' => 'production-workflow.index', 'icon' => 'folder'],
        ['label' => 'Analytics', 'route' => 'analytics', 'icon' => 'bar_chart'],
        ['label' => 'AI Advisor', 'route' => 'ai-advisor', 'icon' => 'insights'],
        ['label' => 'Client', 'route' => 'client-onboarding.index', 'icon' => 'group'],
        ['label' => 'Team', 'route' => 'team-performance.index', 'icon' => 'groups'],
        ['label' => 'Settings', 'route' => 'settings', 'icon' => 'settings'],
    ];
@endphp

<aside class="w-[260px] shrink-0 bg-white flex flex-col h-screen sticky top-0 border-r border-gray-100">

    {{-- Brand --}}
    <div class="px-6 py-6 flex items-center gap-2">
        <span class="material-symbols-outlined text-[#044b46] text-[26px]">water_drop</span>
        <span class="text-lg font-extrabold text-[#191c1c]">523 Studio</span>
    </div>

    {{-- Menu --}}
    <nav class="flex-1 px-4 space-y-1">
        @foreach ($menuItems as $item)
            @php
                $isActive = Route::has($item['route']) && request()->routeIs($item['route']);
            @endphp

            
                <a href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
                class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold transition-colors duration-150
                    {{ $isActive
                        ? 'bg-gray-100 text-[#191c1c]'
                        : 'text-gray-500 hover:bg-gray-50 hover:text-[#191c1c]' }}"
            >
                <span class="material-symbols-outlined text-[20px]">{{ $item['icon'] }}</span>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    {{-- Logout --}}
    <div class="px-4 py-5 border-t border-gray-100">
        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button type="submit"
                    class="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl text-sm font-semibold text-rose-600 bg-rose-50 hover:bg-rose-100 transition-colors duration-150">
                <span class="material-symbols-outlined text-[20px]">logout</span>
                Logout
            </button>
        </form>
    </div>
</aside>