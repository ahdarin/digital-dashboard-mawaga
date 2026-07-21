@extends('layouts.app')
@section('title', 'Tambah Client Baru')
@section('content')
<div class="p-8 max-w-lg">
    <h2 class="text-2xl font-bold text-[#191c1c] mb-6">Tambah Client Baru</h2>

    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <form action="{{ route('client-onboarding.store') }}" method="POST" class="space-y-4">
            @csrf

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Nama Perusahaan</label>
                <input type="text" name="name" value="{{ old('name') }}" required
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]">
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Nama Brand</label>
                <input type="text" name="brand_name" value="{{ old('brand_name') }}" required
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]">
                @error('brand_name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Kategori Client</label>
                <select name="client_category_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]">
                    <option value="">-- Pilih Kategori --</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" {{ old('client_category_id') == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                    @endforeach
                </select>
                @error('client_category_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <hr class="border-gray-100 my-4">
            <p class="text-xs font-semibold text-gray-500 uppercase">Akun Owner (Client Owner)</p>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Nama Owner</label>
                <input type="text" name="owner_name" value="{{ old('owner_name') }}" required
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]">
                @error('owner_name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email Owner</label>
                <input type="email" name="owner_email" value="{{ old('owner_email') }}" required
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]">
                @error('owner_email') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Nomor WhatsApp Owner</label>
                <input type="tel" name="owner_phone" value="{{ old('owner_phone') }}" required
                       placeholder="08xxxxxxxxxx"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]">
                @error('owner_phone') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="bg-[#044b46] text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-[#044b46]/90">
                    Simpan Client
                </button>
                <a href="{{ route('client-onboarding.index') }}" class="text-sm text-gray-500 px-4 py-2">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection