@php
    $notifications = auth()->user()->notifications()->latest()->take(20)->get();
    $unreadCount = $notifications->where('is_read', false)->count();

    $typeMeta = [
        'ai_insight' => ['icon' => 'auto_awesome', 'bg' => 'bg-[#f0f5f4]', 'color' => 'text-[#044b46]'],
        'task' => ['icon' => 'assignment', 'bg' => 'bg-[#eef2fb]', 'color' => 'text-[#3452a8]'],
        'system' => ['icon' => 'cloud_done', 'bg' => 'bg-[#eef2fb]', 'color' => 'text-[#3452a8]'],
        'plan_submitted' => ['icon' => 'fact_check', 'bg' => 'bg-[#eef2fb]', 'color' => 'text-[#3452a8]'],
        'client_approved' => ['icon' => 'thumb_up', 'bg' => 'bg-[#f0f5f4]', 'color' => 'text-[#044b46]'],
        'deadline_reminder' => ['icon' => 'schedule', 'bg' => 'bg-[#eef2fb]', 'color' => 'text-[#3452a8]'],
        'overdue_reminder' => ['icon' => 'warning', 'bg' => 'bg-[#fdf2f1]', 'color' => 'text-[#b3423e]'],
        'delay_risk_alert' => ['icon' => 'report', 'bg' => 'bg-[#fdf2f1]', 'color' => 'text-[#b3423e]'],
    ];

    $tabs = ['all' => 'All', 'delay_risk_alert' => 'Risk Alerts', 'task' => 'Tasks', 'ai_insight' => 'AI Insights'];

    $tabCounts = collect($tabs)->mapWithKeys(function ($label, $key) use ($notifications) {
        $scoped = $key === 'all' ? $notifications : $notifications->where('type', $key);
        return [$key => ['total' => $scoped->count(), 'unread' => $scoped->where('is_read', false)->count()]];
    });
@endphp

