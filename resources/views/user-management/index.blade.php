@extends('layouts.app')
@section('title', 'Kelola Pengguna')
@section('content')

<div x-data="{
        openAssign: null,
        {{-- Kalau form Edit Role gagal validasi (mis. semua role dikosongkan),
             buka lagi modal Edit Role milik user yang sama - bukan modal lain.
             _edit_roles_user_id cuma diisi form Edit Role (lihat modalnya di
             bawah), jadi old()-nya nggak akan ke-trigger form lain manapun. --}}
        editRoles: {{ $errors->editRoles->any() && old('_edit_roles_user_id') ? (int) old('_edit_roles_user_id') : 'null' }},
        confirmDeactivate: null,
        confirmActivate: null,
        {{-- Bag 'inviteUser' - halaman ini juga punya form Edit Role yang
             divalidasi dengan field 'role_ids' yang sama persis; tanpa bag
             terpisah, gagal Edit Role ikut membuka modal Undang User ini. --}}
        showCreateModal: {{ $errors->inviteUser->any() ? 'true' : 'false' }},
        {{-- Tooltip custom aksi tabel - gaya sama seperti tooltip sidebar
             saat collapse, tapi muncul DI ATAS tombol (bukan di samping). --}}
        tooltip: { show: false, text: '', top: 0, left: 0 },
        showTooltip(event, text) {
            const rect = event.currentTarget.getBoundingClientRect();
            this.tooltip = { show: true, text, top: rect.top - 8, left: rect.left + rect.width / 2 };
        },
        hideTooltip() { this.tooltip.show = false; },
    }" class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[var(--text-primary)]">Kelola Pengguna</h1>
            <p class="text-[var(--text-secondary)] text-sm mt-1">
                Roster staf internal agensi - real dari Content Planner, lengkap dengan role dan status akses dashboard. Tidak semua staf punya akses login.
            </p>
        </div>

        <button type="button" @click="showCreateModal = true"
           class="self-start btn-primary">
            <span class="material-symbols-outlined text-[17px]">person_add</span>
            Undang User
        </button>
    </div>

    @if (session('status'))
        <div class="bg-[var(--brand-tint)] text-[var(--brand)] text-sm p-3.5 rounded-lg mb-5">{{ session('status') }}</div>
    @endif

    {{-- Tab Aktif/Nonaktif - pola sama seperti Produksi (Papan/Revisi/Sudah
         Tayang) - roster nonaktif dipisah ke tabel sendiri supaya tidak
         menumpuk di tabel utama. --}}
    <div class="flex items-center h-10 bg-[var(--surface-muted)] rounded-lg p-1 w-fit mb-5">
        <a href="{{ route('user-management.index') }}"
           class="flex items-center h-full text-xs font-medium px-4 rounded-md transition-colors {{ $tab === 'aktif' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]' }}">
            Aktif
        </a>
        <a href="{{ route('user-management.index', ['tab' => 'nonaktif']) }}"
           class="flex items-center h-full text-xs font-medium px-4 rounded-md transition-colors {{ $tab === 'nonaktif' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]' }}">
            Nonaktif
        </a>
    </div>

    @if ($tab === 'nonaktif')
        @include('user-management.partials.inactive-tab')
    @else
        @include('user-management.partials.active-tab')
    @endif

    {{-- Modal Undang User --}}
    <div x-show="showCreateModal" x-cloak x-transition
         x-on:keydown.escape.window="showCreateModal = false"
         class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-[#14181a]/40" @click="showCreateModal = false"></div>

        <div x-show="showCreateModal" x-transition
             role="dialog" aria-modal="true" aria-labelledby="invite-user-modal-title" x-trap="showCreateModal"
             class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                <h3 id="invite-user-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)]">Undang User Baru</h3>
                <button type="button" @click="showCreateModal = false" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                    <span class="material-symbols-outlined text-[19px]">close</span>
                </button>
            </div>

            <form action="{{ route('user-management.store') }}" method="POST">
                @csrf
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label for="invite_name" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Nama</label>
                        <input id="invite_name" type="text" name="name" value="{{ old('name') }}" required
                               class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                        @error('name', 'inviteUser') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="invite_email" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Email Google</label>
                        <input id="invite_email" type="email" name="email" value="{{ old('email') }}" required
                               placeholder="akan dipakai untuk login Google"
                               class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[var(--text-idle)] focus:outline-none focus:border-[#044b46]/40">
                        @error('email', 'inviteUser') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <p class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Role Sistem</p>
                        <div class="space-y-2 max-h-48 overflow-y-auto">
                            @php $oldRoleIds = old('role_ids', []); @endphp
                            @foreach ($roles as $role)
                                <label class="flex items-center gap-3 p-3 border border-[var(--border)] rounded-lg hover:bg-[var(--surface-page)] cursor-pointer">
                                    <input type="checkbox" name="role_ids[]" value="{{ $role->id }}"
                                           {{ in_array((string) $role->id, array_map('strval', $oldRoleIds), true) ? 'checked' : '' }}
                                           class="rounded border-[var(--border-strong)] text-[var(--brand)] focus:ring-[var(--brand)]">
                                    <p class="text-sm font-medium text-[var(--text-primary)]">{{ $role->name }}</p>
                                </label>
                            @endforeach
                        </div>
                        @error('role_ids', 'inviteUser') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                    <button type="submit" class="btn-primary">
                        Kirim Undangan
                    </button>
                    <button type="button" @click="showCreateModal = false" class="btn-secondary">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Tooltip custom aksi tabel - satu instance dipakai bareng oleh tab
         Aktif dan Nonaktif lewat showTooltip()/hideTooltip() di atas. --}}
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
@endsection
