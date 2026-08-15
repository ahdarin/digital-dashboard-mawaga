@extends('layouts.app')
@section('title', 'Kelola Pengguna')
@section('content')

<div x-data="{ openAssign: null, confirmDeactivate: null, confirmActivate: null, showCreateModal: {{ $errors->any() ? 'true' : 'false' }} }" class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-7">
        <div>
            <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[#14181a]">Kelola Pengguna</h1>
            <p class="text-[#5c6266] text-sm mt-1">Kelola akun tim internal 523 Studio.</p>
        </div>

        <button type="button" @click="showCreateModal = true"
           class="self-start flex items-center gap-2 bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
            <span class="material-symbols-outlined text-[17px]">person_add</span>
            Undang User
        </button>
    </div>

    @if (session('status'))
        <div class="bg-[#f0f5f4] text-[#044b46] text-sm p-3.5 rounded-lg mb-5">{{ session('status') }}</div>
    @endif

    <div class="card overflow-hidden hidden sm:block">
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="border-b border-[#f2f3f6] bg-[#f7f8fc]">
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Nama</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Email</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Role</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Client Ditangani</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Status</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide text-right whitespace-nowrap">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr class="border-b border-[#f2f3f6] last:border-0 hover:bg-[#f7f8fc] transition-colors">
                        <td class="px-6 py-3.5">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full bg-[#044b46] text-white flex items-center justify-center text-sm font-semibold shrink-0 overflow-hidden">
                                    @if ($user->avatar_url)
                                        <img src="{{ $user->avatar_url }}" referrerpolicy="no-referrer" class="w-full h-full object-cover">
                                    @else
                                        {{ strtoupper(substr($user->name, 0, 1)) }}
                                    @endif
                                </div>
                                <p class="font-medium text-[#14181a]">{{ $user->name }}</p>
                            </div>
                        </td>
                        <td class="px-6 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $user->email }}</td>
                        <td class="px-6 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $user->role->name ?? '-' }}</td>
                        <td class="px-6 py-3.5">
                            @if ($user->assignedClients->isEmpty())
                                <span class="text-xs text-[#c3c7cb] italic">Belum ada client ditangani</span>
                            @else
                                <div class="flex flex-wrap gap-1 max-w-xs">
                                    @foreach ($user->assignedClients as $client)
                                        <span class="text-[10px] font-medium px-2 py-0.5 rounded-full bg-[#f2f3f6] text-[#5c6266]">{{ $client->name }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="px-6 py-3.5">
                            <span class="text-xs font-medium px-2.5 py-1 rounded-full whitespace-nowrap
                                {{ $user->status === 'active' ? 'bg-[#f0f5f4] text-[#0f7a5f]' : '' }}
                                {{ $user->status === 'invited' ? 'bg-[#fdf6ec] text-[#b8873a]' : '' }}
                                {{ $user->status === 'inactive' ? 'bg-[#f2f3f6] text-[#9aa0a4]' : '' }}">
                                {{ match($user->status) { 'active' => 'Aktif', 'invited' => 'Diundang', 'inactive' => 'Nonaktif', default => ucfirst($user->status) } }}
                            </span>
                        </td>
                        <td class="px-6 py-3.5">
                            <div class="flex items-center justify-end gap-1">
                                <button type="button" @click="openAssign = {{ $user->id }}"
                                        class="w-8 h-8 flex items-center justify-center rounded-lg text-[#9aa0a4] hover:bg-[#f2f3f6] hover:text-[#044b46] transition-colors" title="Assign Klien">
                                    <span class="material-symbols-outlined text-[17px]">assignment_ind</span>
                                </button>

                                @if ($user->status === 'inactive')
                                    <button type="button" @click="confirmActivate = {{ $user->id }}"
                                            class="w-8 h-8 flex items-center justify-center rounded-lg text-[#9aa0a4] hover:bg-[#f0f5f4] hover:text-[#0f7a5f] transition-colors" title="Aktifkan">
                                        <span class="material-symbols-outlined text-[17px]">restart_alt</span>
                                    </button>
                                @else
                                    <button type="button" @click="confirmDeactivate = {{ $user->id }}"
                                            class="w-8 h-8 flex items-center justify-center rounded-lg text-[#9aa0a4] hover:bg-[#fdf2f1] hover:text-[#b3423e] transition-colors" title="Nonaktifkan">
                                        <span class="material-symbols-outlined text-[17px]">toggle_off</span>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>

                    {{-- Modal Assign Klien --}}
                    <template x-teleport="body">
                        <div x-show="openAssign === {{ $user->id }}" x-cloak
                             class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                            <div class="absolute inset-0 bg-[#14181a]/40" @click="openAssign = null"></div>

                            <div x-show="openAssign === {{ $user->id }}" x-transition
                                 class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
                                <div class="flex items-center justify-between px-6 py-5 border-b border-[#eef0f4]">
                                    <div>
                                        <h3 class="font-display text-lg font-semibold text-[#14181a]">Assign Klien</h3>
                                        <p class="text-xs text-[#9aa0a4] mt-0.5">Untuk {{ $user->name }} ({{ $user->role->name ?? '-' }})</p>
                                    </div>
                                    <button type="button" @click="openAssign = null" class="text-[#9aa0a4] hover:text-[#5c6266]">
                                        <span class="material-symbols-outlined text-[19px]">close</span>
                                    </button>
                                </div>

                                <form action="{{ route('user-client-assignment.update', $user) }}" method="POST">
                                    @csrf @method('PUT')
                                    <div class="px-6 py-5">
                                        <p class="text-xs font-semibold text-[#9aa0a4] uppercase mb-3">Pilih Client yang Ditangani</p>

                                        <div class="space-y-2 max-h-72 overflow-y-auto">
                                            @forelse ($allClients as $client)
                                                @php $assignedIds = $user->assignedClients->pluck('id')->toArray(); @endphp
                                                <label class="flex items-center gap-3 p-3 border border-[#eef0f4] rounded-lg hover:bg-[#f7f8fc] cursor-pointer">
                                                    <input type="checkbox" name="client_ids[]" value="{{ $client->id }}"
                                                           {{ in_array($client->id, $assignedIds) ? 'checked' : '' }}
                                                           class="rounded border-[#dadfe0] text-[#044b46] focus:ring-[#044b46]">
                                                    <div>
                                                        <p class="text-sm font-medium text-[#14181a]">{{ $client->name }}</p>
                                                        <p class="text-xs text-[#9aa0a4]">{{ $client->brand_name }}</p>
                                                    </div>
                                                </label>
                                            @empty
                                                <p class="text-xs text-[#9aa0a4] italic">Belum ada client aktif.</p>
                                            @endforelse
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[#eef0f4]">
                                        <button type="submit" class="bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                                            Simpan
                                        </button>
                                        <button type="button" @click="openAssign = null" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">
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
                             class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                            <div class="absolute inset-0 bg-[#14181a]/40" @click="confirmDeactivate = null"></div>

                            <div x-show="confirmDeactivate === {{ $user->id }}" x-transition
                                 class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
                                <div class="flex items-center justify-between px-6 py-5 border-b border-[#eef0f4]">
                                    <div>
                                        <h3 class="font-display text-lg font-semibold text-[#14181a]">Nonaktifkan User</h3>
                                        <p class="text-xs text-[#9aa0a4] mt-0.5">{{ $user->name }} ({{ $user->role->name ?? '-' }})</p>
                                    </div>
                                    <button type="button" @click="confirmDeactivate = null" class="text-[#9aa0a4] hover:text-[#5c6266]">
                                        <span class="material-symbols-outlined text-[19px]">close</span>
                                    </button>
                                </div>

                                <div class="px-6 py-5">
                                    <p class="text-sm text-[#5c6266]">Yakin ingin menonaktifkan <strong class="text-[#14181a]">{{ $user->name }}</strong>? User tidak akan bisa login sampai diaktifkan kembali.</p>
                                </div>

                                <form action="{{ route('user-management.destroy', $user) }}" method="POST">
                                    @csrf @method('DELETE')
                                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[#eef0f4]">
                                        <button type="submit" class="bg-[#b3423e] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#9c3733] transition-colors">
                                            Ya, Nonaktifkan
                                        </button>
                                        <button type="button" @click="confirmDeactivate = null" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">
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
                             class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                            <div class="absolute inset-0 bg-[#14181a]/40" @click="confirmActivate = null"></div>

                            <div x-show="confirmActivate === {{ $user->id }}" x-transition
                                 class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
                                <div class="flex items-center justify-between px-6 py-5 border-b border-[#eef0f4]">
                                    <div>
                                        <h3 class="font-display text-lg font-semibold text-[#14181a]">Aktifkan User</h3>
                                        <p class="text-xs text-[#9aa0a4] mt-0.5">{{ $user->name }} ({{ $user->role->name ?? '-' }})</p>
                                    </div>
                                    <button type="button" @click="confirmActivate = null" class="text-[#9aa0a4] hover:text-[#5c6266]">
                                        <span class="material-symbols-outlined text-[19px]">close</span>
                                    </button>
                                </div>

                                <div class="px-6 py-5">
                                    <p class="text-sm text-[#5c6266]">Aktifkan kembali <strong class="text-[#14181a]">{{ $user->name }}</strong>? User akan bisa login kembali seperti biasa.</p>
                                </div>

                                <form action="{{ route('user-management.activate', $user) }}" method="POST">
                                    @csrf @method('PATCH')
                                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[#eef0f4]">
                                        <button type="submit" class="bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                                            Ya, Aktifkan
                                        </button>
                                        <button type="button" @click="confirmActivate = null" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">
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
                            <span class="material-symbols-outlined text-[#d4d7db] text-[28px] mb-2 block">group_add</span>
                            <p class="text-sm text-[#9aa0a4]">Belum ada user internal.</p>
                            <button type="button" @click="showCreateModal = true" class="text-xs text-[#044b46] font-medium hover:underline mt-1 inline-block">Undang user pertama</button>
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
            <div class="card p-3.5" x-data="{ open: false }">
                <div class="flex items-center justify-between gap-3 cursor-pointer" @click="open = !open">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-9 h-9 rounded-full bg-[#044b46] text-white flex items-center justify-center text-sm font-semibold shrink-0 overflow-hidden">
                            @if ($user->avatar_url)
                                <img src="{{ $user->avatar_url }}" referrerpolicy="no-referrer" class="w-full h-full object-cover">
                            @else
                                {{ strtoupper(substr($user->name, 0, 1)) }}
                            @endif
                        </div>
                        <div class="min-w-0">
                            <p class="font-medium text-[#14181a] truncate">{{ $user->name }}</p>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-xs text-[#5c6266]">{{ $user->role->name ?? '-' }}</span>
                                <span class="text-[10px] font-medium px-2 py-0.5 rounded-full whitespace-nowrap
                                    {{ $user->status === 'active' ? 'bg-[#f0f5f4] text-[#0f7a5f]' : '' }}
                                    {{ $user->status === 'invited' ? 'bg-[#fdf6ec] text-[#b8873a]' : '' }}
                                    {{ $user->status === 'inactive' ? 'bg-[#f2f3f6] text-[#9aa0a4]' : '' }}">
                                    {{ match($user->status) { 'active' => 'Aktif', 'invited' => 'Diundang', 'inactive' => 'Nonaktif', default => ucfirst($user->status) } }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <span class="material-symbols-outlined text-[#9aa0a4] transition-transform shrink-0" :class="open && 'rotate-180'">expand_more</span>
                </div>

                <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[#f2f3f6] space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#9aa0a4]">Email</span>
                        <span class="text-[#14181a] font-medium truncate ml-3">{{ $user->email }}</span>
                    </div>

                    <div class="text-xs">
                        <span class="text-[#9aa0a4] block mb-1.5">Client Ditangani</span>
                        @if ($user->assignedClients->isEmpty())
                            <span class="text-xs text-[#c3c7cb] italic">Belum ada client ditangani</span>
                        @else
                            <div class="flex flex-wrap gap-1">
                                @foreach ($user->assignedClients as $client)
                                    <span class="text-[10px] font-medium px-2 py-0.5 rounded-full bg-[#f2f3f6] text-[#5c6266]">{{ $client->name }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-2 pt-1">
                        <button type="button" @click="openAssign = {{ $user->id }}"
                                class="w-8 h-8 flex items-center justify-center rounded-lg text-[#9aa0a4] hover:bg-[#f2f3f6] hover:text-[#044b46] transition-colors" title="Assign Klien">
                            <span class="material-symbols-outlined text-[17px]">assignment_ind</span>
                        </button>

                        @if ($user->status === 'inactive')
                            <button type="button" @click="confirmActivate = {{ $user->id }}"
                                    class="w-8 h-8 flex items-center justify-center rounded-lg text-[#9aa0a4] hover:bg-[#f0f5f4] hover:text-[#0f7a5f] transition-colors" title="Aktifkan">
                                <span class="material-symbols-outlined text-[17px]">restart_alt</span>
                            </button>
                        @else
                            <button type="button" @click="confirmDeactivate = {{ $user->id }}"
                                    class="w-8 h-8 flex items-center justify-center rounded-lg text-[#9aa0a4] hover:bg-[#fdf2f1] hover:text-[#b3423e] transition-colors" title="Nonaktifkan">
                                <span class="material-symbols-outlined text-[17px]">toggle_off</span>
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="card p-8 text-center">
                <span class="material-symbols-outlined text-[#d4d7db] text-[28px] mb-2 block">group_add</span>
                <p class="text-sm text-[#9aa0a4]">Belum ada user internal.</p>
                <button type="button" @click="showCreateModal = true" class="text-xs text-[#044b46] font-medium hover:underline mt-1 inline-block">Undang user pertama</button>
            </div>
        @endforelse
    </div>

    {{-- Modal Undang User --}}
    <div x-show="showCreateModal" x-cloak x-transition
         class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-[#14181a]/40" @click="showCreateModal = false"></div>

        <div x-show="showCreateModal" x-transition
             class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
            <div class="flex items-center justify-between px-6 py-5 border-b border-[#eef0f4]">
                <h3 class="font-display text-lg font-semibold text-[#14181a]">Undang User Baru</h3>
                <button type="button" @click="showCreateModal = false" class="text-[#9aa0a4] hover:text-[#5c6266]">
                    <span class="material-symbols-outlined text-[19px]">close</span>
                </button>
            </div>

            <form action="{{ route('user-management.store') }}" method="POST">
                @csrf
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Nama</label>
                        <input type="text" name="name" value="{{ old('name') }}" required
                               class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                        @error('name') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Email Google</label>
                        <input type="email" name="email" value="{{ old('email') }}" required
                               placeholder="akan dipakai untuk login Google"
                               class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[#c3c7cb] focus:outline-none focus:border-[#044b46]/40">
                        @error('email') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Role</label>
                        <select name="role_id" required class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                            <option value="">-- Pilih Role --</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}" {{ old('role_id') == $role->id ? 'selected' : '' }}>{{ $role->name }}</option>
                            @endforeach
                        </select>
                        @error('role_id') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex items-center gap-3 px-6 py-4 border-t border-[#eef0f4]">
                    <button type="submit" class="bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                        Kirim Undangan
                    </button>
                    <button type="button" @click="showCreateModal = false" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
