@extends('layouts.app')
@section('title', 'Tambah Klien Baru')
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-2xl mx-auto">

    <div class="flex items-center gap-3 mb-7">
        <a href="{{ route('client-management.index') }}"
           class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[var(--surface-card)] text-[var(--text-secondary)] transition-colors shrink-0">
            <span class="material-symbols-outlined text-[19px]">arrow_back</span>
        </a>
        <div>
            <h1 class="font-display text-2xl font-semibold text-[var(--text-primary)]">Tambah Klien Baru</h1>
            <p class="text-sm text-[var(--text-muted)] mt-0.5">Link Portal Klien otomatis tersedia begitu klien dibuat.</p>
        </div>
    </div>

    <form action="{{ route('client-management.store') }}" method="POST" enctype="multipart/form-data" class="space-y-5">
        @csrf

        <div class="card p-6">
            <p class="text-sm font-semibold text-[var(--text-primary)] mb-5 flex items-center gap-2">
                <span class="material-symbols-outlined text-[var(--brand)] text-[19px]">apartment</span>
                Informasi Perusahaan
            </p>

            <div class="space-y-4">
                <div x-data="{ preview: null }">
                    <label for="logo" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Logo Brand</label>
                    <div class="flex items-center gap-4">
                        <div class="w-16 h-16 rounded-xl bg-[var(--brand-tint)] border border-[var(--border)] flex items-center justify-center overflow-hidden shrink-0">
                            <template x-if="preview">
                                <img :src="preview" alt="" class="w-full h-full object-cover">
                            </template>
                            <template x-if="!preview">
                                <span class="material-symbols-outlined text-[var(--text-muted)] text-[26px]">image</span>
                            </template>
                        </div>
                        <div>
                            <label class="cursor-pointer inline-block text-sm font-medium text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] px-3.5 py-2 rounded-lg">
                                <span>Pilih Logo</span>
                                <input id="logo" type="file" name="logo" accept="image/*" class="hidden"
                                       x-on:change="const f = $event.target.files[0]; if (f) preview = URL.createObjectURL(f)">
                            </label>
                            <p class="text-[11px] text-[var(--text-muted)] mt-1.5">PNG/JPG, maks 2MB. Opsional — kalau kosong, dipakai inisial nama brand.</p>
                        </div>
                    </div>
                    @error('logo') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="name" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Nama Perusahaan <span class="text-[var(--danger-text)]">*</span></label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required placeholder="PT Contoh Sejahtera"
                           class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[var(--text-idle)] focus:outline-none focus:border-[#044b46]/40 @error('name') border-[var(--danger-border-strong)] @enderror">
                    @error('name') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="brand_name" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Nama Brand <span class="text-[var(--danger-text)]">*</span></label>
                    <input id="brand_name" type="text" name="brand_name" value="{{ old('brand_name') }}" required placeholder="Contoh Coffee"
                           class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[var(--text-idle)] focus:outline-none focus:border-[#044b46]/40 @error('brand_name') border-[var(--danger-border-strong)] @enderror">
                    <p class="text-xs text-[var(--text-muted)] mt-1.5">Nama ini yang akan tampil di seluruh dashboard &amp; laporan.</p>
                    @error('brand_name') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="client_category_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Kategori Klien <span class="text-[var(--danger-text)]">*</span></label>
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
                    <label for="package_template_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Paket</label>
                    <select id="package_template_id" name="package_template_id"
                            class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 @error('package_template_id') border-[var(--danger-border-strong)] @enderror">
                        <option value="">-- Belum Ditentukan --</option>
                        @foreach ($packageTemplates as $template)
                            <option value="{{ $template->id }}" {{ old('package_template_id') == $template->id ? 'selected' : '' }}>
                                {{ $template->name }} ({{ $template->monthly_content_quota }} Konten / {{ $template->monthly_design_quota }} Desain)
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-[var(--text-muted)] mt-1.5">Opsional. Bisa diisi/diubah nanti dari halaman detail klien.</p>
                    @error('package_template_id') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="asset_link" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Link Aset (Google Drive)</label>
                    <input id="asset_link" type="url" name="asset_link" value="{{ old('asset_link') }}" placeholder="https://drive.google.com/..."
                           class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[var(--text-idle)] focus:outline-none focus:border-[#044b46]/40 @error('asset_link') border-[var(--danger-border-strong)] @enderror">
                    <p class="text-xs text-[var(--text-muted)] mt-1.5">Opsional. Link folder aset konten/desain klien, tampil di tiap content item-nya.</p>
                    @error('asset_link') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary">
                <span class="material-symbols-outlined text-[17px]">save</span> Simpan Klien
            </button>
            <a href="{{ route('client-management.index') }}" class="btn-secondary">Batal</a>
        </div>
    </form>
</div>
@endsection
