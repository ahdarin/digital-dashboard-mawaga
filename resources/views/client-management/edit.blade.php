@extends('layouts.app')
@section('title', 'Edit ' . $client->brand_name)
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-2xl mx-auto">

    <div class="flex items-center gap-3 mb-7">
        <a href="{{ route('client-management.show', $client) }}"
           class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[var(--surface-card)] text-[var(--text-secondary)] transition-colors">
            <span class="material-symbols-outlined text-[19px]">arrow_back</span>
        </a>
        <h1 class="font-display text-2xl font-semibold text-[var(--text-primary)]">Edit {{ $client->brand_name }}</h1>
    </div>

    <form action="{{ route('client-management.update', $client) }}" method="POST" enctype="multipart/form-data" class="space-y-5">
        @csrf
        @method('PUT')

        <div class="card p-6">
            <p class="text-sm font-semibold text-[var(--text-primary)] mb-5 flex items-center gap-2">
                <span class="material-symbols-outlined text-[var(--brand)] text-[19px]">apartment</span>
                Informasi Perusahaan
            </p>

            <div class="space-y-4">
                <div x-data="{ preview: null, remove: false }">
                    <label for="logo" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Logo Brand</label>
                    <div class="flex items-center gap-4">
                        <div class="w-16 h-16 rounded-xl bg-[var(--brand-tint)] border border-[var(--border)] flex items-center justify-center overflow-hidden shrink-0">
                            <template x-if="preview">
                                <img :src="preview" alt="" class="w-full h-full object-cover">
                            </template>
                            <template x-if="!preview && !remove && '{{ $client->logo_url }}'">
                                <img src="{{ $client->logo_url }}" alt="Logo {{ $client->brand_name }}" class="w-full h-full object-cover">
                            </template>
                            <template x-if="!preview && (remove || !'{{ $client->logo_url }}')">
                                <span class="text-[var(--brand)] text-lg font-semibold">{{ strtoupper(substr($client->brand_name, 0, 1)) }}</span>
                            </template>
                        </div>
                        <div>
                            <label class="cursor-pointer inline-block text-sm font-medium text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] px-3.5 py-2 rounded-lg">
                                <span>Ganti Logo</span>
                                <input id="logo" type="file" name="logo" accept="image/*" class="hidden"
                                       x-on:change="const f = $event.target.files[0]; if (f) { preview = URL.createObjectURL(f); remove = false; }">
                            </label>
                            @if ($client->logo_url)
                                <label class="ml-2 text-xs text-[var(--danger-text)] cursor-pointer">
                                    <input type="checkbox" name="remove_logo" value="1" class="hidden" x-model="remove" x-on:change="preview = null">
                                    Hapus logo
                                </label>
                            @endif
                            <p class="text-[11px] text-[var(--text-muted)] mt-1.5">PNG/JPG, maks 2MB.</p>
                        </div>
                    </div>
                    @error('logo') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="name" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Nama Perusahaan</label>
                    <input id="name" type="text" name="name" value="{{ old('name', $client->name) }}" required
                           class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[var(--text-idle)] focus:outline-none focus:border-[#044b46]/40 @error('name') border-[var(--danger-border-strong)] @enderror">
                    @error('name') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="brand_name" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Nama Brand</label>
                    <input id="brand_name" type="text" name="brand_name" value="{{ old('brand_name', $client->brand_name) }}" required
                           class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[var(--text-idle)] focus:outline-none focus:border-[#044b46]/40 @error('brand_name') border-[var(--danger-border-strong)] @enderror">
                    @error('brand_name') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="client_category_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Kategori</label>
                        <select id="client_category_id" name="client_category_id" required
                                class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 @error('client_category_id') border-[var(--danger-border-strong)] @enderror">
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" {{ old('client_category_id', $client->client_category_id) == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('client_category_id') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="status" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Status</label>
                        <select id="status" name="status" required
                                class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 @error('status') border-[var(--danger-border-strong)] @enderror">
                            @foreach (['active' => 'Aktif', 'past_due' => 'Jatuh Tempo', 'paused' => 'Dijeda'] as $value => $label)
                                <option value="{{ $value }}" {{ old('status', $client->status) === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('status') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="asset_link" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Link Aset (Google Drive)</label>
                    <input id="asset_link" type="url" name="asset_link" value="{{ old('asset_link', $client->asset_link) }}" placeholder="https://drive.google.com/..."
                           class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[var(--text-idle)] focus:outline-none focus:border-[#044b46]/40 @error('asset_link') border-[var(--danger-border-strong)] @enderror">
                    <p class="text-xs text-[var(--text-muted)] mt-1.5">Opsional. Link folder aset konten/desain klien, tampil di tiap content item-nya.</p>
                    @error('asset_link') <p class="text-[var(--danger-text)] text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary">
                <span class="material-symbols-outlined text-[17px]">save</span> Simpan Perubahan
            </button>
            <a href="{{ route('client-management.show', $client) }}" class="btn-secondary">Batal</a>
        </div>
    </form>
</div>
@endsection
