@php
    $notifications = auth()->user()->notifications()->latest()->take(20)->get();
    $unreadCount = $notifications->where('is_read', false)->count();

    $typeMeta = [
        'mention' => ['icon' => 'alternate_email', 'bg' => 'bg-[#f0f5f4]', 'color' => 'text-[#044b46]'],
        'ai_insight' => ['icon' => 'auto_awesome', 'bg' => 'bg-[#f0f5f4]', 'color' => 'text-[#044b46]'],
        'task' => ['icon' => 'assignment', 'bg' => 'bg-[#eef2fb]', 'color' => 'text-[#3452a8]'],
        'system' => ['icon' => 'cloud_done', 'bg' => 'bg-[#eef2fb]', 'color' => 'text-[#3452a8]'],
        'deadline_reminder' => ['icon' => 'schedule', 'bg' => 'bg-[#eef2fb]', 'color' => 'text-[#3452a8]'],
        'overdue_reminder' => ['icon' => 'warning', 'bg' => 'bg-[#fdf2f1]', 'color' => 'text-[#b3423e]'],
        'delay_risk_alert' => ['icon' => 'report', 'bg' => 'bg-[#fdf2f1]', 'color' => 'text-[#b3423e]'],
    ];
@endphp

<header class="h-16 bg-white border-b border-[#eef0f4]">
    <div class="h-full flex items-center justify-between px-6 gap-6">

        <div class="relative flex-1 max-w-md">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#9aa0a4] text-[19px]">search</span>
            <input type="text" placeholder="Cari proyek, konten, atau client..."
                   class="w-full pl-10 pr-4 py-2 text-sm bg-[#f7f8fc] border border-transparent rounded-lg focus:outline-none focus:border-[#044b46]/30 focus:bg-white transition-colors">
        </div>

        <div class="flex items-center gap-3 shrink-0">

            <div x-data="{ open: false, tab: 'all' }" class="relative">
                <button @click="open = !open" type="button"
                        class="relative w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[#f7f8fc] transition-colors">
                    <span class="material-symbols-outlined text-[#5c6266] text-[20px]">notifications</span>
                    @if ($unreadCount > 0)
                        <span class="absolute top-1.5 right-1.5 w-1.5 h-1.5 bg-[#b3423e] rounded-full"></span>
                    @endif
                </button>

                <div x-show="open" @click.outside="open = false" x-transition x-cloak
                     class="absolute right-0 mt-2 w-96 card z-50 overflow-hidden">
                    <div class="px-5 pt-5 pb-4">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-display text-lg font-semibold text-[#14181a]">Notifications</h3>
                            <div class="flex items-center gap-3">
                                <form action="{{ route('notifications.mark-all-read') }}" method="POST">
                                    @csrf
                                    <button type="submit" class="text-xs font-medium text-[#044b46] hover:underline">Tandai semua dibaca</button>
                                </form>
                                <button @click="open = false" type="button" class="text-[#9aa0a4] hover:text-[#5c6266]">
                                    <span class="material-symbols-outlined text-[18px]">close</span>
                                </button>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5">
                            @foreach (['all' => 'All', 'delay_risk_alert' => 'Risk Alerts', 'task' => 'Tasks', 'ai_insight' => 'AI Insights', 'mention' => 'Mentions'] as $key => $label)
                                <button @click="tab = '{{ $key }}'" type="button"
                                        :class="tab === '{{ $key }}' ? 'bg-[#044b46] text-white' : 'bg-[#f7f8fc] text-[#5c6266] hover:bg-[#eef0f4]'"
                                        class="text-xs font-medium px-3 py-1.5 rounded-full transition-colors">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="max-h-96 overflow-y-auto px-3 pb-3 space-y-1">
                        @forelse ($notifications as $notif)
                            @php $meta = $typeMeta[$notif->type] ?? $typeMeta['system']; @endphp
                            <div x-show="tab === 'all' || tab === '{{ $notif->type }}'"
                                 class="flex gap-3 rounded-lg p-3 hover:bg-[#f7f8fc] relative">
                                @if (! $notif->is_read)
                                    <span class="absolute left-0 top-3 bottom-3 w-0.5 bg-[#044b46] rounded-full"></span>
                                @endif
                                <div class="w-8 h-8 rounded-full {{ $meta['bg'] }} flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined {{ $meta['color'] }} text-[16px]">{{ $meta['icon'] }}</span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-sm font-medium text-[#14181a]">{{ $notif->title }}</p>
                                        <span class="text-[10px] text-[#9aa0a4] shrink-0 whitespace-nowrap">{{ $notif->created_at->diffForHumans() }}</span>
                                    </div>
                                    @if ($notif->body)
                                        <p class="text-xs text-[#5c6266] mt-0.5 line-clamp-2">{{ $notif->body }}</p>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-10">
                                <span class="material-symbols-outlined text-[#d4d7db] text-[28px]">notifications_off</span>
                                <p class="text-sm text-[#9aa0a4] mt-2">Belum ada notifikasi.</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <a href="{{ route('profile.me') }}" class="flex items-center gap-2.5 pl-3 border-l border-[#eef0f4]">
                @if (auth()->user()->avatar_url)
                    <img src="{{ auth()->user()->avatar_url }}" referrerpolicy="no-referrer" class="w-8 h-8 rounded-full object-cover">
                @else
                    <div class="w-8 h-8 rounded-full bg-[#044b46] text-white flex items-center justify-center text-xs font-semibold">
                        {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
                    </div>
                @endif
                <div class="hidden md:block leading-tight">
                    <p class="text-[13px] font-medium text-[#14181a]">{{ auth()->user()->name ?? 'User' }}</p>
                    <p class="text-[11px] text-[#9aa0a4]">{{ auth()->user()->role->name ?? '-' }}</p>
                </div>
            </a>
        </div>
    </div>
</header>