@extends('layouts.app')
@section('title', 'Settings')
@section('content')

<div class="p-8 max-w-3xl">

    <div class="mb-7">
        <h1 class="font-display text-[32px] font-semibold text-[#14181a]">Settings</h1>
        <p class="text-[#5c6266] text-sm mt-1">Kelola akun dan koneksi data performa kontenmu.</p>
    </div>

    <div class="space-y-5">

        {{-- Account --}}
        <div class="card p-6">
            <div class="flex items-center justify-between mb-5">
                <h2 class="font-display text-lg font-semibold text-[#14181a]">Account</h2>
                <a href="{{ route('profile.me') }}" class="text-sm font-medium text-[#044b46] hover:underline">Lihat Profile</a>
            </div>

            <div class="flex items-center gap-4">
                @if ($user->avatar_url)
                    <img src="{{ $user->avatar_url }}" referrerpolicy="no-referrer" class="w-12 h-12 rounded-full object-cover">
                @else
                    <div class="w-12 h-12 rounded-full bg-[#044b46] text-white text-base font-semibold flex items-center justify-center">
                        {{ strtoupper(substr($user->name, 0, 1)) }}
                    </div>
                @endif
                <div>
                    <p class="text-sm font-semibold text-[#14181a]">{{ $user->name }}</p>
                    <p class="text-sm text-[#5c6266]">{{ $user->email }}</p>
                    <p class="text-xs text-[#9aa0a4] mt-0.5">{{ $user->role->name ?? '-' }}</p>
                </div>
            </div>
        </div>

        {{-- Link ke halaman Import Performance Data (PRD 7.3.4) --}}
        <a href="{{ route('settings.import') }}" class="card p-6 flex items-center justify-between hover:border-[#044b46]/30 transition-colors">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-[#f0f5f4] flex items-center justify-center">
                    <span class="material-symbols-outlined text-[#044b46] text-[19px]">upload_file</span>
                </div>
                <div>
                    <p class="text-sm font-semibold text-[#14181a]">Import Performance Data</p>
                    <p class="text-xs text-[#9aa0a4]">Upload CSV metrik performa konten manual.</p>
                </div>
            </div>
            <span class="material-symbols-outlined text-[#c3c7cb] text-[19px]">chevron_right</span>
        </a>

        {{-- Link ke halaman Analytics Integration + Sync Log (PRD 7.3.4) --}}
        <a href="{{ route('settings.integrations') }}" class="card p-6 flex items-center justify-between hover:border-[#044b46]/30 transition-colors">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-[#f0f5f4] flex items-center justify-center">
                    <span class="material-symbols-outlined text-[#044b46] text-[19px]">hub</span>
                </div>
                <div>
                    <p class="text-sm font-semibold text-[#14181a]">Analytics Integration &amp; Sync Log</p>
                    <p class="text-xs text-[#9aa0a4]">Status koneksi API per platform dan riwayat sinkronisasi.</p>
                </div>
            </div>
            <span class="material-symbols-outlined text-[#c3c7cb] text-[19px]">chevron_right</span>
        </a>


        {{-- Notifications --}}
        <div class="card p-6">
            <h2 class="font-display text-lg font-semibold text-[#14181a] mb-4">Notifications</h2>
            <div class="space-y-3.5">
                @foreach (['Konten mendekati deadline', 'Ada revisi baru yang perlu direspons', 'Sinkronisasi data performa selesai/gagal'] as $label)
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-[#5c6266]">{{ $label }}</span>
                        <button type="button" class="w-10 h-[22px] rounded-full bg-[#044b46] relative">
                            <span class="absolute top-0.5 right-0.5 w-[18px] h-[18px] rounded-full bg-white"></span>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Anomaly Detection --}}
        <div class="card p-6">
            <div class="flex items-center gap-2.5 mb-1">
                <span class="material-symbols-outlined text-[#3452a8] text-[18px]">auto_awesome</span>
                <h2 class="font-display text-lg font-semibold text-[#14181a]">Anomaly Detection</h2>
            </div>
            <p class="text-sm text-[#5c6266] mb-5">
                Otomatis bandingin performa konten hari ini vs rata-rata 30 hari terakhir, kirim notifikasi kalau ada lonjakan/penurunan signifikan.
                Berjalan otomatis tiap jam - ini buat trigger manual.
            </p>
            <form action="{{ route('settings.detect-anomalies') }}" method="POST">
                @csrf
                <button type="submit" class="bg-[#eef2fb] text-[#3452a8] text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[#e2e8f8] transition-colors flex items-center gap-2">
                    <span class="material-symbols-outlined text-[16px]">bolt</span> Jalankan Sekarang
                </button>
            </form>
        </div>

    </div>
</div>

@endsection