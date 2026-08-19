@php
    $hasUnresolvedRevisions = $contentItem->revisions->whereIn('status', ['open', 'in_progress'])->isNotEmpty();
@endphp

<div id="status-management" class="card p-5 scroll-mt-6" x-data="{ scheduledUploadAt: '' }">
    <div class="flex items-center justify-between mb-1">
        <h3 class="text-sm font-semibold text-[var(--text-primary)]">Status Management</h3>
    </div>
    <p class="text-xs text-[var(--text-muted)] mb-4">Pindahkan status konten tanpa perlu drag & drop di board.</p>

    @unless ($canUpdateWorkflow)
        <div class="flex items-start gap-2 bg-[var(--warning-tint)] text-[var(--warning-text)] text-xs p-3 rounded-lg mb-3.5">
            <span class="material-symbols-outlined text-[16px] shrink-0">info</span>
            <span>Kamu cuma bisa melihat, tidak punya izin memindahkan status konten.</span>
        </div>
    @endunless

    {{-- Aksi utama per status --}}
    @if ($workflow->current_status === 'brief_ready')
        <button type="button" :disabled="{{ $canUpdateWorkflow ? 'false' : 'true' }}"
            @click="confirmAction = {
                title: 'Kerjakan Konten',
                message: 'Pindahkan status &quot;{{ addslashes($contentItem->title) }}&quot; ke Sedang Dikerjakan? Langkah ini tidak bisa dibatalkan - status cuma bisa maju.',
                formAction: '{{ route('content-items.transition', $contentItem) }}',
                method: 'PATCH',
                fields: { to_status: 'in_progress' },
                confirmLabel: 'Ya, Kerjakan',
            }"
            title="{{ $canUpdateWorkflow ? '' : 'Kamu tidak punya izin memindahkan status' }}"
            class="btn-primary w-full">
            <span class="material-symbols-outlined text-[16px]">play_arrow</span> KERJAKAN KONTEN
        </button>
    @elseif ($workflow->current_status === 'in_progress')
        <button type="button" :disabled="{{ $canUpdateWorkflow ? 'false' : 'true' }}"
            @click="confirmAction = {
                title: 'Konten Telah Selesai',
                message: 'Pindahkan status &quot;{{ addslashes($contentItem->title) }}&quot; ke Menunggu Persetujuan? Langkah ini tidak bisa dibatalkan - status cuma bisa maju.',
                formAction: '{{ route('content-items.transition', $contentItem) }}',
                method: 'PATCH',
                fields: { to_status: 'waiting_review' },
                confirmLabel: 'Ya, Selesai',
            }"
            title="{{ $canUpdateWorkflow ? '' : 'Kamu tidak punya izin memindahkan status' }}"
            class="btn-primary w-full">
            <span class="material-symbols-outlined text-[16px]">check</span> KONTEN TELAH SELESAI
        </button>
    @elseif ($workflow->current_status === 'waiting_review')
        @php $approveDisabled = ! $canApprove || $hasUnresolvedRevisions; @endphp
        @if ($workflow->client_reviewed_at)
            <div class="flex items-start gap-2 bg-[var(--success-tint)] text-[var(--success-text)] text-xs p-3 rounded-lg mb-3">
                <span class="material-symbols-outlined text-[16px] shrink-0">check_circle</span>
                <span>Klien sudah menyetujui konten ini ({{ $workflow->client_reviewed_at->format('d M Y, H:i') }}). Cek sekali lagi sebelum approve final.</span>
            </div>
        @else
            <div class="flex items-start gap-2 bg-[var(--warning-tint)] text-[var(--warning-text)] text-xs p-3 rounded-lg mb-3">
                <span class="material-symbols-outlined text-[16px] shrink-0">hourglass_empty</span>
                <span>Klien belum merespons konten ini. Approve tetap bisa dilakukan, tapi belum ada persetujuan dari klien.</span>
            </div>
        @endif
        @if ($hasUnresolvedRevisions)
            <div class="flex items-start gap-2 bg-[var(--warning-tint)] text-[var(--warning-text)] text-xs p-3 rounded-lg mb-3">
                <span class="material-symbols-outlined text-[16px] shrink-0">hourglass_empty</span>
                <span>Masih ada revisi yang belum diselesaikan - selesaikan dulu di bagian Revision Log sebelum bisa approve.</span>
            </div>
        @endif
        <button type="button" :disabled="{{ $approveDisabled ? 'true' : 'false' }}"
            @click="confirmAction = {
                title: 'Approve Konten',
                message: 'Approve &quot;{{ addslashes($contentItem->title) }}&quot;? Konten akan lanjut ke tahap penjadwalan upload. Langkah ini tidak bisa dibatalkan.',
                formAction: '{{ route('content-items.transition', $contentItem) }}',
                method: 'PATCH',
                fields: { to_status: 'approved' },
                confirmLabel: 'Ya, Approve',
            }"
            title="{{ $hasUnresolvedRevisions ? 'Masih ada revisi yang belum diselesaikan' : ($canApprove ? '' : 'Kamu tidak punya izin approve konten') }}"
            class="btn-primary w-full">
            <span class="material-symbols-outlined text-[16px]">task_alt</span> APPROVE KONTEN
        </button>
    @elseif ($workflow->current_status === 'revision')
        <div class="flex items-start gap-2 bg-[var(--surface-page)] text-[var(--text-secondary)] text-xs p-3 rounded-lg">
            <span class="material-symbols-outlined text-[16px] shrink-0">rate_review</span>
            <span>Konten lagi dalam siklus revisi. Kelola & mulai kerjakan revisinya di bagian <strong>Revision Log</strong> di bawah.</span>
        </div>
    @elseif ($workflow->current_status === 'approved')
        <div class="mb-3">
            <label for="scheduled_upload_at" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Rencana Tanggal &amp; Jam Upload</label>
            <input id="scheduled_upload_at" type="text" x-model="scheduledUploadAt" data-flatpickr="datetime" autocomplete="off" :disabled="{{ $canUpdateWorkflow ? 'false' : 'true' }}"
                class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40 disabled:bg-[var(--surface-page)] disabled:text-[var(--text-muted)]">
        </div>
        <button type="button" :disabled="{{ $canUpdateWorkflow ? 'false' : 'true' }} || !scheduledUploadAt"
            @click="confirmAction = {
                title: 'Jadwalkan Upload',
                message: 'Jadwalkan upload untuk &quot;{{ addslashes($contentItem->title) }}&quot; pada (langkah ini tidak bisa dibatalkan):',
                extra: new Date(scheduledUploadAt).toLocaleString('id-ID', { dateStyle: 'full', timeStyle: 'short' }),
                formAction: '{{ route('content-items.transition', $contentItem) }}',
                method: 'PATCH',
                fields: { to_status: 'scheduled', scheduled_upload_at: scheduledUploadAt },
                confirmLabel: 'Ya, Jadwalkan',
            }"
            class="btn-primary w-full">
            <span class="material-symbols-outlined text-[16px]">event_available</span> JADWALKAN UPLOAD
        </button>
    @elseif ($workflow->current_status === 'scheduled')
        <div class="flex items-start gap-2 bg-[var(--surface-page)] text-[var(--text-secondary)] text-xs p-3 rounded-lg">
            <span class="material-symbols-outlined text-[16px] shrink-0">cloud_upload</span>
            <span>Tandai upload dengan mengisi data publikasi di bagian <strong>Record Publication</strong> di bawah.</span>
        </div>
    @endif

    {{-- Koreksi Status - khusus Manager & CEO, buat kasus status terlanjur
         salah dipindahkan. Dicatat terpisah dari revisi biasa. --}}
    @if (in_array(auth()->user()->role?->name, ['Manager', 'CEO']) && ! in_array($workflow->current_status, ['uploaded', 'cancelled']))
        <div class="mt-5 pt-4 border-t border-[var(--surface-muted)]" x-data="{ showCorrection: false, correctTo: '', correctReason: '' }">
            <button type="button" @click="showCorrection = !showCorrection" class="text-xs font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[15px]">build</span> Koreksi Status (kesalahan input)
            </button>
            <div x-show="showCorrection" x-cloak x-transition class="mt-3 space-y-2.5">
                <p class="text-[11px] text-[var(--text-muted)]">Buat status yang terlanjur salah dipindahkan. Dicatat terpisah dari revisi tim, tidak memengaruhi penilaian kinerja Penanggung Jawab.</p>
                <select x-model="correctTo" class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                    <option value="">Pilih status yang benar...</option>
                    @foreach (\App\Support\WorkflowTransitions::labels() as $value => $label)
                        @if ($value !== $workflow->current_status)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endif
                    @endforeach
                </select>
                <textarea x-model="correctReason" rows="2" placeholder="Alasan koreksi (wajib)..."
                    class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40"></textarea>
                <button type="button" :disabled="! correctTo || ! correctReason"
                    @click="confirmAction = {
                        title: 'Koreksi Status',
                        message: 'Koreksi status &quot;{{ addslashes($contentItem->title) }}&quot; jadi status yang dipilih? Ini bukan transisi produksi normal, dicatat terpisah sebagai koreksi.',
                        formAction: '{{ route('content-items.correct-status', $contentItem) }}',
                        method: 'PATCH',
                        fields: { to_status: correctTo, reason: correctReason },
                        confirmLabel: 'Ya, Koreksi',
                        danger: true,
                    }"
                    class="btn-danger w-full">
                    Koreksi Status
                </button>
            </div>
        </div>
    @endif

    {{-- Danger Zone --}}
    @if (! in_array($workflow->current_status, ['uploaded', 'cancelled']))
        <div class="mt-5 pt-4 border-t border-[var(--surface-muted)]">
            <p class="text-[10px] font-semibold text-[var(--danger-text)] uppercase tracking-wide mb-2.5">Danger Zone</p>
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
                class="btn-danger w-full">
                <span class="material-symbols-outlined text-[16px]">cancel</span> Batalkan Konten
            </button>
        </div>
    @endif
</div>
