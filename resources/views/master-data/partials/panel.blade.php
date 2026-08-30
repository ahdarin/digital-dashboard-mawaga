@php
    $mdTabs = [
        'content-pillar' => 'Pilar Konten',
        'content-type' => 'Tipe Konten',
        'platform' => 'Platform',
        'client-category' => 'Kategori Klien',
        'package-template' => 'Paket',
    ];
@endphp
<div x-data="{
        showAdd: false,
        editPackage: null,
        {{-- Tooltip custom aksi tabel - gaya sama seperti tooltip sidebar
             saat collapse, tapi muncul DI ATAS tombol (bukan di samping). --}}
        tooltip: { show: false, text: '', top: 0, left: 0 },
        showTooltip(event, text) {
            const rect = event.currentTarget.getBoundingClientRect();
            this.tooltip = { show: true, text, top: rect.top - 8, left: rect.left + rect.width / 2 };
        },
        hideTooltip() { this.tooltip.show = false; },
    }">
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

    @if ($mdTab === 'package-template')
        <div x-show="showAdd" x-cloak class="card p-5 mb-5">
            <form action="{{ route('package-templates.store') }}" method="POST" class="flex flex-col sm:flex-row sm:items-end gap-3">
                @csrf
                <div class="flex-1">
                    <label class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Nama Paket</label>
                    <input type="text" name="name" required placeholder="Paket Basic, Growth, dst."
                           class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                </div>
                <div class="w-full sm:w-36">
                    <label class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Kuota Konten</label>
                    <input type="number" name="monthly_content_quota" required min="0" placeholder="0"
                           class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                </div>
                <div class="w-full sm:w-36">
                    <label class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Kuota Desain</label>
                    <input type="number" name="monthly_design_quota" required min="0" placeholder="0"
                           class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                </div>
                <label class="flex items-center gap-2 shrink-0 pb-2.5">
                    <input type="checkbox" name="is_active" value="1" checked
                           class="rounded border-[var(--border-strong)] text-[var(--brand)] focus:ring-[var(--brand)]">
                    <span class="text-sm text-[var(--text-secondary)]">Aktif</span>
                </label>
                <button type="submit" class="btn-primary shrink-0">Simpan</button>
            </form>
        </div>

        <div class="card overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-[var(--surface-page)]">
                    <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                        <th class="px-6 py-3 font-medium whitespace-nowrap">Nama</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Kuota Konten</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Kuota Desain</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                        <th class="px-6 py-3 font-medium text-right whitespace-nowrap">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mdItems as $item)
                        <tr class="border-t border-[var(--surface-muted)]">
                            <td class="px-6 py-3.5 font-medium text-[var(--text-primary)] whitespace-nowrap">{{ $item->name }}</td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $item->monthly_content_quota }} / bulan</td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $item->monthly_design_quota }} / bulan</td>
                            <td class="px-4 py-3.5 whitespace-nowrap">
                                <span class="badge {{ $item->is_active ? 'badge-success' : 'badge-neutral' }}">
                                    {{ $item->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>
                            <td class="px-6 py-3.5">
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button" @click="editPackage = {{ $item->id }}"
                                            @mouseenter="showTooltip($event, 'Edit')" @mouseleave="hideTooltip()"
                                            class="w-8 h-8 flex items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--surface-muted)] hover:text-[var(--brand)] transition-colors" aria-label="Edit">
                                        <span class="material-symbols-outlined text-[17px]">edit</span>
                                    </button>
                                    <form action="{{ route('package-templates.destroy', $item) }}" method="POST" class="inline"
                                          onsubmit="return appConfirm(this, 'Yakin hapus {{ addslashes($item->name) }}?', { danger: true })">
                                        @csrf @method('DELETE')
                                        <button type="submit"
                                                @mouseenter="showTooltip($event, 'Hapus')" @mouseleave="hideTooltip()"
                                                class="w-8 h-8 flex items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--danger-tint)] hover:text-[var(--danger-text)] transition-colors" aria-label="Hapus">
                                            <span class="material-symbols-outlined text-[17px]">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <template x-teleport="body">
                            <div x-show="editPackage === {{ $item->id }}" x-cloak
                                 x-on:keydown.escape.window="editPackage = null"
                                 class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                                <div class="absolute inset-0 bg-[#14181a]/40" @click="editPackage = null"></div>

                                <div x-show="editPackage === {{ $item->id }}" x-transition
                                     role="dialog" aria-modal="true" aria-labelledby="edit-package-modal-title-{{ $item->id }}" x-trap="editPackage === {{ $item->id }}"
                                     class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                                    <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                                        <h3 id="edit-package-modal-title-{{ $item->id }}" class="font-display text-lg font-semibold text-[var(--text-primary)]">Edit Paket</h3>
                                        <button type="button" @click="editPackage = null" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                                            <span class="material-symbols-outlined text-[19px]">close</span>
                                        </button>
                                    </div>

                                    <form action="{{ route('package-templates.update', $item) }}" method="POST">
                                        @csrf @method('PUT')
                                        <div class="px-6 py-5 space-y-4">
                                            <div>
                                                <label class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Nama Paket</label>
                                                <input type="text" name="name" required value="{{ $item->name }}"
                                                       class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                                            </div>
                                            <div class="flex gap-3">
                                                <div class="flex-1">
                                                    <label class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Kuota Konten</label>
                                                    <input type="number" name="monthly_content_quota" required min="0" value="{{ $item->monthly_content_quota }}"
                                                           class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                                                </div>
                                                <div class="flex-1">
                                                    <label class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Kuota Desain</label>
                                                    <input type="number" name="monthly_design_quota" required min="0" value="{{ $item->monthly_design_quota }}"
                                                           class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                                                </div>
                                            </div>
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" name="is_active" value="1" {{ $item->is_active ? 'checked' : '' }}
                                                       class="rounded border-[var(--border-strong)] text-[var(--brand)] focus:ring-[var(--brand)]">
                                                <span class="text-sm text-[var(--text-secondary)]">Aktif</span>
                                            </label>
                                        </div>
                                        <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                                            <button type="submit" class="btn-primary">Simpan</button>
                                            <button type="button" @click="editPackage = null" class="btn-secondary">Batal</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </template>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[26px] mb-2 block">database</span>
                                <p class="text-sm text-[var(--text-muted)]">Belum ada Paket.</p>
                                <p class="text-xs text-[var(--text-muted)] mt-1">Klik "Tambah Paket" di atas buat mulai.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
          </div>
        </div>
    @else
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
                                    <button type="submit"
                                            @mouseenter="showTooltip($event, 'Hapus')" @mouseleave="hideTooltip()"
                                            aria-label="Hapus"
                                            class="text-[var(--text-muted)] hover:text-[var(--danger-text)]">
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
    @endif

    {{-- Tooltip custom aksi tabel --}}
    <template x-teleport="body">
        <div x-show="tooltip.show" x-cloak x-transition.opacity.duration.100ms
            class="pointer-events-none fixed z-[100] whitespace-nowrap"
            :style="`top: ${tooltip.top}px; left: ${tooltip.left}px; transform: translate(-50%, -100%);`">
            <div class="relative bg-[var(--brand-solid)] text-white text-xs font-medium px-2.5 py-1.5 rounded-md shadow-lg">
                <span x-text="tooltip.text"></span>
                <span class="absolute top-full left-1/2 -translate-x-1/2 w-0 h-0 border-x-[5px] border-x-transparent border-t-[6px] border-t-[var(--brand)]"></span>
            </div>
        </div>
    </template>
</div>
