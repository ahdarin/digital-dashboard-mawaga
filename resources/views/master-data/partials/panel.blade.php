@php
    $mdTabs = [
        'content-pillar' => 'Pilar Konten',
        'content-type' => 'Tipe Konten',
        'platform' => 'Platform',
        'client-category' => 'Kategori Klien',
    ];
@endphp
<div x-data="{ showAdd: false }">
    <div class="flex items-center gap-1 bg-[var(--surface-muted)] rounded-lg p-1 mb-5 max-w-full overflow-x-auto thin-autohide-scrollbar">
        @foreach ($mdTabs as $key => $label)
            <a href="{{ route('settings', ['tab' => 'data-pilihan', 'type' => $key]) }}"
               class="text-sm font-medium px-4 py-2 rounded-md transition-colors shrink-0 whitespace-nowrap {{ $mdTab === $key ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-5">
        <form method="GET" class="flex-1">
            <input type="hidden" name="tab" value="data-pilihan">
            <input type="hidden" name="type" value="{{ $mdTab }}">
            <div class="relative max-w-sm">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[19px]">search</span>
                <input type="text" name="search" value="{{ $mdSearch }}" placeholder="Cari data master..."
                       class="bg-[var(--surface-card)] w-full pl-10 pr-4 py-2.5 text-sm border border-[var(--border)] rounded-lg focus:outline-none focus:border-[#044b46]/40">
            </div>
        </form>

        <button type="button" x-on:click="showAdd = !showAdd" class="btn-primary">
            <span class="material-symbols-outlined text-[17px]">add</span> Tambah {{ $mdTabs[$mdTab] }}
        </button>
    </div>

    <div x-show="showAdd" x-cloak class="card p-5 mb-5">
        <form action="{{ route('master-data.store', $mdTab) }}" method="POST" class="flex flex-col sm:flex-row sm:items-center gap-3">
            @csrf
            <input type="text" name="name" required placeholder="Nama {{ $mdTabs[$mdTab] }} baru..."
                   class="bg-[var(--surface-card)] flex-1 border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
            <button type="submit" class="btn-primary">Simpan</button>
        </form>
    </div>

    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-[var(--surface-page)]">
                <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                    <th class="px-6 py-3 font-medium whitespace-nowrap">Nama</th>
                    <th class="px-6 py-3 font-medium text-right whitespace-nowrap">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($mdItems as $item)
                    <tr class="border-t border-[var(--surface-muted)]">
                        <td class="px-6 py-3.5 font-medium text-[var(--text-primary)] whitespace-nowrap">{{ $item->name }}</td>
                        <td class="px-6 py-3.5 text-right">
                            <form action="{{ route('master-data.destroy', [$mdTab, $item->id]) }}" method="POST" class="inline"
                                  onsubmit="return appConfirm(this, 'Yakin hapus {{ addslashes($item->name) }}?', { danger: true })">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-[var(--text-muted)] hover:text-[var(--danger-text)]">
                                    <span class="material-symbols-outlined text-[17px]">delete</span>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="px-6 py-12 text-center">
                            <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[26px] mb-2 block">database</span>
                            <p class="text-sm text-[var(--text-muted)]">Belum ada {{ $mdTabs[$mdTab] }}.</p>
                            <p class="text-xs text-[var(--text-muted)] mt-1">Klik "Tambah {{ $mdTabs[$mdTab] }}" di atas buat mulai.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
      </div>
    </div>
</div>
