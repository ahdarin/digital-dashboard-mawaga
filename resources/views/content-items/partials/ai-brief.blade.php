@php
    $contentBrief = $contentItem->contentBriefDraft;
@endphp

@if (! $contentBrief)
    {{-- Belum ada brief - CTA menonjol supaya fitur AI ini kelihatan penting --}}
    <div class="card p-6 border-2 border-[#044b46]/20 bg-gradient-to-br from-[var(--brand-tint)] to-[var(--surface-card)]">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div class="flex items-start gap-3">
                <div class="w-11 h-11 rounded-xl bg-[var(--brand-solid)] text-white flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[22px]">auto_awesome</span>
                </div>
                <div>
                    <p class="text-[10px] font-semibold text-[var(--brand)] uppercase tracking-wide mb-1">AI Brief Execution Assistant</p>
                    <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-1">Brief produksi belum dibuat</h3>
                    <p class="text-xs text-[var(--text-secondary)] max-w-md">Ubah ide mentah di bawah jadi brief produksi siap pakai — lengkap dengan naskah, talent, properti, dan estimasi kompleksitas teknis buat tim produksi.</p>
                </div>
            </div>
            @if (auth()->user()->hasPermissionTo('content_plan', 'create'))
                <form action="{{ route('content-brief.generate', $contentItem) }}" method="POST">
                    @csrf
                    <button class="btn-primary whitespace-nowrap">
                        <span class="material-symbols-outlined text-[18px]">auto_awesome</span> Buat Brief dengan AI
                    </button>
                </form>
            @endif
        </div>
    </div>
