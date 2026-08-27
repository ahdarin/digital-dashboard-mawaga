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
        showCreateModal: {{ $errors->inviteUser->any() ? 'true' : 'false' }}
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

    {{-- Satu tabel, User sebagai satu-satunya entity person (keputusan
         final user: "satu orang = satu record", tidak ada TeamMember
         terpisah lagi). "Edit Role" mengedit user_roles (many-to-many,
         RBAC multi-role - satu user bisa punya beberapa role sekaligus),
         yang LANGSUNG jadi permission dashboard User terkait (lihat
         User::hasPermissionTo()). "Assign Klien" mengedit
         user_client_assignments langsung. Status kolom mencerminkan
         login_enabled (kapabilitas login), terpisah dari status
         aktif/nonaktif operasional (lifecycle akun) - staf tanpa akses
         login tetap bisa dinonaktifkan/diaktifkan secara operasional
         (mis. resign), tugas aktifnya tetap perlu dipindahkan. --}}
    <div class="card overflow-hidden hidden sm:block">
        <div class="overflow-x-auto">
            <table class="w-full table-fixed text-sm text-left">
                <thead class="bg-[var(--surface-page)]">
                    <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                        <th class="w-[19%] px-6 py-3 font-medium">Nama</th>
                        <th class="w-[19%] px-4 py-3 font-medium">Email</th>
                        <th class="w-[12%] px-4 py-3 font-medium">Role</th>
                        <th class="w-[21%] px-4 py-3 font-medium">Klien Ditangani</th>
                        <th class="w-[19%] px-4 py-3 font-medium">Status</th>
                        <th class="w-[10%] px-4 py-3 font-medium text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        @php
                            $userClients = $user->assignedClients->pluck('name');
                            $userClientsLabel = $userClients->isEmpty() ? '-' : $userClients->take(2)->join(', ').($userClients->count() > 2 ? ', +'.($userClients->count() - 2).' lainnya' : '');
                        @endphp
                        <tr class="border-t border-[var(--surface-muted)]">
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-full bg-[var(--brand-solid)] text-white flex items-center justify-center text-sm font-semibold shrink-0 overflow-hidden">
                                        @if ($user->avatar_url)
                                            <img src="{{ $user->avatar_url }}" alt="" referrerpolicy="no-referrer" class="w-full h-full object-cover">
                                        @else
                                            {{ strtoupper(substr($user->name, 0, 1)) }}
                                        @endif
                                    </div>
                                    <p class="font-medium text-[var(--text-primary)] truncate">{{ $user->name }}</p>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)] break-all">{{ $user->email ?? '-' }}</td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)]">{{ $user->roleNamesLabel() }}</td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)]">{{ $userClientsLabel }}</td>
                            <td class="px-4 py-3.5">
                                @if ($user->status === 'inactive')
                                    <span class="badge badge-danger">Nonaktif</span>
                                @elseif ($user->login_enabled)
                                    <span class="badge badge-success">Akses aktif</span>
                                @else
                                    <span class="badge badge-neutral">Belum ada akses</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="flex items-center justify-end gap-1">

                                    {{-- Edit Role --}}
                                    <button
                                        type="button"
                                        @click="editRoles = {{ $user->id }}"
                                        class="w-8 h-8 shrink-0 p-0 border-0 flex items-center justify-center rounded-lg
                                            text-[var(--text-muted)] hover:bg-[var(--surface-muted)]
                                            hover:text-[var(--brand)] transition-colors"
                                        title="Edit Role"
                                        aria-label="Edit Role"
                                    >
                                        <span class="material-symbols-outlined text-[18px] leading-none w-[18px] h-[18px] flex items-center justify-center">
                                            work
                                        </span>
                                    </button>

                                    {{-- Assign Klien --}}
                                    <button
                                        type="button"
                                        @click="openAssign = {{ $user->id }}"
                                        class="w-8 h-8 shrink-0 p-0 border-0 flex items-center justify-center rounded-lg
                                            text-[var(--text-muted)] hover:bg-[var(--surface-muted)]
                                            hover:text-[var(--brand)] transition-colors"
                                        title="Assign Klien"
                                        aria-label="Assign Klien"
                                    >
                                        <span class="material-symbols-outlined text-[18px] leading-none w-[18px] h-[18px] flex items-center justify-center">
                                            assignment_ind
                                        </span>
                                    </button>

                                    {{-- Akses Login --}}
                                    @if ($user->status !== 'inactive')
                                        <form
                                            action="{{ route('user-management.toggle-login-access', $user) }}"
                                            method="POST"
                                            class="w-8 h-8 shrink-0 flex items-center justify-center m-0 p-0"
                                        >
                                            @csrf
                                            @method('PATCH')

                                            <button
                                                type="submit"
                                                class="w-8 h-8 shrink-0 p-0 border-0 flex items-center justify-center rounded-lg
                                                    text-[var(--text-muted)] hover:bg-[var(--surface-muted)]
                                                    hover:text-[var(--brand)] transition-colors"
                                                title="{{ $user->login_enabled ? 'Cabut akses login' : 'Aktifkan akses login' }}"
                                                aria-label="{{ $user->login_enabled ? 'Cabut akses login' : 'Aktifkan akses login' }}"
                                            >
                                                <span class="material-symbols-outlined text-[18px] leading-none w-[18px] h-[18px] flex items-center justify-center">
                                                    {{ $user->login_enabled ? 'no_accounts' : 'login' }}
                                                </span>
                                            </button>
                                        </form>
                                    @endif

                                    {{-- Aktifkan / Nonaktifkan User --}}
                                    @if ($user->status === 'inactive')
                                        <button
                                            type="button"
                                            @click="confirmActivate = {{ $user->id }}"
                                            class="w-8 h-8 shrink-0 p-0 border-0 flex items-center justify-center rounded-lg
                                                text-[var(--text-muted)] hover:bg-[var(--success-tint)]
                                                hover:text-[var(--success-text)] transition-colors"
                                            title="Aktifkan"
                                            aria-label="Aktifkan user"
                                        >
                                            <span class="material-symbols-outlined text-[18px] leading-none w-[18px] h-[18px] flex items-center justify-center">
                                                restart_alt
                                            </span>
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            @click="confirmDeactivate = {{ $user->id }}"
                                            class="w-8 h-8 shrink-0 p-0 border-0 flex items-center justify-center rounded-lg
                                                text-[var(--text-muted)] hover:bg-[var(--danger-tint)]
                                                hover:text-[var(--danger-text)] transition-colors"
                                            title="Nonaktifkan"
                                            aria-label="Nonaktifkan user"
                                        >
                                            <span class="material-symbols-outlined text-[18px] leading-none w-[18px] h-[18px] flex items-center justify-center">
                                                toggle_off
                                            </span>
                                        </button>
                                    @endif

                                </div>
                            </td>
                        </tr>

                        {{-- Modal Assign Klien --}}
                        <template x-teleport="body">
                            <div x-show="openAssign === {{ $user->id }}" x-cloak
                                 x-on:keydown.escape.window="openAssign = null"
                                 class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                                <div class="absolute inset-0 bg-[#14181a]/40" @click="openAssign = null"></div>

                                <div x-show="openAssign === {{ $user->id }}" x-transition
                                     role="dialog" aria-modal="true" aria-labelledby="assign-client-modal-title-{{ $user->id }}" x-trap="openAssign === {{ $user->id }}"
                                     class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                                    <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                                        <div>
                                            <h3 id="assign-client-modal-title-{{ $user->id }}" class="font-display text-lg font-semibold text-[var(--text-primary)]">Assign Klien</h3>
                                            <p class="text-xs text-[var(--text-muted)] mt-0.5">Untuk {{ $user->name }} ({{ $user->roleNamesLabel() }})</p>
                                        </div>
                                        <button type="button" @click="openAssign = null" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                                            <span class="material-symbols-outlined text-[19px]">close</span>
                                        </button>
                                    </div>

                                    <form action="{{ route('user-management.clients.update', $user) }}" method="POST">
                                        @csrf @method('PUT')
                                        <div class="px-6 py-5">
                                            <p class="text-xs font-semibold text-[var(--text-muted)] uppercase mb-3">Pilih Client yang Ditangani</p>

                                            <div class="space-y-2 max-h-72 overflow-y-auto">
                                                @php $assignedIds = $user->assignedClients->pluck('id')->toArray(); @endphp
                                                @forelse ($allClients as $client)
                                                    <label class="flex items-center gap-3 p-3 border border-[var(--border)] rounded-lg hover:bg-[var(--surface-page)] cursor-pointer">
                                                        <input type="checkbox" name="client_ids[]" value="{{ $client->id }}"
                                                               {{ in_array($client->id, $assignedIds) ? 'checked' : '' }}
                                                               class="rounded border-[var(--border-strong)] text-[var(--brand)] focus:ring-[var(--brand)]">
                                                        <div>
                                                            <p class="text-sm font-medium text-[var(--text-primary)]">{{ $client->name }}</p>
                                                            <p class="text-xs text-[var(--text-muted)]">{{ $client->brand_name }}</p>
                                                        </div>
                                                    </label>
                                                @empty
                                                    <p class="text-xs text-[var(--text-muted)] italic">Belum ada client aktif.</p>
                                                @endforelse
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                                            <button type="submit" class="btn-primary">
                                                Simpan
                                            </button>
                                            <button type="button" @click="openAssign = null" class="btn-secondary">
                                                Batal
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </template>

                        {{-- Modal Edit Role --}}
                        <template x-teleport="body">
                            <div x-show="editRoles === {{ $user->id }}" x-cloak
                                 x-on:keydown.escape.window="editRoles = null"
                                 class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                                <div class="absolute inset-0 bg-[#14181a]/40" @click="editRoles = null"></div>

                                <div x-show="editRoles === {{ $user->id }}" x-transition
                                     role="dialog" aria-modal="true" aria-labelledby="edit-roles-modal-title-{{ $user->id }}" x-trap="editRoles === {{ $user->id }}"
                                     class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                                    <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                                        <div>
                                            <h3 id="edit-roles-modal-title-{{ $user->id }}" class="font-display text-lg font-semibold text-[var(--text-primary)]">Edit Role</h3>
                                            <p class="text-xs text-[var(--text-muted)] mt-0.5">Untuk {{ $user->name }}</p>
                                        </div>
                                        <button type="button" @click="editRoles = null" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                                            <span class="material-symbols-outlined text-[19px]">close</span>
                                        </button>
                                    </div>

                                    <form action="{{ route('user-management.role.update', $user) }}" method="POST">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="_edit_roles_user_id" value="{{ $user->id }}">
                                        <div class="px-6 py-5">
                                            <p class="text-xs font-semibold text-[var(--text-muted)] uppercase mb-3">Pilih Role</p>

                                            <div class="space-y-2 max-h-72 overflow-y-auto">
                                                @php $assignedRoleIds = $user->roles->pluck('id')->toArray(); @endphp
                                                @foreach ($roles as $role)
                                                    <label class="flex items-center gap-3 p-3 border border-[var(--border)] rounded-lg hover:bg-[var(--surface-page)] cursor-pointer">
                                                        <input type="checkbox" name="role_ids[]" value="{{ $role->id }}"
                                                               {{ in_array($role->id, $assignedRoleIds) ? 'checked' : '' }}
                                                               class="rounded border-[var(--border-strong)] text-[var(--brand)] focus:ring-[var(--brand)]">
                                                        <p class="text-sm font-medium text-[var(--text-primary)]">{{ $role->name }}</p>
                                                    </label>
                                                @endforeach
                                            </div>
                                            @error('role_ids', 'editRoles') <p class="text-[var(--danger-text)] text-xs mt-2">{{ $message }}</p> @enderror
                                        </div>

                                        <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                                            <button type="submit" class="btn-primary">
                                                Simpan
                                            </button>
                                            <button type="button" @click="editRoles = null" class="btn-secondary">
                                                Batal
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </template>

                        {{-- Modal Konfirmasi Nonaktifkan --}}
                        <template x-teleport="body">
                            <div x-show="confirmDeactivate === {{ $user->id }}" x-cloak
                                 x-on:keydown.escape.window="confirmDeactivate = null"
                                 class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                                <div class="absolute inset-0 bg-[#14181a]/40" @click="confirmDeactivate = null"></div>

                                <div x-show="confirmDeactivate === {{ $user->id }}" x-transition
                                     role="dialog" aria-modal="true" aria-labelledby="deactivate-user-modal-title-{{ $user->id }}" x-trap="confirmDeactivate === {{ $user->id }}"
                                     class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                                    <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                                        <div>
                                            <h3 id="deactivate-user-modal-title-{{ $user->id }}" class="font-display text-lg font-semibold text-[var(--text-primary)]">Nonaktifkan User</h3>
                                            <p class="text-xs text-[var(--text-muted)] mt-0.5">{{ $user->name }} ({{ $user->roleNamesLabel() }})</p>
                                        </div>
                                        <button type="button" @click="confirmDeactivate = null" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                                            <span class="material-symbols-outlined text-[19px]">close</span>
                                        </button>
                                    </div>

                                    <form action="{{ route('user-management.destroy', $user) }}" method="POST">
                                        @csrf @method('DELETE')
                                        <div class="px-6 py-5 space-y-4">
                                            <p class="text-sm text-[var(--text-secondary)]">Yakin ingin menonaktifkan <strong class="text-[var(--text-primary)]">{{ $user->name }}</strong>?
                                                @if ($user->login_enabled)
                                                    User tidak akan bisa login sampai diaktifkan kembali.
                                                @endif
                                            </p>

                                            @if ($user->active_task_count > 0)
                                                <div class="bg-[var(--warning-tint)] text-[var(--warning-text)] text-xs p-3 rounded-lg">
                                                    {{ $user->name }} masih PIC di <strong>{{ $user->active_task_count }} konten aktif</strong>. Pilih pengganti supaya konten-konten itu tidak nyangkut ke akun yang sudah dinonaktifkan.
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Pindahkan Semua Tugas Ke</label>
                                                    <select name="replacement_user_id" required
                                                            class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                                        <option value="">-- Pilih Pengganti --</option>
                                                        @foreach ($replacementOptions as $candidate)
                                                            @if ($candidate->id !== $user->id)
                                                                <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->roleNamesLabel() }})</option>
                                                            @endif
                                                        @endforeach
                                                    </select>
                                                </div>
                                            @endif
                                        </div>

                                        <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                                            <button type="submit" class="btn-danger">
                                                {{ $user->active_task_count > 0 ? 'Pindahkan Tugas & Nonaktifkan' : 'Ya, Nonaktifkan' }}
                                            </button>
                                            <button type="button" @click="confirmDeactivate = null" class="btn-secondary">
                                                Batal
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </template>

                        {{-- Modal Konfirmasi Aktifkan --}}
                        <template x-teleport="body">
                            <div x-show="confirmActivate === {{ $user->id }}" x-cloak
                                 x-on:keydown.escape.window="confirmActivate = null"
                                 class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                                <div class="absolute inset-0 bg-[#14181a]/40" @click="confirmActivate = null"></div>

                                <div x-show="confirmActivate === {{ $user->id }}" x-transition
                                     role="dialog" aria-modal="true" aria-labelledby="activate-user-modal-title-{{ $user->id }}" x-trap="confirmActivate === {{ $user->id }}"
                                     class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                                    <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                                        <div>
                                            <h3 id="activate-user-modal-title-{{ $user->id }}" class="font-display text-lg font-semibold text-[var(--text-primary)]">Aktifkan</h3>
                                            <p class="text-xs text-[var(--text-muted)] mt-0.5">{{ $user->name }} ({{ $user->roleNamesLabel() }})</p>
                                        </div>
                                        <button type="button" @click="confirmActivate = null" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                                            <span class="material-symbols-outlined text-[19px]">close</span>
                                        </button>
                                    </div>

                                    <div class="px-6 py-5">
                                        <p class="text-sm text-[var(--text-secondary)]">Aktifkan kembali <strong class="text-[var(--text-primary)]">{{ $user->name }}</strong>?
                                            @if ($user->login_enabled)
                                                User akan bisa login kembali seperti biasa.
                                            @endif
                                        </p>
                                    </div>

                                    <form action="{{ route('user-management.activate', $user) }}" method="POST">
                                        @csrf @method('PATCH')
                                        <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                                            <button type="submit" class="btn-primary">
                                                Ya, Aktifkan
                                            </button>
                                            <button type="button" @click="confirmActivate = null" class="btn-secondary">
                                                Batal
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </template>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">group_add</span>
                                <p class="text-sm text-[var(--text-muted)]">Belum ada anggota tim tercatat.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Mobile accordion list --}}
    <div class="sm:hidden space-y-3">
        @forelse ($users as $user)
            @php
                $userClientsMobile = $user->assignedClients->pluck('name');
                $userClientsMobileLabel = $userClientsMobile->isEmpty() ? '-' : $userClientsMobile->take(2)->join(', ').($userClientsMobile->count() > 2 ? ', +'.($userClientsMobile->count() - 2).' lainnya' : '');
            @endphp
            <div class="card p-3.5" x-data="{ open: false }">
                <button type="button" class="w-full text-left flex items-center justify-between gap-3 cursor-pointer" @click="open = !open" :aria-expanded="open">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-9 h-9 rounded-full bg-[var(--brand-solid)] text-white flex items-center justify-center text-sm font-semibold shrink-0 overflow-hidden">
                            @if ($user->avatar_url)
                                <img src="{{ $user->avatar_url }}" alt="" referrerpolicy="no-referrer" class="w-full h-full object-cover">
                            @else
                                {{ strtoupper(substr($user->name, 0, 1)) }}
                            @endif
                        </div>
                        <div class="min-w-0">
                            <p class="font-medium text-[var(--text-primary)] truncate">{{ $user->name }}</p>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-xs text-[var(--text-secondary)]">{{ $user->roleNamesLabel() }}</span>
                                @if ($user->status === 'inactive')
                                    <span class="badge badge-danger">Nonaktif</span>
                                @elseif ($user->login_enabled)
                                    <span class="badge badge-success">Akses aktif</span>
                                @else
                                    <span class="badge badge-neutral">Belum ada akses</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <span class="material-symbols-outlined text-[var(--text-muted)] transition-transform shrink-0" :class="open && 'rotate-180'">expand_more</span>
                </button>

                <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[var(--surface-muted)] space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Email</span>
                        <span class="text-[var(--text-primary)] font-medium truncate ml-3">{{ $user->email ?? '-' }}</span>
                    </div>

                    <div class="text-xs">
                        <span class="text-[var(--text-muted)] block mb-1.5">Klien Ditangani</span>
                        <span class="text-[var(--text-secondary)]">{{ $userClientsMobileLabel }}</span>
                    </div>

                    <div class="flex items-center gap-2 pt-1">
                        <button type="button" @click="editRoles = {{ $user->id }}"
                                class="w-8 h-8 flex items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--surface-muted)] hover:text-[var(--brand)] transition-colors" title="Edit Role">
                            <span class="material-symbols-outlined text-[17px]">work</span>
                        </button>
                        <button type="button" @click="openAssign = {{ $user->id }}"
                                class="w-8 h-8 flex items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--surface-muted)] hover:text-[var(--brand)] transition-colors" title="Assign Klien">
                            <span class="material-symbols-outlined text-[17px]">assignment_ind</span>
                        </button>

                        @if ($user->status !== 'inactive')
                            <form action="{{ route('user-management.toggle-login-access', $user) }}" method="POST">
                                @csrf @method('PATCH')
                                <button type="submit"
                                        class="w-8 h-8 flex items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--surface-muted)] hover:text-[var(--brand)] transition-colors"
                                        title="{{ $user->login_enabled ? 'Cabut akses login' : 'Aktifkan akses login' }}">
                                    <span class="material-symbols-outlined text-[17px]">{{ $user->login_enabled ? 'no_accounts' : 'login' }}</span>
                                </button>
                            </form>
                        @endif

                        @if ($user->status === 'inactive')
                            <button type="button" @click="confirmActivate = {{ $user->id }}"
                                    class="w-8 h-8 flex items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--success-tint)] hover:text-[var(--success-text)] transition-colors" title="Aktifkan">
                                <span class="material-symbols-outlined text-[17px]">restart_alt</span>
                            </button>
                        @else
                            <button type="button" @click="confirmDeactivate = {{ $user->id }}"
                                    class="w-8 h-8 flex items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--danger-tint)] hover:text-[var(--danger-text)] transition-colors" title="Nonaktifkan">
                                <span class="material-symbols-outlined text-[17px]">toggle_off</span>
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="card p-8 text-center">
                <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">group_add</span>
                <p class="text-sm text-[var(--text-muted)]">Belum ada anggota tim tercatat.</p>
            </div>
        @endforelse
    </div>

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
</div>
@endsection
