@extends('layouts.app')
@section('title', 'Tambah Konten')
@section('content')
<div class="p-4 sm:p-6 lg:p-8 max-w-2xl mx-auto">

    <div class="flex items-center gap-3 mb-7">
        <a href="{{ route('content-plan.show', $contentPlan) }}" title="Kembali ke Content Plan"
           class="w-9 h-9 flex items-center justify-center rounded-lg border border-[#eef0f4] text-[#5c6266]">
            <span class="material-symbols-outlined text-[19px]">arrow_back</span>
        </a>
        <div>
            <p class="text-xs text-[#767c80]">{{ $contentPlan->client->name }} / {{ \Carbon\Carbon::create()->month($contentPlan->month)->translatedFormat('F') }} {{ $contentPlan->year }}</p>
            <h1 class="font-display text-2xl font-semibold text-[#14181a]">Tambah Konten</h1>
        </div>
    </div>

    @if ($errors->any())
        <div class="bg-[#fdf2f1] border border-[#f5d9d7] text-[#b3423e] text-sm p-4 rounded-lg mb-6">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
            </ul>
        </div>
    @endif

    @php
        // Dipakai buat inisialisasi field estimasi kondisional (Alpine) pas
        // form di-redisplay ulang setelah validasi gagal - biar field yang
        // relevan tetap kebuka, bukan ketutup gara-gara jenis konten yang
        // udah dipilih user hilang dari state Alpine.
        $oldContentTypeName = old('content_type_id')
            ? optional($types->firstWhere('id', (int) old('content_type_id')))->name ?? ''
            : '';
    @endphp
    <form action="{{ route('content-plan.items.store', $contentPlan) }}" method="POST" class="card p-6 space-y-4"
          x-data="{ contentTypeName: {{ Illuminate\Support\Js::from($oldContentTypeName) }} }">
        @csrf

        <div>
            <label for="title" class="block text-xs font-medium text-[#767c80] uppercase mb-1.5">Judul Konten <span class="text-[#b3423e]">*</span></label>
            <input id="title" type="text" name="title" required value="{{ old('title') }}" placeholder="Tips Memilih Warna Cat Rumah"
                   class="input placeholder:text-[#c3c7cb] {{ $errors->has('title') ? 'input-error' : '' }}">
            @error('title') <p class="text-xs text-[#b3423e] mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="brief" class="block text-xs font-medium text-[#767c80] uppercase mb-1.5">Brief Konten</label>
            <textarea id="brief" name="brief" rows="4" placeholder="Tuliskan brief detail, hook, dan poin utama konten..."
                      class="input placeholder:text-[#c3c7cb] {{ $errors->has('brief') ? 'input-error' : '' }}">{{ old('brief') }}</textarea>
            @error('brief') <p class="text-xs text-[#b3423e] mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label for="content_pillar_id" class="block text-xs font-medium text-[#767c80] uppercase mb-1.5">Content Pillar</label>
                <select id="content_pillar_id" name="content_pillar_id" class="input {{ $errors->has('content_pillar_id') ? 'input-error' : '' }}">
                    <option value="">Pilih Pillar...</option>
                    @foreach ($pillars as $p) <option value="{{ $p->id }}" {{ (string) old('content_pillar_id') === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option> @endforeach
                </select>
                @error('content_pillar_id') <p class="text-xs text-[#b3423e] mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="content_type_id" class="block text-xs font-medium text-[#767c80] uppercase mb-1.5">Jenis Konten</label>
                <select id="content_type_id" name="content_type_id"
                        x-on:change="contentTypeName = $event.target.selectedOptions[0]?.dataset.name || ''"
                        class="input {{ $errors->has('content_type_id') ? 'input-error' : '' }}">
                    <option value="">Pilih Jenis...</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->id }}" data-name="{{ $t->name }}" {{ (string) old('content_type_id') === (string) $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                    @endforeach
                </select>
                @error('content_type_id') <p class="text-xs text-[#b3423e] mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="platform_id" class="block text-xs font-medium text-[#767c80] uppercase mb-1.5">Platform</label>
                <select id="platform_id" name="platform_id" class="input {{ $errors->has('platform_id') ? 'input-error' : '' }}">
                    <option value="">Pilih Platform...</option>
                    @foreach ($platforms as $p) <option value="{{ $p->id }}" {{ (string) old('platform_id') === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option> @endforeach
                </select>
                @error('platform_id') <p class="text-xs text-[#b3423e] mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label for="deadline_at" class="block text-xs font-medium text-[#767c80] uppercase mb-1.5">Deadline Produksi <span class="text-[#b3423e]">*</span></label>
                <input id="deadline_at" type="text" name="deadline_at" required value="{{ old('deadline_at') }}" data-flatpickr="datetime" autocomplete="off"
                       class="input {{ $errors->has('deadline_at') ? 'input-error' : '' }}">
                @error('deadline_at') <p class="text-xs text-[#b3423e] mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="pic_id" class="block text-xs font-medium text-[#767c80] uppercase mb-1.5">Penanggung Jawab <span class="text-[#b3423e]">*</span></label>
                <select id="pic_id" name="pic_id" required class="input {{ $errors->has('pic_id') ? 'input-error' : '' }}">
                    <option value="">Pilih Penanggung Jawab...</option>
                    @foreach ($picOptions as $pic) <option value="{{ $pic->id }}" {{ (string) old('pic_id') === (string) $pic->id ? 'selected' : '' }}>{{ $pic->name }}</option> @endforeach
                </select>
                @error('pic_id') <p class="text-xs text-[#b3423e] mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div x-show="contentTypeName === 'Video'" x-cloak>
            <label for="estimated_duration_seconds" class="block text-xs font-medium text-[#767c80] uppercase mb-1.5">Estimasi Durasi Video</label>
            <select id="estimated_duration_seconds" name="estimated_duration_seconds" class="input {{ $errors->has('estimated_duration_seconds') ? 'input-error' : '' }}">
                <option value="">Pilih Estimasi...</option>
                <option value="25" {{ (string) old('estimated_duration_seconds') === '25' ? 'selected' : '' }}>Pendek (&lt;30 detik)</option>
                <option value="35" {{ (string) old('estimated_duration_seconds') === '35' ? 'selected' : '' }}>Sedang (31-40 detik)</option>
                <option value="60" {{ (string) old('estimated_duration_seconds') === '60' ? 'selected' : '' }}>Panjang (41 detik ke atas)</option>
            </select>
            @error('estimated_duration_seconds') <p class="text-xs text-[#b3423e] mt-1">{{ $message }}</p> @enderror
            <p class="text-[11px] text-[#767c80] mt-1">Dipakai buat hitung skor Delay Risk.</p>
        </div>
        <div x-show="contentTypeName === 'Desain'" x-cloak>
            <label for="estimated_slide_count" class="block text-xs font-medium text-[#767c80] uppercase mb-1.5">Estimasi Jumlah Slide</label>
            <select id="estimated_slide_count" name="estimated_slide_count" class="input {{ $errors->has('estimated_slide_count') ? 'input-error' : '' }}">
                <option value="">Pilih Estimasi...</option>
                <option value="1" {{ (string) old('estimated_slide_count') === '1' ? 'selected' : '' }}>Single (1 slide)</option>
                <option value="3" {{ (string) old('estimated_slide_count') === '3' ? 'selected' : '' }}>Beberapa (2-4 slide)</option>
                <option value="6" {{ (string) old('estimated_slide_count') === '6' ? 'selected' : '' }}>Banyak (5+ slide)</option>
            </select>
            @error('estimated_slide_count') <p class="text-xs text-[#b3423e] mt-1">{{ $message }}</p> @enderror
            <p class="text-[11px] text-[#767c80] mt-1">Dipakai buat hitung skor Delay Risk.</p>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="btn-primary">
                <span class="material-symbols-outlined text-[17px]">save</span> Simpan Item
            </button>
            <a href="{{ route('content-plan.show', $contentPlan) }}" class="btn-secondary">Batal</a>
        </div>
    </form>
</div>
@endsection