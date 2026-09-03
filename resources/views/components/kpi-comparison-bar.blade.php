@php
    // Bar chart khusus perbandingan Nilai KPI antar anggota (kategori diskrit
    // per orang, bukan deret waktu - makanya tetap batang, bukan garis kayak
    // <x-kpi-trend-line>). Sumbu-Y tetap 0-100% konsisten dengan komponen
    // KPI lain, TIDAK dinamis mengikuti skor tertinggi.
    $items = collect($trend)->values();
    $count = $items->count();
    $withValue = $items->filter(fn ($p) => $p['value'] !== null);
    $avg = $withValue->isNotEmpty() ? $withValue->avg('value') : null;
    $peakIndex = $withValue->isNotEmpty() ? $withValue->sortByDesc('value')->keys()->first() : null;
    $barWidth = $count > 20 ? 30 : 44;
    $gridLines = [1, 0.5, 0];
@endphp

<div class="w-full">
    @if ($avg === null)
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2">bar_chart</span>
            <p class="text-sm text-[var(--text-muted)]">Belum ada data pada periode ini.</p>
        </div>
    @else
        <div class="mb-5">
            <p class="text-[11px] text-[var(--text-muted)] mb-0.5">Rata-rata</p>
            <p class="font-display text-xl font-semibold text-[var(--text-primary)]">{{ round($avg) }}%</p>
        </div>

        <div class="flex gap-2">
            <div class="flex flex-col justify-between text-right shrink-0 pb-6" style="height: 180px">
                @foreach ($gridLines as $g)
                    <span class="text-[10px] text-[var(--text-muted)]">{{ round(100 * $g) }}%</span>
                @endforeach
            </div>

            <div class="overflow-x-auto flex-1 min-w-0 pt-8 -mt-8 thin-autohide-scrollbar">
                <div class="relative" style="min-width: {{ $count * ($barWidth + 6) }}px">
                    <div class="absolute inset-x-0 top-0 flex flex-col justify-between pointer-events-none" style="height: 180px">
                        @foreach ($gridLines as $g)
                            <div class="border-t border-[var(--border)] w-full"></div>
                        @endforeach
                    </div>

                    <div class="relative flex items-end gap-1.5" style="height: 180px">
                        @foreach ($items as $i => $point)
                            @php $isGap = $point['value'] === null; @endphp
                            <div class="group relative shrink-0 h-full flex flex-col items-center justify-end" style="width: {{ $barWidth }}px">
                                <div class="pointer-events-none absolute -top-7 left-1/2 -translate-x-1/2 whitespace-nowrap
                                            bg-[var(--overlay-solid)] text-white text-[10px] font-medium px-2 py-1 rounded opacity-0
                                            group-hover:opacity-100 transition-opacity z-10">
                                    {{ $isGap ? 'Tidak ada data' : round($point['value']).'%' }}
                                </div>
                                @if ($isGap)
                                    <div class="w-full rounded-[3px] border border-dashed border-[var(--border)]" style="height: 2%"></div>
                                @else
                                    <div class="w-full rounded-[3px] transition-colors {{ $i === $peakIndex && $point['value'] > 0 ? 'bg-[var(--brand)]' : 'bg-[var(--brand-tint-border)] group-hover:bg-[var(--brand-muted)]' }}"
                                         style="height: {{ max($point['value'], 2) }}%"></div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="flex gap-1.5 mt-2">
                        @foreach ($items as $point)
                            <div class="shrink-0 text-center" style="width: {{ $barWidth }}px">
                                <span class="text-[10px] text-[var(--text-muted)] whitespace-nowrap truncate block">
                                    {{ $point['label'] }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
