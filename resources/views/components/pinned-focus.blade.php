{{-- Widget "Fokus Saya" - panen dari Pin (lihat PinService). Cuma tampil
     kalau user punya minimal 1 pin, biar nggak jadi kartu kosong yang
     numpuk di Beranda buat user yang belum pernah pakai fitur ini.
     Expects: $pinnedItems (Collection<ContentItem>, sudah eager-load
     client & workflow). --}}
@props(['pinnedItems'])

@if ($pinnedItems->isNotEmpty())
    <div class="card p-5 mb-6">
        <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-outlined text-[var(--brand)] text-[18px]" style="font-variation-settings: 'FILL' 1">push_pin</span>
            <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Fokus Saya</h2>
            <span class="text-xs text-[var(--text-muted)]">({{ $pinnedItems->count() }}/{{ \App\Services\PinService::MAX_PINS }})</span>
        </div>
        <div class="flex flex-col gap-2.5">
            @foreach ($pinnedItems as $item)
                <a href="{{ route('content-items.show', $item) }}"
                    class="flex items-center justify-between gap-3 p-3 rounded-lg bg-[var(--surface-page)] hover:bg-[var(--brand-tint)] transition-colors min-w-0">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-[var(--text-primary)] truncate">{{ $item->title }}</p>
                        <p class="text-xs text-[var(--text-muted)] truncate">{{ $item->client->name ?? '-' }}</p>
                    </div>
                    <span class="badge badge-success shrink-0">
                        {{ \App\Support\WorkflowTransitions::label($item->workflow->current_status ?? '') }}
                    </span>
                </a>
            @endforeach
        </div>
    </div>
@endif
