@php
    $notifications = auth()->user()->notifications()->latest()->take(20)->get();
    $unreadCount = $notifications->where('is_read', false)->count();

    $typeMeta = [
        'mention' => ['icon' => 'alternate_email', 'bg' => 'bg-gray-100', 'color' => 'text-gray-600'],
        'ai_insight' => ['icon' => 'auto_awesome', 'bg' => 'bg-emerald-50', 'color' => 'text-emerald-600'],
        'task' => ['icon' => 'assignment', 'bg' => 'bg-blue-50', 'color' => 'text-blue-600'],
        'system' => ['icon' => 'cloud_done', 'bg' => 'bg-blue-50', 'color' => 'text-blue-600'],
    ];
@endphp

<header class="h-16 bg-white/80 backdrop-blur border-b border-gray-100">
    <div class="h-full flex items-center justify-end px-6">

        {{-- Right side --}}
        <div class="flex items-center gap-4">

            {{-- Notification bell + popup --}}
            <div x-data="{ open: false, tab: 'all' }" class="relative">
                <button @click="open = !open" type="button"
                        class="relative w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-50 transition-colors duration-150">
                    <span class="material-symbols-outlined text-gray-500 text-[20px]">notifications</span>
                    @if ($unreadCount > 0)
                        <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-[#044b46] rounded-full"></span>
                    @endif
                </button>

                <div x-show="open"
                     @click.outside="open = false"
                     x-transition
                     x-cloak
                     class="absolute right-0 mt-2 w-96 bg-[#f8faf8] rounded-2xl shadow-xl border border-gray-100 z-50 overflow-hidden">

                    {{-- Popup header --}}
                    <div class="px-5 pt-5 pb-4">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-xl font-extrabold text-[#191c1c]">Notifications</h3>
                            <div class="flex items-center gap-3">
                                <form action="{{ route('notifications.mark-all-read') }}" method="POST">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold text-[#044b46] hover:underline">
                                        Mark all as read
                                    </button>
                                </form>
                                <button @click="open = false" type="button" class="text-gray-400 hover:text-gray-600">
                                    <span class="material-symbols-outlined text-[20px]">close</span>
                                </button>
                            </div>
                        </div>

                        {{-- Tabs --}}
                        <div class="flex items-center gap-2">
                            @foreach ([
                                'all' => 'All',
                                'task' => 'Tasks',
                                'ai_insight' => 'AI Insights',
                                'mention' => 'Mentions',
                            ] as $key => $label)
                                <button @click="tab = '{{ $key }}'" type="button"
                                        :class="tab === '{{ $key }}' ? 'bg-[#044b46] text-white' : 'bg-white text-gray-500 hover:bg-gray-100'"
                                        class="text-xs font-semibold px-3.5 py-2 rounded-full transition-colors duration-150">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- List --}}
                    <div class="max-h-96 overflow-y-auto px-3 pb-3 space-y-2">
                        @forelse ($notifications as $notif)
                            @php $meta = $typeMeta[$notif->type] ?? $typeMeta['system']; @endphp

                            <div x-show="tab === 'all' || tab === '{{ $notif->type }}'"
                                 class="flex gap-3 bg-white rounded-xl p-4 relative">
                                @if (! $notif->is_read)
                                    <span class="absolute left-0 top-3 bottom-3 w-1 bg-[#044b46] rounded-full"></span>
                                @endif

                                <div class="w-10 h-10 rounded-full {{ $meta['bg'] }} flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined {{ $meta['color'] }} text-[18px]">{{ $meta['icon'] }}</span>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-sm font-bold text-[#191c1c]">{{ $notif->title }}</p>
                                        <span class="text-[10px] text-gray-400 shrink-0 whitespace-nowrap">{{ $notif->created_at->diffForHumans() }}</span>
                                    </div>
                                    @if ($notif->body)
                                        <p class="text-xs text-gray-500 mt-1 line-clamp-2">{{ $notif->body }}</p>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-10">
                                <span class="material-symbols-outlined text-gray-300 text-[32px]">notifications_off</span>
                                <p class="text-sm text-gray-400 mt-2">Belum ada notifikasi.</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <a href="{{ route('profile.me') }}" class="flex items-center gap-3 pl-3 border-l border-gray-100 hover:opacity-80 transition-opacity">
                @if (auth()->user()->avatar_url)
                    <img src="{{ auth()->user()->avatar_url }}" referrerpolicy="no-referrer"
                        class="w-9 h-9 rounded-full object-cover shrink-0">
                @else
                    <div class="w-9 h-9 rounded-full bg-[#044b46] text-white flex items-center justify-center text-sm font-semibold shrink-0">
                        {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
                    </div>
                @endif
                <div class="hidden md:block leading-tight">
                    <p class="text-sm font-semibold text-[#191c1c]">{{ auth()->user()->name ?? 'User' }}</p>
                    <p class="text-xs text-gray-400">{{ auth()->user()->role->name ?? '-' }}</p>
                </div>
            </a>

        </div>
    </div>
</header>