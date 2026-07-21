@extends('layouts.app')
@section('title', 'Undang User Baru')
@section('content')
<div class="p-8 max-w-lg">
    <h2 class="text-2xl font-bold text-[#191c1c] mb-6">Undang User Baru</h2>

    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <form action="{{ route('user-management.store') }}" method="POST" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Nama</label>
                <input type="text" name="name" value="{{ old('name') }}" required
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]">
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email Google</label>
                <input type="email" name="email" value="{{ old('email') }}" required
                       placeholder="akan dipakai untuk login Google"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]">
                @error('email') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Role</label>
                <select name="role_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]">
                    <option value="">-- Pilih Role --</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}" {{ old('role_id') == $role->id ? 'selected' : '' }}>{{ $role->name }}</option>
                    @endforeach
                </select>
                @error('role_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="bg-[#044b46] text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-[#044b46]/90">
                    Kirim Undangan
                </button>
                <a href="{{ route('user-management.index') }}" class="text-sm text-gray-500 px-4 py-2">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection