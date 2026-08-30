{{-- Tab "Nonaktif" - roster staf berstatus nonaktif. Susunan kolom sama
     persis dengan tab Aktif, tapi satu-satunya aksi yang tersedia adalah
     Aktifkan - user dengan status ini tidak bisa login apa pun nilai
     login_enabled-nya, jadi Edit Role/Assign Klien/toggle akses tidak
     relevan sampai diaktifkan kembali. --}}
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
                            <span class="badge badge-danger">Nonaktif</span>
                        </td>
                        <td class="px-4 py-3.5">
                            @if (auth()->user()->hasPermissionTo('user_management', 'manage'))
                            <div class="flex items-center justify-end gap-1">
                                {{-- Aktifkan User --}}
                                <button
                                    type="button"
                                    @click="confirmActivate = {{ $user->id }}"
                                    @mouseenter="showTooltip($event, 'Aktifkan')"
                                    @mouseleave="hideTooltip()"
                                    class="w-8 h-8 shrink-0 p-0 border-0 flex items-center justify-center rounded-lg
                                        text-[var(--text-muted)] hover:bg-[var(--success-tint)]
                                        hover:text-[var(--success-text)] transition-colors"
                                    aria-label="Aktifkan user"
                                >
                                    <span class="material-symbols-outlined text-[18px] leading-none w-[18px] h-[18px] flex items-center justify-center">
                                        restart_alt
                                    </span>
                                </button>
                            </div>
                            @else
                                <p class="text-right text-xs text-[var(--text-idle)]">-</p>
                            @endif
                        </td>
                    </tr>

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
                            <p class="text-sm text-[var(--text-muted)]">Belum ada anggota tim nonaktif.</p>
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
                            <span class="badge badge-danger">Nonaktif</span>
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

                @if (auth()->user()->hasPermissionTo('user_management', 'manage'))
                <div class="flex items-center gap-2 pt-1">
                    <button type="button" @click="confirmActivate = {{ $user->id }}"
                            class="w-8 h-8 flex items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--success-tint)] hover:text-[var(--success-text)] transition-colors" aria-label="Aktifkan user">
                        <span class="material-symbols-outlined text-[17px]">restart_alt</span>
                    </button>
                </div>
                @endif
            </div>
        </div>
    @empty
        <div class="card p-8 text-center">
            <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">group_add</span>
            <p class="text-sm text-[var(--text-muted)]">Belum ada anggota tim nonaktif.</p>
        </div>
    @endforelse
</div>
