@extends('layouts.app')
@section('title', 'Buat Content Plan')
@section('content')
<div class="p-8 max-w-lg">
    <div class="flex items-center gap-3 mb-7">
        <a href="{{ route('content-plan.index') }}" title="Kembali ke daftar Content Plan"
           class="w-9 h-9 flex items-center justify-center rounded-lg border border-[#eef0f4] text-[#5c6266]">
            <span class="material-symbols-outlined text-[19px]">arrow_back</span>
        </a>
        <h1 class="font-display text-2xl font-semibold text-[#14181a]">Buat Content Plan Baru</h1>
    </div>

    @error('client_id')<div class="bg-[#fdf2f1] text-[#b3423e] text-sm p-3.5 rounded-lg mb-5">{{ $message }}</div>@enderror

    <form action="{{ route('content-plan.store') }}" method="POST" class="card p-6 space-y-4">
        @csrf
        <div>
            <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Client <span class="text-[#b3423e]">*</span></label>
            <select name="client_id" required class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                <option value="">Pilih client...</option>
                @foreach ($clientOptions as $c)
                    <option value="{{ $c->id }}" {{ !$c->activePackage ? 'disabled' : '' }}>
                        {{ $c->name }} {{ !$c->activePackage ? '(belum ada paket aktif)' : '' }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Bulan</label>
                <select name="month" required class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                    @foreach (range(1,12) as $m)
                        <option value="{{ $m }}" {{ now()->month === $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Tahun</label>
                <select name="year" required class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                    @foreach (range(now()->year - 1, now()->year + 1) as $y)
                        <option value="{{ $y }}" {{ now()->year === $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <button type="submit" class="w-full bg-[#044b46] text-white text-sm font-medium py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
            Buat Plan
        </button>
    </form>
</div>
@endsection