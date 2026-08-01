@extends('layouts.app')
@section('title', 'Report Generator')
@section('content')

<div class="p-8">

    {{-- Header --}}
    <div class="mb-8">
        <h1 class="text-4xl font-extrabold text-[#191c1c]">Report Generator</h1>
        <p class="text-gray-500 mt-2">Generate laporan progres operasional atau performa konten, siap dikirim ke client.</p>
    </div>

    @if (session('status'))
        <div class="mb-6 bg-emerald-50 border border-emerald-100 rounded-xl p-4 text-sm text-emerald-700 flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">check_circle</span>
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-2 gap-6 mb-6">

        {{-- Laporan Progres Operasional --}}
        <div class="relative bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6 overflow-hidden">
            <div class="absolute -right-8 -top-8 w-28 h-28 rounded-full bg-indigo-200/20 blur-2xl"></div>

            <div class="flex items-center gap-3 mb-1">
                <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center">
                    <span class="material-symbols-outlined text-indigo-500 text-[20px]">fact_check</span>
                </div>
                <h3 class="text-base font-extrabold text-[#191c1c]">Laporan Progres Operasional</h3>
            </div>
            <p class="text-xs text-gray-400 mb-5 ml-[52px]">Jumlah konten selesai, overdue, dan revisi.</p>

            <form action="{{ route('report.generate') }}" method="POST" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">Client</label>
                    <select name="client_id" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46] bg-white">
                        <option value="">Semua Client</option>
                        @foreach ($clientOptions as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">Periode</label>
                    <div class="flex items-center gap-2 border border-gray-200 rounded-xl px-3.5 py-1 focus-within:border-[#044b46]">
                        <input type="date" name="period_start" required
                               class="w-full text-sm py-1.5 border-0 focus:ring-0 focus:outline-none p-0 bg-transparent">
                        <span class="material-symbols-outlined text-gray-300 text-[16px] shrink-0">arrow_forward</span>
                        <input type="date" name="period_end" required
                               class="w-full text-sm py-1.5 border-0 focus:ring-0 focus:outline-none p-0 bg-transparent">
                    </div>
                </div>

                <div class="flex gap-3 pt-1">
                    <button type="submit" name="format" value="pdf"
                            class="flex-1 bg-gradient-to-r from-indigo-500 to-indigo-400 text-white text-sm font-semibold px-4 py-2.5 rounded-xl hover:opacity-90 transition-opacity shadow-[0_4px_10px_rgba(99,102,241,0.25)] flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]">picture_as_pdf</span>
                        Export PDF
                    </button>
                    <button type="submit" name="format" value="excel"
                            class="flex-1 bg-white border border-gray-200 text-gray-700 text-sm font-semibold px-4 py-2.5 rounded-xl hover:bg-gray-50 transition-colors flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]">table_view</span>
                        Export Excel
                    </button>
                </div>
            </form>
        </div>

        {{-- Laporan Performa Konten --}}
        <div class="relative bg-gradient-to-br from-white via-white to-[#f0f8f5] rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6 overflow-hidden border border-[#044b46]/5">
            <div class="absolute -right-8 -top-8 w-28 h-28 rounded-full bg-[#044b46]/10 blur-2xl"></div>

            <div class="flex items-center gap-3 mb-1">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#044b46] to-[#0a8f76] flex items-center justify-center shadow-[0_4px_10px_rgba(4,75,70,0.3)]">
                    <span class="material-symbols-outlined text-white text-[20px]">trending_up</span>
                </div>
                <h3 class="text-base font-extrabold text-[#191c1c]">Laporan Performa Konten</h3>
            </div>
            <p class="text-xs text-gray-400 mb-5 ml-[52px]">Views, engagement rate, top content &amp; breakdown platform.</p>

            <form action="{{ route('report.generate-performance') }}" method="POST" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">Client <span class="text-rose-500">*</span></label>
                    <select name="client_id" required class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46] bg-white">
                        <option value="">Pilih client...</option>
                        @foreach ($clientOptions as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1.5">Periode</label>
                    <div class="flex items-center gap-2 border border-gray-200 rounded-xl px-3.5 py-1 bg-white focus-within:border-[#044b46]">
                        <input type="date" name="period_start" required
                               class="w-full text-sm py-1.5 border-0 focus:ring-0 focus:outline-none p-0 bg-transparent">
                        <span class="material-symbols-outlined text-gray-300 text-[16px] shrink-0">arrow_forward</span>
                        <input type="date" name="period_end" required
                               class="w-full text-sm py-1.5 border-0 focus:ring-0 focus:outline-none p-0 bg-transparent">
                    </div>
                </div>

                <div class="flex gap-3 pt-1">
                    <button type="submit" name="format" value="pdf"
                            class="flex-1 bg-gradient-to-r from-[#044b46] to-[#0a6b5c] text-white text-sm font-semibold px-4 py-2.5 rounded-xl hover:opacity-90 transition-opacity shadow-[0_4px_10px_rgba(4,75,70,0.25)] flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]">picture_as_pdf</span>
                        Export PDF
                    </button>
                    <button type="submit" name="format" value="excel"
                            class="flex-1 bg-white border border-gray-200 text-gray-700 text-sm font-semibold px-4 py-2.5 rounded-xl hover:bg-gray-50 transition-colors flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]">table_view</span>
                        Export Excel
                    </button>
                </div>
            </form>
        </div>

    </div>

    {{-- Riwayat Laporan --}}
    <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] overflow-hidden">
        <div class="p-6 pb-0 flex items-center justify-between">
            <h3 class="text-lg font-extrabold text-[#191c1c]">Riwayat Laporan</h3>
            <span class="text-xs text-gray-400">{{ $reports->count() }} laporan</span>
        </div>

        <div class="p-6">
            @if ($reports->isEmpty())
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <div class="w-14 h-14 rounded-full bg-gray-50 flex items-center justify-center mb-3">
                        <span class="material-symbols-outlined text-gray-300 text-[26px]">description</span>
                    </div>
                    <p class="text-sm text-gray-400">Belum ada laporan dibuat.</p>
                </div>
            @else
                <table class="w-full text-sm text-left">
                    <thead class="text-gray-400 text-xs uppercase">
                        <tr>
                            <th class="pb-3 pr-4 font-semibold">Tipe</th>
                            <th class="pb-3 pr-4 font-semibold">Client</th>
                            <th class="pb-3 pr-4 font-semibold">Periode</th>
                            <th class="pb-3 pr-4 font-semibold">Dibuat</th>
                            <th class="pb-3 pr-4 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reports as $report)
                            <tr class="border-t border-gray-50">
                                <td class="py-3 pr-4">
                                    @if ($report->report_type === 'performance_summary')
                                        <span class="text-[10px] font-semibold px-2.5 py-1 rounded-full bg-[#044b46]/10 text-[#044b46]">Performa</span>
                                    @else
                                        <span class="text-[10px] font-semibold px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-500">Progres</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 font-medium text-[#191c1c]">{{ $report->client->name ?? 'Semua Client' }}</td>
                                <td class="py-3 pr-4 text-gray-500">{{ $report->period_start->format('d M') }} - {{ $report->period_end->format('d M Y') }}</td>
                                <td class="py-3 pr-4 text-gray-400">{{ $report->created_at->diffForHumans() }}</td>
                                <td class="py-3 pr-4 text-right">
                                    <a href="{{ Storage::url($report->file_path) }}" target="_blank"
                                       class="inline-flex items-center gap-1 text-[#044b46] text-xs font-semibold hover:underline">
                                        <span class="material-symbols-outlined text-[14px]">download</span>
                                        Unduh
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>

@endsection