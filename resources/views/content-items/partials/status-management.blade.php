@php
    $hasUnresolvedRevisions = $contentItem->revisions->whereIn('status', ['open', 'in_progress'])->isNotEmpty();
    $btnBase = 'w-full text-sm font-medium px-4 py-2.5 rounded-lg transition-colors flex items-center justify-center gap-1.5';
    $btnEnabled = 'bg-[#044b46] text-white hover:bg-[#033b37]';
    $btnDisabled = 'bg-[#f2f3f6] text-[#c3c7cb] cursor-not-allowed';
@endphp

<div id="status-management" class="card p-5 scroll-mt-6" x-data="{ scheduledUploadAt: '' }">
    <div class="flex items-center justify-between mb-1">
        <h3 class="text-sm font-semibold text-[#14181a]">Status Management</h3>
    </div>
    <p class="text-xs text-[#9aa0a4] mb-4">Pindahkan status konten tanpa perlu drag & drop di board.</p>

    @unless ($canUpdateWorkflow)
        <div class="flex items-start gap-2 bg-[#fdf6ec] text-[#8a6423] text-xs p-3 rounded-lg mb-3.5">
            <span class="material-symbols-outlined text-[16px] shrink-0">info</span>
            <span>Kamu cuma bisa melihat, tidak punya izin memindahkan status konten.</span>
        </div>
    @endunless

    {{-- Aksi utama per status --}}
    @if ($workflow->current_status === 'brief_ready')
        <button type="button" :disabled="{{ $canUpdateWorkflow ? 'false' : 'true' }}"
            @click="confirmAction = {
                title: 'Kerjakan Konten',
                message: 'Pindahkan status &quot;{{ addslashes($contentItem->title) }}&quot; ke In Progress?',
                formAction: '{{ route('content-items.transition', $contentItem) }}',
                method: 'PATCH',
                fields: { to_status: 'in_progress' },
                confirmLabel: 'Ya, Kerjakan',
            }"
            title="{{ $canUpdateWorkflow ? '' : 'Kamu tidak punya izin memindahkan status' }}"
            class="{{ $btnBase }} {{ $canUpdateWorkflow ? $btnEnabled : $btnDisabled }}">
            <span class="material-symbols-outlined text-[16px]">play_arrow</span> KERJAKAN KONTEN
        </button>
    @elseif ($workflow->current_status === 'in_progress')
        <button type="button" :disabled="{{ $canUpdateWorkflow ? 'false' : 'true' }}"
            @click="confirmAction = {
                title: 'Konten Telah Selesai',
                message: 'Pindahkan status &quot;{{ addslashes($contentItem->title) }}&quot; ke Waiting Review?',
                formAction: '{{ route('content-items.transition', $contentItem) }}',
                method: 'PATCH',
                fields: { to_status: 'waiting_review' },
                confirmLabel: 'Ya, Selesai',
            }"
            title="{{ $canUpdateWorkflow ? '' : 'Kamu tidak punya izin memindahkan status' }}"
            class="{{ $btnBase }} {{ $canUpdateWorkflow ? $btnEnabled : $btnDisabled }}">
            <span class="material-symbols-outlined text-[16px]">check</span> KONTEN TELAH SELESAI
        </button>
    @elseif ($workflow->current_status === 'waiting_review')
        @php $approveDisabled = ! $canUpdateWorkflow || $hasUnresolvedRevisions; @endphp
        @if ($hasUnresolvedRevisions)
            <div class="flex items-start gap-2 bg-[#fdf6ec] text-[#8a6423] text-xs p-3 rounded-lg mb-3">
                <span class="material-symbols-outlined text-[16px] shrink-0">hourglass_empty</span>
                <span>Masih ada revisi yang belum diselesaikan - selesaikan dulu di bagian Revision Log sebelum bisa approve.</span>
            </div>
        @endif
        <button type="button" :disabled="{{ $approveDisabled ? 'true' : 'false' }}"
            @click="confirmAction = {
                title: 'Approve Konten',
                message: 'Approve &quot;{{ addslashes($contentItem->title) }}&quot;? Konten akan lanjut ke tahap penjadwalan upload.',
                formAction: '{{ route('content-items.transition', $contentItem) }}',
                method: 'PATCH',
                fields: { to_status: 'approved' },
                confirmLabel: 'Ya, Approve',
            }"
            title="{{ $hasUnresolvedRevisions ? 'Masih ada revisi yang belum diselesaikan' : ($canUpdateWorkflow ? '' : 'Kamu tidak punya izin memindahkan status') }}"
            class="{{ $btnBase }} {{ $approveDisabled ? $btnDisabled : $btnEnabled }}">
            <span class="material-symbols-outlined text-[16px]">task_alt</span> APPROVE KONTEN
        </button>
    @elseif ($workflow->current_status === 'revision')
        <div class="flex items-start gap-2 bg-[#f7f8fc] text-[#5c6266] text-xs p-3 rounded-lg">
            <span class="material-symbols-outlined text-[16px] shrink-0">rate_review</span>
            <span>Konten lagi dalam siklus revisi. Kelola & mulai kerjakan revisinya di bagian <strong>Revision Log</strong> di bawah.</span>
        </div>
    @elseif ($workflow->current_status === 'approved')
        <div class="mb-3">
            <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Rencana Tanggal &amp; Jam Upload</label>
            <input type="datetime-local" x-model="scheduledUploadAt" :disabled="{{ $canUpdateWorkflow ? 'false' : 'true' }}"
                class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40 disabled:bg-[#f7f8fc] disabled:text-[#c3c7cb]">
        </div>
        <button type="button" :disabled="{{ $canUpdateWorkflow ? 'false' : 'true' }} || !scheduledUploadAt"
            @click="confirmAction = {
                title: 'Jadwalkan Upload',
                message: 'Jadwalkan upload untuk &quot;{{ addslashes($contentItem->title) }}&quot; pada:',
                extra: new Date(scheduledUploadAt).toLocaleString('id-ID', { dateStyle: 'full', timeStyle: 'short' }),
                formAction: '{{ route('content-items.transition', $contentItem) }}',
                method: 'PATCH',
                fields: { to_status: 'scheduled', scheduled_upload_at: scheduledUploadAt },
                confirmLabel: 'Ya, Jadwalkan',
            }"
            :class="(({{ $canUpdateWorkflow ? 'true' : 'false' }} && scheduledUploadAt) ? '{{ $btnEnabled }}' : '{{ $btnDisabled }}')"
            class="{{ $btnBase }}">
            <span class="material-symbols-outlined text-[16px]">event_available</span> JADWALKAN UPLOAD
        </button>
    @elseif ($workflow->current_status === 'scheduled')
        <div class="flex items-start gap-2 bg-[#f7f8fc] text-[#5c6266] text-xs p-3 rounded-lg">
            <span class="material-symbols-outlined text-[16px] shrink-0">cloud_upload</span>
            <span>Tandai upload dengan mengisi data publikasi di bagian <strong>Record Publication</strong> di bawah.</span>
        </div>
    @endif

    {{-- Danger Zone --}}
    @if (! in_array($workflow->current_status, ['uploaded', 'cancelled']))
        <div class="mt-5 pt-4 border-t border-[#f2f3f6]">
            <p class="text-[10px] font-semibold text-[#b3423e] uppercase tracking-wide mb-2.5">Danger Zone</p>
            <button type="button" :disabled="{{ $canUpdateWorkflow ? 'false' : 'true' }}"
                @click="confirmNotes = ''; confirmAction = {
                    title: 'Batalkan Konten',
                    message: 'Yakin membatalkan &quot;{{ addslashes($contentItem->title) }}&quot;? Konten tidak bisa dikembalikan ke alur produksi setelah dibatalkan.',
                    formAction: '{{ route('content-items.transition', $contentItem) }}',
                    method: 'PATCH',
                    fields: { to_status: 'cancelled' },
                    withNotes: true,
                    notesLabel: 'Alasan pembatalan (opsional)',
                    confirmLabel: 'Ya, Batalkan',
                    danger: true,
                }"
                title="{{ $canUpdateWorkflow ? '' : 'Kamu tidak punya izin memindahkan status' }}"
                class="w-full text-sm font-medium px-4 py-2.5 rounded-lg border transition-colors flex items-center justify-center gap-1.5
                    {{ $canUpdateWorkflow ? 'border-[#f5d9d7] text-[#b3423e] hover:bg-[#fdf2f1]' : 'border-[#eef0f4] text-[#c3c7cb] cursor-not-allowed' }}">
                <span class="material-symbols-outlined text-[16px]">cancel</span> Batalkan Konten
            </button>
        </div>
    @endif
</div>
