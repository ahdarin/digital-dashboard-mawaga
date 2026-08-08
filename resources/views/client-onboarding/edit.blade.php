@extends('layouts.app')
@section('title', 'Edit ' . $client->brand_name)
@section('content')

<div class="p-8 max-w-2xl">

    <div class="flex items-center gap-3 mb-7">
        <a href="{{ route('client-onboarding.show', $client) }}"
           class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white text-[#5c6266] transition-colors">
            <span class="material-symbols-outlined text-[19px]">arrow_back</span>
        </a>
        <h1 class="font-display text-2xl font-semibold text-[#14181a]">Edit {{ $client->brand_name }}</h1>
    </div>

    <form action="{{ route('client-onboarding.update', $client) }}" method="POST" enctype="multipart/form-data" class="space-y-5">
        @csrf
        @method('PUT')

        <div class="card p-6">
            <p class="text-sm font-semibold text-[#14181a] mb-5 flex items-center gap-2">
                <span class="material-symbols-outlined text-[#044b46] text-[19px]">apartment</span>
                Company Information
            </p>

            <div class="space-y-4">
                <div x-data="{ preview: null, remove: false }">
                    <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Logo Brand</label>
                    <div class="flex items-center gap-4">
                        <div class="w-16 h-16 rounded-xl bg-[#f0f5f4] border border-[#eef0f4] flex items-center justify-center overflow-hidden shrink-0">
                            <template x-if="preview">
                                <img :src="preview" class="w-full h-full object-cover">
                            </template>
                            <template x-if="!preview && !remove && '{{ $client->logo_url }}'">
                                <img src="{{ $client->logo_url }}" class="w-full h-full object-cover">
                            </template>
                            <template x-if="!preview && (remove || !'{{ $client->logo_url }}')">
                                <span class="text-[#044b46] text-lg font-semibold">{{ strtoupper(substr($client->brand_name, 0, 1)) }}</span>
                            </template>
                        </div>
                        <div>
                            <label class="cursor-pointer inline-block text-sm font-medium text-[#044b46] bg-[#f0f5f4] hover:bg-[#e4ede9] px-3.5 py-2 rounded-lg">
                                <span>Ganti Logo</span>
                                <input type="file" name="logo" accept="image/*" class="hidden"
                                       x-on:change="const f = $event.target.files[0]; if (f) { preview = URL.createObjectURL(f); remove = false; }">
                            </label>
                            @if ($client->logo_url)
                                <label class="ml-2 text-xs text-[#b3423e] cursor-pointer">
                                    <input type="checkbox" name="remove_logo" value="1" class="hidden" x-model="remove" x-on:change="preview = null">
                                    Hapus logo
                                </label>
                            @endif
                            <p class="text-[11px] text-[#9aa0a4] mt-1.5">PNG/JPG, maks 2MB.</p>
                        </div>
                    </div>
                    @error('logo') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Nama Perusahaan</label>
                    <input type="text" name="name" value="{{ old('name', $client->name) }}" required
                           class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                    @error('name') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Nama Brand</label>
                    <input type="text" name="brand_name" value="{{ old('brand_name', $client->brand_name) }}" required
                           class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                    @error('brand_name') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Kategori</label>
                        <select name="client_category_id" required
                                class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" {{ old('client_category_id', $client->client_category_id) == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('client_category_id') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Status</label>
                        <select name="status" required
                                class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                            @foreach (['active' => 'Active', 'past_due' => 'Past Due', 'paused' => 'Paused'] as $value => $label)
                                <option value="{{ $value }}" {{ old('status', $client->status) === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('status') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Warna Penanda (Kalender)</label>
                        <div class="flex items-center gap-3">
                        <input type="color" name="color" value="{{ old('color', $client->color ?? '#044b46') }}"
                        class="h-10 w-14 rounded-lg cursor-pointer border border-[#eef0f4]">
                        <p class="text-xs text-[#9aa0a4]">Dipakai sebagai warna penanda deadline client ini di Content Plan Calendar.</p>
                    </div>
                     @error('color') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card p-6">
            <p class="text-sm font-semibold text-[#14181a] mb-5 flex items-center gap-2">
                <span class="material-symbols-outlined text-[#044b46] text-[19px]">person</span>
                Owner Account
            </p>

            @if ($client->owner)
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Nama Owner</label>
                        <input type="text" name="owner_name" value="{{ old('owner_name', $client->owner->name) }}"
                               class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                        @error('owner_name') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Email Owner</label>
                        <input type="email" name="owner_email" value="{{ old('owner_email', $client->owner->email) }}"
                               class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                        @error('owner_email') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Nomor WhatsApp</label>
                        <input type="tel" name="owner_phone" value="{{ old('owner_phone', $client->owner->phone_number) }}"
                               class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                        @error('owner_phone') <p class="text-[#b3423e] text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>
                </div>
            @else
                <p class="text-sm text-[#9aa0a4]">Client ini belum punya akun owner. Tambahkan lewat fitur terpisah.</p>
            @endif
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="bg-[#044b46] text-white text-sm font-medium px-6 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                Simpan Perubahan
            </button>
            <a href="{{ route('client-onboarding.show', $client) }}" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a]">Batal</a>
        </div>
    </form>
</div>
@endsection