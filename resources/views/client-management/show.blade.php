@extends('layouts.app')
@section('title', $client->brand_name)
@section('content')

@php $canManageClient = auth()->user()->hasPermissionTo('client', 'manage'); @endphp
<div x-data="{ showPackageModal: false, showPicModal: {{ $errors->has('user_ids') ? 'true' : 'false' }}, removePic: null }" class="p-4 sm:p-6 lg:p-8 max-w-[1300px] mx-auto">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-7">
        <div class="flex items-center gap-4">
            <a href="{{ route('client-management.index') }}"
               class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[var(--surface-card)] text-[var(--text-secondary)] transition-colors shrink-0">
                <span class="material-symbols-outlined text-[19px]">arrow_back</span>
            </a>
            <div class="rounded-xl bg-[var(--brand-tint)] text-[var(--brand)] flex items-center justify-center text-lg font-semibold shrink-0 overflow-hidden" style="width:52px;height:52px">
                @if ($client->logo_url)
                    <img src="{{ $client->logo_url }}" alt="" class="w-full h-full object-cover">
                @else
                    {{ strtoupper(substr($client->brand_name, 0, 1)) }}
                @endif
            </div>
            <div>
                <div class="flex items-center gap-2.5">
                    <h1 class="font-display text-2xl font-semibold text-[var(--text-primary)]">{{ $client->brand_name }}</h1>
                    <span class="badge
                        {{ $client->status === 'active' ? 'badge-success' : '' }}
                        {{ $client->status === 'past_due' ? 'badge-danger' : '' }}
                        {{ $client->status === 'paused' ? 'badge-neutral' : '' }}">
                        {{ match($client->status) { 'active' => 'Aktif', 'past_due' => 'Jatuh Tempo', 'paused' => 'Dijeda', default => ucfirst($client->status) } }}
                    </span>
                </div>
                <p class="text-sm text-[var(--text-muted)] mt-0.5">{{ $client->name }} &middot; {{ $client->category->name ?? '-' }}</p>
            </div>
        </div>

        @if ($canManageClient)
            <a href="{{ route('client-management.edit', $client) }}"
               class="btn-primary shrink-0">
                <span class="material-symbols-outlined text-[17px]">edit</span> Edit Klien
            </a>
        @else
            <span title="Cuma CEO/Manager yang bisa mengubah data klien"
                  class="btn-primary shrink-0 opacity-40 cursor-not-allowed pointer-events-none">
                <span class="material-symbols-outlined text-[17px]">edit</span> Edit Klien
            </span>
        @endif
    </div>

    @if (session('status'))
        <div class="bg-[var(--brand-tint)] text-[var(--brand)] text-sm p-3.5 rounded-lg mb-6">{{ session('status') }}</div>
    @endif

    <div class="flex flex-col lg:flex-row gap-5 items-stretch lg:items-start">

        <div class="flex-1 min-w-0 space-y-5">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div class="card p-6">
                    <p class="text-sm text-[var(--text-secondary)] mb-2">Total Rencana Konten</p>
                    <p class="font-display text-2xl font-semibold text-[var(--text-primary)]">{{ $planCount }}</p>
                </div>
                <div class="card p-6">
                    <p class="text-sm text-[var(--text-secondary)] mb-2">Total Konten Dibuat</p>
                    <p class="font-display text-2xl font-semibold text-[var(--text-primary)]">{{ $contentCount }}</p>
                </div>
            </div>

            <div class="card p-6">
                <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-4">Konten Terbaru</h2>

                @if ($recentContentItems->isEmpty())
                    <p class="text-sm text-[var(--text-muted)] py-6 text-center">Belum ada konten untuk klien ini.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-[var(--surface-page)]">
                                <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                                    <th class="px-6 py-3 font-medium whitespace-nowrap">Judul</th>
                                    <th class="px-4 py-3 font-medium whitespace-nowrap">Tipe</th>
                                    <th class="px-4 py-3 font-medium whitespace-nowrap">Deadline</th>
                                    <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentContentItems as $item)
                                    <tr class="border-t border-[var(--surface-muted)]">
                                        <td class="px-6 py-3.5 font-medium text-[var(--text-primary)] whitespace-nowrap">{{ $item->title }}</td>
                                        <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $item->contentType->name ?? '-' }}</td>
                                        <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $item->deadline_at ? $item->deadline_at->translatedFormat('d M Y') : '-' }}</td>
                                        <td class="px-4 py-3.5">
                                            <span class="badge {{ ($item->workflow->is_overdue ?? false) ? 'badge-danger' : 'badge-success' }}">
                                                {{ $item->workflow ? \App\Support\WorkflowTransitions::label($item->workflow->current_status) : '-' }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

        </div>

        <div class="w-full lg:w-[320px] shrink-0 space-y-5">

            <div class="card p-6">
                <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-4">Akun Owner</h2>

                @if ($client->owner)
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-9 h-9 rounded-full bg-[var(--brand-solid)] text-white flex items-center justify-center text-sm font-semibold shrink-0">
                            {{ strtoupper(substr($client->owner->name, 0, 1)) }}
                        </div>
                        <div>
                            <p class="text-sm font-medium text-[var(--text-primary)]">{{ $client->owner->name }}</p>
                            <p class="text-xs text-[var(--text-muted)]">{{ match($client->owner->status) { 'active' => 'Aktif', 'invited' => 'Diundang', 'inactive' => 'Nonaktif', default => ucfirst($client->owner->status) } }}</p>
                        </div>
                    </div>

                    <div class="space-y-2.5 text-sm">
                        <div class="flex items-center gap-2 text-[var(--text-secondary)]">
                            <span class="material-symbols-outlined text-[15px]">call</span> {{ $client->owner->phone_number ?? '-' }}
                        </div>
                    </div>
                @else
                    <p class="text-sm text-[var(--text-muted)]">Belum ada akun owner terdaftar.</p>
                @endif
            </div>

            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Paket Aktif</h2>
                    <button type="button" @click="showPackageModal = true" {{ $canManageClient ? '' : 'disabled' }}
                            title="{{ $canManageClient ? '' : 'Cuma CEO/Manager yang bisa mengubah paket' }}"
                            class="text-xs font-medium text-[var(--brand)] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline">Ubah Paket</button>
                </div>

                @if ($client->activePackage)
                    <p class="text-sm font-medium text-[var(--text-primary)]">{{ $client->activePackage->package_name_snapshot }}</p>
                    <div class="mt-3 space-y-1.5 text-xs text-[var(--text-secondary)]">
                        <p>Kuota Konten: {{ $client->activePackage->monthly_content_quota }} / bulan</p>
                        <p>Kuota Desain: {{ $client->activePackage->monthly_design_quota }} / bulan</p>
                        <p>Periode: {{ optional($client->activePackage->start_date)->translatedFormat('d M Y') }} &mdash;
                            {{ optional($client->activePackage->end_date)->translatedFormat('d M Y') ?? 'Berjalan' }}</p>
                    </div>
                @else
                    <p class="text-sm text-[var(--text-muted)]">Belum ada paket aktif.</p>
                @endif
            </div>

            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">PIC Ditugaskan</h2>
                    <button type="button" @click="showPicModal = true" {{ $canManageClient ? '' : 'disabled' }}
                            title="{{ $canManageClient ? '' : 'Cuma CEO/Manager yang bisa mengatur PIC' }}"
                            class="text-xs font-medium text-[var(--brand)] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline">Atur PIC</button>
                </div>

                @if ($client->assignedUsers->isEmpty())
                    <p class="text-sm text-[var(--text-muted)]">Belum ada PIC ditugaskan - konten untuk klien ini belum bisa dibuat sampai ada yang di-assign.</p>
                @else
                    <div class="space-y-2.5">
                        @foreach ($client->assignedUsers as $staff)
                            @php $picActiveCount = $picActiveCounts[$staff->id] ?? 0; @endphp
                            <div class="flex items-center gap-2.5">
                                <div class="w-7 h-7 rounded-full bg-[var(--brand-solid)] text-white flex items-center justify-center text-xs font-semibold shrink-0 overflow-hidden">
                                    @if ($staff->avatar_url)
                                        <img src="{{ $staff->avatar_url }}" alt="" referrerpolicy="no-referrer" class="w-full h-full object-cover">
                                    @else
                                        {{ strtoupper(substr($staff->name, 0, 1)) }}
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-[var(--text-primary)] truncate">{{ $staff->name }}</p>
                                    <p class="text-xs text-[var(--text-muted)] truncate">{{ $staff->roleNamesLabel() }}
                                        @if ($picActiveCount > 0)
                                            &middot; {{ $picActiveCount }} konten aktif
                                        @endif
                                    </p>
                                </div>
                                @if ($canManageClient)
                                    <button type="button" @click="removePic = {{ $staff->id }}" title="Keluarkan dari PIC"
                                            class="w-7 h-7 flex items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--danger-tint)] hover:text-[var(--danger-text)] transition-colors shrink-0">
                                        <span class="material-symbols-outlined text-[16px]">person_remove</span>
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="card p-6">
                <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-4">Aset Klien</h2>

                @if ($client->asset_link)
                    <a href="{{ $client->asset_link }}" target="_blank" rel="noopener"
                       class="flex items-center gap-2.5 bg-[var(--brand-tint)] text-[var(--brand)] text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[var(--brand-tint-hover)] transition-colors">
                        <span class="material-symbols-outlined text-[17px]">folder_open</span>
                        Buka Google Drive
                        <span class="material-symbols-outlined text-[14px] ml-auto">open_in_new</span>
                    </a>
                @elseif ($canManageClient)
                    <p class="text-sm text-[var(--text-muted)]">Belum ada link aset. <a href="{{ route('client-management.edit', $client) }}" class="text-[var(--brand)] hover:underline">Tambahkan di Edit Klien</a>.</p>
                @else
                    <p class="text-sm text-[var(--text-muted)]">Belum ada link aset.</p>
                @endif
            </div>

            <div class="card p-6">
                <h2 class="text-sm font-semibold text-[var(--danger-text)] mb-3">Zona Berbahaya</h2>
                <form action="{{ route('client-management.destroy', $client) }}" method="POST"
                      onsubmit="return appConfirm(this, 'Yakin hapus {{ addslashes($client->brand_name) }}? Kalau sudah punya riwayat konten, klien hanya akan dinonaktifkan, bukan dihapus permanen.', { danger: true })">
                    @csrf
                    @method('DELETE')
                    <button type="submit" {{ $canManageClient ? '' : 'disabled' }}
                            title="{{ $canManageClient ? '' : 'Cuma CEO/Manager yang bisa menghapus klien' }}"
                            class="btn-danger w-full disabled:opacity-40 disabled:cursor-not-allowed">
                        Hapus Klien
                    </button>
                </form>
            </div>

        </div>

    </div>

    {{-- Modal Ubah Paket --}}
    <template x-teleport="body">
        <div x-show="showPackageModal" x-cloak
             x-on:keydown.escape.window="showPackageModal = false"
             class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
            <div class="absolute inset-0 bg-[#14181a]/40" @click="showPackageModal = false"></div>

            <div x-show="showPackageModal" x-transition
                 role="dialog" aria-modal="true" aria-labelledby="package-modal-title" x-trap="showPackageModal"
                 class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                    <div>
                        <h3 id="package-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)]">Ubah Paket</h3>
                        <p class="text-xs text-[var(--text-muted)] mt-0.5">Untuk {{ $client->brand_name }}</p>
                    </div>
                    <button type="button" @click="showPackageModal = false" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                        <span class="material-symbols-outlined text-[19px]">close</span>
                    </button>
                </div>

                @if ($packageTemplates->isEmpty())
                    <div class="px-6 py-5">
                        <p class="text-sm text-[var(--text-secondary)]">Belum ada paket tersedia. Tambahkan dulu di
                            <a href="{{ route('settings', ['tab' => 'data-pilihan', 'type' => 'package-template']) }}" class="text-[var(--brand)] hover:underline">Pengaturan &rarr; Data Pilihan &rarr; Paket</a>.</p>
                    </div>
                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                        <button type="button" @click="showPackageModal = false" class="btn-secondary">Tutup</button>
                    </div>
                @else
                    <form action="{{ route('client-management.package.update', $client) }}" method="POST">
                        @csrf @method('PUT')
                        <div class="px-6 py-5">
                            <label class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Pilih Paket</label>
                            <select name="package_template_id" required
                                    class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                <option value="">-- Pilih Paket --</option>
                                @foreach ($packageTemplates as $template)
                                    <option value="{{ $template->id }}" {{ $client->activePackage?->package_template_id === $template->id ? 'selected' : '' }}>
                                        {{ $template->name }} ({{ $template->monthly_content_quota }} Konten / {{ $template->monthly_design_quota }} Desain)
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                            <button type="submit" class="btn-primary">Simpan</button>
                            <button type="button" @click="showPackageModal = false" class="btn-secondary">Batal</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </template>

    {{-- Modal Atur PIC --}}
    <template x-teleport="body">
        <div x-show="showPicModal" x-cloak
             x-on:keydown.escape.window="showPicModal = false"
             class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
            <div class="absolute inset-0 bg-[#14181a]/40" @click="showPicModal = false"></div>

            <div x-show="showPicModal" x-transition
                 role="dialog" aria-modal="true" aria-labelledby="pic-modal-title" x-trap="showPicModal"
                 class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                    <div>
                        <h3 id="pic-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)]">Atur PIC</h3>
                        <p class="text-xs text-[var(--text-muted)] mt-0.5">Untuk {{ $client->brand_name }}</p>
                    </div>
                    <button type="button" @click="showPicModal = false" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                        <span class="material-symbols-outlined text-[19px]">close</span>
                    </button>
                </div>

                <form action="{{ route('client-management.pic.update', $client) }}" method="POST">
                    @csrf @method('PUT')
                    <div class="px-6 py-5">
                        <p class="text-xs font-semibold text-[var(--text-muted)] uppercase mb-3">Pilih Staff yang Jadi PIC</p>
                        @error('user_ids') <p class="text-xs text-[var(--danger-text)] mb-3">{{ $message }}</p> @enderror

                        <div class="space-y-2 max-h-72 overflow-y-auto">
                            @php
                                $assignedStaffIds = $client->assignedUsers->pluck('id')->toArray();
                            @endphp
                            @forelse ($staffOptions as $staff)
                                @php
                                    $isAssigned = in_array($staff->id, $assignedStaffIds);
                                    $lockChecked = $isAssigned && ($picActiveCounts[$staff->id] ?? 0) > 0;
                                @endphp
                                <label class="flex items-center gap-3 p-3 border border-[var(--border)] rounded-lg {{ $lockChecked ? '' : 'hover:bg-[var(--surface-page)] cursor-pointer' }}">
                                    <input type="checkbox" name="user_ids[]" value="{{ $staff->id }}"
                                           {{ $isAssigned ? 'checked' : '' }} {{ $lockChecked ? 'disabled' : '' }}
                                           class="rounded border-[var(--border-strong)] text-[var(--brand)] focus:ring-[var(--brand)]">
                                    {{-- Checkbox disabled tidak ikut ke-submit - hidden input jaga dia tetap terhitung "assigned" di request. --}}
                                    @if ($lockChecked)
                                        <input type="hidden" name="user_ids[]" value="{{ $staff->id }}">
                                    @endif
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-[var(--text-primary)]">{{ $staff->name }}</p>
                                        <p class="text-xs text-[var(--text-muted)]">{{ $staff->roleNamesLabel() }}
                                            @if ($lockChecked)
                                                &middot; {{ $picActiveCounts[$staff->id] }} konten aktif, keluarkan lewat tombol "Keluarkan" di kartu PIC
                                            @endif
                                        </p>
                                    </div>
                                </label>
                            @empty
                                <p class="text-xs text-[var(--text-muted)] italic">Belum ada staff internal aktif.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                        <button type="submit" class="btn-primary">Simpan</button>
                        <button type="button" @click="showPicModal = false" class="btn-secondary">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </template>

    {{-- Modal Keluarkan dari PIC - satu per staff ter-assign, biar bisa
         nawarin pengganti kalau dia masih PIC di konten aktif client ini. --}}
    @foreach ($client->assignedUsers as $staff)
        @php $picActiveCount = $picActiveCounts[$staff->id] ?? 0; @endphp
        <template x-teleport="body">
            <div x-show="removePic === {{ $staff->id }}" x-cloak
                 x-on:keydown.escape.window="removePic = null"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                <div class="absolute inset-0 bg-[#14181a]/40" @click="removePic = null"></div>

                <div x-show="removePic === {{ $staff->id }}" x-transition
                     role="dialog" aria-modal="true" aria-labelledby="remove-pic-modal-title-{{ $staff->id }}" x-trap="removePic === {{ $staff->id }}"
                     class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                        <div>
                            <h3 id="remove-pic-modal-title-{{ $staff->id }}" class="font-display text-lg font-semibold text-[var(--text-primary)]">Keluarkan dari PIC</h3>
                            <p class="text-xs text-[var(--text-muted)] mt-0.5">{{ $staff->name }} &middot; {{ $client->brand_name }}</p>
                        </div>
                        <button type="button" @click="removePic = null" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                            <span class="material-symbols-outlined text-[19px]">close</span>
                        </button>
                    </div>

                    <form action="{{ route('client-management.pic.remove', [$client, $staff]) }}" method="POST">
                        @csrf @method('DELETE')
                        <div class="px-6 py-5 space-y-4">
                            @if ($picActiveCount > 0)
                                <div class="bg-[var(--warning-tint)] text-[var(--warning-text)] text-xs p-3 rounded-lg">
                                    {{ $staff->name }} masih PIC di <strong>{{ $picActiveCount }} konten aktif</strong> untuk {{ $client->brand_name }}. Pilih pengganti supaya konten-konten itu tidak nyangkut.
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Pindahkan Semua Tugas Ke</label>
                                    <select name="replacement_user_id" required
                                            class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                        <option value="">-- Pilih Pengganti --</option>
                                        @foreach ($client->assignedUsers as $otherStaff)
                                            @if ($otherStaff->id !== $staff->id)
                                                <option value="{{ $otherStaff->id }}">{{ $otherStaff->name }} ({{ $otherStaff->roleNamesLabel() }})</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    @if ($client->assignedUsers->count() <= 1)
                                        <p class="text-xs text-[var(--danger-text)] mt-1.5">Belum ada PIC lain di client ini buat jadi pengganti - tambahkan PIC lain dulu lewat "Atur PIC".</p>
                                    @endif
                                </div>
                            @else
                                <p class="text-sm text-[var(--text-secondary)]">Yakin keluarkan <strong class="text-[var(--text-primary)]">{{ $staff->name }}</strong> dari PIC {{ $client->brand_name }}?</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                            <button type="submit" class="btn-danger">Keluarkan</button>
                            <button type="button" @click="removePic = null" class="btn-secondary">Batal</button>
                        </div>
                    </form>
                </div>
            </div>
        </template>
    @endforeach
</div>
@endsection