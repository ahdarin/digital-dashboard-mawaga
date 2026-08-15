@extends('layouts.app')
@section('title', 'Report Generator')
@section('content')

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px]">

    <div class="mb-7">
        <h1 class="font-display text-[32px] font-semibold text-[#14181a]">Report Generator</h1>
        <p class="text-[#5c6266] text-sm mt-1">Generate laporan progres operasional atau performa konten, siap dikirim ke client.</p>
    </div>

    @if (session('status'))
        <div class="mb-5 bg-[#f0f5f4] border border-[#dbe6e4] rounded-lg p-3.5 text-sm text-[#044b46] flex items-center gap-2">
            <span class="material-symbols-outlined text-[17px]">check_circle</span>
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-5">

        {{-- Laporan Progres Operasional --}}
        <div class="card p-6">
            <div class="flex items-center gap-3 mb-1">
                <div class="w-9 h-9 rounded-lg bg-[#eef2fb] flex items-center justify-center">
                    <span class="material-symbols-outlined text-[#3452a8] text-[18px]">fact_check</span>
                </div>
                <h3 class="text-sm font-semibold text-[#14181a]">Operational Progress Report</h3>
            </div>
            <p class="text-xs text-[#9aa0a4] mb-5 ml-12">Jumlah konten selesai, overdue, dan revisi.</p>

            <form action="{{ route('report.generate') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Client</label>
                    <select name="client_id" class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                        <option value="">Semua Client</option>
                        @foreach ($clientOptions as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="date-range-wrapper">
                    <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Periode</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-[#c3c7cb] text-[16px]">date_range</span>
                        <input type="text" class="date-range-picker w-full border border-[#eef0f4] rounded-lg pl-10 pr-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40 bg-white"
                               placeholder="Pilih rentang tanggal" autocomplete="off" required>
                        <input type="hidden" name="period_start" class="period-start-input">
                        <input type="hidden" name="period_end" class="period-end-input">
                    </div>
                </div>

                <div class="flex gap-3 pt-1">
                    <button type="submit" name="format" value="pdf"
                            class="flex-1 bg-[#3452a8] text-white text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[#2c4590] transition-colors flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-[15px]">picture_as_pdf</span> Export PDF
                    </button>
                    <button type="submit" name="format" value="excel"
                            class="flex-1 bg-white border border-[#eef0f4] text-[#5c6266] text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[#f7f8fc] transition-colors flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-[15px]">table_view</span> Export Excel
                    </button>
                </div>
            </form>
        </div>

        {{-- Laporan Performa Konten --}}
        <div class="card p-6">
            <div class="flex items-center gap-3 mb-1">
                <div class="w-9 h-9 rounded-lg bg-[#f0f5f4] flex items-center justify-center">
                    <span class="material-symbols-outlined text-[#044b46] text-[18px]">trending_up</span>
                </div>
                <h3 class="text-sm font-semibold text-[#14181a]">Content Performance Report</h3>
            </div>
            <p class="text-xs text-[#9aa0a4] mb-5 ml-12">Views, engagement rate, top content &amp; breakdown platform.</p>

            <form action="{{ route('report.generate-performance') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Client <span class="text-[#b3423e]">*</span></label>
                    <select name="client_id" required class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                        <option value="">Pilih client...</option>
                        @foreach ($clientOptions as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="date-range-wrapper">
                    <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Periode</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-[#c3c7cb] text-[16px]">date_range</span>
                        <input type="text" class="date-range-picker w-full border border-[#eef0f4] rounded-lg pl-10 pr-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40 bg-white"
                               placeholder="Pilih rentang tanggal" autocomplete="off" required>
                        <input type="hidden" name="period_start" class="period-start-input">
                        <input type="hidden" name="period_end" class="period-end-input">
                    </div>
                </div>

                <div class="flex gap-3 pt-1">
                    <button type="submit" name="format" value="pdf"
                            class="flex-1 bg-[#044b46] text-white text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-[15px]">picture_as_pdf</span> Export PDF
                    </button>
                    <button type="submit" name="format" value="excel"
                            class="flex-1 bg-white border border-[#eef0f4] text-[#5c6266] text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[#f7f8fc] transition-colors flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-[15px]">table_view</span> Export Excel
                    </button>
                </div>
            </form>
        </div>

    </div>

    {{-- Riwayat --}}
    <div class="card overflow-hidden">
        <div class="p-6 pb-0 flex items-center justify-between flex-wrap gap-2">
            <h3 class="font-display text-lg font-semibold text-[#14181a]">Report History</h3>
            <span class="text-xs text-[#9aa0a4]">{{ $reports->count() }} laporan</span>
        </div>

        <div class="p-6">
            @if ($reports->isEmpty())
                <div class="flex flex-col items-center justify-center py-10 text-center">
                    <span class="material-symbols-outlined text-[#d4d7db] text-[24px] mb-2">description</span>
                    <p class="text-sm text-[#9aa0a4]">Belum ada laporan dibuat.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                                <th class="pb-3 pr-4 font-medium whitespace-nowrap">Type</th>
                                <th class="pb-3 pr-4 font-medium whitespace-nowrap">Client</th>
                                <th class="pb-3 pr-4 font-medium whitespace-nowrap">Period</th>
                                <th class="pb-3 pr-4 font-medium whitespace-nowrap">Created</th>
                                <th class="pb-3 pr-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reports as $report)
                                <tr class="border-t border-[#f2f3f6]">
                                    <td class="py-3 pr-4 whitespace-nowrap">
                                        @if ($report->report_type === 'performance_summary')
                                            <span class="text-[10px] font-medium px-2.5 py-1 rounded-full bg-[#f0f5f4] text-[#044b46]">Performance</span>
                                        @else
                                            <span class="text-[10px] font-medium px-2.5 py-1 rounded-full bg-[#eef2fb] text-[#3452a8]">Progress</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4 font-medium text-[#14181a] whitespace-nowrap">{{ $report->client->name ?? 'Semua Client' }}</td>
                                    <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $report->period_start->format('d M') }} - {{ $report->period_end->format('d M Y') }}</td>
                                    <td class="py-3 pr-4 text-[#9aa0a4] whitespace-nowrap">{{ $report->created_at->diffForHumans() }}</td>
                                    <td class="py-3 pr-4 text-right whitespace-nowrap">
                                        <a href="{{ Storage::url($report->file_path) }}" target="_blank" class="inline-flex items-center gap-1 text-[#044b46] text-xs font-medium hover:underline">
                                            <span class="material-symbols-outlined text-[13px]">download</span> Unduh
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('.date-range-picker').forEach(function (el) {
        const wrapper = el.closest('.date-range-wrapper');
        const startInput = wrapper.querySelector('.period-start-input');
        const endInput = wrapper.querySelector('.period-end-input');

        flatpickr(el, {
            mode: 'range',
            dateFormat: 'd M Y',
            onChange: function (selectedDates) {
                const toIso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                startInput.value = selectedDates[0] ? toIso(selectedDates[0]) : '';
                endInput.value = selectedDates[1] ? toIso(selectedDates[1]) : '';
            },
        });

        el.closest('form').addEventListener('submit', function (e) {
            if (!startInput.value || !endInput.value) {
                e.preventDefault();
                el.focus();
            }
        });
    });
</script>

@endsection