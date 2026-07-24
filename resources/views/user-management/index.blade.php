@extends('layouts.app')
@section('title', 'User Management')
@section('content')
<div class="p-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-[#191c1c]">User Management</h2>
            <p class="text-sm text-gray-500 mt-1">Kelola akun tim internal 523 Studio</p>
        </div>
        <a href="{{ route('user-management.create') }}"
           class="bg-[#044b46] text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-[#044b46]/90">
            + Undang User
        </a>
    </div>

    @if (session('status'))
        <div class="bg-teal-50 text-[#044b46] text-sm p-3 rounded-lg mb-4">{{ session('status') }}</div>
    @endif

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                <tr>
                    <th class="px-4 py-3">Nama</th>
                    <th class="px-4 py-3">Email</th>
                    <th class="px-4 py-3">Role</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr class="border-t border-gray-100">
                        <td class="px-4 py-3 font-medium">{{ $user->name }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $user->email }}</td>
                        <td class="px-4 py-3">{{ $user->role->name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs px-2 py-1 rounded-full
                                {{ $user->status === 'active' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $user->status === 'invited' ? 'bg-yellow-100 text-yellow-700' : '' }}
                                {{ $user->status === 'inactive' ? 'bg-gray-100 text-gray-600' : '' }}">
                                {{ $user->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('user-client-assignment.edit', $user) }}" class="text-[#044b46] text-xs font-semibold hover:underline mr-3">
                                Assign Client
                            </a>
                            
                            @if ($user->status !== 'inactive')
                                <form action="{{ route('user-management.destroy', $user) }}" method="POST" onsubmit="return confirm('Nonaktifkan user ini?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 text-xs font-semibold hover:underline">Nonaktifkan</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection