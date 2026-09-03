@extends('layouts.app')
@section('title', $contentItem->title)

@section('content')
    @php
        $workflow = $contentItem->workflow;
        $statusLabels = \App\Support\WorkflowTransitions::labels();

        // url()->previous() ikut Referer header - kalau halaman ini
        // di-reload (misal abis generate AI brief, ubah status, dsb lalu
        // redirect balik ke sini), Referer-nya jadi halaman ini sendiri,
        // bikin tombol back looping ke diri sendiri kalau cuma diambil apa
        // adanya. Supaya user konsisten balik ke halaman ASLI dia datang
        // (misal Rencana Bulanan client tertentu), bukan tiba-tiba nyasar
        // ke Produksi tiap habis submit form - URL awal yang BUKAN self-loop
        // disimpan ke session per content item, dipakai lagi tiap kali
        // Referer ternyata self-loop. Fallback ke Produksi cuma kalau
        // memang belum pernah ada URL awal yang tercatat sama sekali
        // (akses langsung/link luar).
        $backSessionKey = "content_item_back_url:{$contentItem->id}";
        $backUrl = url()->previous();
        $backPath = parse_url($backUrl, PHP_URL_PATH) ?? '';
        if (trim($backPath, '/') === trim(request()->path(), '/')) {
            $backUrl = session($backSessionKey, route('production-workflow.index'));
        } else {
            session([$backSessionKey => $backUrl]);
        }
    @endphp

    <div x-data="{ showReassignModal: false, confirmAction: null, confirmNotes: '', confirmLink: '' }" class="p-4 sm:p-6 lg:p-8 max-w-6xl mx-auto">

        <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
            <div class="flex items-center gap-3">
                <a href="{{ $backUrl }}" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                    <span class="material-symbols-outlined">arrow_back</span>
                </a>
                <div>
                    <p class="text-xs text-[var(--text-muted)]">{{ $contentItem->client->name ?? '-' }} / Item #{{ $contentItem->id }}
                    </p>
                    <div class="flex items-center gap-2">
                        <h1 class="font-display text-xl font-semibold text-[var(--text-primary)]">{{ $contentItem->title }}</h1>
                        @if ($contentItem->is_urgent)
                            <span class="flex items-center gap-1 text-[10px] font-semibold text-white bg-[var(--danger-solid)] px-2 py-0.5 rounded-full uppercase tracking-wide whitespace-nowrap">
                                <span class="material-symbols-outlined text-[12px]">bolt</span> Jobdesk Tambahan
                            </span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if ($workflow->client_reviewed_at && $workflow->current_status === 'waiting_review')
                    <span class="badge badge-success">
                        <span class="material-symbols-outlined text-[14px]">check_circle</span> Klien Setuju
                    </span>
                @endif
                <span class="badge badge-success">
                    {{ $statusLabels[$workflow->current_status] ?? $workflow->current_status }}
                </span>
            </div>
        </div>

        @if (session('status'))
            <div class="bg-[var(--brand-tint)] text-[var(--brand)] text-sm p-3.5 rounded-lg mb-6">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="bg-[var(--danger-tint)] text-[var(--danger-text)] text-sm p-3.5 rounded-lg mb-6 flex items-center gap-2">
                <span class="material-symbols-outlined text-[17px]">error</span> {{ session('error') }}
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

            <div class="lg:col-span-2 space-y-5">

                @php
                    $hasBasicInfo = $contentItem->title !== $contentItem->provisional_code;
                    $selectedPlatformIds = $contentItem->platforms->pluck('id')->all() ?: array_filter([$contentItem->platform_id]);
                @endphp

                @unless ($workflow->current_status === 'draft')
                    <div class="card p-5">
                        <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-3">Brief Awal</h3>
                        <div class="flex items-start justify-between gap-3 mb-4">
                            <p class="text-sm text-[var(--text-secondary)] whitespace-pre-line">
                                {{ $contentItem->brief ?: 'Belum ada brief.' }}</p>
                            @if ($contentItem->reference_link)
                                <a href="{{ $contentItem->reference_link }}" target="_blank"
                                    class="shrink-0 inline-flex items-center gap-1 text-[11px] font-medium text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] px-2.5 py-1.5 rounded-lg whitespace-nowrap">
                                    <span class="material-symbols-outlined text-[13px]">open_in_new</span> Lihat Referensi
                                </a>
                            @endif
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <div>
                                <p class="text-[var(--text-muted)] uppercase font-medium mb-1">Platform</p>
                                <p class="text-[var(--text-secondary)]">{{ $contentItem->platforms->pluck('name')->implode(', ') ?: ($contentItem->platform->name ?? '-') }}</p>
                            </div>
                            <div>
                                <p class="text-[var(--text-muted)] uppercase font-medium mb-1">Deadline</p>
                                <p class="text-[var(--text-secondary)]">{{ $contentItem->deadline_at->format('d M Y, H:i') }}</p>
                            </div>
                            <div>
                                <p class="text-[var(--text-muted)] uppercase font-medium mb-1">Jenis Produksi</p>
                                <p class="text-[var(--text-secondary)]">{{ $contentItem->contentType->name ?? '-' }}</p>
                            </div>
                            {{-- SYSTEM CONSISTENCY PASS (Part C/F) - master
                                 contentFormat (relasi baru) diprioritaskan;
                                 fallback ke content_format string lama HANYA
                                 kalau master belum diisi (item lama/import
                                 Excel) - tidak pernah ditebak/ditimpa. --}}
                            @if ($contentItem->contentFormat || $contentItem->content_format)
                                <div>
                                    <p class="text-[var(--text-muted)] uppercase font-medium mb-1">Format Konten</p>
                                    <p class="text-[var(--text-secondary)]">{{ $contentItem->contentFormat->name ?? $contentItem->content_format }}</p>
                                </div>
                            @endif
                        </div>
                    </div>
                @endunless

                @if ($workflow->current_status === 'draft')
                    <div class="card p-5" x-data="{ editingInfo: {{ $hasBasicInfo ? 'false' : 'true' }} }">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-sm font-semibold text-[var(--text-primary)]">Info Dasar</h3>
                            @if ($hasBasicInfo)
                                <button type="button" @click="editingInfo = !editingInfo" class="text-xs font-medium text-[var(--brand)] hover:underline">
                                    <span x-text="editingInfo ? 'Batal' : 'Edit'"></span>
                                </button>
                            @endif
                        </div>

                        {{-- VIEW MODE - begitu tersimpan, tampil seperti Brief Awal
                             (baca-saja + tombol Edit), bukan tetap berbentuk form
                             supaya tidak membingungkan apakah sudah disimpan. --}}
                        <div x-show="!editingInfo" x-cloak class="space-y-3">
                            <div>
                                <div class="flex items-center justify-between gap-3 mb-0.5">
                                    <p class="text-[10px] font-medium text-[var(--text-muted)] uppercase">Brief Singkat</p>
                                    @if ($contentItem->reference_link)
                                        <a href="{{ $contentItem->reference_link }}" target="_blank"
                                            class="shrink-0 inline-flex items-center gap-1 text-[11px] font-medium text-[var(--brand)] hover:underline">
                                            <span class="material-symbols-outlined text-[13px]">open_in_new</span> Lihat Referensi
                                        </a>
                                    @endif
                                </div>
                                <p class="text-sm text-[var(--text-secondary)] whitespace-pre-line">{{ $contentItem->brief ?: '-' }}</p>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs pt-2 border-t border-[var(--surface-muted)]">
                                <div>
                                    <p class="text-[var(--text-muted)] uppercase font-medium mb-1">Pilar</p>
                                    <p class="text-[var(--text-secondary)]">{{ $contentItem->contentPillar->name ?? '-' }}</p>
                                </div>
                                <div>
                                    <p class="text-[var(--text-muted)] uppercase font-medium mb-1">Platform</p>
                                    <p class="text-[var(--text-secondary)]">{{ $contentItem->platforms->pluck('name')->implode(', ') ?: '-' }}</p>
                                </div>
                                <div>
                                    <p class="text-[var(--text-muted)] uppercase font-medium mb-1">PIC</p>
                                    <p class="text-[var(--text-secondary)]">{{ $workflow->currentPic->name ?? $contentItem->external_pic_name ?? '-' }}</p>
                                </div>
                            </div>
                        </div>

                        {{-- FORM MODE --}}
                        <div x-show="editingInfo" x-cloak>
                            <p class="text-xs text-[var(--text-muted)] mb-4">Lengkapi dulu sebelum bisa mengisi Brief Produksi di bawah.</p>
                            <form action="{{ route('content-items.update-info', $contentItem) }}" method="POST" class="space-y-3">
                                @csrf @method('PATCH')
                                <div>
                                    <label for="info-title" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Judul</label>
                                    <input id="info-title" type="text" name="title" required value="{{ old('title', $hasBasicInfo ? $contentItem->title : '') }}"
                                        placeholder="Judul konten..."
                                        class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
                                </div>
                                <div>
                                    <label for="info-brief" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Brief Singkat</label>
                                    <textarea id="info-brief" name="brief" rows="3" placeholder="Gambaran singkat konten ini..."
                                        class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">{{ old('brief', $contentItem->brief) }}</textarea>
                                </div>
                                <div>
                                    <label for="info-reference" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Referensi <span class="normal-case text-[var(--text-muted)]">(opsional)</span></label>
                                    <input id="info-reference" type="url" name="reference_link" value="{{ old('reference_link', $contentItem->reference_link) }}"
                                        placeholder="Link konten orang lain sebagai referensi/inspirasi..."
                                        class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label for="info-pillar" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Pilar</label>
                                        <select id="info-pillar" name="content_pillar_id" class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                            <option value="">Pilih pilar...</option>
                                            @foreach ($pillarOptions as $pillar)
                                                <option value="{{ $pillar->id }}" @selected($contentItem->content_pillar_id === $pillar->id)>{{ $pillar->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label for="info-pic" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">PIC</label>
                                        <select id="info-pic" name="pic_user_id" class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                            <option value="">Pilih PIC...</option>
                                            @foreach ($reassignCandidates as $candidate)
                                                <option value="{{ $candidate->id }}" @selected($workflow->current_pic_id === $candidate->id)>{{ $candidate->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div x-data="{
                                    selected: @js($selectedPlatformIds),
                                    options: @js($platformOptions->pluck('id')),
                                }">
                                    <label class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Platform</label>
                                    <div class="border border-[var(--border)] rounded-lg p-2.5 space-y-1.5">
                                        <label class="flex items-center gap-2 text-xs font-medium pb-1.5 mb-1 border-b border-[var(--surface-muted)] cursor-pointer">
                                            <input type="checkbox" :checked="selected.length === options.length"
                                                @change="selected = $event.target.checked ? [...options] : []"
                                                class="rounded border-[var(--border-strong)] text-[var(--brand)] focus:ring-[var(--brand)]">
                                            Pilih Semua
                                        </label>
                                        @foreach ($platformOptions as $platform)
                                            <label class="flex items-center gap-2 text-xs cursor-pointer">
                                                <input type="checkbox" name="platform_ids[]" value="{{ $platform->id }}" x-model="selected"
                                                    class="rounded border-[var(--border-strong)] text-[var(--brand)] focus:ring-[var(--brand)]">
                                                {{ $platform->name }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="submit" class="btn-primary">
                                        <span class="material-symbols-outlined text-[16px]">save</span> Simpan Info Dasar
                                    </button>
                                    @if ($hasBasicInfo)
                                        <button type="button" @click="editingInfo = false" class="btn-secondary">Batal</button>
                                    @endif
                                </div>
                            </form>
                        </div>
                    </div>
                @endif

                @if ($workflow->current_status !== 'draft' || $hasBasicInfo)
                    @include('content-items.partials.ai-brief', ['contentItem' => $contentItem])
                @endif

                @if ($workflow->current_status !== 'draft' && auth()->user()->hasPermissionTo('content_plan', 'create'))
                    <div class="card p-5" x-data="{ editingCaption: {{ $contentItem->caption_draft ? 'false' : 'true' }} }">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-sm font-semibold text-[var(--text-primary)]">Caption / Copy</h3>
                            @if ($contentItem->caption_draft)
                                <button type="button" @click="editingCaption = !editingCaption" class="text-xs font-medium text-[var(--brand)] hover:underline">
                                    <span x-text="editingCaption ? 'Batal' : 'Ubah'"></span>
                                </button>
                            @endif
                        </div>
                        <p class="text-xs text-[var(--text-muted)] mb-3">Draft caption yang akan dibaca & disetujui klien di Portal Klien.</p>

                        <div x-show="! editingCaption" x-cloak>
                            <p class="text-sm text-[var(--text-primary)] whitespace-pre-line">{{ $contentItem->caption_draft }}</p>
                        </div>

                        <form x-show="editingCaption" x-cloak action="{{ route('content-items.caption', $contentItem) }}" method="POST" class="space-y-2">
                            @csrf @method('PATCH')
                            <textarea name="caption_draft" rows="3" placeholder="Tulis draft caption di sini..."
                                class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">{{ $contentItem->caption_draft }}</textarea>
                            <button type="submit" class="btn-primary">
                                Simpan Caption
                            </button>
                        </form>
                    </div>
                @endif

                @unless (in_array($workflow->current_status, ['draft', 'brief_ready']))
                    <div class="card p-5" x-data="{ editingLink: {{ $contentItem->content_file_link ? 'false' : 'true' }} }">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-sm font-semibold text-[var(--text-primary)]">Link Konten (Draft)</h3>
                            @if ($contentItem->content_file_link)
                                <button type="button" @click="editingLink = !editingLink" class="text-xs font-medium text-[var(--brand)] hover:underline">
                                    <span x-text="editingLink ? 'Batal' : 'Ubah'"></span>
                                </button>
                            @endif
                        </div>
                        <p class="text-xs text-[var(--text-muted)] mb-3">Link file hasil produksi (Google Drive/Canva/dsb) - diisi setelah konten selesai diedit, supaya bisa direview sebelum upload. Beda dengan Link Post di Record Publication (itu link postingan yang sudah live).</p>

                        <div x-show="! editingLink" x-cloak class="flex items-center gap-2">
                            <a href="{{ $contentItem->content_file_link }}" target="_blank"
                                class="flex-1 text-sm text-[var(--brand)] underline break-all">{{ $contentItem->content_file_link }}</a>
                        </div>

                        <form x-show="editingLink" x-cloak action="{{ route('content-items.content-link', $contentItem) }}" method="POST" class="flex items-center gap-2">
                            @csrf @method('PATCH')
                            <input type="url" name="content_file_link" value="{{ $contentItem->content_file_link }}"
                                placeholder="https://drive.google.com/..."
                                class="bg-[var(--surface-card)] flex-1 border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
                            <button type="submit" class="btn-primary whitespace-nowrap">
                                Simpan
                            </button>
                        </form>
                    </div>
                @endunless

                @unless ($workflow->current_status === 'draft')
                <div class="card p-5">
                    <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-4">Catatan Revisi
                        ({{ $contentItem->revisions->count() }})</h3>

                    <div class="space-y-2.5 mb-4">
                        @forelse ($contentItem->revisions as $revision)
                            @php
                                $revisionStyles = [
                                    'open' => ['card' => 'bg-[var(--warning-tint)]', 'badge' => 'bg-[var(--warning-tint-soft-2)] text-[var(--warning-text)]', 'label' => 'Terbuka'],
                                    'in_progress' => ['card' => 'bg-[var(--info-tint)]', 'badge' => 'bg-[var(--info-border)] text-[var(--info-text)]', 'label' => 'Sedang Dikerjakan'],
                                    'resolved' => ['card' => 'bg-[var(--surface-page)]', 'badge' => 'bg-[var(--success-tint)] text-[var(--success-text)]', 'label' => 'Selesai'],
                                ];
                                $revisionStyle = $revisionStyles[$revision->status] ?? $revisionStyles['resolved'];
                            @endphp
                            <div class="border border-[var(--border)] rounded-lg p-3 {{ $revisionStyle['card'] }}">
                                <div class="flex items-center justify-between mb-1">
                                    <p class="text-xs font-medium text-[var(--text-primary)]">Revisi #{{ $revision->revision_round }} -
                                        {{ $revision->requestedByLabel() }}</p>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full {{ $revisionStyle['badge'] }}">{{ $revisionStyle['label'] }}</span>
                                </div>
                                <p class="text-xs text-[var(--text-secondary)]">{{ $revision->revision_note }}</p>

                                @if ($revision->status === 'open')
                                    <button type="button" :disabled="{{ $canUpdateWorkflow ? 'false' : 'true' }}"
                                        @click="confirmAction = {
                                            title: 'Kerjakan Revisi',
                                            message: 'Mulai kerjakan revisi ini? Status konten akan berpindah ke Sedang Dikerjakan, dan semua revisi yang masih terbuka ikut mulai dikerjakan bareng.',
                                            formAction: '{{ route('content-revision.start-work', [$contentItem, $revision]) }}',
                                            method: 'PATCH',
                                            confirmLabel: 'Ya, Kerjakan',
                                        }"
                                        title="{{ $canUpdateWorkflow ? '' : 'Kamu tidak punya izin memindahkan status' }}"
                                        class="mt-2 inline-flex items-center gap-1 text-[11px] font-medium px-3 py-1.5 rounded-lg transition-colors
                                            {{ $canUpdateWorkflow ? 'bg-[var(--brand-solid)] text-white hover:bg-[var(--brand-dark)]' : 'bg-[var(--surface-muted)] text-[var(--text-muted)] cursor-not-allowed' }}">
                                        <span class="material-symbols-outlined text-[13px]">build</span> Kerjakan Revisi
                                    </button>
                                @elseif ($revision->status === 'in_progress')
                                    <button type="button" disabled
                                        class="mt-2 inline-flex items-center gap-1.5 bg-[var(--info-border)] text-[var(--info-text)] text-[11px] font-medium px-3 py-1.5 rounded-lg cursor-not-allowed">
                                        <span class="inline-block w-3 h-3 border-2 border-[#3452a8]/30 border-t-[var(--info-text)] rounded-full animate-spin"></span>
                                        Sedang Revisi
                                    </button>
                                @endif
                            </div>
                        @empty
                            <p class="text-xs text-[var(--text-muted)] italic">Belum ada revisi.</p>
                        @endforelse
                    </div>

                    @if (in_array($workflow->current_status, ['waiting_review', 'revision']))
                        <form action="{{ route('content-revision.store', $contentItem) }}" method="POST" class="flex gap-2">
                            @csrf
                            <input type="text" name="revision_note" required placeholder="Tulis catatan revisi..."
                                class="bg-[var(--surface-card)] flex-1 border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                            <button type="submit"
                                class="btn-primary">Kirim</button>
                        </form>
                    @else
                        <p class="text-[11px] text-[var(--text-muted)] italic">Catatan revisi cuma bisa ditambahkan saat status Menunggu Persetujuan atau Perlu Revisi.</p>
                    @endif
                </div>
                @endunless

                @if ($contentItem->is_posted)
                    <div class="card p-5">
                        <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-3">Publication</h3>
                        @foreach ($contentItem->publications as $pub)
                            <div class="text-xs text-[var(--text-secondary)] border-b border-[var(--surface-muted)] py-2 last:border-0">
                                <p class="font-medium text-[var(--text-primary)]">{{ $pub->platform->name }} —
                                    {{ $pub->published_at->format('d M Y, H:i') }}</p>
                                @if ($pub->post_url)
                                    <a href="{{ $pub->post_url }}" target="_blank"
                                        class="text-[var(--brand)] underline">{{ $pub->post_url }}</a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @elseif ($workflow->current_status === 'scheduled')
                    @php
                        $canPublish = auth()->user()->hasPermissionTo('publishing', 'manage');
                        $publishPlatforms = $contentItem->platforms->isNotEmpty()
                            ? $contentItem->platforms
                            : ($contentItem->platform ? collect([$contentItem->platform]) : collect());
                    @endphp
                    <div id="record-publication" class="card p-5 scroll-mt-6">
                        <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-1">Record Publication</h3>
                        @if ($publishPlatforms->count() > 1)
                            <p class="text-xs text-[var(--text-muted)] mb-4">Konten ini dipublikasikan ke {{ $publishPlatforms->count() }} platform - isi data tiap platform di bawah, semua disimpan sekaligus.</p>
                        @endif
                        @unless ($canPublish)
                            <div class="flex items-start gap-2 bg-[var(--warning-tint)] text-[var(--warning-text)] text-xs p-3 rounded-lg mb-3.5 mt-3">
                                <span class="material-symbols-outlined text-[16px] shrink-0">info</span>
                                <span>Hanya SMO yang bisa mencatat data publikasi. Hubungi SMO yang bertanggung jawab untuk client ini.</span>
                            </div>
                        @endunless
                        @if ($publishPlatforms->isEmpty())
                            <p class="text-xs text-[var(--danger-text)] mt-3">Konten ini belum punya platform - lengkapi dulu lewat Info Dasar sebelum bisa mencatat publikasi.</p>
                        @else
                            <form action="{{ route('content-publication.store', $contentItem) }}" method="POST" class="space-y-4 mt-3">
                                @csrf
                                @foreach ($publishPlatforms as $pf)
                                    <div class="{{ $publishPlatforms->count() > 1 ? 'border border-[var(--border)] rounded-lg p-3 space-y-3' : 'space-y-3' }}">
                                        @if ($publishPlatforms->count() > 1)
                                            <p class="text-xs font-semibold text-[var(--text-primary)]">{{ $pf->name }}</p>
                                        @endif
                                        <input type="hidden" name="publications[{{ $loop->index }}][platform_id]" value="{{ $pf->id }}">
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Platform</label>
                                                <div class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs bg-[var(--surface-page)] text-[var(--text-secondary)]">
                                                    {{ $pf->name }}
                                                </div>
                                            </div>
                                            <div>
                                                <label for="published_at-{{ $pf->id }}" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Tanggal
                                                    Publish</label>
                                                <div class="relative">
                                                    <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[15px] pointer-events-none">calendar_month</span>
                                                    <input id="published_at-{{ $pf->id }}" type="text" name="publications[{{ $loop->index }}][published_at]" required data-flatpickr="datetime" autocomplete="off"
                                                        class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg pl-8 pr-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <label for="post_url-{{ $pf->id }}" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Link Post</label>
                                            <input id="post_url-{{ $pf->id }}" type="url" name="publications[{{ $loop->index }}][post_url]" placeholder="https://..."
                                                class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                                        </div>
                                        <div>
                                            <label for="caption_final-{{ $pf->id }}" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Caption Final</label>
                                            <textarea id="caption_final-{{ $pf->id }}" name="publications[{{ $loop->index }}][caption_final]" rows="2"
                                                class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40"></textarea>
                                        </div>
                                    </div>
                                @endforeach
                                <button type="submit" @disabled(! $canPublish)
                                    title="{{ $canPublish ? '' : 'Hanya SMO yang bisa mencatat data publikasi' }}"
                                    class="btn-primary">
                                    Simpan &amp; Tandai Uploaded</button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            <div class="space-y-5">
                @unless ($contentItem->contentBriefDraft?->isLocked())
                    @include('content-items.partials.ai-brief-discussion', ['contentItem' => $contentItem])
                @endunless

                @include('content-items.partials.client-assets', ['contentItem' => $contentItem])

                @unless ($workflow->current_status === 'draft')
                <div class="card p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-[var(--text-primary)]">Penanggung Jawab</h3>
                        @if ($canUpdateWorkflow)
                            <button type="button" @click="showReassignModal = true"
                                    class="inline-flex items-center gap-1 bg-[var(--brand-tint)] text-[var(--brand)] text-xs font-medium px-3 py-1.5 rounded-lg hover:bg-[var(--brand-tint-hover)] transition-colors">
                                <span class="material-symbols-outlined text-[14px]">sync_alt</span> Reassign
                            </button>
                        @endif
                    </div>
                    <div class="space-y-3">
                        @forelse ($contentItem->assignments as $assignment)
                            <div class="flex items-center gap-2">
                                @if ($assignment->user->avatar_url)
                                    <img src="{{ $assignment->user->avatar_url }}" alt="" class="w-8 h-8 rounded-full object-cover">
                                @else
                                    <div
                                        class="w-8 h-8 rounded-full bg-[var(--brand-solid)] text-white text-xs font-semibold flex items-center justify-center">
                                        {{ strtoupper(substr($assignment->user->name, 0, 1)) }}</div>
                                @endif
                                <div>
                                    <p class="text-xs font-medium text-[var(--text-primary)]">{{ $assignment->user->name }}</p>
                                    <p class="text-[10px] text-[var(--text-muted)]">
                                        {{ ucwords(str_replace('_', ' ', $assignment->assignment_role)) }}</p>
                                </div>
                            </div>
                        @empty
                            @php $detailPic = $picResolver->resolve($contentItem); @endphp
                            @if ($detailPic['name'])
                                <div class="flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-full bg-[var(--surface-muted)] text-[var(--text-secondary)] text-xs font-semibold flex items-center justify-center">
                                        {{ strtoupper(substr($detailPic['name'], 0, 1)) }}
                                    </div>
                                    <div>
                                        <p class="text-xs font-medium text-[var(--text-primary)]">{{ $detailPic['name'] }}</p>
                                        @if ($detailPic['email'])
                                            <p class="text-[10px] text-[var(--text-muted)]">{{ $detailPic['email'] }}</p>
                                        @endif
                                        <p class="text-[10px] text-[var(--text-muted)] italic">PIC operasional - belum memiliki akun</p>
                                    </div>
                                </div>
                            @else
                                <p class="text-xs text-[var(--text-muted)] italic">Belum ada Penanggung Jawab.</p>
                            @endif
                        @endforelse
                    </div>
                </div>
                @endunless

                @unless (in_array($workflow->current_status, ['draft', 'uploaded', 'cancelled']))
                    @include('content-items.partials.status-management', [
                        'contentItem' => $contentItem,
                        'workflow' => $workflow,
                        'canUpdateWorkflow' => $canUpdateWorkflow,
                        'canApprove' => $canApprove,
                    ])
                @endunless

                    @if ($contentItem->delayRiskScores->isNotEmpty())
                        @php
                            $latestRisk = $contentItem->delayRiskScores->first();
                            $riskColors = [
                                'high' => ['bg' => '#fdf2f1', 'text' => '#b3423e', 'label' => 'Risiko Tinggi'],
                                'medium' => ['bg' => '#fdf6ec', 'text' => '#8a6423', 'label' => 'Risiko Sedang'],
                                'low' => ['bg' => '#f0f5f4', 'text' => '#0f7a5f', 'label' => 'Risiko Rendah'],
                            ];
                            $riskColor = $riskColors[$latestRisk->risk_level] ?? $riskColors['low'];
                        @endphp
                        <div class="card p-5">
                            <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-3">AI Delay Risk</h3>

                            <div class="rounded-lg p-3 mb-3" style="background-color: {{ $riskColor['bg'] }};">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-semibold" style="color: {{ $riskColor['text'] }};">
                                        {{ $riskColor['label'] }}
                                    </span>
                                    <span class="text-2xl font-bold" style="color: {{ $riskColor['text'] }};">
                                        {{ $latestRisk->risk_score }}%
                                    </span>
                                </div>
                                @if ($latestRisk->top_factor)
                                    <p class="text-xs" style="color: {{ $riskColor['text'] }};">
                                        Faktor utama: {{ $latestRisk->top_factor }}
                                    </p>
                                @endif
                            </div>

                            @if ($latestRisk->top_factor && str_contains($latestRisk->top_factor, 'Beban kerja'))
                                <div class="bg-[var(--warning-tint)] rounded-lg p-3 mb-3 flex items-start gap-2.5">
                                    <span class="material-symbols-outlined text-[var(--warning-text)] text-[16px] mt-0.5">group</span>
                                    <div class="flex-1">
                                        <p class="text-xs text-[var(--warning-text)] mb-2">Penanggung Jawab saat ini sedang menangani banyak task aktif. Pertimbangkan reassign ke yang lebih longgar.</p>
                                        @if ($canUpdateWorkflow)
                                            <button type="button" @click="showReassignModal = true"
                                                    class="inline-flex items-center gap-1 bg-[var(--warning-solid)] text-white text-xs font-medium px-3 py-1.5 rounded-lg hover:bg-[var(--warning-dark)] transition-colors">
                                                <span class="material-symbols-outlined text-[14px]">sync_alt</span> Ganti Penanggung Jawab
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <p class="text-[10px] text-[var(--text-muted)]">
                                Dihitung {{ $latestRisk->created_at->diffForHumans() }}
                            </p>

                            @if ($contentItem->delayRiskScores->count() > 1)
                                @php
                                    $riskTrend = $contentItem->delayRiskScores->reverse()->values()->map(fn ($s) => [
                                        'label' => $s->created_at->format('d/m'),
                                        'value' => $s->risk_score,
                                    ]);
                                @endphp
                                <div class="mt-4 pt-4 border-t border-[var(--border)]">
                                    <p class="text-[10px] font-semibold text-[var(--text-muted)] uppercase mb-2">Tren Skor Risiko</p>
                                    <x-trend-chart :trend="$riskTrend" :show-total="false" />
                                </div>
                            @endif

                            <p class="text-[10px] text-[var(--text-muted)] mt-2 italic">
                                Skor ini estimasi otomatis (bukan keputusan final), gunakan sebagai sinyal awal untuk prioritas.
                            </p>
                        </div>
                    @endif

                    @unless ($workflow->current_status === 'draft')
                    <div class="card p-5">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-[var(--text-primary)]">Riwayat Status</h3>
                            <a href="{{ route('production-workflow.index') }}"
                                class="flex items-center gap-1 text-xs font-medium text-[var(--brand)] hover:underline whitespace-nowrap">
                                <span class="material-symbols-outlined text-[14px]">view_kanban</span> Lihat di Workflow
                            </a>
                        </div>
                        <div class="space-y-3">
                            @forelse ($contentItem->statusLogs->sortByDesc('changed_at') as $log)
                                <div class="flex gap-3">
                                    <div class="w-1.5 h-1.5 rounded-full bg-[var(--brand)] mt-1.5 flex-shrink-0"></div>
                                    <div>
                                        <p class="text-xs text-[var(--text-secondary)]">
                                            <span
                                                class="font-medium text-[var(--text-primary)]">{{ $log->from_status ? ($statusLabels[$log->from_status] ?? $log->from_status) : 'Dibuat' }}</span>
                                            →
                                            <span
                                                class="font-medium text-[var(--text-primary)]">{{ $statusLabels[$log->to_status] ?? $log->to_status }}</span>
                                        </p>
                                        <p class="text-[10px] text-[var(--text-muted)]">{{ $log->changedByLabel() }} ·
                                            {{ $log->changed_at->format('d M, H:i') }}</p>
                                        @if ($log->notes)
                                            <p class="text-[10px] text-[var(--text-muted)] italic mt-0.5">{{ $log->notes }}</p>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="text-xs text-[var(--text-muted)] italic">Belum ada histori.</p>
                            @endforelse
                        </div>
                    </div>
                    @endunless
                </div>
            </div>

        {{-- Modal Ganti Penanggung Jawab --}}
        <div x-show="showReassignModal" x-cloak
             x-on:keydown.escape.window="showReassignModal = false"
             class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
            <div class="absolute inset-0 bg-[#14181a]/40" @click="showReassignModal = false"></div>

            <div x-show="showReassignModal" x-transition
                 role="dialog" aria-modal="true" aria-labelledby="reassign-pic-modal-title" x-trap="showReassignModal"
                 class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                    <div>
                        <h3 id="reassign-pic-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)]">Ganti Penanggung Jawab</h3>
                        <p class="text-xs text-[var(--text-muted)] mt-0.5">{{ $contentItem->title }}</p>
                    </div>
                    <button type="button" @click="showReassignModal = false" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                        <span class="material-symbols-outlined text-[19px]">close</span>
                    </button>
                </div>

                <form action="{{ route('content-items.reassign', $contentItem) }}" method="POST">
                    @csrf @method('PATCH')
                    <div class="px-6 py-5">
                        <p class="text-xs font-semibold text-[var(--text-muted)] uppercase mb-3">Pilih Penanggung Jawab Baru</p>
                        <p class="text-[11px] text-[var(--text-muted)] mb-3">Diurutkan dari yang task aktifnya paling sedikit.</p>

                        <div class="space-y-2 max-h-72 overflow-y-auto">
                            @forelse ($reassignCandidates as $candidate)
                                @php $candidateActiveCount = $candidate->active_task_count ?? 0; @endphp
                                <label class="flex items-center justify-between gap-3 p-3 border border-[var(--border)] rounded-lg hover:bg-[var(--surface-page)] cursor-pointer">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <input type="radio" name="pic_user_id" value="{{ $candidate->id }}"
                                               class="border-[var(--border-strong)] text-[var(--brand)] focus:ring-[var(--brand)]">
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-[var(--text-primary)] truncate">{{ $candidate->name }}</p>
                                            <p class="text-xs text-[var(--text-muted)]">{{ $candidate->roleNamesLabel() }}{{ $candidate->login_enabled ? '' : ' (belum memiliki akses dashboard)' }}</p>
                                        </div>
                                    </div>
                                    <span class="badge {{ $candidateActiveCount > 8 ? 'badge-danger' : 'badge-success' }} shrink-0">
                                        {{ $candidateActiveCount }} task aktif
                                    </span>
                                </label>
                            @empty
                                <p class="text-sm text-[var(--text-muted)]">Belum ada anggota tim tercatat untuk client ini.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                        <button type="submit" class="btn-primary">
                            Simpan
                        </button>
                        <button type="button" @click="showReassignModal = false" class="btn-secondary">
                            Batal
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Modal Konfirmasi generik - dipakai semua tombol Status Management
             (Kerjakan Konten, Konten Telah Selesai, Approve Konten, Jadwalkan
             Upload, Batalkan Konten) dan tombol Kerjakan Revisi, biar nggak
             ada 6 blok modal yang isinya nyaris sama persis berulang-ulang. --}}
        <div x-show="confirmAction" x-cloak
             x-on:keydown.escape.window="confirmAction = null"
             class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
            <div class="absolute inset-0 bg-[#14181a]/40" @click="confirmAction = null"></div>

            <div x-show="confirmAction" x-transition
                 role="dialog" aria-modal="true" aria-labelledby="confirm-action-modal-title" x-trap="!!confirmAction"
                 class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                    <div>
                        <h3 id="confirm-action-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)]" x-text="confirmAction?.title"></h3>
                        <p class="text-xs text-[var(--text-muted)] mt-0.5">{{ $contentItem->title }}</p>
                    </div>
                    <button type="button" @click="confirmAction = null" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                        <span class="material-symbols-outlined text-[19px]">close</span>
                    </button>
                </div>

                <div class="px-6 py-5">
                    <p class="text-sm text-[var(--text-secondary)]" x-text="confirmAction?.message"></p>

                    <template x-if="confirmAction?.extra">
                        <p class="mt-3 bg-[var(--surface-page)] rounded-lg p-3 text-sm font-medium text-[var(--text-primary)]" x-text="confirmAction?.extra"></p>
                    </template>

                    <template x-if="confirmAction?.withNotes">
                        <div class="mt-3">
                            <label for="confirm-action-notes" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5" x-text="confirmAction?.notesLabel"></label>
                            <textarea id="confirm-action-notes" x-model="confirmNotes" rows="2" placeholder="Tulis alasan..."
                                class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40"></textarea>
                        </div>
                    </template>

                    <template x-if="confirmAction?.withLink">
                        <div class="mt-3">
                            <label for="confirm-action-link" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5" x-text="confirmAction?.linkLabel"></label>
                            <input id="confirm-action-link" type="url" x-model="confirmLink" required placeholder="https://drive.google.com/..."
                                class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                            <p class="text-[10px] text-[var(--text-muted)] mt-1">Link file hasil produksi (Google Drive/Canva/dsb) - wajib diisi supaya reviewer bisa cek hasilnya.</p>
                        </div>
                    </template>
                </div>

                {{-- Sengaja TIDAK ada @submit="confirmAction = null" - :action & hidden
                     input di form ini reaktif ke confirmAction, jadi kalau di-null-kan
                     di handler submit, race condition: browser baca action yang udah
                     kosong duluan sebelum benar-benar navigasi. Modal ketutup sendiri
                     karena halaman langsung redirect/reload setelah submit selesai. --}}
                <form :action="confirmAction?.formAction" method="POST">
                    @csrf
                    <template x-if="confirmAction?.method && confirmAction.method !== 'POST'">
                        <input type="hidden" name="_method" :value="confirmAction.method">
                    </template>
                    <template x-for="(value, key) in (confirmAction?.fields || {})" :key="key">
                        <input type="hidden" :name="key" :value="value">
                    </template>
                    <template x-if="confirmAction?.withNotes">
                        <input type="hidden" name="notes" :value="confirmNotes">
                    </template>
                    <template x-if="confirmAction?.withLink">
                        <input type="hidden" name="content_file_link" :value="confirmLink">
                    </template>

                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                        <button type="submit"
                            :disabled="confirmAction?.withLink && !confirmLink.trim()"
                            :class="confirmAction?.danger ? 'btn-danger' : 'btn-primary'"
                            x-text="confirmAction?.confirmLabel || 'Ya, Lanjutkan'"></button>
                        <button type="button" @click="confirmAction = null" class="btn-secondary">
                            Batal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
