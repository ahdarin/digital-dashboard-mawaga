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
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#767c80] text-[17px]">search</span>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari judul konten..."
                   class="w-full pl-9 pr-3 py-2 text-sm border border-[#eef0f4] rounded-lg focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
        </div>

        <select name="platform_id" onchange="this.form.submit()"
                class="text-sm border border-[#eef0f4] rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
            <option value="">Semua Platform</option>
            @foreach ($platformOptions as $p)
                <option value="{{ $p->id }}" {{ (string) request('platform_id') === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
            @endforeach
        </select>

        <select name="content_type_id" onchange="this.form.submit()"
                class="text-sm border border-[#eef0f4] rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
            <option value="">Semua Tipe</option>
            @foreach ($contentTypeOptions as $ct)
                <option value="{{ $ct->id }}" {{ (string) request('content_type_id') === (string) $ct->id ? 'selected' : '' }}>{{ $ct->name }}</option>
            @endforeach
        </select>

        <button type="submit" class="btn-primary">
            Terapkan
        </button>

        @if (request('search') || request('platform_id') || request('content_type_id'))
            <a href="{{ route('analytics', ['tab' => 'table', 'client_id' => $selectedClientId]) }}" class="text-xs font-medium text-[#767c80] hover:text-[#5c6266] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#044b46] rounded">Reset filter</a>
        @endif
    </form>
</div>

{{-- Table --}}
<div class="card overflow-hidden">
    @if ($items->isEmpty())
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <span class="material-symbols-outlined text-[#d4d7db] text-[24px] mb-2">search_off</span>
            <p class="text-sm text-[#767c80]">Nggak ada konten yang cocok dengan filter ini.</p>
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
                <thead class="bg-[#f7f8fc]">
                    <tr class="text-[#767c80] text-[11px] uppercase tracking-wide">
                        <th class="px-6 py-3 font-medium whitespace-nowrap">
                            <a href="{{ $sortLink('title') }}" class="flex items-center gap-1 hover:text-[#044b46] transition-colors">
                                Content <span class="material-symbols-outlined text-[13px]">{{ $sortIcon('title') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Platform</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Type</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">
                            <a href="{{ $sortLink('total_views') }}" class="flex items-center gap-1 hover:text-[#044b46] transition-colors">
                                Views <span class="material-symbols-outlined text-[13px]">{{ $sortIcon('total_views') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">
                            <a href="{{ $sortLink('avg_engagement') }}" class="flex items-center gap-1 hover:text-[#044b46] transition-colors">
                                Engagement <span class="material-symbols-outlined text-[13px]">{{ $sortIcon('avg_engagement') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">
                            <a href="{{ $sortLink('deadline_at') }}" class="flex items-center gap-1 hover:text-[#044b46] transition-colors">
                                Deadline <span class="material-symbols-outlined text-[13px]">{{ $sortIcon('deadline_at') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] transition-colors">
                            <td class="px-6 py-3.5 font-medium text-[#14181a] max-w-[240px] truncate">{{ $item->title }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->platform->name ?? '-' }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->contentType->name ?? '-' }}</td>
                            <td class="px-4 py-3.5 font-medium text-[#14181a] whitespace-nowrap [font-variant-numeric:tabular-nums]">{{ $item->total_views !== null ? number_format($item->total_views) : '-' }}</td>
                            <td class="px-4 py-3.5 whitespace-nowrap">
                                @if ($item->avg_engagement !== null)
                                    <span class="badge badge-success [font-variant-numeric:tabular-nums]">{{ round($item->avg_engagement, 2) }}%</span>
                                @else
                                    <span class="text-[#767c80]">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap [font-variant-numeric:tabular-nums]">{{ $item->deadline_at?->format('d M Y') }}</td>
                            <td class="px-4 py-3.5 whitespace-nowrap">
                                @if ($item->is_posted)
                                    <span class="badge badge-success">Published</span>
                                @elseif ($item->workflow?->is_overdue)
                                    <span class="badge badge-danger">Terlambat</span>
                                @else
                                    <span class="badge badge-warning">On Progress</span>
                                @endif
                            </td>
                            <td class="px-6 py-3.5 text-right whitespace-nowrap">
                                <a href="{{ route('analytics.show', $item->id) }}" class="text-xs font-medium text-[#044b46] hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#044b46] rounded">Detail</a>
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
                    if ($item->is_posted) {
                        $rowStatus = ['class' => 'badge-success', 'label' => 'Published'];
                    } elseif ($item->workflow?->is_overdue) {
                        $rowStatus = ['class' => 'badge-danger', 'label' => 'Terlambat'];
                    } else {
                        $rowStatus = ['class' => 'badge-warning', 'label' => 'On Progress'];
                    }
                @endphp
                <div class="card p-3.5" x-data="{ open: false }">
                    <button type="button" class="w-full text-left flex items-start gap-2 cursor-pointer" @click="open = !open" :aria-expanded="open">
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-[#14181a] text-sm truncate">{{ $item->title }}</p>
                            <div class="flex items-center gap-1.5 flex-wrap mt-1.5">
                                <span class="badge {{ $rowStatus['class'] }}">{{ $rowStatus['label'] }}</span>
                                <span class="text-xs text-[#5c6266] whitespace-nowrap">{{ $item->platform->name ?? '-' }} &middot; {{ $item->contentType->name ?? '-' }}</span>
                            </div>
                        </div>
                        <div class="w-7 h-7 shrink-0 flex items-center justify-center rounded-lg text-[#767c80]">
                            <span class="material-symbols-outlined text-[19px] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                        </div>
                    </button>

                    <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[#f2f3f6] space-y-2">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-[#767c80]">Views</span>
                            <span class="text-[#14181a] font-medium [font-variant-numeric:tabular-nums]">{{ $item->total_views !== null ? number_format($item->total_views) : '-' }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-[#767c80]">Engagement</span>
                            @if ($item->avg_engagement !== null)
                                <span class="badge badge-success [font-variant-numeric:tabular-nums]">{{ round($item->avg_engagement, 2) }}%</span>
                            @else
                                <span class="text-[#767c80]">-</span>
                            @endif
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-[#767c80]">Deadline</span>
                            <span class="text-[#14181a] font-medium [font-variant-numeric:tabular-nums]">{{ $item->deadline_at?->format('d M Y') ?? '-' }}</span>
                        </div>
                        <a href="{{ route('analytics.show', $item->id) }}"
                            class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[#044b46] bg-[#f0f5f4] hover:bg-[#e4ede9] rounded-lg py-2 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#044b46]">
                            Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                        </a>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="px-6 py-4 border-t border-[#f2f3f6]">
            {{ $items->links() }}
        </div>
    @endif
</div>
