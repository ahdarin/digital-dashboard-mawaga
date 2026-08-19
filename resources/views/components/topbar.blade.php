@php
    $notifications = auth()->user()->notifications()->latest()->take(20)->get();
    $unreadCount = $notifications->where('is_read', false)->count();

    $typeMeta = [
        'ai_insight' => ['icon' => 'auto_awesome', 'bg' => 'bg-[var(--brand-tint)]', 'color' => 'text-[var(--brand)]'],
        'task' => ['icon' => 'assignment', 'bg' => 'bg-[var(--info-tint)]', 'color' => 'text-[var(--info-text)]'],
        'system' => ['icon' => 'cloud_done', 'bg' => 'bg-[var(--info-tint)]', 'color' => 'text-[var(--info-text)]'],
        'plan_submitted' => ['icon' => 'fact_check', 'bg' => 'bg-[var(--info-tint)]', 'color' => 'text-[var(--info-text)]'],
        'client_approved' => ['icon' => 'thumb_up', 'bg' => 'bg-[var(--brand-tint)]', 'color' => 'text-[var(--brand)]'],
        'deadline_reminder' => ['icon' => 'schedule', 'bg' => 'bg-[var(--info-tint)]', 'color' => 'text-[var(--info-text)]'],
        'overdue_reminder' => ['icon' => 'warning', 'bg' => 'bg-[var(--danger-tint)]', 'color' => 'text-[var(--danger-text)]'],
        'delay_risk_alert' => ['icon' => 'report', 'bg' => 'bg-[var(--danger-tint)]', 'color' => 'text-[var(--danger-text)]'],
    ];

    $tabs = ['all' => 'All', 'delay_risk_alert' => 'Risk Alerts', 'task' => 'Tasks', 'ai_insight' => 'AI Insights'];

    $tabCounts = collect($tabs)->mapWithKeys(function ($label, $key) use ($notifications) {
        $scoped = $key === 'all' ? $notifications : $notifications->where('type', $key);
        return [$key => ['total' => $scoped->count(), 'unread' => $scoped->where('is_read', false)->count()]];
    });
@endphp

<header class="h-16 bg-[var(--surface-card)] border-b border-[var(--border)]">
    <div class="h-full flex items-center justify-between px-4 sm:px-6 gap-3 sm:gap-6">

        <button type="button" @click="sidebarOpen = true" title="Buka menu"
            class="lg:hidden shrink-0 w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[var(--surface-page)] transition-colors">
            <span class="material-symbols-outlined text-[var(--text-secondary)] text-[22px]">menu</span>
        </button>

        <div x-data="topbarSearch()" @click.outside="open = false" class="relative flex-1 min-w-0 max-w-md">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[19px]">search</span>
            <input type="text" x-model="query" @input.debounce.300ms="search()" @focus="query.length >= 2 && (open = true)"
                   @keydown.escape="open = false"
                   placeholder="Cari proyek, konten, atau client..." autocomplete="off"
                   class="w-full pl-10 pr-4 py-2 text-sm bg-[var(--surface-page)] border border-transparent rounded-lg focus:outline-none focus:border-[#044b46]/30 focus:bg-[var(--surface-card)] transition-colors">

            <div x-show="open" x-cloak x-transition
                 class="absolute left-0 right-0 mt-2 card z-50 max-h-[26rem] overflow-y-auto">
                <template x-if="loading">
                    <div class="p-4 text-center text-sm text-[var(--text-muted)]">Mencari...</div>
                </template>
                <template x-if="!loading && results.length === 0">
                    <div class="p-4 text-center text-sm text-[var(--text-muted)]">Tidak ada hasil untuk "<span x-text="query"></span>"</div>
                </template>
                <template x-if="!loading && results.length > 0">
                    <div class="py-1.5">
                        <template x-for="result in results" :key="result.type + '-' + result.id">
                            <a :href="result.url"
                               class="flex items-center gap-3 px-4 py-2.5 hover:bg-[var(--surface-page)] transition-colors">
                                <span class="material-symbols-outlined text-[18px] shrink-0" :class="categoryIconClass(result.type)" x-text="categoryIcon(result.type)"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-[var(--text-primary)] truncate" x-text="result.title"></p>
                                    <div class="flex items-center gap-1.5 mt-1">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded" :class="categoryBadgeClass(result.type)" x-text="categoryLabel(result.type)"></span>
                                        <span x-show="result.subtitle" class="text-[11px] text-[var(--text-muted)] truncate" x-text="result.subtitle"></span>
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
                        class="relative w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[var(--surface-page)] transition-colors">
                    <span class="material-symbols-outlined text-[var(--text-secondary)] text-[20px]">notifications</span>
                    @if ($unreadCount > 0)
                        <span class="absolute -top-1 -right-1 min-w-[16px] h-4 px-1 bg-[var(--danger-text)] text-white text-[10px] font-semibold rounded-full flex items-center justify-center">
                            {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                        </span>
                    @endif
                </button>

                <div x-show="open" @click.outside="open = false" x-transition x-cloak
                     class="absolute right-0 mt-2 w-[min(24rem,calc(100vw-2rem))] card z-50 overflow-hidden">
                    <div class="px-5 pt-5 pb-4">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-display text-lg font-semibold text-[var(--text-primary)] leading-none">Notifications</h3>
                            <div class="flex items-center gap-3">
                                @if ($unreadCount > 0)
                                    <form action="{{ route('notifications.mark-all-read') }}" method="POST" class="leading-none">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="text-xs font-medium text-[var(--brand)] hover:underline leading-none">Tandai semua dibaca</button>
                                    </form>
                                @endif
                                <button @click="open = false" type="button" class="flex items-center text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                                    <span class="material-symbols-outlined text-[18px]">close</span>
                                </button>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 overflow-x-auto thin-autohide-scrollbar -mx-1 px-1">
                            @foreach ($tabs as $key => $label)
                                <button @click="tab = '{{ $key }}'" type="button"
                                        :class="tab === '{{ $key }}' ? 'bg-[var(--brand)] text-white' : 'bg-[var(--surface-page)] text-[var(--text-secondary)] hover:bg-[var(--border)]'"
                                        class="text-xs font-medium px-3 py-1.5 rounded-full transition-colors flex items-center gap-1.5 shrink-0 whitespace-nowrap">
                                    {{ $label }}
                                    @if ($tabCounts[$key]['unread'] > 0)
                                        <span :class="tab === '{{ $key }}' ? 'bg-white/25 text-white' : 'bg-[var(--border)] text-[var(--text-secondary)]'"
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
                                <button type="submit" class="w-full flex gap-3 rounded-lg p-3 hover:bg-[var(--surface-page)] relative text-left">
                                    <div class="relative shrink-0">
                                        <div class="w-8 h-8 rounded-full {{ $meta['bg'] }} flex items-center justify-center">
                                            <span class="material-symbols-outlined {{ $meta['color'] }} text-[16px]">{{ $meta['icon'] }}</span>
                                        </div>
                                        @if (! $notif->is_read)
                                            <span class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-[var(--danger-text)] border-2 border-[var(--surface-card)] rounded-full"></span>
                                        @endif
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="text-sm font-medium text-[var(--text-primary)]">{{ $notif->title }}</p>
                                            <span class="text-[10px] text-[var(--text-muted)] shrink-0 whitespace-nowrap">{{ $notif->created_at->diffForHumans() }}</span>
                                        </div>
                                        @if ($notif->body)
                                            <p class="text-xs text-[var(--text-secondary)] mt-0.5 line-clamp-2">{{ $notif->body }}</p>
                                        @endif
                                    </div>
                                </button>
                            </form>
                        @empty
                            <div class="text-center py-10">
                                <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px]">notifications_off</span>
                                <p class="text-sm text-[var(--text-muted)] mt-2">Belum ada notifikasi.</p>
                            </div>
                        @endforelse

                        @if ($notifications->isNotEmpty())
                            <div x-show="tabCounts[tab].total === 0" x-cloak class="text-center py-10">
                                <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px]">filter_alt_off</span>
                                <p class="text-sm text-[var(--text-muted)] mt-2">Tidak ada notifikasi di kategori ini.</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <a href="{{ route('profile.show', auth()->id()) }}" class="flex items-center gap-2.5 pl-3 border-l border-[var(--border)]">
                @if (auth()->user()->avatar_url)
                    <img src="{{ auth()->user()->avatar_url }}" alt="{{ auth()->user()->name ?? 'User' }}" referrerpolicy="no-referrer" class="w-8 h-8 rounded-full object-cover">
                @else
                    <div class="w-8 h-8 rounded-full bg-[var(--brand)] text-white flex items-center justify-center text-xs font-semibold">
                        {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
                    </div>
                @endif
                <div class="hidden md:block leading-tight">
                    <p class="text-[13px] font-medium text-[var(--text-primary)]">{{ auth()->user()->name ?? 'User' }}</p>
                    <p class="text-[11px] text-[var(--text-muted)]">{{ auth()->user()->role->name ?? '-' }}</p>
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
                return { client: 'Klien', user: 'User', content: 'Konten' }[type] ?? type;
            },
            categoryIcon(type) {
                return { client: 'apartment', user: 'person', content: 'description' }[type] ?? 'circle';
            },
            categoryIconClass(type) {
                return {
                    client: 'text-[var(--info-text)]',
                    user: 'text-[var(--warning-text)]',
                    content: 'text-[var(--brand)]',
                }[type] ?? 'text-[var(--text-muted)]';
            },
            categoryBadgeClass(type) {
                return {
                    client: 'bg-[var(--info-tint)] text-[var(--info-text)]',
                    user: 'bg-[var(--warning-tint)] text-[var(--warning-text)]',
                    content: 'bg-[var(--brand-tint)] text-[var(--brand)]',
                }[type] ?? 'bg-[var(--surface-muted)] text-[var(--text-secondary)]';
            },
        }
    }
</script>
