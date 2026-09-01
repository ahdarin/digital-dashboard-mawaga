@extends('layouts.app')
@section('title', 'Atur Deadline - ' . $contentPlan->client->name)
@section('content')
<div class="p-4 sm:p-6 lg:p-8 max-w-[1100px] mx-auto" x-data="{ bulkDate: '' }">

    <div class="flex items-start gap-3 mb-6">
        <a href="{{ route('content-plan.show', $contentPlan) }}" title="Kembali"
           class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[var(--surface-card)] text-[var(--text-secondary)] transition-colors shrink-0 mt-0.5">
            <span class="material-symbols-outlined text-[19px]">arrow_back</span>
        </a>
        <div>
            <p class="text-xs text-[var(--text-muted)] mb-1">
                <a href="{{ route('content-plan.show', $contentPlan) }}" class="hover:text-[var(--brand)]">{{ $contentPlan->client->name }}</a> / Atur Deadline
            </p>
            <h1 class="font-display text-[26px] font-semibold text-[var(--text-primary)]">Atur Deadline Upload</h1>
            <p class="text-sm text-[var(--text-secondary)] mt-1">Isi tanggal upload tiap item - deadline produksi otomatis dihitung 2 hari sebelumnya.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="bg-[var(--brand-tint)] text-[var(--brand)] text-sm p-3.5 rounded-lg mb-5">{{ session('status') }}</div>
    @endif

    @if ($items->isEmpty())
        <div class="card p-8 text-center text-[var(--text-muted)] text-sm">Semua item sudah dikirim ke produksi - tidak ada lagi yang perlu diatur deadline-nya di sini.</div>
    @else
        <div class="card p-5 mb-5 flex flex-col sm:flex-row sm:items-end gap-3">
            <div class="flex-1">
                <label for="bulk-date" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Terapkan ke Semua</label>
                <input id="bulk-date" type="text" x-model="bulkDate" data-flatpickr="datetime" autocomplete="off"
                    class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
            </div>
            <button type="button"
                @click="document.querySelectorAll('.upload-deadline-input').forEach(el => { el.value = bulkDate; el._flatpickr?.setDate(bulkDate); el.dispatchEvent(new Event('input')); })"
                class="btn-secondary whitespace-nowrap">Terapkan ke Semua Baris</button>
        </div>

        <form action="{{ route('content-plan.deadlines.update', $contentPlan) }}" method="POST" x-data="{}">
            @csrf @method('PATCH')
            <div class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-[var(--surface-page)]">
                            <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                                <th class="px-5 py-3 font-medium">Item</th>
                                <th class="px-4 py-3 font-medium">Tipe</th>
                                <th class="px-4 py-3 font-medium w-[220px]">Tanggal Upload</th>
                                <th class="px-5 py-3 font-medium w-[160px]">Deadline Produksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $item)
                                <tr class="border-t border-[var(--surface-muted)]" x-data="{ upload: '{{ old('upload_deadline_at.' . $item->id, optional($item->upload_deadline_at)->format('Y-m-d H:i')) }}' }">
                                    <td class="px-5 py-3">
                                        <p class="font-medium text-[var(--text-primary)]">{{ $item->provisional_code }} <span class="text-[var(--text-muted)] font-normal">· {{ $item->title }}</span></p>
                                    </td>
                                    <td class="px-4 py-3 text-[var(--text-secondary)]">{{ $item->contentType->name ?? '-' }}</td>
                                    <td class="px-4 py-3">
                                        <input type="text" name="upload_deadline_at[{{ $item->id }}]" x-model="upload" data-flatpickr="datetime" autocomplete="off"
                                            class="upload-deadline-input w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                                    </td>
                                    <td class="px-5 py-3 text-xs text-[var(--text-secondary)]" x-text="upload ? new Date(new Date(upload).getTime() - 2*86400000).toLocaleDateString('id-ID') : '-'"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-5">
                <button type="submit" class="btn-primary">
                    <span class="material-symbols-outlined text-[16px]">save</span> Simpan Deadline
                </button>
            </div>
        </form>
    @endif

    @if ($contentPlan->contentItems()->whereHas('workflow', fn ($q) => $q->where('current_status', 'draft'))->whereNotNull('upload_deadline_at')->count() > 0
        && $contentPlan->contentItems()->whereHas('workflow', fn ($q) => $q->where('current_status', 'draft'))->whereNull('upload_deadline_at')->count() === 0)
        <div class="card p-5 mt-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-[var(--text-primary)]">Semua deadline sudah terisi.</p>
                <p class="text-xs text-[var(--text-muted)] mt-0.5">Item akan pindah ke Brief Ready dan briefnya dikunci - tidak bisa diedit lagi setelah ini.</p>
            </div>
            <form action="{{ route('content-plan.send-to-production', $contentPlan) }}" method="POST"
                onsubmit="return appConfirm(this, 'Kirim semua item ke produksi? Brief akan dikunci dan tidak bisa diedit lagi setelah ini.')">
                @csrf
                <button class="btn-primary whitespace-nowrap">
                    <span class="material-symbols-outlined text-[16px]">rocket_launch</span> Kirim ke Produksi
                </button>
            </form>
        </div>
    @endif
</div>
@endsection
