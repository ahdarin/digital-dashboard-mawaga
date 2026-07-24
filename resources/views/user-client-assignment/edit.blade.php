@extends('layouts.app')
@section('title', 'Assign Client - ' . $user->name)
@section('content')
<div class="p-8 max-w-lg">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('user-management.index') }}" class="text-gray-400 hover:text-gray-600">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
            <h2 class="text-xl font-bold text-[#191c1c]">Assign Client</h2>
            <p class="text-sm text-gray-500">Untuk {{ $user->name }} ({{ $user->role->name ?? '-' }})</p>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <form action="{{ route('user-client-assignment.update', $user) }}" method="POST">
            @csrf @method('PUT')

            <p class="text-xs font-semibold text-gray-500 uppercase mb-3">Pilih Client yang Ditangani</p>

            <div class="space-y-2 max-h-80 overflow-y-auto mb-4">
                @forelse ($allClients as $client)
                    <label class="flex items-center gap-3 p-3 border border-gray-100 rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" name="client_ids[]" value="{{ $client->id }}"
                               {{ in_array($client->id, $assignedClientIds) ? 'checked' : '' }}
                               class="rounded border-gray-300 text-[#044b46] focus:ring-[#044b46]">
                        <div>
                            <p class="text-sm font-medium text-gray-800">{{ $client->name }}</p>
                            <p class="text-xs text-gray-400">{{ $client->brand_name }}</p>
                        </div>
                    </label>
                @empty
                    <p class="text-xs text-gray-400 italic">Belum ada client aktif.</p>
                @endforelse
            </div>

            <div class="flex gap-3">
                <button type="submit" class="bg-[#044b46] text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-[#044b46]/90">
                    Simpan
                </button>
                <a href="{{ route('user-management.index') }}" class="text-sm text-gray-500 px-4 py-2">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection