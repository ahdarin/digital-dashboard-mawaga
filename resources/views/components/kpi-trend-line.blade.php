@php
    // Komponen khusus metrik skor 0-100 (Nilai KPI/Ketepatan/Kualitas) - beda
    // dari <x-trend-chart> (bar chart generik, dipakai Analytics untuk metrik
    // hitungan seperti reach/views) karena di sini yang diminta betul-betul
    // garis, BUKAN batang, dan sumbu-Y tetap 0-100% (bukan dinamis mengikuti
    // nilai tertinggi) supaya "77%" selalu terbaca sebagai 77 dari 100, bukan
    // relatif ke titik lain di chart yang sama.
    //
    // Dilebarkan mengikuti 100% lebar kartu (BUKAN scroll horizontal ala
    // <x-trend-chart>) - jumlah titik selalu kecil & tetap (6 bulan), jadi
    // selalu muat tanpa perlu discroll, beda dari chart harian Analytics
    // yang titiknya bisa puluhan.
    $items = collect($trend)->values();
    $count = $items->count();
    $withValue = $items->filter(fn ($p) => $p['value'] !== null);
    $avg = $withValue->isNotEmpty() ? $withValue->avg('value') : null;
    $peakIndex = $withValue->isNotEmpty() ? $withValue->sortByDesc('value')->keys()->first() : null;

    $height = 180;
    $denom = max($count - 1, 1);
    $gridLines = [1, 0.5, 0];

    $points = $items->values()->map(function ($point, $i) use ($denom, $height) {
        $value = $point['value'] !== null ? max(0, min(100, $point['value'])) : null;

        return [
            'xPct' => $denom > 0 ? ($i / $denom) * 100 : 0,
            'y' => $value !== null ? $height - ($value / 100 * $height) : null,
            'value' => $point['value'],
            'label' => $point['label'],
        ];
    });

    // Pecah jadi beberapa segmen garis terputus di titik yang datanya kosong -
    // TIDAK PERNAH menyambung lurus melewati bulan tanpa data (itu akan
    // mengarang tren yang tidak pernah terjadi).
    $segments = [];
    $current = [];
    foreach ($points as $p) {
        if ($p['y'] === null) {
            if (count($current) > 0) { $segments[] = $current; $current = []; }
            continue;
        }
        $current[] = $p;
    }
    if (count($current) > 0) $segments[] = $current;
@endphp

<div class="w-full">
    @if ($avg === null)
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2">show_chart</span>
            <p class="text-sm text-[var(--text-muted)]">Belum ada data pada periode ini.</p>
        </div>
    @else
        <div class="mb-5">
            <p class="text-[11px] text-[var(--text-muted)] mb-0.5">Rata-rata</p>
            <p class="font-display text-xl font-semibold text-[var(--text-primary)]">{{ round($avg) }}%</p>
        </div>

        <div class="flex gap-2">
            <div class="flex flex-col justify-between text-right shrink-0 pb-6" style="height: {{ $height }}px">
                @foreach ($gridLines as $g)
                    <span class="text-[10px] text-[var(--text-muted)]">{{ round(100 * $g) }}%</span>
                @endforeach
            </div>

            <div class="relative flex-1 min-w-0">
                <div class="absolute inset-x-0 top-0 flex flex-col justify-between pointer-events-none" style="height: {{ $height }}px">
                    @foreach ($gridLines as $g)
                        <div class="border-t border-[var(--border)] w-full"></div>
                    @endforeach
                </div>

                <div class="relative" style="height: {{ $height }}px">
                    <svg width="100%" height="{{ $height }}" viewBox="0 0 100 {{ $height }}" preserveAspectRatio="none" class="absolute inset-0 overflow-visible">
                        @foreach ($segments as $segment)
                            @if (count($segment) > 1)
                                <polygon
                                    points="{{ collect($segment)->map(fn ($p) => "{$p['xPct']},{$p['y']}")->implode(' ') }} {{ $segment[count($segment)-1]['xPct'] }},{{ $height }} {{ $segment[0]['xPct'] }},{{ $height }}"
                                    fill="var(--brand)" fill-opacity="0.08" stroke="none" />
                                <polyline
                                    points="{{ collect($segment)->map(fn ($p) => "{$p['xPct']},{$p['y']}")->implode(' ') }}"
                                    fill="none" stroke="var(--brand)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                    vector-effect="non-scaling-stroke" />
                            @endif
                        @endforeach
                    </svg>

                    @foreach ($points as $i => $p)
                        <div class="group absolute" style="left: {{ $p['xPct'] }}%; top: {{ $p['y'] ?? $height }}px; transform: translate(-50%, -50%)">
                            @if ($p['y'] === null)
                                <div class="w-2.5 h-2.5 rounded-full border border-dashed border-[var(--border)] bg-[var(--surface-card)]"></div>
                            @else
                                <div class="rounded-full transition-colors {{ $i === $peakIndex ? 'w-3 h-3 bg-[var(--brand)]' : 'w-2.5 h-2.5 bg-[var(--brand)]/70' }} group-hover:bg-[var(--brand)]"></div>
                            @endif
                            <div class="pointer-events-none absolute -top-8 left-1/2 -translate-x-1/2 whitespace-nowrap
                                        bg-[var(--overlay-solid)] text-white text-[10px] font-medium px-2 py-1 rounded opacity-0
                                        group-hover:opacity-100 transition-opacity z-10">
                                {{ $p['value'] === null ? 'Tidak ada data' : round($p['value']).'%' }}
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="relative mt-2" style="height: 16px">
                    @foreach ($points as $p)
                        <div class="absolute text-center" style="left: {{ $p['xPct'] }}%; transform: translateX(-50%)">
                            <span class="text-[10px] text-[var(--text-muted)] whitespace-nowrap">{{ $p['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
