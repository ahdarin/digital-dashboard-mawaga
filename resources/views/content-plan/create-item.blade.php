@extends('layouts.app')
@section('title', 'Tambah Content Item')
@section('content')
<div class="p-8 max-w-2xl">

    <div class="flex items-center gap-3 mb-7">
        <a href="{{ route('content-plan.show', $contentPlan) }}" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white text-[#5c6266]">
            <span class="material-symbols-outlined text-[19px]">arrow_back</span>
        </a>
        <div>
            <p class="text-xs text-[#9aa0a4]">{{ $contentPlan->client->name }} / {{ \Carbon\Carbon::create()->month($contentPlan->month)->translatedFormat('F') }} {{ $contentPlan->year }}</p>
            <h1 class="font-display text-2xl font-semibold text-[#14181a]">Tambah Content Item</h1>
        </div>
    </div>

    @if ($errors->any())
        <div class="bg-[#fdf2f1] border border-[#f5d9d7] text-[#b3423e] text-sm p-4 rounded-lg mb-6">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('content-plan.items.store', $contentPlan) }}" method="POST" class="card p-6 space-y-4">
        @csrf

        <div>
            <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Judul Konten <span class="text-[#b3423e]">*</span></label>
            <input type="text" name="title" required value="{{ old('title') }}" placeholder="Tips Memilih Warna Cat Rumah"
                   class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[#c3c7cb] focus:outline-none focus:border-[#044b46]/40">
        </div>

        <div>
            <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Brief Konten</label>
            <textarea name="brief" rows="4" placeholder="Tuliskan brief detail, hook, dan poin utama konten..."
                      class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm placeholder:text-[#c3c7cb] focus:outline-none focus:border-[#044b46]/40">{{ old('brief') }}</textarea>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Content Pillar</label>
                <select name="content_pillar_id" class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                    <option value="">Pilih Pillar...</option>
                    @foreach ($pillars as $p) <option value="{{ $p->id }}">{{ $p->name }}</option> @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Jenis Konten</label>
                <select name="content_type_id" class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                    <option value="">Pilih Jenis...</option>
                    @foreach ($types as $t) <option value="{{ $t->id }}">{{ $t->name }}</option> @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Platform</label>
                <select name="platform_id" class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                    <option value="">Pilih Platform...</option>
                    @foreach ($platforms as $p) <option value="{{ $p->id }}">{{ $p->name }}</option> @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Deadline Produksi <span class="text-[#b3423e]">*</span></label>
                <input type="date" name="deadline_at" required value="{{ old('deadline_at') }}"
                       class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
            </div>
            <div>
                <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">PIC Pengerjaan</label>
                <select name="pic_id" class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                    <option value="">Pilih PIC...</option>
                    @foreach ($picOptions as $pic) <option value="{{ $pic->id }}">{{ $pic->name }}</option> @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Estimasi Durasi Video</label>
                <select name="estimated_duration_seconds" class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                    <option value="">Pilih Estimasi...</option>
                    <option value="25" {{ (string) old('estimated_duration_seconds') === '25' ? 'selected' : '' }}>Pendek (&lt;30 detik)</option>
                    <option value="35" {{ (string) old('estimated_duration_seconds') === '35' ? 'selected' : '' }}>Sedang (31-40 detik)</option>
                    <option value="60" {{ (string) old('estimated_duration_seconds') === '60' ? 'selected' : '' }}>Panjang (41 detik ke atas)</option>
                </select>
                <p class="text-[11px] text-[#9aa0a4] mt-1">Isi kalau jenis kontennya Video - dipakai buat hitung skor Delay Risk.</p>
            </div>
            <div>
                <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Estimasi Jumlah Slide</label>
                <select name="estimated_slide_count" class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                    <option value="">Pilih Estimasi...</option>
                    <option value="1" {{ (string) old('estimated_slide_count') === '1' ? 'selected' : '' }}>Single (1 slide)</option>
                    <option value="3" {{ (string) old('estimated_slide_count') === '3' ? 'selected' : '' }}>Beberapa (2-4 slide)</option>
                    <option value="6" {{ (string) old('estimated_slide_count') === '6' ? 'selected' : '' }}>Banyak (5+ slide)</option>
                </select>
                <p class="text-[11px] text-[#9aa0a4] mt-1">Isi kalau jenis kontennya Feed/Carousel - dipakai buat hitung skor Delay Risk.</p>
            </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="bg-[#044b46] text-white text-sm font-medium px-6 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors flex items-center gap-2">
                <span class="material-symbols-outlined text-[17px]">save</span> Simpan Item
            </button>
            <a href="{{ route('content-plan.show', $contentPlan) }}" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a]">Batal</a>
        </div>
    </form>
</div>
@endsection