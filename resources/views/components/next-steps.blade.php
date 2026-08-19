@props(['steps'])

@if (! empty($steps))
    <div class="card p-5 mb-6">
        <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-outlined text-[#044b46] text-[18px]">bolt</span>
            <h2 class="font-display text-base font-semibold text-[#14181a]">Langkah Berikutnya</h2>
        </div>
        <div class="flex flex-col gap-2.5">
            @foreach ($steps as $step)
                <a href="{{ $step['route'] }}" class="flex items-start gap-2.5 p-3 rounded-lg bg-[#f7f8fc] hover:bg-[#f0f5f4] transition-colors">
                    <span class="material-symbols-outlined text-[#044b46] text-[18px] shrink-0">{{ $step['icon'] }}</span>
                    <span class="text-sm text-[#14181a] leading-snug">{{ $step['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
