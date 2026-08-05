@php
    $trendItems = collect($trend)->values();
    $total = $trendItems->sum('value');
    $max = max($trendItems->max('value') ?? 0, 1);
    $avg = $trendItems->count() > 0 ? $total / $trendItems->count() : 0;
    $peakIndex = $trendItems->sortByDesc('value')->keys()->first();
    $count = $trendItems->count();

    // Lebar bar dibikin cukup lebar buat nampung label tanggal (~"07/07")
    // tanpa numpuk - SEMUA tanggal ditampilin, kalau kepanjangan tinggal
    // di-scroll (bukan disembunyiin kayak sebelumnya).
    $barWidth = $count > 60 ? 30 : 38;

    $compact = function ($n) {
        if ($n >= 1000000) return number_format($n / 1000000, 1).'M';
        if ($n >= 1000) return number_format($n / 1000, 1).'K';
        return number_format($n);
    };
    $gridLines = [1, 0.5, 0];
@endphp

<div class="w-full">
    @if ($total === 0)
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <span class="material-symbols-outlined text-[#d4d7db] text-[28px] mb-2">show_chart</span>
            <p class="text-sm text-[#9aa0a4]">Belum ada data metrik pada periode ini.</p>
        </div>
    @else
        <div class="flex items-baseline gap-6 mb-5">
            <div>
                <p class="text-[11px] text-[#9aa0a4] mb-0.5">Total</p>
                <p class="font-display text-xl font-semibold text-[#14181a]">{{ number_format($total) }}</p>
            </div>
            <div>
                <p class="text-[11px] text-[#9aa0a4] mb-0.5">Rata-rata</p>
                <p class="font-display text-xl font-semibold text-[#14181a]">{{ $compact(round($avg)) }}</p>
            </div>
        </div>

        <div class="flex gap-2">
            <div class="flex flex-col justify-between text-right shrink-0 pb-6" style="height: 180px">
                @foreach ($gridLines as $g)
                    <span class="text-[10px] text-[#c3c7cb]">{{ $compact($max * $g) }}</span>
                @endforeach
            </div>

            <div class="overflow-x-auto flex-1 min-w-0 pt-8 -mt-8">
                <div class="relative" style="min-width: {{ $count * ($barWidth + 6) }}px">
                    <div class="absolute inset-x-0 top-0 flex flex-col justify-between pointer-events-none" style="height: 180px">
                        @foreach ($gridLines as $g)
                            <div class="border-t border-[#eef0f4] w-full"></div>
                        @endforeach
                    </div>

                    <div class="relative flex items-end gap-1.5" style="height: 180px">
                        @foreach ($trendItems as $i => $point)
                            <div class="group relative shrink-0 h-full flex flex-col items-center justify-end" style="width: {{ $barWidth }}px">
                                <div class="pointer-events-none absolute -top-7 left-1/2 -translate-x-1/2 whitespace-nowrap
                                            bg-[#14181a] text-white text-[10px] font-medium px-2 py-1 rounded opacity-0
                                            group-hover:opacity-100 transition-opacity z-10">
                                    {{ number_format($point['value']) }}
                                </div>
                                <div class="w-full rounded-[3px] transition-colors {{ $i === $peakIndex && $point['value'] > 0 ? 'bg-[#044b46]' : 'bg-[#dbe6e4] group-hover:bg-[#a9c4bf]' }}"
                                     style="height: {{ max(($point['value'] / $max) * 100, 2) }}%"></div>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex gap-1.5 mt-2">
                        @foreach ($trendItems as $i => $point)
                            <div class="shrink-0 text-center" style="width: {{ $barWidth }}px">
                                <span class="text-[10px] text-[#9aa0a4] whitespace-nowrap">
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