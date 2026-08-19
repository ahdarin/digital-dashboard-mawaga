@props(['steps'])

@if (! empty($steps))
    <div class="card p-5 mb-6">
        <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-outlined text-[var(--brand)] text-[18px]">bolt</span>
            <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Langkah Berikutnya</h2>
        </div>
        <div class="flex flex-col gap-2.5">
            @foreach ($steps as $step)
                <a href="{{ $step['route'] }}" class="flex items-start gap-2.5 p-3 rounded-lg bg-[var(--surface-page)] hover:bg-[var(--brand-tint)] transition-colors">
                    <span class="material-symbols-outlined text-[var(--brand)] text-[18px] shrink-0">{{ $step['icon'] }}</span>
                    <span class="text-sm text-[var(--text-primary)] leading-snug">{{ $step['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