<header class="h-16 bg-white border-b border-[#eef0f4]">
    <div class="h-full flex items-center justify-between px-4 sm:px-6 gap-3 sm:gap-6">

        <button type="button" @click="sidebarOpen = true" title="Buka menu"
            class="lg:hidden shrink-0 w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[#f7f8fc] transition-colors">
            <span class="material-symbols-outlined text-[#5c6266] text-[22px]">menu</span>
        </button>

        <div x-data="topbarSearch()" @click.outside="open = false" class="relative flex-1 min-w-0 max-w-md">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#9aa0a4] text-[19px]">search</span>
            <input type="text" x-model="query" @input.debounce.300ms="search()" @focus="query.length >= 2 && (open = true)"
                   @keydown.escape="open = false"
                   placeholder="Cari proyek, konten, atau client..." autocomplete="off"
                   class="w-full pl-10 pr-4 py-2 text-sm bg-[#f7f8fc] border border-transparent rounded-lg focus:outline-none focus:border-[#044b46]/30 focus:bg-white transition-colors">

            <div x-show="open" x-cloak x-transition
                 class="absolute left-0 right-0 mt-2 card z-50 max-h-[26rem] overflow-y-auto">
                <template x-if="loading">
                    <div class="p-4 text-center text-sm text-[#9aa0a4]">Mencari...</div>
                </template>
                <template x-if="!loading && results.length === 0">
                    <div class="p-4 text-center text-sm text-[#9aa0a4]">Tidak ada hasil untuk "<span x-text="query"></span>"</div>
                </template>
                <template x-if="!loading && results.length > 0">
                    <div class="py-1.5">
                        <template x-for="result in results" :key="result.type + '-' + result.id">
                            <a :href="result.url"
                               class="flex items-center gap-3 px-4 py-2.5 hover:bg-[#f7f8fc] transition-colors">
                                <span class="material-symbols-outlined text-[18px] shrink-0" :class="categoryIconClass(result.type)" x-text="categoryIcon(result.type)"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-[#14181a] truncate" x-text="result.title"></p>
                                    <div class="flex items-center gap-1.5 mt-1">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded" :class="categoryBadgeClass(result.type)" x-text="categoryLabel(result.type)"></span>
                                        <span x-show="result.subtitle" class="text-[11px] text-[#9aa0a4] truncate" x-text="result.subtitle"></span>
                                    </div>
                                </div>
                            </a>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        <div class="flex items-center gap-3 shrink-0">

            <div x-data="{ open: false, tab: 'all', tabCounts: {{ Illuminate\Support\Js::from($tabCounts) }} }" class="relative">
                <button @click="open = !open" type="button"
                        class="relative w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[#f7f8fc] transition-colors">
                    <span class="material-symbols-outlined text-[#5c6266] text-[20px]">notifications</span>
                    @if ($unreadCount > 0)
                        <span class="absolute -top-1 -right-1 min-w-[16px] h-4 px-1 bg-[#b3423e] text-white text-[10px] font-semibold rounded-full flex items-center justify-center">
                            {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                        </span>
                    @endif
                </button>

                <div x-show="open" @click.outside="open = false" x-transition x-cloak
                     class="absolute right-0 mt-2 w-[min(24rem,calc(100vw-2rem))] card z-50 overflow-hidden">
                    <div class="px-5 pt-5 pb-4">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-display text-lg font-semibold text-[#14181a] leading-none">Notifications</h3>
                            <div class="flex items-center gap-3">
                                @if ($unreadCount > 0)
                                    <form action="{{ route('notifications.mark-all-read') }}" method="POST" class="leading-none">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="text-xs font-medium text-[#044b46] hover:underline leading-none">Tandai semua dibaca</button>
                                    </form>
                                @endif
                                <button @click="open = false" type="button" class="flex items-center text-[#9aa0a4] hover:text-[#5c6266]">
                                    <span class="material-symbols-outlined text-[18px]">close</span>
                                </button>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 overflow-x-auto thin-autohide-scrollbar -mx-1 px-1">
                            @foreach ($tabs as $key => $label)
                                <button @click="tab = '{{ $key }}'" type="button"
                                        :class="tab === '{{ $key }}' ? 'bg-[#044b46] text-white' : 'bg-[#f7f8fc] text-[#5c6266] hover:bg-[#eef0f4]'"
                                        class="text-xs font-medium px-3 py-1.5 rounded-full transition-colors flex items-center gap-1.5 shrink-0 whitespace-nowrap">
                                    {{ $label }}
                                    @if ($tabCounts[$key]['unread'] > 0)
                                        <span :class="tab === '{{ $key }}' ? 'bg-white/25 text-white' : 'bg-[#eef0f4] text-[#5c6266]'"
                                              class="text-[10px] font-semibold px-1.5 rounded-full">{{ $tabCounts[$key]['unread'] }}</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="max-h-96 overflow-y-auto px-3 pb-3 space-y-1">
                        @forelse ($notifications as $notif)
                            @php $meta = $typeMeta[$notif->type] ?? $typeMeta['system']; @endphp
                            <form method="POST" action="{{ route('notifications.read', $notif->id) }}" x-show="tab === 'all' || tab === '{{ $notif->type }}'">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="w-full flex gap-3 rounded-lg p-3 hover:bg-[#f7f8fc] relative text-left">
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
                                </button>
                            </form>
                        @empty
                            <div class="text-center py-10">
                                <span class="material-symbols-outlined text-[#d4d7db] text-[28px]">notifications_off</span>
                                <p class="text-sm text-[#9aa0a4] mt-2">Belum ada notifikasi.</p>
                            </div>
                        @endforelse

                        @if ($notifications->isNotEmpty())
                            <div x-show="tabCounts[tab].total === 0" x-cloak class="text-center py-10">
                                <span class="material-symbols-outlined text-[#d4d7db] text-[28px]">filter_alt_off</span>
                                <p class="text-sm text-[#9aa0a4] mt-2">Tidak ada notifikasi di kategori ini.</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <a href="{{ route('profile.show', auth()->id()) }}" class="flex items-center gap-2.5 pl-3 border-l border-[#eef0f4]">
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

<script>
    function topbarSearch() {
        return {
            query: '',
            results: [],
            loading: false,
            open: false,
            requestId: 0,
            search() {
                if (this.query.trim().length < 2) {
                    this.results = [];
                    this.open = false;
                    return;
                }
                this.open = true;
                this.loading = true;
                const currentRequest = ++this.requestId;
                fetch(`{{ route('search') }}?q=${encodeURIComponent(this.query)}`)
                    .then((res) => res.json())
                    .then((data) => {
                        if (currentRequest !== this.requestId) return;
                        this.results = data;
                        this.loading = false;
                    })
                    .catch(() => {
                        if (currentRequest !== this.requestId) return;
                        this.results = [];
                        this.loading = false;
                    });
            },
            categoryLabel(type) {
                return { client: 'Client', user: 'User', content: 'Konten' }[type] ?? type;
            },
            categoryIcon(type) {
                return { client: 'apartment', user: 'person', content: 'description' }[type] ?? 'circle';
            },
            categoryIconClass(type) {
                return {
                    client: 'text-[#3452a8]',
                    user: 'text-[#8a6423]',
                    content: 'text-[#044b46]',
                }[type] ?? 'text-[#9aa0a4]';
            },
            categoryBadgeClass(type) {
                return {
                    client: 'bg-[#eef2fb] text-[#3452a8]',
                    user: 'bg-[#fdf6ec] text-[#8a6423]',
                    content: 'bg-[#f0f5f4] text-[#044b46]',
                }[type] ?? 'bg-[#f2f3f6] text-[#5c6266]';
            },
        }
    }
</script>
