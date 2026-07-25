@extends('layouts.app')
@section('title', 'AI Planning Advisor')
@section('content')

<div class="p-8">

    {{-- Header --}}
    <div class="mb-8">
        <h1 class="text-4xl font-extrabold text-[#191c1c]">AI Planning Advisor</h1>
        <p class="text-gray-500 mt-2">Strategic insights tailored for Q4 growth.</p>
    </div>

    <div class="flex gap-6 items-start">

        {{-- Kolom Kiri --}}
        <div class="flex-1 min-w-0 space-y-6">

            {{-- Strategic Recommendation --}}
            <div class="relative bg-gradient-to-br from-white via-white to-[#f0f8f5] rounded-2xl shadow-[0_8px_32px_rgba(4,75,70,0.10)] p-8 overflow-hidden border border-[#044b46]/5">
                <div class="absolute -right-10 -top-10 w-56 h-56 rounded-full bg-gradient-to-br from-[#044b46]/15 to-emerald-300/10 blur-2xl"></div>
                <div class="absolute -left-16 -bottom-16 w-48 h-48 rounded-full bg-indigo-200/10 blur-2xl"></div>

                <div class="flex items-center gap-2 mb-5">
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-[#044b46] to-[#0a8f76] flex items-center justify-center shadow-[0_4px_10px_rgba(4,75,70,0.3)]">
                        <span class="material-symbols-outlined text-white text-[18px]">auto_awesome</span>
                    </div>
                    <span class="text-xs font-bold tracking-wider text-[#044b46] uppercase">{{ $recommendation['label'] }}</span>
                </div>

                <h2 class="text-3xl font-extrabold text-[#191c1c] mb-4 leading-tight">
                    {{ $recommendation['title'] }}
                </h2>

                <p class="text-sm text-gray-600 leading-relaxed mb-6 max-w-2xl">
                    {{ $recommendation['description'] }}
                </p>

                {{-- Action Items --}}
                <div class="bg-gradient-to-br from-indigo-50/70 to-[#f4f6fb] rounded-xl p-5 mb-6 border border-indigo-100/50">
                    <h3 class="text-sm font-bold text-gray-700 mb-3">Action Items</h3>
                    <ul class="space-y-2.5">
                        @foreach ($actionItems as $item)
                            <li class="flex items-start gap-2 text-sm text-gray-600">
                                <span class="material-symbols-outlined text-emerald-500 text-[18px] shrink-0">check_circle</span>
                                {{ $item }}
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-3">
                    <button type="button"
                            class="bg-gradient-to-r from-[#044b46] to-[#0a6b5c] text-white text-sm font-semibold px-6 py-3 rounded-xl hover:opacity-90 transition-opacity duration-150 shadow-[0_6px_16px_rgba(4,75,70,0.28)]">
                        Apply Strategy
                    </button>
                    <button type="button"
                            class="bg-[#f4f6fb] text-gray-600 text-sm font-semibold px-6 py-3 rounded-xl hover:bg-gray-100 transition-colors duration-150">
                        Regenerate
                    </button>
                </div>
            </div>

            {{-- Top Content Pillars --}}
            <div>
                <h2 class="text-2xl font-extrabold text-[#191c1c] mb-4">Top Content Pillars</h2>

                @if ($topPillars->isEmpty())
                    <div class="bg-white rounded-2xl shadow-sm p-6 text-center text-sm text-gray-400">
                        Belum cukup data content item untuk menghitung ranking pilar.
                    </div>
                @else
                    <div class="grid grid-cols-3 gap-5">
                        @php
                            $pillarIcons = ['school', 'auto_stories', 'lightbulb'];
                            $pillarStyles = [
                                ['chip' => 'bg-gradient-to-br from-[#044b46] to-[#0a8f76]', 'icon' => 'text-white'],
                                ['chip' => 'bg-gradient-to-br from-indigo-500 to-indigo-400', 'icon' => 'text-white'],
                                ['chip' => 'bg-gradient-to-br from-amber-400 to-amber-300', 'icon' => 'text-white'],
                            ];
                        @endphp
                        @foreach ($topPillars as $i => $pillar)
                            @php $ps = $pillarStyles[$i] ?? $pillarStyles[0]; @endphp
                            <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6 hover:-translate-y-0.5 transition-transform duration-150">
                                <div class="w-11 h-11 rounded-xl {{ $ps['chip'] }} flex items-center justify-center mb-4 shadow-[0_4px_10px_rgba(0,0,0,0.12)]">
                                    <span class="material-symbols-outlined {{ $ps['icon'] }} text-[22px]">
                                        {{ $pillarIcons[$i] ?? 'label' }}
                                    </span>
                                </div>
                                <h3 class="text-base font-bold text-[#191c1c] mb-1.5">{{ $pillar['name'] }}</h3>
                                <p class="text-sm text-gray-500">{{ $pillar['description'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

        </div>

        {{-- Kolom Kanan --}}
        <div class="w-[340px] shrink-0 flex flex-col gap-6">

            {{-- AI Confidence --}}
            <div class="bg-gradient-to-br from-[#044b46] to-[#0a6b5c] rounded-2xl shadow-[0_8px_28px_rgba(4,75,70,0.35)] p-6 text-white">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-bold tracking-wider text-white/70 uppercase">AI Confidence</span>
                    <div class="w-9 h-9 rounded-full bg-white/15 flex items-center justify-center">
                        <span class="material-symbols-outlined text-white text-[18px]">psychology</span>
                    </div>
                </div>

                <p class="text-4xl font-extrabold text-white mb-4">{{ $confidence }}%</p>

                <div class="w-full h-2 rounded-full bg-white/20 overflow-hidden mb-4">
                    <div class="h-full bg-white rounded-full" style="width: {{ $confidence }}%"></div>
                </div>

                <p class="text-xs text-white/70 leading-relaxed">{{ $confidenceNote }}</p>
            </div>

            {{-- Suggested Split --}}
            <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
                <h2 class="text-xl font-extrabold text-[#191c1c] mb-5">Suggested Split</h2>

                <div class="space-y-4">
                    @foreach ($suggestedSplit as $row)
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-sm font-medium text-gray-600 flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full shrink-0" style="background-color: {{ $row['color'] }}"></span>
                                    {{ $row['label'] }}
                                </span>
                                <span class="text-sm font-semibold text-[#191c1c]">{{ $row['value'] }}%</span>
                            </div>
                            <div class="w-full h-2 rounded-full bg-gray-100 overflow-hidden">
                                <div class="h-full rounded-full" style="width: {{ $row['value'] }}%; background-color: {{ $row['color'] }}"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <button type="button"
                        class="mt-5 w-full flex items-center justify-center gap-2 text-sm font-semibold text-gray-500 hover:text-[#044b46] transition-colors duration-150">
                    <span class="material-symbols-outlined text-[16px]">tune</span>
                    Adjust Distribution
                </button>
            </div>

        </div>

    </div>
</div>

@endsection