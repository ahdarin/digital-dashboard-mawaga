@extends('layouts.app')
@section('title', 'AI Planning Advisor')
@section('content')

<div class="p-8 max-w-[1400px]">

    <div class="mb-7">
        <h1 class="font-display text-[32px] font-semibold text-[#14181a]">AI Planning Advisor</h1>
        <p class="text-[#5c6266] text-sm mt-1">Strategic insights tailored for your content planning.</p>
    </div>

    <div class="flex gap-5 items-start">

        <div class="flex-1 min-w-0 space-y-5">

            {{-- Strategic Recommendation --}}
            <div class="card p-7">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-[#044b46] text-[17px]">auto_awesome</span>
                    <span class="text-xs font-medium tracking-wide text-[#044b46] uppercase">{{ $recommendation['label'] }}</span>
                </div>

                <h2 class="font-display text-2xl font-semibold text-[#14181a] mb-3 leading-snug">
                    {{ $recommendation['title'] }}
                </h2>

                <p class="text-sm text-[#5c6266] leading-relaxed mb-6 max-w-2xl">
                    {{ $recommendation['description'] }}
                </p>

                <div class="bg-[#f7f8fc] rounded-xl p-5 mb-6">
                    <h3 class="text-sm font-semibold text-[#14181a] mb-3">Action Items</h3>
                    <ul class="space-y-2.5">
                        @foreach ($actionItems as $item)
                            <li class="flex items-start gap-2 text-sm text-[#5c6266]">
                                <span class="material-symbols-outlined text-[#0f7a5f] text-[16px] shrink-0">check_circle</span>
                                {{ $item }}
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" class="bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                        Apply Strategy
                    </button>
                    <button type="button" class="bg-[#f7f8fc] text-[#5c6266] text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#eef0f4] transition-colors">
                        Regenerate
                    </button>
                </div>
            </div>

            {{-- Top Content Pillars --}}
            <div>
                <h2 class="font-display text-lg font-semibold text-[#14181a] mb-4">Top Content Pillars</h2>

                @if ($topPillars->isEmpty())
                    <div class="card p-6 text-center text-sm text-[#9aa0a4]">
                        Belum cukup data content item untuk menghitung ranking pilar.
                    </div>
                @else
                    <div class="grid grid-cols-3 gap-4">
                        @php $pillarIcons = ['school', 'auto_stories', 'lightbulb']; @endphp
                        @foreach ($topPillars as $i => $pillar)
                            <div class="card p-5">
                                <div class="w-9 h-9 rounded-lg bg-[#f0f5f4] flex items-center justify-center mb-3">
                                    <span class="material-symbols-outlined text-[#044b46] text-[18px]">{{ $pillarIcons[$i] ?? 'label' }}</span>
                                </div>
                                <h3 class="text-sm font-semibold text-[#14181a] mb-1">{{ $pillar['name'] }}</h3>
                                <p class="text-xs text-[#5c6266]">{{ $pillar['description'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Right column --}}
        <div class="w-[280px] shrink-0 flex flex-col gap-5">

            <div class="card p-6">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-medium tracking-wide text-[#9aa0a4] uppercase">AI Confidence</span>
                    <span class="material-symbols-outlined text-[#044b46] text-[17px]">psychology</span>
                </div>
                <p class="font-display text-[34px] font-semibold text-[#14181a] mb-3">{{ $confidence }}%</p>
                <div class="w-full h-1.5 rounded-full bg-[#f2f3f6] overflow-hidden mb-3">
                    <div class="h-full bg-[#044b46] rounded-full" style="width: {{ $confidence }}%"></div>
                </div>
                <p class="text-xs text-[#9aa0a4] leading-relaxed">{{ $confidenceNote }}</p>
            </div>

            <div class="card p-6">
                <h2 class="font-display text-base font-semibold text-[#14181a] mb-4">Suggested Split</h2>
                <div class="space-y-3.5">
                    @foreach ($suggestedSplit as $row)
                        <div>
                            <div class="flex items-center justify-between mb-1.5 text-sm">
                                <span class="text-[#5c6266] flex items-center gap-1.5">
                                    <span class="w-1.5 h-1.5 rounded-full shrink-0" style="background-color: {{ $row['color'] }}"></span>
                                    {{ $row['label'] }}
                                </span>
                                <span class="font-medium text-[#14181a]">{{ $row['value'] }}%</span>
                            </div>
                            <div class="w-full h-1.5 rounded-full bg-[#f2f3f6] overflow-hidden">
                                <div class="h-full rounded-full" style="width: {{ $row['value'] }}%; background-color: {{ $row['color'] }}"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

        </div>

    </div>
</div>

@endsection