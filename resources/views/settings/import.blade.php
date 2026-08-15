@extends('layouts.app')
@section('title', 'Import Performance Data')
@section('content')

<div x-data="importPage()" class="p-4 sm:p-6 lg:p-8 max-w-[1300px]">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2">
        <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[#14181a]">Import Performance Data</h1>
        <a href="{{ route('settings.integrations') }}" class="text-sm font-medium text-[#044b46] hover:underline flex items-center gap-1">
            Lihat Sync Log <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
        </a>
    </div>
    <p class="text-[#5c6266] text-sm mb-6 max-w-2xl">Upload file CSV berisi metrik performa konten manual. Pastikan data sudah sesuai format sebelum meng-import.</p>

    @if (session('import_success'))
        <div class="mb-5 bg-[#f0f5f4] border border-[#dbe6e4] rounded-lg p-4">
            <p class="text-sm font-medium text-[#044b46] flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px]">check_circle</span>
                {{ session('import_success') }}
            </p>
            @if (! empty(session('import_skipped')))
                <ul class="mt-2 ml-6 list-disc text-xs text-[#5c6266] space-y-0.5">
                    @foreach (session('import_skipped') as $skip)
                        <li>{{ $skip }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if (session('import_error'))
        <div class="mb-5 bg-[#fdf2f1] border border-[#f5d9d7] rounded-lg p-4">
            <p class="text-sm font-medium text-[#b3423e] flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px]">error</span>
                {{ session('import_error') }}
            </p>
        </div>
    @endif

    <form action="{{ route('settings.import-performance') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <div class="flex flex-col lg:flex-row gap-6 items-stretch lg:items-start">

            <div class="flex-1 min-w-0 space-y-5">

                <div class="mb-1">
                    <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Client <span class="text-[#b3423e]">*</span></label>
                    <select name="client_id" required class="w-full max-w-sm border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                        <option value="">Pilih client tujuan data ini...</option>
                        @foreach ($clientOptions as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Dropzone --}}
                <div class="card p-10 flex flex-col items-center justify-center text-center transition-colors"
                     :class="dragging ? 'border-[#044b46] bg-[#f0f5f4]' : ''"
                     x-on:dragover.prevent="dragging = true"
                     x-on:dragleave.prevent="dragging = false"
                     x-on:drop.prevent="dragging = false; handleFile($event.dataTransfer.files[0])">

                    <div class="w-14 h-14 rounded-full bg-[#f0f5f4] flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-[#044b46] text-[26px]">cloud_upload</span>
                    </div>
                    <p class="font-display text-lg font-semibold text-[#14181a] mb-1">Tarik &amp; lepas file di sini</p>
                    <p class="text-sm text-[#9aa0a4] mb-4">atau</p>

                    <label class="cursor-pointer inline-block bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                        Pilih File
                        <input type="file" name="file" accept=".csv,.txt" required class="hidden" x-on:change="handleFile($event.target.files[0])">
                    </label>

                    <p class="text-xs text-[#9aa0a4] mt-4">Supports .csv, maksimal 5MB</p>

                    <p x-show="fileName" x-cloak class="text-sm font-medium text-[#044b46] mt-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-[17px]">description</span>
                        <span x-text="fileName"></span>
                    </p>
                </div>

                {{-- Preview --}}
                <div class="card overflow-hidden" x-show="previewRows.length > 0" x-cloak>
                    <div class="p-5 pb-3 flex items-center justify-between">
                        <h3 class="font-display text-lg font-semibold text-[#14181a]">Data Preview</h3>
                        <span class="text-xs px-2.5 py-1 rounded-full bg-[#f2f3f6] text-[#5c6266]">Sample (First 5 Rows)</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead>
                                <tr class="bg-[#f7f8fc] text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                                    <template x-for="col in previewHeader" :key="col">
                                        <th class="px-5 py-2.5 font-medium" x-text="col"></th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(row, i) in previewRows" :key="i">
                                    <tr class="border-t border-[#f2f3f6]">
                                        <template x-for="cell in row" :key="cell">
                                            <td class="px-5 py-2.5 text-[#5c6266]" x-text="cell"></td>
                                        </template>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <button type="submit" :disabled="!fileName"
                        class="bg-[#044b46] text-white text-sm font-medium px-6 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                    Konfirmasi Import
                </button>

            </div>

            {{-- Instructions --}}
            <div class="w-full lg:w-[300px] shrink-0 space-y-5">
                <div class="card p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="material-symbols-outlined text-[#044b46] text-[18px]">info</span>
                        <h3 class="font-display text-base font-semibold text-[#14181a]">Instructions</h3>
                    </div>
                    <p class="text-sm text-[#5c6266] mb-4">Data kamu harus sesuai skema standar berikut sebelum diproses.</p>

                    <div class="bg-[#f7f8fc] rounded-lg p-4">
                        <p class="text-xs font-semibold text-[#9aa0a4] uppercase mb-2.5">Required Columns</p>
                        <ul class="text-xs text-[#5c6266] space-y-1.5">
                            <li><strong class="text-[#14181a]">content_title</strong> (persis judul konten)</li>
                            <li><strong class="text-[#14181a]">platform</strong> (Instagram, TikTok, dst)</li>
                            <li><strong class="text-[#14181a]">metric_date</strong> (YYYY-MM-DD)</li>
                            <li><strong class="text-[#14181a]">views</strong> (angka)</li>
                            <li><strong class="text-[#14181a]">engagement_rate</strong> (angka, %)</li>
                        </ul>
                        <p class="text-[11px] text-[#9aa0a4] mt-3">Opsional: reach, impressions, likes, comments, profile_visit. Khusus Reels/TikTok: watch_time_avg, completion_rate, shares, saves.</p>
                    </div>

                    <button type="button" x-on:click="downloadTemplate()" class="mt-4 text-sm font-medium text-[#044b46] hover:underline flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]">download</span> Download Template CSV
                    </button>
                </div>
            </div>

        </div>
    </form>
</div>

<script>
function importPage() {
    return {
        dragging: false,
        fileName: null,
        previewHeader: [],
        previewRows: [],

        handleFile(file) {
            if (!file) return;
            this.fileName = file.name;

            const reader = new FileReader();
            reader.onload = (e) => {
                const lines = e.target.result.split(/\r?\n/).filter(l => l.trim() !== '');
                if (lines.length === 0) return;
                this.previewHeader = lines[0].split(',').map(s => s.trim());
                this.previewRows = lines.slice(1, 6).map(l => l.split(',').map(s => s.trim()));
            };
            reader.readAsText(file);

            // sinkronkan ke <input type=file> form kalau file dipilih lewat drag-drop
            const input = this.$root.querySelector('input[type=file]');
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
        },

        downloadTemplate() {
            const csv = 'content_title,platform,metric_date,views,engagement_rate\nPost Promo Ramadan,Instagram,2026-07-01,1200,4.5';
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'template-import-performance.csv';
            a.click();
            URL.revokeObjectURL(url);
        },
    }
}
</script>
@endsection