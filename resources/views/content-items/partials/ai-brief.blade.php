@php
    $contentBrief = $contentItem->contentBriefDraft;
    $deadlinePassed = $contentItem->deadline_at && $contentItem->deadline_at->startOfDay()->lt(now()->startOfDay());
    $daysOverdue = $deadlinePassed ? now()->startOfDay()->diffInDays($contentItem->deadline_at->copy()->startOfDay()) : 0;
    $isVideo = ($contentItem->contentType->name ?? '') === 'Video';
    $secondFieldLabel = $isVideo ? 'Script Talent' : 'Isi Design / Copy';
    $secondFieldIcon = $isVideo ? 'record_voice_over' : 'text_fields';
    $scenesForDisplay = $contentBrief?->scenes_for_display ?? [];
    $canEdit = auth()->user()->hasPermissionTo('content_plan', 'create');
    $isLocked = $contentBrief?->isLocked() ?? false;
@endphp

<div class="card p-6 border border-[#044b46]/15" x-data="{ editing: {{ $contentBrief ? 'false' : 'true' }} }">
    <div class="flex items-center gap-2 mb-1">
        <span class="material-symbols-outlined text-[var(--brand)] text-[16px]">auto_awesome</span>
        <h3 class="text-sm font-semibold text-[var(--text-primary)]">Brief Lengkap</h3>
    </div>
    <p class="text-xs text-[var(--text-muted)] mb-4">Naskah, talent, dan properti produksi - isi manual sendiri atau bantu AI per bagian.</p>

    @if ($deadlinePassed)
        <p class="flex items-center gap-1 text-xs text-[var(--danger-text)] font-medium mb-4">
            <span class="material-symbols-outlined text-[14px]">warning</span>
            Deadline sudah lewat {{ $daysOverdue }} hari ({{ $contentItem->deadline_at->format('d M Y') }}).
        </p>
    @endif

    {{-- Video di status In Progress bisa selesai syuting belakangan setelah
         proses edit sudah/lagi berjalan. Tanggal take-nya diisi manual
         (bukan otomatis "sekarang") karena sering baru sempat ditandai
         beberapa hari setelah syuting beneran terjadi. --}}
    @if ($isVideo && $contentItem->workflow->current_status === 'in_progress')
        <div x-data="{ editingTakeDate: {{ $contentItem->footage_captured_at ? 'false' : 'true' }} }" class="mb-4">
            @if ($contentItem->footage_captured_at)
                <div x-show="! editingTakeDate" class="bg-[var(--success-tint)] text-[var(--success-text)] text-xs p-3 rounded-lg">
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-[16px] shrink-0">check_circle</span>
                        <span>Video sudah di-take di lokasi ({{ $contentItem->footage_captured_at->format('d M Y, H:i') }}), menunggu proses edit.</span>
                    </div>
                    <div class="flex items-center justify-end gap-3 mt-2 pt-2 border-t border-[#0f7a5f]/15">
                        <button type="button" @click="editingTakeDate = true" class="text-[11px] font-medium text-[var(--success-text)] hover:underline">Ubah Tanggal</button>
                        <form action="{{ route('content-items.footage-captured.unmark', $contentItem) }}" method="POST" class="m-0"
                              onsubmit="return appConfirm(this, 'Batalkan penandaan? Kalau ternyata belum di-take, batalkan supaya statusnya akurat lagi.')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-[11px] font-medium text-[var(--warning-text)] hover:underline">Batalkan</button>
                        </form>
                    </div>
                </div>
            @endif

            <form x-show="editingTakeDate" action="{{ route('content-items.footage-captured', $contentItem) }}" method="POST"
                  class="flex items-center gap-2 flex-wrap">
                @csrf @method('PATCH')
                <div class="relative flex-1 min-w-0">
                    <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[16px] pointer-events-none">calendar_month</span>
                    <input type="text" name="footage_captured_at" data-flatpickr="datetime" autocomplete="off"
                        value="{{ ($contentItem->footage_captured_at ?? now())->format('Y-m-d H:i') }}" required
                        class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg pl-8 pr-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
                </div>
                <button type="submit"
                    class="flex items-center justify-center gap-1.5 border border-[#044b46]/30 text-[var(--brand)] text-sm font-medium px-4 py-2 rounded-lg hover:bg-[var(--brand-tint)] transition-colors whitespace-nowrap">
                    <span class="material-symbols-outlined text-[16px]">videocam</span> Tandai Sudah Di-take
                </button>
                @if ($contentItem->footage_captured_at)
                    <button type="button" @click="editingTakeDate = false" class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-primary)] px-2">Batal</button>
                @endif
            </form>
        </div>
    @endif

    {{-- Tombol AI keseluruhan - "Isi dengan AI" kalau brief belum ada,
         "Regenerate dengan AI" (menimpa semua field) kalau sudah ada. --}}
    @if ($canEdit && ! $isLocked)
        <form action="{{ $contentBrief ? route('content-brief.regenerate', $contentBrief) : route('content-brief.generate', $contentItem) }}" method="POST" class="mb-5"
            @if ($contentBrief)
                onsubmit="return appConfirm(this, 'Susun ulang brief ini dari awal pakai AI? Semua isi saat ini akan tertimpa (masih bisa di-revert 1x kalau berubah pikiran).')"
            @elseif ($deadlinePassed)
                onsubmit="return appConfirm(this, 'Deadline content ini sudah lewat {{ $daysOverdue }} hari ({{ $contentItem->deadline_at->format('d M Y') }}). Brief tetap bisa dibuat untuk konten yang terlambat, tapi kamu perlu pilih tanggal upload manual sesudahnya supaya keterlambatannya tercatat. Lanjutkan?')"
            @endif>
            @csrf
            <button class="btn-primary w-full whitespace-nowrap">
                <span class="material-symbols-outlined text-[18px]">auto_awesome</span>
                {{ $contentBrief ? 'Regenerate hasil AI' : 'Isi dengan AI' }}
            </button>
        </form>
    @endif

    @if ($contentBrief)
        {{-- ===== VIEW MODE - tampil begitu brief tersimpan, tombol Edit di
             bawah menggantikan posisi Simpan (bukan tetap berbentuk form). ===== --}}
        <div x-show="!editing" x-cloak>
            <div class="mb-5">
                <p class="flex items-center gap-1.5 text-xs font-semibold text-[var(--text-primary)] mb-2">
                    <span class="material-symbols-outlined text-[15px] text-[var(--brand)]">description</span> Naskah / Script
                </p>

                @if (count($scenesForDisplay))
                    <div class="space-y-3">
                        @foreach ($scenesForDisplay as $i => $scene)
                            <div class="border border-[var(--border)] rounded-xl overflow-hidden">
                                <div class="flex items-center gap-2 bg-[var(--surface-page)] border-b border-[var(--border)] px-4 py-2">
                                    <span class="w-5 h-5 rounded-full bg-[var(--brand-solid)] text-white text-[10px] font-bold flex items-center justify-center shrink-0">{{ $i + 1 }}</span>
                                    <p class="text-xs font-semibold text-[var(--text-primary)] uppercase tracking-wide">
                                        {{ $scene['label'] ?: 'Adegan/Slide '.($i + 1) }}
                                    </p>
                                </div>

                                <div class="divide-y divide-[var(--border)]">
                                    <div class="px-4 py-3">
                                        <p class="flex items-center gap-1.5 text-[10px] font-semibold text-[var(--text-muted)] uppercase mb-1.5">
                                            <span class="material-symbols-outlined text-[13px]">visibility</span> Visual
                                        </p>
                                        <p class="text-sm text-[var(--text-primary)] whitespace-pre-line">{{ $scene['visual'] ?: '-' }}</p>
                                    </div>
                                    <div class="px-4 py-3">
                                        <p class="flex items-center gap-1.5 text-[10px] font-semibold text-[var(--text-muted)] uppercase mb-1.5">
                                            <span class="material-symbols-outlined text-[13px]">{{ $secondFieldIcon }}</span> {{ $secondFieldLabel }}
                                        </p>
                                        <p class="text-sm text-[var(--text-primary)] whitespace-pre-line">{{ $scene['talent_script'] ?: '-' }}</p>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-sm text-[var(--text-secondary)] bg-[var(--surface-page)] border border-[var(--border)] rounded-lg p-4">
                        <p>Belum diisi.</p>
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pb-5 mb-5 border-b border-[var(--surface-muted)]">
                <div>
                    <p class="flex items-center gap-1 text-[10px] text-[var(--text-muted)] uppercase font-semibold mb-1">
                        <span class="material-symbols-outlined text-[13px]">groups</span> Talent
                    </p>
                    <p class="text-sm text-[var(--text-primary)]">{{ $contentBrief->talent ?? '-' }}</p>
                </div>
                <div>
                    <p class="flex items-center gap-1 text-[10px] text-[var(--text-muted)] uppercase font-semibold mb-1">
                        <span class="material-symbols-outlined text-[13px]">inventory_2</span> Properti
                    </p>
                    <p class="text-sm text-[var(--text-primary)]">{{ $contentBrief->properti ?? '-' }}</p>
                </div>
            </div>

            @if ($deadlinePassed && ! $contentBrief->post_date)
                {{-- AI tidak pernah menentukan tanggal upload - PIC pilih sendiri
                     supaya keterlambatan riil tercatat di Team Performance. --}}
                <div class="flex items-start gap-2.5 p-3.5 rounded-lg mb-5" style="background-color: var(--danger-tint);">
                    <span class="material-symbols-outlined text-[17px] shrink-0 mt-0.5" style="color: var(--danger-text);">event_busy</span>
                    <div class="flex-1 min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-wide mb-0.5" style="color: var(--danger-text);">
                            Deadline Sudah Lewat {{ $daysOverdue }} Hari
                        </p>
                        <p class="text-sm mb-3" style="color: var(--danger-text);">
                            Pilih tanggal upload manual di bawah supaya keterlambatannya tercatat di Team Performance.
                        </p>
                        @if ($canEdit)
                            <form action="{{ route('content-brief.set-upload-date', $contentBrief) }}" method="POST" class="flex items-center gap-2 flex-wrap">
                                @csrf
                                @method('PATCH')
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[16px] pointer-events-none">calendar_month</span>
                                    <input type="text" name="post_date" data-flatpickr="date" autocomplete="off" required
                                        placeholder="Pilih tanggal upload"
                                        class="bg-[var(--surface-card)] border border-[var(--border)] rounded-lg pl-8 pr-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
                                </div>
                                <button type="submit" class="btn-primary whitespace-nowrap">
                                    <span class="material-symbols-outlined text-[16px]">event_available</span> Simpan Tanggal Upload
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @elseif ($contentBrief->post_date)
                <div class="flex items-center gap-1.5 text-xs text-[var(--text-secondary)] pb-4 mb-4 border-b border-[var(--surface-muted)]">
                    <span class="material-symbols-outlined text-[14px]">event_available</span>
                    Tanggal upload (manual): <span class="font-medium text-[var(--text-primary)]">{{ $contentBrief->post_date->format('d M Y') }}</span>
                </div>
            @endif

            {{-- Kelayakan + Kompleksitas Teknis disatukan - dua hal yang
                 sebenarnya saling terkait (kompleksitas adalah salah satu
                 input penilaian kelayakan), jadi ditampilkan sebagai satu
                 kartu, muncul setelah brief-nya ada isinya. --}}
            <div class="card p-5 bg-[var(--info-tint)] border-0 mb-2">
                @if ($contentBrief->feasibility_level)
                    @php
                        $feasibilityMeta = match ($contentBrief->feasibility_level) {
                            'critical' => ['icon' => 'error', 'text' => 'var(--danger-text)', 'label' => 'Risiko Tinggi'],
                            'warning' => ['icon' => 'warning', 'text' => 'var(--warning-text)', 'label' => 'Perlu Diperhatikan'],
                            default => ['icon' => 'check_circle', 'text' => 'var(--success-text)', 'label' => 'Jadwal Aman'],
                        };
                    @endphp
                    <div class="flex items-start gap-2.5 pb-4 mb-4 border-b border-[#0f7a5f]/10">
                        <span class="material-symbols-outlined text-[17px] shrink-0 mt-0.5" style="color: {{ $feasibilityMeta['text'] }};">{{ $feasibilityMeta['icon'] }}</span>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wide mb-0.5" style="color: {{ $feasibilityMeta['text'] }};">
                                Cek Kelayakan AI &middot; {{ $feasibilityMeta['label'] }}
                            </p>
                            <p class="text-sm" style="color: {{ $feasibilityMeta['text'] }};">{{ $contentBrief->feasibility_notes }}</p>
                        </div>
                    </div>
                @endif

                <div class="flex items-center gap-2 mb-3">
                    <span class="material-symbols-outlined text-[var(--info-text)] text-[17px]">insights</span>
                    <h3 class="text-sm font-semibold text-[var(--text-primary)]">Estimasi Kompleksitas Teknis</h3>
                </div>
                <p class="text-xs text-[var(--text-secondary)] mb-4">Dipakai modul Delay Risk Insight untuk menilai risiko keterlambatan.</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    @if ($contentBrief->estimated_duration_seconds)
                        <div><span class="text-[var(--text-muted)]">Durasi:</span> {{ $contentBrief->estimated_duration_seconds }} detik</div>
                    @endif
                    @if ($contentBrief->slide_count)
                        <div><span class="text-[var(--text-muted)]">Jumlah slide:</span> {{ $contentBrief->slide_count }}</div>
                    @endif
                    <div><span class="text-[var(--text-muted)]">Talent:</span> {{ $contentBrief->talent_count ?? 0 }} orang</div>
                    <div><span class="text-[var(--text-muted)]">Lokasi:</span> {{ $contentBrief->location_count ?? 0 }} lokasi</div>
                </div>

                <div class="mt-3">
                    <span class="badge
                        {{ $contentBrief->complexity_level === 'complex' ? 'badge-danger' : '' }}
                        {{ $contentBrief->complexity_level === 'medium' ? 'badge-warning' : '' }}
                        {{ $contentBrief->complexity_level === 'simple' ? 'badge-success' : '' }}">
                        Kompleksitas: {{ ucfirst($contentBrief->complexity_level ?? '-') }}
                    </span>
                </div>
            </div>

            @if ($canEdit && ! $isLocked)
                <button type="button" @click="editing = true" class="btn-secondary w-full mt-3">
                    <span class="material-symbols-outlined text-[16px]">edit</span> Edit
                </button>
            @endif

            @if ($isLocked)
                <div class="flex items-start gap-2 mt-3 px-3.5 py-3 rounded-lg bg-[var(--brand-tint)] border border-[#0f7a5f]/15">
                    <span class="material-symbols-outlined text-[15px] text-[var(--success-text)] mt-0.5">check_circle</span>
                    <p class="text-xs text-[var(--success-text)] leading-relaxed">
                        Brief ini sudah <strong>diterapkan ke tim produksi</strong> dan terkunci (tidak bisa diedit) - Penanggung Jawab yang ditugaskan sudah menerima notifikasi.
                    </p>
                </div>
            @elseif ($canEdit && $contentItem->workflow->current_status === 'draft')
                {{-- Item dari alur Content Plan - brief dikunci otomatis
                     bareng saat SMO "Kirim ke Produksi" (batch, lihat
                     halaman Content Plan), bukan tombol manual per-item. --}}
                <div class="flex items-start gap-2 mt-3 px-3.5 py-3 rounded-lg bg-[var(--surface-page)] border border-[var(--border)]">
                    <span class="material-symbols-outlined text-[15px] text-[var(--text-muted)] mt-0.5">hourglass_empty</span>
                    <p class="text-xs text-[var(--text-secondary)] leading-relaxed">
                        Brief ini akan otomatis dikunci &amp; diserahkan ke tim produksi bersamaan dengan item lain di rencana ini, begitu Content Plan diajukan, disetujui, dan dikirim ke produksi oleh SMO.
                    </p>
                </div>
            @elseif ($canEdit)
                {{-- Item non-plan (mis. Jobdesk Tambahan) yang tidak pernah
                     lewat fase Draf - serah-terima manual masih relevan
                     di sini karena tidak ada aksi batch SMO yang menguncinya. --}}
                <form action="{{ route('content-brief.finalize', $contentBrief) }}" method="POST" class="mt-3"
                      onsubmit="return appConfirm(this, 'Terapkan brief ini ke tim produksi? Brief akan terkunci (tidak bisa diedit) dan Penanggung Jawab produksi langsung dapat notifikasi.')">
                    @csrf
                    <button class="btn-primary w-full">
                        <span class="material-symbols-outlined text-[18px]">handshake</span> Terapkan Brief ke Tim Produksi
                    </button>
                </form>
            @endif
        </div>
    @endif

    @if ($canEdit && ! $isLocked)
        {{-- ===== FORM MODE - selalu bentuk isian manual langsung (bukan
             pilihan AI/manual terpisah), AI cuma bantu per-bagian lewat
             tombol ✨ di sebelah tiap field. ===== --}}
        <div x-show="editing" x-cloak
             x-data="briefForm({{ $contentItem->id }}, {{ Js::from(count($scenesForDisplay) ? $scenesForDisplay : [['label' => null, 'visual' => '', 'talent_script' => '']]) }}, {{ Js::from($contentBrief->talent ?? '') }}, {{ Js::from($contentBrief->properti ?? '') }})">
            <form action="{{ $contentBrief ? route('content-brief.update', $contentBrief) : route('content-brief.store-manual', $contentItem) }}" method="POST" class="space-y-5">
                @csrf
                @if ($contentBrief) @method('PATCH') @endif

                <div>
                    <div class="flex items-center justify-between mb-2.5">
                        <label class="block text-[10px] font-semibold text-[var(--text-muted)] uppercase">Naskah / Script (Adegan / Slide)</label>
                        <div class="flex items-center gap-3">
                            <button type="button" @click="addScene()" class="flex items-center gap-1 text-xs font-medium text-[var(--brand)] hover:underline">
                                <span class="material-symbols-outlined text-[14px]">add</span> Tambah Adegan/Slide
                            </button>
                            <button type="button" @click="assist('scenes')" :disabled="assisting.scenes"
                                class="flex items-center gap-1 text-xs font-medium text-[var(--brand)] hover:underline disabled:opacity-50">
                                <span>✨</span> <span x-text="assisting.scenes ? 'Membuat...' : 'Isi dengan AI'"></span>
                            </button>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <template x-for="(scene, index) in scenes" :key="index">
                            <div class="border border-[var(--border)] rounded-xl bg-[var(--surface-card)] overflow-hidden">
                                <div class="flex items-center justify-between gap-2 bg-[var(--surface-page)] border-b border-[var(--border)] px-3.5 py-2">
                                    <input type="text" :name="'scenes[' + index + '][label]'" x-model="scene.label"
                                        placeholder="Contoh: ADEGAN 1 / SLIDE 1"
                                        class="flex-1 text-xs font-semibold text-[var(--text-primary)] bg-transparent border-0 focus:outline-none focus:ring-0 p-0 uppercase placeholder:text-[var(--text-muted)] placeholder:normal-case">
                                    <button type="button" @click="removeScene(index)"
                                        class="text-[var(--danger-text)] hover:opacity-70 shrink-0">
                                        <span class="material-symbols-outlined text-[16px]">delete</span>
                                    </button>
                                </div>

                                <div class="divide-y divide-[var(--border)]">
                                    <div class="p-3.5">
                                        <label :for="'scene_' + index + '_visual'" class="flex items-center gap-1.5 text-[10px] font-semibold text-[var(--text-muted)] uppercase mb-1.5">
                                            <span class="material-symbols-outlined text-[13px]">visibility</span> Visual
                                        </label>
                                        <textarea :id="'scene_' + index + '_visual'" :name="'scenes[' + index + '][visual]'" x-model="scene.visual" rows="2"
                                            class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs bg-[var(--surface-subtle)] focus:outline-none focus:border-[#044b46]/40 focus:bg-[var(--surface-card)]"></textarea>
                                    </div>
                                    <div class="p-3.5">
                                        <label :for="'scene_' + index + '_talent_script'" class="flex items-center gap-1.5 text-[10px] font-semibold text-[var(--text-muted)] uppercase mb-1.5">
                                            <span class="material-symbols-outlined text-[13px]">{{ $secondFieldIcon }}</span> {{ $secondFieldLabel }}
                                        </label>
                                        <textarea :id="'scene_' + index + '_talent_script'" :name="'scenes[' + index + '][talent_script]'" x-model="scene.talent_script" rows="2"
                                            class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs bg-[var(--surface-subtle)] focus:outline-none focus:border-[#044b46]/40 focus:bg-[var(--surface-card)]"></textarea>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="talent" class="block text-[10px] font-semibold text-[var(--text-muted)] uppercase">Talent</label>
                        <button type="button" @click="assist('talent')" :disabled="assisting.talent"
                            class="flex items-center gap-1 text-[11px] font-medium text-[var(--brand)] hover:underline disabled:opacity-50">
                            <span>✨</span> <span x-text="assisting.talent ? 'Membuat...' : 'Isi dengan AI'"></span>
                        </button>
                    </div>
                    <input id="talent" type="text" name="talent" x-model="talent"
                        class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="properti" class="block text-[10px] font-semibold text-[var(--text-muted)] uppercase">Properti</label>
                        <button type="button" @click="assist('properti')" :disabled="assisting.properti"
                            class="flex items-center gap-1 text-[11px] font-medium text-[var(--brand)] hover:underline disabled:opacity-50">
                            <span>✨</span> <span x-text="assisting.properti ? 'Membuat...' : 'Isi dengan AI'"></span>
                        </button>
                    </div>
                    <input id="properti" type="text" name="properti" x-model="properti"
                        class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                </div>

                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" class="btn-primary">
                        Simpan Perubahan
                    </button>
                    @if ($contentBrief)
                        <button type="button" @click="editing = false" class="btn-secondary">
                            Batal
                        </button>
                    @endif
                </div>
            </form>
        </div>
    @endif
</div>

<script>
function briefForm(itemId, initialScenes, initialTalent, initialProperti) {
    return {
        scenes: initialScenes,
        talent: initialTalent,
        properti: initialProperti,
        assisting: { scenes: false, talent: false, properti: false },
        addScene() {
            this.scenes.push({ label: 'ADEGAN ' + (this.scenes.length + 1), visual: '', talent_script: '' });
        },
        removeScene(index) {
            this.scenes.splice(index, 1);
        },
        async assist(field) {
            if (this.assisting[field]) return;
            this.assisting[field] = true;
            try {
                const res = await fetch(`/content-brief/assist/${itemId}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ field }),
                });
                if (!res.ok) throw new Error('Gagal meminta bantuan AI');
                const data = await res.json();
                if (field === 'scenes') {
                    if (Array.isArray(data.value) && data.value.length) this.scenes = data.value;
                } else if (data.value) {
                    this[field] = data.value;
                }
            } catch (e) {
                // Gagal diam-diam - field tetap seperti semula, user bisa isi manual atau coba lagi.
            } finally {
                this.assisting[field] = false;
            }
        },
    };
}
</script>

<style>
    .ai-markdown p { margin-bottom: 0.6rem; }
    .ai-markdown p:last-child { margin-bottom: 0; }
    .ai-markdown strong { color: var(--brand); font-weight: 700; }
    .ai-markdown em { color: var(--text-secondary); font-style: normal; font-weight: 600; }
    .ai-markdown ul, .ai-markdown ol { padding-left: 1.1rem; margin-bottom: 0.6rem; }
    .ai-markdown ul { list-style: disc; }
    .ai-markdown ol { list-style: decimal; }
    .ai-markdown li { margin-bottom: 0.15rem; }
    .ai-markdown h1, .ai-markdown h2, .ai-markdown h3 {
        text-transform: uppercase;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        color: var(--brand);
        margin-top: 0.85rem;
        margin-bottom: 0.3rem;
    }
    .ai-markdown h1:first-child, .ai-markdown h2:first-child, .ai-markdown h3:first-child { margin-top: 0; }
</style>
