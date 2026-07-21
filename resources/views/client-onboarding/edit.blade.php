@extends('layouts.app')
@section('title', 'Edit ' . $client->brand_name)
@section('content')

<div class="p-8 max-w-2xl">

    <div class="flex items-center gap-3 mb-8">
        <a href="{{ route('client-onboarding.show', $client) }}"
           class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-500 transition-colors duration-150">
            <span class="material-symbols-outlined text-[20px]">arrow_back</span>
        </a>
        <h1 class="text-3xl font-extrabold text-[#191c1c]">Edit {{ $client->brand_name }}</h1>
    </div>

    <form action="{{ route('client-onboarding.update', $client) }}" method="POST" class="space-y-6">
        @csrf
        @method('PUT')

        {{-- Info Perusahaan --}}
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <p class="text-sm font-bold text-[#191c1c] mb-5 flex items-center gap-2">
                <span class="material-symbols-outlined text-[#044b46] text-[20px]">apartment</span>
                Informasi Perusahaan
            </p>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">Nama Perusahaan</label>
                    <input type="text" name="name" value="{{ old('name', $client->name) }}" required
                           class="w-full border-0 bg-[#f8faf8] rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#044b46]/30">
                    @error('name') <p class="text-rose-500 text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">Nama Brand</label>
                    <input type="text" name="brand_name" value="{{ old('brand_name', $client->brand_name) }}" required
                           class="w-full border-0 bg-[#f8faf8] rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#044b46]/30">
                    @error('brand_name') <p class="text-rose-500 text-xs mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">Kategori</label>
                        <select name="client_category_id" required
                                class="w-full border-0 bg-[#f8faf8] rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#044b46]/30">
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}"
                                    {{ old('client_category_id', $client->client_category_id) == $category->id ? 'selected' : '' }}>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('client_category_id') <p class="text-rose-500 text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">Status</label>
                        <select name="status" required
                                class="w-full border-0 bg-[#f8faf8] rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#044b46]/30">
                            @foreach (['active' => 'Active', 'past_due' => 'Past Due', 'paused' => 'Paused'] as $value => $label)
                                <option value="{{ $value }}" {{ old('status', $client->status) === $value ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('status') <p class="text-rose-500 text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- Info Owner --}}
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <p class="text-sm font-bold text-[#191c1c] mb-5 flex items-center gap-2">
                <span class="material-symbols-outlined text-[#044b46] text-[20px]">person</span>
                Akun Owner
            </p>

            @if ($client->owner)
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">Nama Owner</label>
                        <input type="text" name="owner_name" value="{{ old('owner_name', $client->owner->name) }}"
                               class="w-full border-0 bg-[#f8faf8] rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#044b46]/30">
                        @error('owner_name') <p class="text-rose-500 text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">Email Owner</label>
                        <input type="email" name="owner_email" value="{{ old('owner_email', $client->owner->email) }}"
                               class="w-full border-0 bg-[#f8faf8] rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#044b46]/30">
                        @error('owner_email') <p class="text-rose-500 text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">Nomor WhatsApp</label>
                        <input type="tel" name="owner_phone" value="{{ old('owner_phone', $client->owner->phone_number) }}"
                               class="w-full border-0 bg-[#f8faf8] rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#044b46]/30">
                        @error('owner_phone') <p class="text-rose-500 text-xs mt-1.5">{{ $message }}</p> @enderror
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-400">Client ini belum punya akun owner. Tambahkan lewat fitur terpisah.</p>
            @endif
        </div>

        <div class="flex items-center gap-3">
            <button type="submit"
                class="bg-[#044b46] text-white text-sm font-semibold px-6 py-3 rounded-xl hover:bg-[#044b46]/90 transition-colors duration-150">
                Simpan Perubahan
            </button>
            <a href="{{ route('client-onboarding.show', $client) }}" class="text-sm font-semibold text-gray-500 px-4 py-3 hover:text-[#191c1c]">
                Batal
            </a>
        </div>
    </form>
</div>
@endsection