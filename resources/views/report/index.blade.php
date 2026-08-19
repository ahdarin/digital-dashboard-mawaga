@extends('layouts.app')
@section('title', 'Report Generator')
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    <div class="mb-7">
        <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[var(--text-primary)]">Laporan</h1>
        <p class="text-[var(--text-secondary)] text-sm mt-1">Generate laporan progres operasional atau performa konten, siap dikirim ke klien.</p>
    </div>

    @if (session('status'))
        <div class="mb-5 bg-[var(--brand-tint)] border border-[var(--brand-tint-border)] rounded-lg p-3.5 text-sm text-[var(--brand)] flex items-center gap-2">
            <span class="material-symbols-outlined text-[17px]">check_circle</span>
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-5">

        {{-- Laporan Progres Operasional --}}
        <div class="card p-6">
            <div class="flex items-center gap-3 mb-1">
                <div class="w-9 h-9 rounded-lg bg-[var(--info-tint)] flex items-center justify-center">
                    <span class="material-symbols-outlined text-[var(--info-text)] text-[18px]">fact_check</span>
                </div>
                <h2 class="text-sm font-semibold text-[var(--text-primary)]">Operational Progress Report</h2>
            </div>
            <p class="text-xs text-[var(--text-muted)] mb-5 ml-12">Jumlah konten selesai, overdue, dan revisi.</p>

            <form action="{{ route('report.generate') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label for="report_client_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Klien</label>
                    <select id="report_client_id" name="client_id" class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                        <option value="">Semua Klien</option>
                        @foreach ($clientOptions as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="date-range-wrapper">
                    <label for="report_period" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Periode</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[16px]">date_range</span>
                        <input id="report_period" type="text" class="date-range-picker w-full border border-[var(--border)] rounded-lg pl-10 pr-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40 bg-[var(--surface-card)]"
                               placeholder="Pilih rentang tanggal" autocomplete="off" required>
                        <input type="hidden" name="period_start" class="period-start-input">
                        <input type="hidden" name="period_end" class="period-end-input">
                    </div>
                </div>

                <div class="flex gap-3 pt-1">
                    <button type="submit" name="format" value="pdf"
                            class="flex-1 bg-[var(--info-solid)] text-white text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[var(--info-dark)] transition-colors flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-[15px]">picture_as_pdf</span> Export PDF
                    </button>
                    <button type="submit" name="format" value="excel"
                            class="btn-secondary flex-1">
                        <span class="material-symbols-outlined text-[15px]">table_view</span> Export Excel
                    </button>
                </div>
            </form>
        </div>

        {{-- Laporan Performa Konten --}}
        <div class="card p-6">
            <div class="flex items-center gap-3 mb-1">
                <div class="w-9 h-9 rounded-lg bg-[var(--brand-tint)] flex items-center justify-center">
                    <span class="material-symbols-outlined text-[var(--brand)] text-[18px]">trending_up</span>
                </div>
                <h2 class="text-sm font-semibold text-[var(--text-primary)]">Content Performance Report</h2>
            </div>
            <p class="text-xs text-[var(--text-muted)] mb-5 ml-12">Views, engagement rate, top content &amp; breakdown platform.</p>

            <form action="{{ route('report.generate-performance') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label for="performance_client_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Klien <span class="text-[var(--danger-text)]">*</span></label>
                    <select id="performance_client_id" name="client_id" required class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
                        <option value="">Pilih klien...</option>
                        @foreach ($clientOptions as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="date-range-wrapper">
                    <label for="performance_period" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Periode</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[16px]">date_range</span>
                        <input id="performance_period" type="text" class="date-range-picker w-full border border-[var(--border)] rounded-lg pl-10 pr-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40 bg-[var(--surface-card)]"
                               placeholder="Pilih rentang tanggal" autocomplete="off" required>
                        <input type="hidden" name="period_start" class="period-start-input">
                        <input type="hidden" name="period_end" class="period-end-input">
                    </div>
                </div>

                <div class="flex gap-3 pt-1">
                    <button type="submit" name="format" value="pdf"
                            class="btn-primary flex-1">
                        <span class="material-symbols-outlined text-[15px]">picture_as_pdf</span> Export PDF
                    </button>
                    <button type="submit" name="format" value="excel"
                            class="btn-secondary flex-1">
                        <span class="material-symbols-outlined text-[15px]">table_view</span> Export Excel
                    </button>
                </div>
            </form>
        </div>

    </div>

    {{-- Riwayat --}}
    <div class="card overflow-hidden">
        <div class="p-6 pb-0 flex items-center justify-between flex-wrap gap-2">
            <h2 class="font-display text-lg font-semibold text-[var(--text-primary)]">Riwayat Laporan</h2>
            <span class="text-xs text-[var(--text-muted)]">{{ $reports->count() }} laporan</span>
        </div>

        <div class="p-6">
            @if ($reports->isEmpty())
                <div class="flex flex-col items-center justify-center py-10 text-center">
                    <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[24px] mb-2">description</span>
                    <p class="text-sm text-[var(--text-muted)]">Belum ada laporan dibuat.</p>
                </div>
            @else
                <div class="overflow-x-auto hidden sm:block">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-[var(--surface-page)]">
                            <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                                <th class="px-6 py-3 font-medium whitespace-nowrap">Type</th>
                                <th class="px-4 py-3 font-medium whitespace-nowrap">Klien</th>
                                <th class="px-4 py-3 font-medium whitespace-nowrap">Period</th>
                                <th class="px-4 py-3 font-medium whitespace-nowrap">Created</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reports as $report)
                                <tr class="border-t border-[var(--surface-muted)]">
                                    <td class="px-6 py-3.5 whitespace-nowrap">
                                        @if ($report->report_type === 'performance_summary')
                                            <span class="badge badge-success">Performance</span>
                                        @else
                                            <span class="badge badge-info">Progress</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3.5 font-medium text-[var(--text-primary)] whitespace-nowrap">{{ $report->client->name ?? 'Semua Klien' }}</td>
                                    <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $report->period_start->format('d M') }} - {{ $report->period_end->format('d M Y') }}</td>
                                    <td class="px-4 py-3.5 text-[var(--text-muted)] whitespace-nowrap">{{ $report->created_at->diffForHumans() }}</td>
                                    <td class="px-6 py-3.5 text-right whitespace-nowrap">
                                        <a href="{{ Storage::url($report->file_path) }}" target="_blank" class="inline-flex items-center gap-1 text-[var(--brand)] text-xs font-medium hover:underline">
                                            <span class="material-symbols-outlined text-[13px]">download</span> Unduh
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Mobile accordion --}}
                <div class="sm:hidden space-y-3">
                    @foreach ($reports as $report)
                        <div class="card p-3.5" x-data="{ open: false }">
                            <button type="button" class="w-full text-left flex items-start gap-2 cursor-pointer" @click="open = !open" :aria-expanded="open">
                                <div class="flex-1 min-w-0">
                                    @if ($report->report_type === 'performance_summary')
                                        <span class="badge badge-success">Performance</span>
                                    @else
                                        <span class="badge badge-info">Progress</span>
                                    @endif
                                    <p class="font-medium text-[var(--text-primary)] text-sm mt-1.5">{{ $report->client->name ?? 'Semua Klien' }}</p>
                                    <p class="text-xs text-[var(--text-secondary)] mt-0.5">{{ $report->period_start->format('d M') }} - {{ $report->period_end->format('d M Y') }}</p>
                                </div>
                                <span class="material-symbols-outlined text-[19px] text-[var(--text-muted)] transition-transform shrink-0" :class="open && 'rotate-180'">expand_more</span>
                            </button>
                            <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[var(--surface-muted)] space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-[var(--text-muted)]">Created</span>
                                    <span class="text-[var(--text-primary)] font-medium">{{ $report->created_at->diffForHumans() }}</span>
                                </div>
                                <a href="{{ Storage::url($report->file_path) }}" target="_blank" @click.stop
                                   class="flex items-center justify-center gap-1.5 w-full bg-[var(--brand-tint)] text-[var(--brand)] text-xs font-medium px-3 py-2 rounded-lg hover:bg-[var(--brand-tint-soft)] transition-colors">
                                    <span class="material-symbols-outlined text-[15px]">download</span> Unduh
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.date-range-picker').forEach(function (el) {
        const wrapper = el.closest('.date-range-wrapper');
        const startInput = wrapper.querySelector('.period-start-input');
        const endInput = wrapper.querySelector('.period-end-input');

        flatpickr(el, {
            mode: 'range',
            dateFormat: 'd M Y',
            locale: 'id',
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
  });
</script>

@endsection