@else
    @php
        $scenesForDisplay = $contentBrief->scenes_for_display;
        $isVideo = ($contentItem->contentType->name ?? '') === 'Video';
        $secondFieldLabel = $isVideo ? 'Script Talent' : 'Isi Design / Copy';
        $secondFieldIcon = $isVideo ? 'record_voice_over' : 'text_fields';
    @endphp
    <div class="card p-6 border border-[#044b46]/15" x-data="{ editing: false }">
        <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-[var(--brand)] text-[16px]">auto_awesome</span>
                <p class="text-[10px] font-semibold text-[var(--brand)] uppercase tracking-wide">AI Brief Execution Assistant</p>
                <span class="badge {{ $isVideo ? 'badge-info' : 'badge-warning' }} uppercase tracking-wide">
                    <span class="material-symbols-outlined text-[12px]">{{ $isVideo ? 'videocam' : 'palette' }}</span>
                    {{ $contentItem->contentType->name ?? '-' }}
                </span>
            </div>

            <div class="flex items-center gap-2 h-8">
                <span class="badge h-8
                    {{ $contentBrief->status === 'finalized' ? 'badge-success' : '' }}
                    {{ $contentBrief->status === 'discussing' ? 'badge-warning' : '' }}
                    {{ $contentBrief->status === 'draft' ? 'badge-neutral' : '' }}">
                    {{ match($contentBrief->status) { 'finalized' => 'Diterapkan', 'discussing' => 'Sedang Didiskusikan', default => 'Draft' } }}
                </span>

                @if (! $contentBrief->isLocked() && auth()->user()->hasPermissionTo('content_plan', 'create'))
                    <div class="flex items-center h-8 gap-2">
                        <button type="button" @click="editing = !editing"
                            class="inline-flex items-center h-8 gap-1.5 border border-[#044b46]/30 text-[var(--brand)] text-xs font-semibold px-3 rounded-lg hover:bg-[var(--brand-tint)] transition-colors leading-none">
                            <span class="material-symbols-outlined text-[15px]" x-text="editing ? 'close' : 'edit'"></span>
                            <span x-text="editing ? 'Cancel' : 'Edit'"></span>
                        </button>

                        <form action="{{ route('content-brief.regenerate', $contentBrief) }}" method="POST" class="inline-flex h-8 m-0"
                              onsubmit="return appConfirm(this, 'Susun ulang brief dari awal? Isi brief saat ini akan tertimpa (masih bisa di-revert 1x kalau berubah pikiran).')">
                            @csrf
                            <button class="inline-flex items-center h-8 gap-1.5 border border-[#044b46]/30 text-[var(--brand)] text-xs font-semibold px-3 rounded-lg hover:bg-[var(--brand-tint)] transition-colors leading-none">
                                <span class="material-symbols-outlined text-[15px]">refresh</span> Regenerate
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        </div>

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
                    <input type="text" name="footage_captured_at" data-flatpickr="datetime" autocomplete="off"
                        value="{{ ($contentItem->footage_captured_at ?? now())->format('Y-m-d H:i') }}" required
                        class="bg-[var(--surface-card)] flex-1 min-w-0 border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
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

        @if (! $contentBrief->isLocked() && auth()->user()->hasPermissionTo('content_plan', 'create'))
            <div x-show="editing" x-cloak x-transition class="mb-5 -mx-6 -mt-1 px-6 pt-5 pb-6 bg-[var(--surface-subtle)] border-y border-[var(--border)]">
                <form action="{{ route('content-brief.update', $contentBrief) }}" method="POST" class="space-y-5">
                    @csrf
                    @method('PATCH')

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label for="hook_title" class="block text-[10px] font-semibold text-[var(--text-muted)] uppercase mb-1.5">Hook / Judul Brief</label>
                            <input id="hook_title" type="text" name="hook_title" value="{{ $contentBrief->hook_title }}" required
                                class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                        </div>
                        <div>
                            <label for="talent" class="block text-[10px] font-semibold text-[var(--text-muted)] uppercase mb-1.5">Talent</label>
                            <input id="talent" type="text" name="talent" value="{{ $contentBrief->talent }}"
                                class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                        </div>
                        <div>
                            <label for="properti" class="block text-[10px] font-semibold text-[var(--text-muted)] uppercase mb-1.5">Properti</label>
                            <input id="properti" type="text" name="properti" value="{{ $contentBrief->properti }}"
                                class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                        </div>
                        <div>
                            <label for="platform" class="block text-[10px] font-semibold text-[var(--text-muted)] uppercase mb-1.5">Platform</label>
                            <input id="platform" type="text" name="platform" value="{{ $contentBrief->platform }}"
                                class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                        </div>
                    </div>

                    <div x-data="{
                        scenes: @js(count($scenesForDisplay) ? $scenesForDisplay : [['label' => null, 'visual' => '', 'talent_script' => '']])
                    }">
                        <div class="flex items-center justify-between mb-2.5">
                            <label class="block text-[10px] font-semibold text-[var(--text-muted)] uppercase">Adegan / Slide</label>
                            <button type="button" @click="scenes.push({ label: 'ADEGAN ' + (scenes.length + 1), visual: '', talent_script: '' })"
                                class="flex items-center gap-1 text-xs font-medium text-[var(--brand)] hover:underline">
                                <span class="material-symbols-outlined text-[14px]">add</span> Tambah Adegan/Slide
                            </button>
                        </div>

                        <div class="space-y-3">
                            <template x-for="(scene, index) in scenes" :key="index">
                                <div class="border border-[var(--border)] rounded-xl bg-[var(--surface-card)] overflow-hidden">
                                    <div class="flex items-center justify-between gap-2 bg-[var(--surface-page)] border-b border-[var(--border)] px-3.5 py-2">
                                        <input type="text" :name="'scenes[' + index + '][label]'" x-model="scene.label"
                                            placeholder="Contoh: ADEGAN 1 / SLIDE 1"
                                            class="flex-1 text-xs font-semibold text-[var(--text-primary)] bg-transparent border-0 focus:outline-none focus:ring-0 p-0 uppercase placeholder:text-[var(--text-muted)] placeholder:normal-case">
                                        <button type="button" @click="scenes.splice(index, 1)"
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

                    <div class="flex items-center gap-3 pt-1">
                        <button type="submit" class="btn-primary">
                            Simpan Perubahan
                        </button>
                        <button type="button" @click="editing = false" class="btn-secondary">
                            Batal
                        </button>
                    </div>
                </form>
            </div>
        @endif

        <div x-show="! editing">

        <h3 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-4">{{ $contentBrief->hook_title }}</h3>

        {{-- Jadwal & platform - ringkas, 3 kolom sejajar --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pb-4 mb-4 border-b border-[var(--surface-muted)]">
            <div>
                <p class="flex items-center gap-1 text-[10px] text-[var(--text-muted)] uppercase font-semibold mb-1">
                    <span class="material-symbols-outlined text-[13px]">event</span> Start
                </p>
                <p class="text-sm text-[var(--text-primary)] font-medium">{{ $contentBrief->start_date?->format('d M Y') ?? '-' }}</p>
            </div>
            <div>
                <p class="flex items-center gap-1 text-[10px] text-[var(--text-muted)] uppercase font-semibold mb-1">
                    <span class="material-symbols-outlined text-[13px]">event_available</span> Post
                </p>
                <p class="text-sm text-[var(--text-primary)] font-medium">{{ $contentBrief->post_date?->format('d M Y') ?? '-' }}</p>
            </div>
            <div>
                <p class="flex items-center gap-1 text-[10px] text-[var(--text-muted)] uppercase font-semibold mb-1">
                    <span class="material-symbols-outlined text-[13px]">share</span> Platform
                </p>
                <p class="text-sm text-[var(--text-primary)] font-medium">{{ $contentBrief->platform ?? '-' }}</p>
            </div>
        </div>

        {{-- Naskah/script - per adegan/slide, visual & {{ $secondFieldLabel }} disusun atas-bawah --}}
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
                <div class="ai-markdown text-sm text-[var(--text-secondary)] bg-[var(--surface-page)] border border-[var(--border)] rounded-lg p-4">
                    <p>-</p>
                </div>
            @endif
        </div>

        {{-- Kebutuhan produksi --}}
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

        {{-- Fitur teknis - output AI ini, input untuk AI Delay Risk PIC 2 --}}
        <div class="card p-5 bg-[var(--info-tint)] border-0 mb-5">
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

        </div>{{-- /x-show="! editing" --}}

        @if (! $contentBrief->isLocked() && auth()->user()->hasPermissionTo('content_plan', 'create'))
            <div class="flex items-start gap-2 mb-3 px-3.5 py-3 rounded-lg bg-[var(--surface-page)] border border-[var(--border)]">
                <span class="material-symbols-outlined text-[15px] text-[var(--text-muted)] mt-0.5">handshake</span>
                <p class="text-xs text-[var(--text-secondary)] leading-relaxed">
                    <strong class="text-[var(--text-primary)]">"Terapkan Brief ke Tim Produksi" adalah serah-terima resmi</strong> dari
                    Copywriter ke tim produksi — begitu diklik, brief jadi read-only (tidak bisa diedit lagi) dan
                    <strong class="text-[var(--text-primary)]">Penanggung Jawab produksi yang ditugaskan otomatis dapat notifikasi</strong>
                    kalau brief siap dikerjakan. Pastikan brief sudah final. Masih mau diskusi/edit dulu? Pakai tombol Edit/Regenerate/Diskusi di atas.
                </p>
            </div>

            <form action="{{ route('content-brief.finalize', $contentBrief) }}" method="POST" class="mb-3"
                  onsubmit="return appConfirm(this, 'Terapkan brief ini ke tim produksi? Brief akan terkunci (tidak bisa diedit) dan Penanggung Jawab produksi langsung dapat notifikasi. Ini adalah serah-terima resmi dari Copywriter ke tim produksi.')">
                @csrf
                <button class="btn-primary w-full">
                    <span class="material-symbols-outlined text-[18px]">handshake</span> Terapkan Brief ke Tim Produksi
                </button>
            </form>

            @if ($contentBrief->canRevert())
                <form action="{{ route('content-brief.revert', $contentBrief) }}" method="POST">
                    @csrf
                    <button class="w-full border border-[var(--warning-border)] text-[var(--warning-text)] text-sm font-medium py-2.5 rounded-lg hover:bg-[var(--warning-tint)] transition-colors flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]">undo</span> Kembalikan ke Versi Sebelumnya
                    </button>
                </form>
                <p class="text-[11px] text-[var(--text-muted)] text-center mt-1.5">Membatalkan perubahan terakhir (regenerate/edit/apply), balik ke isi sebelumnya.</p>
            @endif
        @elseif ($contentBrief->isLocked())
            <div class="flex items-start gap-2 mb-3 px-3.5 py-3 rounded-lg bg-[var(--brand-tint)] border border-[#0f7a5f]/15">
                <span class="material-symbols-outlined text-[15px] text-[var(--success-text)] mt-0.5">check_circle</span>
                <p class="text-xs text-[var(--success-text)] leading-relaxed">
                    Brief ini sudah <strong>diterapkan ke tim produksi</strong> dan terkunci (tidak bisa diedit) — Penanggung Jawab yang
                    ditugaskan sudah menerima notifikasi.
                    @if (auth()->user()->hasPermissionTo('content_plan', 'create'))
                        Kalau ternyata masih perlu revisi, klik <strong>"Tarik Kembali untuk Direvisi"</strong> di bawah untuk membuka brief lagi.
                    @endif
                </p>
            </div>

            @if (auth()->user()->hasPermissionTo('content_plan', 'create'))
                <form action="{{ route('content-brief.withdraw', $contentBrief) }}" method="POST">
                    @csrf
                    <button class="btn-secondary w-full">
                        Tarik Kembali untuk Direvisi
                    </button>
                </form>
            @endif
        @endif
    </div>

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
@endif