@extends('layouts.app')
@section('title', 'Undang User Baru')
@section('content')
<div class="p-4 sm:p-6 lg:p-8 max-w-lg">
    <h1 class="font-display text-2xl font-semibold text-[#14181a] mb-6">Undang User Baru</h1>

    <div class="card p-6">
        <form action="{{ route('user-management.store') }}" method="POST" class="space-y-4">
            @csrf
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

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="bg-[#044b46] text-white text-sm font-medium px-6 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                    Kirim Undangan
                </button>
                <a href="{{ route('user-management.index') }}" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection