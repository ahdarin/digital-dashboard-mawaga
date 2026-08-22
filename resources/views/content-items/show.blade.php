@extends('layouts.app')
@section('title', $contentItem->title)

@section('content')
    @php
        $workflow = $contentItem->workflow;
        $statusLabels = \App\Support\WorkflowTransitions::labels();

        // url()->previous() ikut Referer header - kalau halaman ini
        // di-reload (misal abis generate AI brief lalu redirect balik ke
        // sini), Referer-nya jadi halaman ini sendiri, bikin tombol back
        // looping ke diri sendiri. Fallback ke Produksi kalau ketauan
        // previous = halaman sekarang.
        $backUrl = url()->previous();
        $backPath = parse_url($backUrl, PHP_URL_PATH) ?? '';
        if (trim($backPath, '/') === trim(request()->path(), '/')) {
            $backUrl = route('production-workflow.index');
        }
    @endphp

    <div x-data="{ showReassignModal: false, confirmAction: null, confirmNotes: '' }" class="p-4 sm:p-6 lg:p-8 max-w-6xl mx-auto">

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

                @include('content-items.partials.ai-brief', ['contentItem' => $contentItem])

                <div class="card p-5">
                    <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-3">Initial Brief</h3>
                    <p class="text-sm text-[var(--text-secondary)] whitespace-pre-line mb-4">
                        {{ $contentItem->brief ?: 'Belum ada brief.' }}</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                        <div>
                            <p class="text-[var(--text-muted)] uppercase font-medium mb-1">Platform</p>
                            <p class="text-[var(--text-secondary)]">{{ $contentItem->platform->name ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-[var(--text-muted)] uppercase font-medium mb-1">Deadline</p>
                            <p class="text-[var(--text-secondary)]">{{ $contentItem->deadline_at->format('d M Y, H:i') }}</p>
                        </div>
                        <div>
                            <p class="text-[var(--text-muted)] uppercase font-medium mb-1">Tipe</p>
                            <p class="text-[var(--text-secondary)]">{{ $contentItem->contentType->name ?? '-' }}</p>
                        </div>
                        @if ($contentItem->content_format)
                            <div>
                                <p class="text-[var(--text-muted)] uppercase font-medium mb-1">Format</p>
                                <p class="text-[var(--text-secondary)]">{{ $contentItem->content_format }}</p>
                            </div>
                        @endif
                    </div>
                </div>

                @if (auth()->user()->hasPermissionTo('content_plan', 'create'))
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

                @unless ($workflow->current_status === 'brief_ready')
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

                <div class="card p-5">
                    <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-4">Revision Log
                        ({{ $contentItem->revisions->count() }})</h3>

                    <div class="space-y-2.5 mb-4">
                        @forelse ($contentItem->revisions as $revision)
                            @php
                                $revisionStyles = [
                                    'open' => ['card' => 'bg-[var(--warning-tint)]', 'badge' => 'bg-[var(--warning-tint-soft-2)] text-[var(--warning-text)]', 'label' => 'Open'],
                                    'in_progress' => ['card' => 'bg-[var(--info-tint)]', 'badge' => 'bg-[var(--info-border)] text-[var(--info-text)]', 'label' => 'Sedang Dikerjakan'],
                                    'resolved' => ['card' => 'bg-[var(--surface-page)]', 'badge' => 'bg-[var(--success-tint)] text-[var(--success-text)]', 'label' => 'Resolved'],
                                ];
                                $revisionStyle = $revisionStyles[$revision->status] ?? $revisionStyles['resolved'];
                            @endphp
                            <div class="border border-[var(--border)] rounded-lg p-3 {{ $revisionStyle['card'] }}">
                                <div class="flex items-center justify-between mb-1">
                                    <p class="text-xs font-medium text-[var(--text-primary)]">Revisi #{{ $revision->revision_round }} -
                                        {{ $revision->requestedBy->name ?? '-' }}</p>
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
                    @php $canPublish = auth()->user()->hasPermissionTo('publishing', 'manage'); @endphp
                    <div id="record-publication" class="card p-5 scroll-mt-6">
                        <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-4">Record Publication</h3>
                        @unless ($canPublish)
                            <div class="flex items-start gap-2 bg-[var(--warning-tint)] text-[var(--warning-text)] text-xs p-3 rounded-lg mb-3.5">
                                <span class="material-symbols-outlined text-[16px] shrink-0">info</span>
                                <span>Hanya SMO yang bisa mencatat data publikasi. Hubungi SMO yang bertanggung jawab untuk client ini.</span>
                            </div>
                        @endunless
                        <form action="{{ route('content-publication.store', $contentItem) }}" method="POST" class="space-y-3">
                            @csrf
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Platform</label>
                                    <div class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs bg-[var(--surface-page)] text-[var(--text-secondary)]">
                                        {{ $contentItem->platform->name ?? '-' }}
                                    </div>
                                    <input type="hidden" name="platform_id" value="{{ $contentItem->platform_id }}">
                                </div>
                                <div>
                                    <label for="published_at" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Tanggal
                                        Publish</label>
                                    <input id="published_at" type="text" name="published_at" required data-flatpickr="datetime" autocomplete="off"
                                        class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                                </div>
                            </div>
                            <div>
                                <label for="post_url" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Link Post</label>
                                <input id="post_url" type="url" name="post_url" placeholder="https://..."
                                    class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                            </div>
                            <div>
                                <label for="caption_final" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Caption Final</label>
                                <textarea id="caption_final" name="caption_final" rows="2"
                                    class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40"></textarea>
                            </div>
                            <button type="submit" @disabled(! $canPublish)
                                title="{{ $canPublish ? '' : 'Hanya SMO yang bisa mencatat data publikasi' }}"
                                class="btn-primary">
                                Simpan &amp; Tandai Uploaded</button>
                        </form>
                    </div>
                @endif
            </div>

            <div class="space-y-5">
                @include('content-items.partials.ai-brief-discussion', ['contentItem' => $contentItem])

                @include('content-items.partials.client-assets', ['contentItem' => $contentItem])

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

                @unless (in_array($workflow->current_status, ['uploaded', 'cancelled']))
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
                                        <p class="text-[10px] text-[var(--text-muted)]">{{ $log->changedBy->name ?? '-' }} ·
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
                                @php $candidateActiveCount = $activeCountsByMember[$candidate->id] ?? 0; @endphp
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

                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                        <button type="submit"
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
