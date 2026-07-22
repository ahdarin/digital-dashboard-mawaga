@extends('layouts.app')
@section('title', 'Tambah Client Baru')
@section('content')

<div x-data="{ brandName: '{{ old('brand_name') }}', ownerName: '{{ old('owner_name') }}' }" class="p-8">

    {{-- Header --}}
    <div class="flex items-center gap-3 mb-8">
        <a href="{{ route('client-onboarding.index') }}"
           class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-500 transition-colors duration-150 shrink-0">
            <span class="material-symbols-outlined text-[20px]">arrow_back</span>
        </a>
        <div>
            <h1 class="text-3xl font-extrabold text-[#191c1c]">Tambah Client Baru</h1>
            <p class="text-sm text-gray-400 mt-1">Buat profil client sekaligus akun Owner-nya dalam satu langkah.</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="bg-rose-50 border border-rose-100 text-rose-600 text-sm p-4 rounded-xl mb-6 flex items-start gap-3">
            <span class="material-symbols-outlined text-[18px] shrink-0">error</span>
            <div>
                <p class="font-semibold mb-1">Ada isian yang perlu diperbaiki:</p>
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form action="{{ route('client-onboarding.store') }}" method="POST">
        @csrf

        <div class="flex gap-6 items-start">

            {{-- Main column: form --}}
            <div class="flex-1 min-w-0 space-y-6">

                {{-- Step 1: Info Perusahaan --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-8 h-8 rounded-lg bg-[#044b46] text-white flex items-center justify-center text-sm font-bold shrink-0">
                            1
                        </div>
                        <div>
                            <p class="text-sm font-bold text-[#191c1c]">Informasi Perusahaan</p>
                            <p class="text-xs text-gray-400">Data dasar client dan kategorinya</p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">
                                Nama Perusahaan <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" name="name" value="{{ old('name') }}" required
                                   placeholder="PT Contoh Sejahtera"
                                   class="w-full border-0 bg-[#f8faf8] rounded-xl px-4 py-3 text-sm placeholder:text-gray-300 focus:outline-none focus:ring-2 focus:ring-[#044b46]/30 transition-shadow duration-150 @error('name') ring-2 ring-rose-300 @enderror">
                            @error('name') <p class="text-rose-500 text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">
                                Nama Brand <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" name="brand_name" x-model="brandName" required
                                   placeholder="Contoh Coffee"
                                   class="w-full border-0 bg-[#f8faf8] rounded-xl px-4 py-3 text-sm placeholder:text-gray-300 focus:outline-none focus:ring-2 focus:ring-[#044b46]/30 transition-shadow duration-150 @error('brand_name') ring-2 ring-rose-300 @enderror">
                            <p class="text-xs text-gray-400 mt-1.5">Nama ini yang akan tampil di seluruh dashboard & laporan.</p>
                            @error('brand_name') <p class="text-rose-500 text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">
                                Kategori Client <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <select name="client_category_id" required
                                        class="w-full appearance-none border-0 bg-[#f8faf8] rounded-xl px-4 py-3 pr-10 text-sm focus:outline-none focus:ring-2 focus:ring-[#044b46]/30 transition-shadow duration-150 cursor-pointer @error('client_category_id') ring-2 ring-rose-300 @enderror">
                                    <option value="">-- Pilih Kategori --</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}" {{ old('client_category_id') == $category->id ? 'selected' : '' }}>
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="material-symbols-outlined absolute inset-y-0 right-3 flex items-center pointer-events-none text-gray-400 text-[18px]">
                                    expand_more
                                </span>
                            </div>
                            @error('client_category_id') <p class="text-rose-500 text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                {{-- Step 2: Info Owner --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <div class="w-8 h-8 rounded-lg bg-[#044b46] text-white flex items-center justify-center text-sm font-bold shrink-0">
                            2
                        </div>
                        <div>
                            <p class="text-sm font-bold text-[#191c1c]">Akun Owner (Client Owner)</p>
                            <p class="text-xs text-gray-400">Akun ini yang login ke dashboard client</p>
                        </div>
                    </div>

                    <div class="ml-11 mb-5 mt-3 bg-[#044b46]/5 rounded-xl px-4 py-3 flex items-start gap-2">
                        <span class="material-symbols-outlined text-[#044b46] text-[16px] mt-0.5">info</span>
                        <p class="text-xs text-[#044b46]">
                            Owner login pakai <strong>nomor WhatsApp</strong> via magic link, bukan password. Pastikan nomornya aktif.
                        </p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">
                                Nama Owner <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" name="owner_name" x-model="ownerName" required
                                   placeholder="Nama lengkap penanggung jawab"
                                   class="w-full border-0 bg-[#f8faf8] rounded-xl px-4 py-3 text-sm placeholder:text-gray-300 focus:outline-none focus:ring-2 focus:ring-[#044b46]/30 transition-shadow duration-150 @error('owner_name') ring-2 ring-rose-300 @enderror">
                            @error('owner_name') <p class="text-rose-500 text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">
                                    Email Owner <span class="text-rose-500">*</span>
                                </label>
                                <input type="email" name="owner_email" value="{{ old('owner_email') }}" required
                                       placeholder="owner@brand.com"
                                       class="w-full border-0 bg-[#f8faf8] rounded-xl px-4 py-3 text-sm placeholder:text-gray-300 focus:outline-none focus:ring-2 focus:ring-[#044b46]/30 transition-shadow duration-150 @error('owner_email') ring-2 ring-rose-300 @enderror">
                                @error('owner_email') <p class="text-rose-500 text-xs mt-1.5">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">
                                    Nomor WhatsApp <span class="text-rose-500">*</span>
                                </label>
                                <input type="tel" name="owner_phone" value="{{ old('owner_phone') }}" required
                                       placeholder="08xxxxxxxxxx"
                                       class="w-full border-0 bg-[#f8faf8] rounded-xl px-4 py-3 text-sm placeholder:text-gray-300 focus:outline-none focus:ring-2 focus:ring-[#044b46]/30 transition-shadow duration-150 @error('owner_phone') ring-2 ring-rose-300 @enderror">
                                @error('owner_phone') <p class="text-rose-500 text-xs mt-1.5">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-3 pb-2">
                    <button type="submit"
                        class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-semibold px-6 py-3 rounded-xl hover:bg-[#044b46]/90 transition-colors duration-150">
                        <span class="material-symbols-outlined text-[18px]">check</span>
                        Simpan Client
                    </button>
                    <a href="{{ route('client-onboarding.index') }}"
                       class="text-sm font-semibold text-gray-500 px-4 py-3 hover:text-[#191c1c] transition-colors duration-150">
                        Batal
                    </a>
                </div>

            </div>

            {{-- Preview panel --}}
            <div class="w-[300px] shrink-0 sticky top-6 space-y-6">
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <p class="text-xs font-semibold text-gray-400 uppercase mb-4">Preview</p>

                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-12 h-12 rounded-full bg-[#044b46]/10 text-[#044b46] flex items-center justify-center text-lg font-bold shrink-0">
                            <span x-text="brandName ? brandName.charAt(0).toUpperCase() : '?'"></span>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-[#191c1c] truncate" x-text="brandName || 'Nama Brand'"></p>
                            <p class="text-xs text-gray-400">Client baru</p>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 pt-4">
                        <p class="text-xs text-gray-400 mb-1">Owner</p>
                        <p class="text-sm font-medium text-[#191c1c]" x-text="ownerName || '-'"></p>
                    </div>
                </div>

                <div class="bg-[#044b46]/5 rounded-2xl p-6">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-[#044b46] text-[18px]">task_alt</span>
                        <p class="text-sm font-bold text-[#044b46]">Yang terjadi setelah submit</p>
                    </div>
                    <ul class="space-y-2 text-xs text-[#044b46]/80">
                        <li class="flex gap-2">
                            <span class="shrink-0">1.</span>
                            Profil client dibuat dengan status <strong>Active</strong>
                        </li>
                        <li class="flex gap-2">
                            <span class="shrink-0">2.</span>
                            Akun Owner dibuat berstatus <strong>Invited</strong>
                        </li>
                        <li class="flex gap-2">
                            <span class="shrink-0">3.</span>
                            Owner bisa login pakai nomor WhatsApp yang didaftarkan
                        </li>
                    </ul>
                </div>
            </div>

        </div>

    </form>
</div>
@endsection