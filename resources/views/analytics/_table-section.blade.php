{{-- Tab "Performance Table" - dipindah dari analytics/table.blade.php,
     sekarang jadi bagian dari halaman Analytics (bukan halaman terpisah
     lagi). Client dipilih dari filter bar bersama di atas; filter di
     bawah ini (search/platform/tipe/sort) khusus tab ini aja. --}}

{{-- Filter bar --}}
<div class="card p-4 mb-5">
    <form method="GET" class="flex items-center gap-2.5 flex-wrap">
        <input type="hidden" name="tab" value="table">
        <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">

        <div class="relative flex-1 min-w-[200px]">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[17px]">search</span>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari judul konten..."
                   class="bg-[var(--surface-card)] w-full pl-9 pr-3 py-2 text-sm border border-[var(--border)] rounded-lg focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
        </div>

        <select name="platform_id" onchange="this.form.submit()"
                class="text-sm border border-[var(--border)] rounded-lg px-3 py-2 bg-[var(--surface-card)] focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
            <option value="">Semua Platform</option>
            @foreach ($platformOptions as $p)
                <option value="{{ $p->id }}" {{ (string) request('platform_id') === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
            @endforeach
        </select>

        <select name="content_type_id" onchange="this.form.submit()"
                class="text-sm border border-[var(--border)] rounded-lg px-3 py-2 bg-[var(--surface-card)] focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
            <option value="">Semua Tipe</option>
            @foreach ($contentTypeOptions as $ct)
                <option value="{{ $ct->id }}" {{ (string) request('content_type_id') === (string) $ct->id ? 'selected' : '' }}>{{ $ct->name }}</option>
            @endforeach
        </select>

        <button type="submit" class="btn-primary">
            Terapkan
        </button>

        @if (request('search') || request('platform_id') || request('content_type_id'))
            <a href="{{ route('analytics', ['tab' => 'table', 'client_id' => $selectedClientId]) }}" class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-secondary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)] rounded">Reset filter</a>
        @endif
    </form>
</div>

{{-- Table --}}
<div class="card overflow-hidden">
    @if ($items->isEmpty())
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[24px] mb-2">search_off</span>
            <p class="text-sm text-[var(--text-muted)]">Nggak ada konten yang cocok dengan filter ini.</p>
        </div>
    @else
        @php
            $sortLink = fn ($col) => route('analytics', array_merge(request()->except(['sort', 'dir']), [
                'sort' => $col,
                'dir' => $sort === $col && $dir === 'desc' ? 'asc' : 'desc',
            ]));
            $sortIcon = fn ($col) => $sort === $col ? ($dir === 'desc' ? 'arrow_downward' : 'arrow_upward') : 'unfold_more';
        @endphp

        <div class="overflow-x-auto hidden sm:block">
            <table class="w-full text-sm text-left">
                <thead class="bg-[var(--surface-page)]">
                    <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                        <th class="px-6 py-3 font-medium whitespace-nowrap">
                            <a href="{{ $sortLink('title') }}" class="flex items-center gap-1 hover:text-[var(--brand)] transition-colors">
                                Content <span class="material-symbols-outlined text-[13px]">{{ $sortIcon('title') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Platform</th>
                        {{-- "Tipe / Format" (bukan cuma "Type") - kolom ini
                             bisa isi ContentType internal (taksonomi produksi,
                             mis. Video/Desain) ATAU format Instagram
                             (Reels/Carousel/Image, mis. post yang belum
                             terhubung ke konten internal) - dua domain beda,
                             judul kolom sengaja dibuat netral biar nggak
                             menyiratkan itu selalu taksonomi internal. --}}
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Tipe / Format</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">
                            <a href="{{ $sortLink('total_views') }}" class="flex items-center gap-1 hover:text-[var(--brand)] transition-colors">
                                Views <span class="material-symbols-outlined text-[13px]">{{ $sortIcon('total_views') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">
                            <a href="{{ $sortLink('avg_engagement') }}" class="flex items-center gap-1 hover:text-[var(--brand)] transition-colors">
                                Engagement <span class="material-symbols-outlined text-[13px]">{{ $sortIcon('avg_engagement') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">
                            <a href="{{ $sortLink('deadline_at') }}" class="flex items-center gap-1 hover:text-[var(--brand)] transition-colors">
                                Deadline <span class="material-symbols-outlined text-[13px]">{{ $sortIcon('deadline_at') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr class="border-t border-[var(--surface-muted)] hover:bg-[var(--surface-page)] transition-colors">
                            <td class="px-6 py-3.5 font-medium text-[var(--text-primary)] max-w-[240px] truncate">{{ $item->title }}</td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $item->platform }}</td>
                            <td class="px-4 py-3.5 whitespace-nowrap {{ $item->linked ? 'text-[var(--text-secondary)]' : 'text-[var(--text-muted)] italic' }}">{{ $item->type ?? '-' }}</td>
                            <td class="px-4 py-3.5 font-medium text-[var(--text-primary)] whitespace-nowrap [font-variant-numeric:tabular-nums]">{{ $item->total_views !== null ? number_format($item->total_views) : '-' }}</td>
                            <td class="px-4 py-3.5 whitespace-nowrap">
                                @if ($item->avg_engagement !== null)
                                    <span class="badge badge-success [font-variant-numeric:tabular-nums]">{{ round($item->avg_engagement, 2) }}%</span>
                                @else
                                    <span class="text-[var(--text-muted)]">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap [font-variant-numeric:tabular-nums]">{{ $item->deadline_at?->format('d M Y') ?? '-' }}</td>
                            <td class="px-4 py-3.5 whitespace-nowrap">
                                @if (! $item->linked)
                                    <span class="badge badge-neutral">Belum terhubung ke konten internal</span>
                                @elseif ($item->is_posted)
                                    <span class="badge badge-success">Published</span>
                                @elseif ($item->is_overdue)
                                    <span class="badge badge-danger">Terlambat</span>
                                @else
                                    <span class="badge badge-warning">On Progress</span>
                                @endif
                            </td>
                            <td class="px-6 py-3.5 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-2.5">
                                    @if ($item->linked)
                                        {{-- Dashboard ini dashboard workflow agensi, bukan
                                             sekadar pintu ke Instagram - detail internal SELALU
                                             jadi primary destination, permalink cuma supporting
                                             action eksternal (Langkah 11). --}}
                                        <a href="{{ route('analytics.show', $item->id) }}" class="text-xs font-medium text-[var(--brand)] hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)] rounded">Lihat Detail</a>
                                        @if ($item->permalink)
                                            <a href="{{ $item->permalink }}" target="_blank" rel="noopener noreferrer" title="Lihat di Instagram" class="text-[var(--text-muted)] hover:text-[var(--brand)] transition-colors">
                                                <span class="material-symbols-outlined text-[16px]">open_in_new</span>
                                            </a>
                                        @endif
                                    @else
                                        @if ($item->api_integration_id)
                                            <a href="{{ route('publishing-tracker.instagram.unmatched', $item->api_integration_id) }}#post-{{ $item->external_post_id }}"
                                               class="text-xs font-medium text-[var(--brand)] hover:underline">Hubungkan Konten</a>
                                        @endif
                                        @if ($item->permalink)
                                            <a href="{{ $item->permalink }}" target="_blank" rel="noopener noreferrer" class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--brand)] transition-colors">Lihat Post</a>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile card list --}}
        <div class="sm:hidden p-3.5 space-y-3">
            @foreach ($items as $item)
                @php
                    if (! $item->linked) {
                        $rowStatus = ['class' => 'badge-neutral', 'label' => 'Belum terhubung'];
                    } elseif ($item->is_posted) {
                        $rowStatus = ['class' => 'badge-success', 'label' => 'Published'];
                    } elseif ($item->is_overdue) {
                        $rowStatus = ['class' => 'badge-danger', 'label' => 'Terlambat'];
                    } else {
                        $rowStatus = ['class' => 'badge-warning', 'label' => 'On Progress'];
                    }
                @endphp
                <div class="card p-3.5" x-data="{ open: false }">
                    <button type="button" class="w-full text-left flex items-start gap-2 cursor-pointer" @click="open = !open" :aria-expanded="open">
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-[var(--text-primary)] text-sm truncate">{{ $item->title }}</p>
                            <div class="flex items-center gap-1.5 flex-wrap mt-1.5">
                                <span class="badge {{ $rowStatus['class'] }}">{{ $rowStatus['label'] }}</span>
                                <span class="text-xs text-[var(--text-secondary)] whitespace-nowrap">{{ $item->platform }} &middot; {{ $item->type ?? '-' }}</span>
                            </div>
                        </div>
                        <div class="w-7 h-7 shrink-0 flex items-center justify-center rounded-lg text-[var(--text-muted)]">
                            <span class="material-symbols-outlined text-[19px] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                        </div>
                    </button>

                    <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[var(--surface-muted)] space-y-2">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-[var(--text-muted)]">Views</span>
                            <span class="text-[var(--text-primary)] font-medium [font-variant-numeric:tabular-nums]">{{ $item->total_views !== null ? number_format($item->total_views) : '-' }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-[var(--text-muted)]">Engagement</span>
                            @if ($item->avg_engagement !== null)
                                <span class="badge badge-success [font-variant-numeric:tabular-nums]">{{ round($item->avg_engagement, 2) }}%</span>
                            @else
                                <span class="text-[var(--text-muted)]">-</span>
                            @endif
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-[var(--text-muted)]">Deadline</span>
                            <span class="text-[var(--text-primary)] font-medium [font-variant-numeric:tabular-nums]">{{ $item->deadline_at?->format('d M Y') ?? '-' }}</span>
                        </div>
                        @if ($item->linked)
                            <a href="{{ route('analytics.show', $item->id) }}"
                                class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] rounded-lg py-2 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]">
                                Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                            </a>
                            @if ($item->permalink)
                                <a href="{{ $item->permalink }}" target="_blank" rel="noopener noreferrer"
                                    class="mt-1.5 flex items-center justify-center gap-1.5 text-xs font-medium text-[var(--text-muted)] hover:text-[var(--brand)] transition-colors">
                                    Lihat di Instagram <span class="material-symbols-outlined text-[13px]">open_in_new</span>
                                </a>
                            @endif
                        @else
                            @if ($item->api_integration_id)
                                <a href="{{ route('publishing-tracker.instagram.unmatched', $item->api_integration_id) }}#post-{{ $item->external_post_id }}"
                                    class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] rounded-lg py-2 transition-colors">
                                    Hubungkan Konten <span class="material-symbols-outlined text-[15px]">link</span>
                                </a>
                            @endif
                            @if ($item->permalink)
                                <a href="{{ $item->permalink }}" target="_blank" rel="noopener noreferrer"
                                    class="mt-1.5 flex items-center justify-center gap-1.5 text-xs font-medium text-[var(--text-muted)] hover:text-[var(--brand)] transition-colors">
                                    Lihat Post <span class="material-symbols-outlined text-[13px]">open_in_new</span>
                                </a>
                            @endif
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="px-6 py-4 border-t border-[var(--surface-muted)]">
            {{ $items->links() }}
        </div>
    @endif
</div>
