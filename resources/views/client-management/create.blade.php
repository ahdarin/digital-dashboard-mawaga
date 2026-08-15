@extends('layouts.app')
@section('title', 'Tambah Client Baru')
@section('content')

<div x-data="{ brandName: '{{ old('brand_name') }}', ownerName: '{{ old('owner_name') }}', logoPreview: null }" class="p-4 sm:p-6 lg:p-8 max-w-[1200px] mx-auto">

    <div class="flex items-center gap-3 mb-7">
        <a href="{{ route('client-management.index') }}"
           class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white text-[#5c6266] transition-colors shrink-0">
            <span class="material-symbols-outlined text-[19px]">arrow_back</span>
        </a>
        <div>
            <h1 class="font-display text-2xl font-semibold text-[#14181a]">Tambah Client Baru</h1>
            <p class="text-sm text-[#9aa0a4] mt-0.5">Buat profil client sekaligus akun Owner-nya dalam satu langkah.</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="bg-[#fdf2f1] border border-[#f5d9d7] text-[#b3423e] text-sm p-4 rounded-lg mb-6 flex items-start gap-3">
            <span class="material-symbols-outlined text-[17px] shrink-0">error</span>
            <div>
                <p class="font-medium mb-1">Ada isian yang perlu diperbaiki:</p>
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form action="{{ route('client-management.store') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <div class="flex flex-col lg:flex-row gap-6 items-stretch lg:items-start">

            <div class="flex-1 min-w-0 space-y-5">

                {{-- Step 1 --}}
                <div class="card p-6">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-7 h-7 rounded-lg bg-[#044b46] text-white flex items-center justify-center text-xs font-semibold shrink-0">1</div>
                        <div>
                            <p class="text-sm font-semibold text-[#14181a]">Company Information</p>
                            <p class="text-xs text-[#9aa0a4]">Data dasar client dan kategorinya</p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Logo Brand</label>
                            <div class="flex items-center gap-4">
                                <div class="w-16 h-16 rounded-xl bg-[#f0f5f4] border border-[#eef0f4] flex items-center justify-center overflow-hidden shrink-0">
                                    <template x-if="logoPreview">
                                        <img :src="logoPreview" class="w-full h-full object-cover">
                                    </template>
                                    <template x-if="!logoPreview">
                                        <span class="material-symbols-outlined text-[#c3c7cb] text-[26px]">image</span>
                                    </template>
                                </div>
                                <div>
                                    <label class="cursor-pointer inline-block text-sm font-medium text-[#044b46] bg-[#f0f5f4] hover:bg-[#e4ede9] px-3.5 py-2 rounded-lg">
                                        <span>Pilih File</span>
                                        <input type="file" name="logo" accept="image/*" class="hidden"
                                               x-on:change="const f = $event.target.files[0]; if (f) logoPreview = URL.createObjectURL(f)">
                                    </label>
                                    <p class="text-[11px] text-[#9aa0a4] mt-1.5">PNG/JPG, maks 2MB. Opsional — kalau kosong, dipakai inisial nama brand.</p>
                                </div>
                            </div>
                            @error('logo') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Nama Perusahaan <span class="text-[#b3423e]">*</span></label>
                            <input type="text" name="name" value="{{ old('name') }}" required placeholder="PT Contoh Sejahtera"
                                   class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[#c3c7cb] focus:outline-none focus:border-[#044b46]/40 @error('name') border-[#e39a96] @enderror">
                            @error('name') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Nama Brand <span class="text-[#b3423e]">*</span></label>
                            <input type="text" name="brand_name" x-model="brandName" required placeholder="Contoh Coffee"
                                   class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[#c3c7cb] focus:outline-none focus:border-[#044b46]/40 @error('brand_name') border-[#e39a96] @enderror">
                            <p class="text-xs text-[#9aa0a4] mt-1.5">Nama ini yang akan tampil di seluruh dashboard &amp; laporan.</p>
                            @error('brand_name') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Kategori Client <span class="text-[#b3423e]">*</span></label>
                            <select name="client_category_id" required
                                    class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40 @error('client_category_id') border-[#e39a96] @enderror">
                                <option value="">-- Pilih Kategori --</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" {{ old('client_category_id') == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                                @endforeach
                            </select>
                            @error('client_category_id') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Warna Penanda (Kalender)</label>
                            <div class="flex items-center gap-3">
                            <input type="color" name="color" value="{{ old('color', '#044b46') }}"
                            class="h-10 w-14 rounded-lg cursor-pointer border border-[#eef0f4]">
                            <p class="text-xs text-[#9aa0a4]">Dipakai sebagai warna penanda deadline client ini di Content Plan Calendar.</p>
                    </div>
                    @error('color') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>

                        <div>
                            <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Link Aset (Google Drive)</label>
                            <input type="url" name="asset_link" value="{{ old('asset_link') }}" placeholder="https://drive.google.com/..."
                                   class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[#c3c7cb] focus:outline-none focus:border-[#044b46]/40 @error('asset_link') border-[#e39a96] @enderror">
                            <p class="text-xs text-[#9aa0a4] mt-1.5">Opsional. Link folder aset konten/desain client, tampil di tiap content item-nya.</p>
                            @error('asset_link') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                {{-- Step 2 --}}
                <div class="card p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <div class="w-7 h-7 rounded-lg bg-[#044b46] text-white flex items-center justify-center text-xs font-semibold shrink-0">2</div>
                        <div>
                            <p class="text-sm font-semibold text-[#14181a]">Owner Account (Client Owner)</p>
                            <p class="text-xs text-[#9aa0a4]">Akun ini yang login ke dashboard client</p>
                        </div>
                    </div>

                    <div class="ml-10 mb-5 mt-3 bg-[#f0f5f4] rounded-lg px-3.5 py-3 flex items-start gap-2">
                        <span class="material-symbols-outlined text-[#044b46] text-[15px] mt-0.5">info</span>
                        <p class="text-xs text-[#044b46]">Owner login pakai <strong>nomor WhatsApp</strong> via magic link, bukan password. Pastikan nomornya aktif.</p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Nama Owner <span class="text-[#b3423e]">*</span></label>
                            <input type="text" name="owner_name" x-model="ownerName" required placeholder="Nama lengkap penanggung jawab"
                                   class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[#c3c7cb] focus:outline-none focus:border-[#044b46]/40 @error('owner_name') border-[#e39a96] @enderror">
                            @error('owner_name') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Email Owner <span class="text-[#b3423e]">*</span></label>
                                <input type="email" name="owner_email" value="{{ old('owner_email') }}" required placeholder="owner@brand.com"
                                       class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[#c3c7cb] focus:outline-none focus:border-[#044b46]/40 @error('owner_email') border-[#e39a96] @enderror">
                                @error('owner_email') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Nomor WhatsApp <span class="text-[#b3423e]">*</span></label>
                                <input type="tel" name="owner_phone" value="{{ old('owner_phone') }}" required placeholder="08xxxxxxxxxx"
                                       class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[#c3c7cb] focus:outline-none focus:border-[#044b46]/40 @error('owner_phone') border-[#e39a96] @enderror">
                                @error('owner_phone') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 pb-2">
                    <button type="submit" class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-medium px-6 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                        <span class="material-symbols-outlined text-[17px]">check</span> Simpan Client
                    </button>
                    <a href="{{ route('client-management.index') }}" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">Batal</a>
                </div>

            </div>

            {{-- Preview panel --}}
            <div class="w-full lg:w-[280px] shrink-0 lg:sticky lg:top-6 space-y-5">
                <div class="card p-6">
                    <p class="text-xs font-medium text-[#9aa0a4] uppercase mb-4">Preview</p>

                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-11 h-11 rounded-full bg-[#f0f5f4] text-[#044b46] flex items-center justify-center text-base font-semibold shrink-0 overflow-hidden">
                            <template x-if="logoPreview">
                                <img :src="logoPreview" class="w-full h-full object-cover">
                            </template>
                            <template x-if="!logoPreview">
                                <span x-text="brandName ? brandName.charAt(0).toUpperCase() : '?'"></span>
                            </template>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-[#14181a] truncate" x-text="brandName || 'Nama Brand'"></p>
                            <p class="text-xs text-[#9aa0a4]">Client baru</p>
                        </div>
                    </div>

                    <div class="border-t border-[#eef0f4] pt-4">
                        <p class="text-xs text-[#9aa0a4] mb-1">Owner</p>
                        <p class="text-sm font-medium text-[#14181a]" x-text="ownerName || '-'"></p>
                    </div>
                </div>

                <div class="bg-[#f0f5f4] rounded-2xl p-6">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-[#044b46] text-[17px]">task_alt</span>
                        <p class="text-sm font-semibold text-[#044b46]">Yang terjadi setelah submit</p>
                    </div>
                    <ul class="space-y-2 text-xs text-[#044b46]/80">
                        <li class="flex gap-2"><span class="shrink-0">1.</span> Profil client dibuat dengan status <strong>Active</strong></li>
                        <li class="flex gap-2"><span class="shrink-0">2.</span> Akun Owner dibuat berstatus <strong>Invited</strong></li>
                        <li class="flex gap-2"><span class="shrink-0">3.</span> Owner bisa login pakai nomor WhatsApp yang didaftarkan</li>
                    </ul>
                </div>
            </div>

        </div>

    </form>
</div>
@endsection