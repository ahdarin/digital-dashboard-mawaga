@extends('layouts.app')
@section('title', 'User Management')
@section('content')

<div x-data="{ openAssign: null, confirmDeactivate: null, confirmActivate: null }" class="p-4 sm:p-6 lg:p-8 max-w-[1400px]">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-7">
        <div>
            <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[#14181a]">User Management</h1>
            <p class="text-[#5c6266] text-sm mt-1">Kelola akun tim internal 523 Studio.</p>
        </div>

        <a href="{{ route('user-management.create') }}"
           class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
            <span class="material-symbols-outlined text-[17px]">person_add</span>
            Undang User
        </a>
    </div>

    @if (session('status'))
        <div class="bg-[#f0f5f4] text-[#044b46] text-sm p-3.5 rounded-lg mb-5">{{ session('status') }}</div>
    @endif

    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="border-b border-[#f2f3f6] bg-[#f7f8fc]">
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Name</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Email</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Role</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Client Handled</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Status</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide text-right whitespace-nowrap">Action</th>
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
                                <span class="text-xs text-[#c3c7cb] italic">Belum ada client</span>
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
                                {{ ucfirst($user->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-3.5">
                            <div class="flex items-center justify-end gap-1">
                                <button type="button" @click="openAssign = {{ $user->id }}"
                                        class="w-8 h-8 flex items-center justify-center rounded-lg text-[#9aa0a4] hover:bg-[#f2f3f6] hover:text-[#044b46] transition-colors" title="Assign Client">
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

                    {{-- Modal Assign Client --}}
                    <template x-teleport="body">
                        <div x-show="openAssign === {{ $user->id }}" x-cloak
                             class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                            <div class="absolute inset-0 bg-[#14181a]/40" @click="openAssign = null"></div>

                            <div x-show="openAssign === {{ $user->id }}" x-transition
                                 class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
                                <div class="flex items-center justify-between px-6 py-5 border-b border-[#eef0f4]">
                                    <div>
                                        <h3 class="font-display text-lg font-semibold text-[#14181a]">Assign Client</h3>
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
                                        <h3 class="font-display text-lg font-semibold text-[#14181a]">Deactivate User</h3>
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
                                        <h3 class="font-display text-lg font-semibold text-[#14181a]">Activate User</h3>
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
                            <a href="{{ route('user-management.create') }}" class="text-xs text-[#044b46] font-medium hover:underline mt-1 inline-block">Undang user pertama</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
      </div>
    </div>
</div>
@endsection
