@extends('layouts.app')
@section('title', 'Tambah Client Baru')
@section('content')

<div x-data="{ brandName: '{{ old('brand_name') }}', ownerName: '{{ old('owner_name') }}', logoPreview: null }" class="p-4 sm:p-6 lg:p-8 max-w-[1200px] mx-auto">

    <div class="flex items-center gap-3 mb-7">
        <a href="{{ route('client-management.index') }}"
           class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[var(--surface-card)] text-[var(--text-secondary)] transition-colors shrink-0">
            <span class="material-symbols-outlined text-[19px]">arrow_back</span>
        </a>
        <div>
            <h1 class="font-display text-2xl font-semibold text-[var(--text-primary)]">Tambah Client Baru</h1>
            <p class="text-sm text-[var(--text-muted)] mt-0.5">Buat profil client sekaligus akun Owner-nya dalam satu langkah.</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="bg-[var(--danger-tint)] border border-[var(--danger-border)] text-[var(--danger-text)] text-sm p-4 rounded-lg mb-6 flex items-start gap-3">
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
                        <div class="w-7 h-7 rounded-lg bg-[var(--brand)] text-white flex items-center justify-center text-xs font-semibold shrink-0">1</div>
                        <div>
                            <p class="text-sm font-semibold text-[var(--text-primary)]">Company Information</p>
                            <p class="text-xs text-[var(--text-muted)]">Data dasar client dan kategorinya</p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label for="logo" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Logo Brand</label>
                            <div class="flex items-center gap-4">
                                <div class="w-16 h-16 rounded-xl bg-[var(--brand-tint)] border border-[var(--border)] flex items-center justify-center overflow-hidden shrink-0">
                                    <template x-if="logoPreview">
                                        <img :src="logoPreview" alt="" class="w-full h-full object-cover">
                                    </template>
                                    <template x-if="!logoPreview">
                                        <span class="material-symbols-outlined text-[var(--text-muted)] text-[26px]">image</span>
                                    </template>
                                </div>
                                <div>
                                    <label class="cursor-pointer inline-block text-sm font-medium text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] px-3.5 py-2 rounded-lg">
                                        <span>Pilih File</span>
                                        <input id="logo" type="file" name="logo" accept="image/*" class="hidden"
                                               x-on:change="const f = $event.target.files[0]; if (f) logoPreview = URL.createObjectURL(f)">
                                    </label>
                                    <p class="text-[11px] text-[var(--text-muted)] mt-1.5">PNG/JPG, maks 2MB. Opsional — kalau kosong, dipakai inisial nama brand.</p>
                                </div>
                            </div>
                            @error('logo') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="name" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Nama Perusahaan <span class="text-[var(--danger-text)]">*</span></label>
                            <input id="name" type="text" name="name" value="{{ old('name') }}" required placeholder="PT Contoh Sejahtera"
                                   class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[var(--text-idle)] focus:outline-none focus:border-[#044b46]/40 @error('name') border-[var(--danger-border-strong)] @enderror">
                            @error('name') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="brand_name" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Nama Brand <span class="text-[var(--danger-text)]">*</span></label>
                            <input id="brand_name" type="text" name="brand_name" x-model="brandName" required placeholder="Contoh Coffee"
                                   class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[var(--text-idle)] focus:outline-none focus:border-[#044b46]/40 @error('brand_name') border-[var(--danger-border-strong)] @enderror">
                            <p class="text-xs text-[var(--text-muted)] mt-1.5">Nama ini yang akan tampil di seluruh dashboard &amp; laporan.</p>
                            @error('brand_name') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="client_category_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Kategori Client <span class="text-[var(--danger-text)]">*</span></label>
                            <select id="client_category_id" name="client_category_id" required
                                    class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 @error('client_category_id') border-[var(--danger-border-strong)] @enderror">
                                <option value="">-- Pilih Kategori --</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" {{ old('client_category_id') == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                                @endforeach
                            </select>
                            @error('client_category_id') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="color" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Warna Penanda (Kalender)</label>
                            <div class="flex items-center gap-3">
                            <input id="color" type="color" name="color" value="{{ old('color', '#044b46') }}"
                            class="h-10 w-14 rounded-lg cursor-pointer border border-[var(--border)]">
                            <p class="text-xs text-[var(--text-muted)]">Dipakai sebagai warna penanda deadline client ini di Content Plan Calendar.</p>
                    </div>
                    @error('color') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>

                        <div>
                            <label for="asset_link" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Link Aset (Google Drive)</label>
                            <input id="asset_link" type="url" name="asset_link" value="{{ old('asset_link') }}" placeholder="https://drive.google.com/..."
                                   class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[var(--text-idle)] focus:outline-none focus:border-[#044b46]/40 @error('asset_link') border-[var(--danger-border-strong)] @enderror">
                            <p class="text-xs text-[var(--text-muted)] mt-1.5">Opsional. Link folder aset konten/desain client, tampil di tiap content item-nya.</p>
                            @error('asset_link') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                {{-- Step 2 --}}
                <div class="card p-6">
                    <div class="flex items-center gap-3 mb-1">
                        <div class="w-7 h-7 rounded-lg bg-[var(--brand)] text-white flex items-center justify-center text-xs font-semibold shrink-0">2</div>
                        <div>
                            <p class="text-sm font-semibold text-[var(--text-primary)]">Owner Account (Client Owner)</p>
                            <p class="text-xs text-[var(--text-muted)]">Akun ini yang login ke dashboard client</p>
                        </div>
                    </div>

                    <div class="ml-10 mb-5 mt-3 bg-[var(--brand-tint)] rounded-lg px-3.5 py-3 flex items-start gap-2">
                        <span class="material-symbols-outlined text-[var(--brand)] text-[15px] mt-0.5">info</span>
                        <p class="text-xs text-[var(--brand)]">Owner login pakai <strong>nomor WhatsApp</strong> via magic link, bukan password. Pastikan nomornya aktif.</p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label for="owner_name" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Nama Owner <span class="text-[var(--danger-text)]">*</span></label>
                            <input id="owner_name" type="text" name="owner_name" x-model="ownerName" required placeholder="Nama lengkap penanggung jawab"
                                   class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[var(--text-idle)] focus:outline-none focus:border-[#044b46]/40 @error('owner_name') border-[var(--danger-border-strong)] @enderror">
                            @error('owner_name') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="owner_email" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Email Owner <span class="text-[var(--danger-text)]">*</span></label>
                                <input id="owner_email" type="email" name="owner_email" value="{{ old('owner_email') }}" required placeholder="owner@brand.com"
                                       class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[var(--text-idle)] focus:outline-none focus:border-[#044b46]/40 @error('owner_email') border-[var(--danger-border-strong)] @enderror">
                                @error('owner_email') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="owner_phone" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Nomor WhatsApp <span class="text-[var(--danger-text)]">*</span></label>
                                <input id="owner_phone" type="tel" name="owner_phone" value="{{ old('owner_phone') }}" required placeholder="08xxxxxxxxxx"
                                       class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[var(--text-idle)] focus:outline-none focus:border-[#044b46]/40 @error('owner_phone') border-[var(--danger-border-strong)] @enderror">
                                @error('owner_phone') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 pb-2">
                    <button type="submit" class="btn-primary">
                        <span class="material-symbols-outlined text-[17px]">check</span> Simpan Client
                    </button>
                    <a href="{{ route('client-management.index') }}" class="btn-secondary">Batal</a>
                </div>

            </div>

            {{-- Preview panel --}}
            <div class="w-full lg:w-[280px] shrink-0 lg:sticky lg:top-6 space-y-5">
                <div class="card p-6">
                    <p class="text-xs font-medium text-[var(--text-muted)] uppercase mb-4">Preview</p>

                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-11 h-11 rounded-full bg-[var(--brand-tint)] text-[var(--brand)] flex items-center justify-center text-base font-semibold shrink-0 overflow-hidden">
                            <template x-if="logoPreview">
                                <img :src="logoPreview" alt="" class="w-full h-full object-cover">
                            </template>
                            <template x-if="!logoPreview">
                                <span x-text="brandName ? brandName.charAt(0).toUpperCase() : '?'"></span>
                            </template>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-[var(--text-primary)] truncate" x-text="brandName || 'Nama Brand'"></p>
                            <p class="text-xs text-[var(--text-muted)]">Client baru</p>
                        </div>
                    </div>

                    <div class="border-t border-[var(--border)] pt-4">
                        <p class="text-xs text-[var(--text-muted)] mb-1">Owner</p>
                        <p class="text-sm font-medium text-[var(--text-primary)]" x-text="ownerName || '-'"></p>
                    </div>
                </div>

                <div class="bg-[var(--brand-tint)] rounded-2xl p-6">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-[var(--brand)] text-[17px]">task_alt</span>
                        <p class="text-sm font-semibold text-[var(--brand)]">Yang terjadi setelah submit</p>
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