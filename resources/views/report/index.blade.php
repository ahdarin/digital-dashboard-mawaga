@extends('layouts.app')
@section('title', 'Report Generator')
@section('content')
<div class="p-8 max-w-2xl">
    <h2 class="text-2xl font-bold text-[#191c1c] mb-6">Report Generator</h2>

    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-8">
        <form action="{{ route('report.generate') }}" method="POST" class="space-y-4">
            @csrf

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Client</label>
                <select name="client_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]">
                    <option value="">Semua Client</option>
                    @foreach ($clientOptions as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Dari Tanggal</label>
                    <input type="date" name="period_start" required
                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Sampai Tanggal</label>
                    <input type="date" name="period_end" required
                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]">
                </div>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" name="format" value="pdf"
                        class="bg-[#044b46] text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-[#044b46]/90">
                    Export PDF
                </button>
                <button type="submit" name="format" value="excel"
                        class="bg-white border border-gray-200 text-gray-700 text-sm font-semibold px-4 py-2 rounded-lg hover:bg-gray-50">
                    Export Excel
                </button>
            </div>
        </form>
    </div>

    <div>
        <h3 class="text-sm font-bold text-gray-700 mb-3">Riwayat Laporan</h3>
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="px-4 py-3">Client</th>
                        <th class="px-4 py-3">Periode</th>
                        <th class="px-4 py-3">Dibuat</th>
                        <th class="px-4 py-3">File</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($reports as $report)
                        <tr class="border-t border-gray-100">
                            <td class="px-4 py-3">{{ $report->client->name ?? 'Semua Client' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $report->period_start->format('d M') }} - {{ $report->period_end->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $report->created_at->diffForHumans() }}</td>
                            <td class="px-4 py-3">
                                <a href="{{ Storage::url($report->file_path) }}" target="_blank" class="text-[#044b46] text-xs font-semibold hover:underline">Unduh</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400 text-sm">Belum ada laporan dibuat.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection