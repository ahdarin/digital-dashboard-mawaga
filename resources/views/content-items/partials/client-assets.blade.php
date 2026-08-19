@php $client = $contentItem->client; @endphp

<div class="card p-5">
    <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-3">Aset Klien</h3>

    @if ($client && $client->asset_link)
        <a href="{{ $client->asset_link }}" target="_blank" rel="noopener"
           class="flex items-center gap-2.5 bg-[var(--brand-tint)] text-[var(--brand)] text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[var(--brand-tint-hover)] transition-colors">
            <span class="material-symbols-outlined text-[17px]">folder_open</span>
            Buka Google Drive
            <span class="material-symbols-outlined text-[14px] ml-auto">open_in_new</span>
        </a>
    @else
        <div class="text-center py-4">
            <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[26px] mb-1.5 block">folder_off</span>
            <p class="text-xs text-[var(--text-muted)] mb-3">Belum ada link aset untuk client ini.</p>
            @if ($client)
                <a href="{{ route('client-management.edit', $client) }}"
                   class="text-xs font-medium text-[var(--brand)] hover:underline">Tambahkan di halaman Edit Client →</a>
            @endif
        </div>
    @endif
</div>
