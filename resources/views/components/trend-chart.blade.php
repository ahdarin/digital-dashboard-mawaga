@php
    // $trend: collection/array of ['label' => string, 'value' => int]
    $trendItems = collect($trend)->values();
    $total = $trendItems->sum('value');
    $max = max($trendItems->max('value') ?? 0, 1);
    $avg = $trendItems->count() > 0 ? $total / $trendItems->count() : 0;
    $peakIndex = $trendItems->sortByDesc('value')->keys()->first();
    $count = $trendItems->count();

    $barWidth = $count > 40 ? 22 : ($count > 15 ? 28 : 44);
    $showEveryLabel = $count <= 14;
    $labelStep = $showEveryLabel ? 1 : max((int) ceil($count / 12), 1);

    // Format angka jadi ringkas: 1.200 -> 1,2K ; 2.500.000 -> 2,5M
    $compact = function ($n) {
        if ($n >= 1000000) return number_format($n / 1000000, 1).'M';
        if ($n >= 1000) return number_format($n / 1000, 1).'K';
        return number_format($n);
    };

    // 4 garis bantu horizontal: 0%, 33%, 66%, 100% dari nilai maksimum
    $gridLines = [1, 0.66, 0.33, 0];
@endphp

<div class="w-full">
    @if ($total === 0)
        <p class="text-sm text-gray-400 text-center py-16">Belum ada data metrik pada periode ini.</p>
    @else
        {{-- Ringkasan angka, biar nggak cuma ngandelin tinggi bar --}}
        <div class="flex items-center gap-6 mb-4">
            <div>
                <p class="text-[11px] text-gray-400 font-medium">Total</p>
                <p class="text-lg font-extrabold text-[#191c1c]">{{ number_format($total) }}</p>
            </div>
            <div class="w-px h-8 bg-gray-100"></div>
            <div>
                <p class="text-[11px] text-gray-400 font-medium">Rata-rata / hari</p>
                <p class="text-lg font-extrabold text-[#191c1c]">{{ $compact(round($avg)) }}</p>
            </div>
            <div class="w-px h-8 bg-gray-100"></div>
            <div>
                <p class="text-[11px] text-gray-400 font-medium">Tertinggi</p>
                <p class="text-lg font-extrabold text-[#044b46]">{{ $compact($max) }}</p>
            </div>
        </div>

        <div class="flex gap-2">
            {{-- Sumbu Y --}}
            <div class="flex flex-col justify-between text-right pb-6 shrink-0" style="height: 224px">
                @foreach ($gridLines as $g)
                    <span class="text-[10px] text-gray-300 font-medium leading-none">{{ $compact($max * $g) }}</span>
                @endforeach
            </div>

            {{-- Chart --}}
            <div class="overflow-x-auto pb-1 pt-10 -mt-10 flex-1 min-w-0">
                <div class="relative" style="min-width: {{ $count * ($barWidth + 6) }}px">

                    {{-- Gridlines horizontal --}}
                    <div class="absolute inset-x-0 top-0 flex flex-col justify-between pointer-events-none" style="height: 224px">
                        @foreach ($gridLines as $g)
                            <div class="border-t border-dashed border-gray-100 w-full"></div>
                        @endforeach
                    </div>

                    <div class="relative flex items-end gap-1.5" style="height: 224px">
                        @foreach ($trendItems as $i => $point)
                            @php $isPeak = $i === $peakIndex && $point['value'] > 0; @endphp

                            <div class="group relative shrink-0 h-full flex flex-col items-center justify-end" style="width: {{ $barWidth }}px">

                                <div class="pointer-events-none absolute -top-8 left-1/2 -translate-x-1/2 whitespace-nowrap
                                            bg-[#191c1c] text-white text-[10px] font-semibold px-2 py-1 rounded-md opacity-0
                                            group-hover:opacity-100 transition-opacity duration-150 z-10">
                                    {{ $point['label'] }}: {{ number_format($point['value']) }}
                                </div>

                                <div
                                    class="w-full rounded-t-md transition-all duration-300
                                        {{ $isPeak
                                            ? 'bg-gradient-to-t from-[#0a8f76] to-[#044b46]'
                                            : 'bg-gradient-to-t from-[#044b46]/20 to-[#044b46]/35 group-hover:from-[#044b46]/35 group-hover:to-[#044b46]/50' }}"
                                    style="height: {{ max(($point['value'] / $max) * 100, 3) }}%"
                                ></div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Sumbu X --}}
                    <div class="flex gap-1.5 mt-2">
                        @foreach ($trendItems as $i => $point)
                            <div class="shrink-0 text-center" style="width: {{ $barWidth }}px">
                                <span class="text-[10px] font-medium text-gray-400 whitespace-nowrap">
                                    {{ $i % $labelStep === 0 || $i === $count - 1 ? $point['label'] : '' }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        @if ($count > 14)
            <p class="text-[11px] text-gray-300 text-center mt-2">← geser untuk lihat semua tanggal →</p>
        @endif
    @endif
</div>