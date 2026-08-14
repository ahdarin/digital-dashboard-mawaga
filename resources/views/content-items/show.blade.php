@extends('layouts.app')
@section('title', $contentItem->title)

@section('content')
    @php
        $workflow = $contentItem->workflow;
        $statusLabels = \App\Support\WorkflowTransitions::labels();
    @endphp

    <div x-data="{ showReassignModal: false, confirmAction: null, confirmNotes: '' }" class="p-8 max-w-6xl">

        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <a href="{{ url()->previous() }}" class="text-[#9aa0a4] hover:text-[#5c6266]">
                    <span class="material-symbols-outlined">arrow_back</span>
                </a>
                <div>
                    <p class="text-xs text-[#9aa0a4]">{{ $contentItem->client->name ?? '-' }} / Item #{{ $contentItem->id }}
                    </p>
                    <div class="flex items-center gap-2">
                        <h2 class="font-display text-xl font-semibold text-[#14181a]">{{ $contentItem->title }}</h2>
                        @if ($contentItem->is_urgent)
                            <span class="flex items-center gap-1 text-[10px] font-semibold text-white bg-[#b3423e] px-2 py-0.5 rounded-full uppercase tracking-wide">
                                <span class="material-symbols-outlined text-[12px]">bolt</span> Dadakan
                            </span>
                        @endif
                    </div>
                </div>
            </div>
            <span class="text-xs font-medium px-3 py-1.5 rounded-full bg-[#f0f5f4] text-[#044b46]">
                {{ $statusLabels[$workflow->current_status] ?? $workflow->current_status }}
            </span>
        </div>

        @if (session('status'))
            <div class="bg-[#f0f5f4] text-[#044b46] text-sm p-3.5 rounded-lg mb-6">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="bg-[#fdf2f1] text-[#b3423e] text-sm p-3.5 rounded-lg mb-6 flex items-center gap-2">
                <span class="material-symbols-outlined text-[17px]">error</span> {{ session('error') }}
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

            <div class="lg:col-span-2 space-y-5">

                @include('content-items.partials.ai-brief', ['contentItem' => $contentItem])

                @php
                    $isVideoContent = ($contentItem->contentType->name ?? '') === 'Video';

                    $nextStepMeta = [
                        'brief_ready' => ['icon' => 'play_arrow', 'text' => 'Brief siap — klik "Kerjakan Konten" di Status Management untuk mulai edit/produksi.'],
                        'in_progress' => ['icon' => 'construction', 'text' => 'Konten sedang dikerjakan — tandai "Konten Telah Selesai" di Status Management kalau sudah rampung.'],
                        'waiting_review' => ['icon' => 'rate_review', 'text' => 'Menunggu review — approve atau ajukan revisi lewat Status Management / Revision Log.'],
                        'revision' => ['icon' => 'edit_note', 'text' => 'Ada revisi terbuka — kerjakan lewat Revision Log di bawah.'],
                        'approved' => ['icon' => 'event_available', 'text' => 'Sudah di-approve — jadwalkan tanggal upload lewat Status Management.'],
                        'scheduled' => ['icon' => 'cloud_upload', 'text' => 'Terjadwal upload — catat link & tanggal publish lewat Record Publication.'],
                        'uploaded' => ['icon' => 'check_circle', 'text' => 'Sudah dipublikasikan. Lihat detail link & tanggal publish di bawah.'],
                        'cancelled' => ['icon' => 'cancel', 'text' => 'Konten ini sudah dibatalkan.'],
                    ];
                    $nextStep = $nextStepMeta[$workflow->current_status] ?? null;
                    $latestPublication = $contentItem->publications->sortByDesc('published_at')->first();
                    $hasUnresolvedRevisions = $contentItem->revisions->whereIn('status', ['open', 'in_progress'])->isNotEmpty();
                @endphp

                <div class="card p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-[#14181a]">Status Produksi &amp; Langkah Selanjutnya</h3>
                        <span class="text-xs font-medium px-3 py-1.5 rounded-full bg-[#f0f5f4] text-[#044b46]">
                            {{ $statusLabels[$workflow->current_status] ?? $workflow->current_status }}
                        </span>
                    </div>

                    @if ($nextStep)
                        <div class="flex items-start gap-2 bg-[#f7f8fc] border border-[#eef0f4] rounded-lg p-3 mb-3">
                            <span class="material-symbols-outlined text-[16px] text-[#044b46] mt-0.5">{{ $nextStep['icon'] }}</span>
                            <p class="text-xs text-[#5c6266] leading-relaxed">{{ $nextStep['text'] }}</p>
                        </div>
                    @endif

                    {{-- Khusus konten Video di status Brief Ready - syuting bisa selesai duluan
                         sebelum proses edit (yang baru memindahkan status ke In Progress) dimulai.
                         Tanggal take-nya diisi manual, bukan otomatis "sekarang". --}}
                    @if ($isVideoContent && $workflow->current_status === 'brief_ready')
                        <div x-data="{ editingTakeDate: {{ $contentItem->footage_captured_at ? 'false' : 'true' }} }" class="mb-3">
                            @if ($contentItem->footage_captured_at)
                                <div x-show="! editingTakeDate" class="bg-[#f0f5f4] text-[#0f7a5f] text-xs p-3 rounded-lg">
                                    <div class="flex items-start gap-2">
                                        <span class="material-symbols-outlined text-[16px] shrink-0">check_circle</span>
                                        <span>Video sudah di-take di lokasi ({{ $contentItem->footage_captured_at->format('d M Y, H:i') }}), menunggu proses edit.</span>
                                    </div>
                                    <div class="flex items-center justify-end gap-3 mt-2 pt-2 border-t border-[#0f7a5f]/15">
                                        <button type="button" @click="editingTakeDate = true" class="text-[11px] font-medium text-[#0f7a5f] hover:underline">Ubah Tanggal</button>
                                        <form action="{{ route('content-items.footage-captured.unmark', $contentItem) }}" method="POST" class="m-0"
                                              onsubmit="return confirm('Batalkan penandaan? Kalau ternyata belum di-take, batalkan supaya statusnya akurat lagi.');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-[11px] font-medium text-[#8a6423] hover:underline">Batalkan</button>
                                        </form>
                                    </div>
                                </div>
                            @endif

                            <form x-show="editingTakeDate" action="{{ route('content-items.footage-captured', $contentItem) }}" method="POST"
                                  class="flex items-center gap-2">
                                @csrf @method('PATCH')
                                <input type="datetime-local" name="footage_captured_at"
                                    value="{{ ($contentItem->footage_captured_at ?? now())->format('Y-m-d\TH:i') }}" required
                                    class="flex-1 border border-[#eef0f4] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
                                <button type="submit"
                                    class="flex items-center justify-center gap-1.5 border border-[#044b46]/30 text-[#044b46] text-sm font-medium px-4 py-2 rounded-lg hover:bg-[#f0f5f4] transition-colors whitespace-nowrap">
                                    <span class="material-symbols-outlined text-[16px]">videocam</span> Tandai Sudah Di-take
                                </button>
                                @if ($contentItem->footage_captured_at)
                                    <button type="button" @click="editingTakeDate = false" class="text-xs font-medium text-[#9aa0a4] hover:text-[#14181a] px-2">Batal</button>
                                @endif
                            </form>
                        </div>
                    @endif

                    @if ($latestPublication)
                        <div class="grid grid-cols-2 gap-4 text-xs mb-4">
                            <div>
                                <p class="text-[#9aa0a4] uppercase font-medium mb-1">Tanggal Publish</p>
                                <p class="text-[#14181a] font-medium">{{ $latestPublication->published_at->format('d M Y, H:i') }}</p>
                            </div>
                            <div>
                                <p class="text-[#9aa0a4] uppercase font-medium mb-1">Link Post</p>
                                @if ($latestPublication->post_url)
                                    <a href="{{ $latestPublication->post_url }}" target="_blank" class="text-[#044b46] underline break-all">{{ $latestPublication->post_url }}</a>
                                @else
                                    <p class="text-[#5c6266]">-</p>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="flex items-center gap-2">
                        <a href="{{ route('production-workflow.index') }}"
                            class="flex items-center justify-center gap-1.5 flex-1 border border-[#eef0f4] text-[#5c6266] text-sm font-medium py-2.5 rounded-lg hover:bg-[#f7f8fc] transition-colors">
                            <span class="material-symbols-outlined text-[16px]">view_kanban</span> Lihat di Production Workflow
                        </a>

                        @unless (in_array($workflow->current_status, ['uploaded', 'cancelled']))
                            @if ($hasUnresolvedRevisions)
                                <button type="button" disabled title="Masih ada revisi terbuka di Revision Log"
                                    class="flex items-center justify-center gap-1.5 flex-1 bg-[#f2f3f6] text-[#c3c7cb] text-sm font-medium py-2.5 rounded-lg cursor-not-allowed">
                                    Lanjut ke Status Management <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
                                </button>
                            @else
                                <a href="#{{ $workflow->current_status === 'scheduled' ? 'record-publication' : 'status-management' }}"
                                    class="flex items-center justify-center gap-1.5 flex-1 bg-[#044b46] text-white text-sm font-medium py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                                    Lanjut ke Status Management <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
                                </a>
                            @endif
                        @endunless
                    </div>

                    @if ($hasUnresolvedRevisions && ! in_array($workflow->current_status, ['uploaded', 'cancelled']))
                        <p class="text-[11px] text-[#8a6423] mt-2">
                            <span class="material-symbols-outlined text-[12px] align-middle">hourglass_empty</span>
                            Masih ada revisi terbuka — selesaikan dulu di <strong>Revision Log</strong> di bawah sebelum lanjut.
                        </p>
                    @endif
                </div>

                <div class="card p-5">
                    <h3 class="text-sm font-semibold text-[#14181a] mb-3">Initial Brief</h3>
                    <p class="text-sm text-[#5c6266] whitespace-pre-line mb-4">
                        {{ $contentItem->brief ?: 'Belum ada brief.' }}</p>
                    <div class="grid grid-cols-2 gap-4 text-xs">
                        <div>
                            <p class="text-[#9aa0a4] uppercase font-medium mb-1">Platform</p>
                            <p class="text-[#5c6266]">{{ $contentItem->platform->name ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-[#9aa0a4] uppercase font-medium mb-1">Deadline</p>
                            <p class="text-[#5c6266]">{{ $contentItem->deadline_at->format('d M Y, H:i') }}</p>
                        </div>
                    </div>
                </div>

                @unless ($workflow->current_status === 'brief_ready')
                    <div class="card p-5" x-data="{ editingLink: {{ $contentItem->content_file_link ? 'false' : 'true' }} }">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-sm font-semibold text-[#14181a]">Link Konten (Draft)</h3>
                            @if ($contentItem->content_file_link)
                                <button type="button" @click="editingLink = !editingLink" class="text-xs font-medium text-[#044b46] hover:underline">
                                    <span x-text="editingLink ? 'Batal' : 'Ubah'"></span>
                                </button>
                            @endif
                        </div>
                        <p class="text-xs text-[#9aa0a4] mb-3">Link file hasil produksi (Google Drive/Canva/dsb) - diisi setelah konten selesai diedit, supaya bisa direview sebelum upload. Beda dengan Link Post di Record Publication (itu link postingan yang sudah live).</p>

                        <div x-show="! editingLink" x-cloak class="flex items-center gap-2">
                            <a href="{{ $contentItem->content_file_link }}" target="_blank"
                                class="flex-1 text-sm text-[#044b46] underline break-all">{{ $contentItem->content_file_link }}</a>
                        </div>

                        <form x-show="editingLink" x-cloak action="{{ route('content-items.content-link', $contentItem) }}" method="POST" class="flex items-center gap-2">
                            @csrf @method('PATCH')
                            <input type="url" name="content_file_link" value="{{ $contentItem->content_file_link }}"
                                placeholder="https://drive.google.com/..."
                                class="flex-1 border border-[#eef0f4] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
                            <button type="submit" class="bg-[#044b46] text-white text-xs font-medium px-4 py-2 rounded-lg hover:bg-[#033b37] transition-colors whitespace-nowrap">
                                Simpan
                            </button>
                        </form>
                    </div>
                @endunless

                <div class="card p-5">
                    <h3 class="text-sm font-semibold text-[#14181a] mb-4">Revision Log
                        ({{ $contentItem->revisions->count() }})</h3>

                    <div class="space-y-2.5 mb-4">
                        @forelse ($contentItem->revisions as $revision)
                            @php
                                $revisionStyles = [
                                    'open' => ['card' => 'bg-[#fdf6ec]', 'badge' => 'bg-[#f7e8cf] text-[#8a6423]', 'label' => 'Open'],
                                    'in_progress' => ['card' => 'bg-[#eef2fb]', 'badge' => 'bg-[#dde4f7] text-[#3452a8]', 'label' => 'Sedang Dikerjakan'],
                                    'resolved' => ['card' => 'bg-[#f7f8fc]', 'badge' => 'bg-[#f0f5f4] text-[#0f7a5f]', 'label' => 'Resolved'],
                                ];
                                $revisionStyle = $revisionStyles[$revision->status] ?? $revisionStyles['resolved'];
                            @endphp
                            <div class="border border-[#eef0f4] rounded-lg p-3 {{ $revisionStyle['card'] }}">
                                <div class="flex items-center justify-between mb-1">
                                    <p class="text-xs font-medium text-[#14181a]">Revisi #{{ $revision->revision_round }} -
                                        {{ $revision->requestedBy->name ?? '-' }}</p>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full {{ $revisionStyle['badge'] }}">{{ $revisionStyle['label'] }}</span>
                                </div>
                                <p class="text-xs text-[#5c6266]">{{ $revision->revision_note }}</p>

                                @if ($revision->status === 'open')
                                    <button type="button" :disabled="{{ $canUpdateWorkflow ? 'false' : 'true' }}"
                                        @click="confirmAction = {
                                            title: 'Kerjakan Revisi',
                                            message: 'Mulai kerjakan revisi ini? Status konten akan berpindah ke In Progress, dan semua revisi yang masih open ikut mulai dikerjakan bareng.',
                                            formAction: '{{ route('content-revision.start-work', [$contentItem, $revision]) }}',
                                            method: 'PATCH',
                                            confirmLabel: 'Ya, Kerjakan',
                                        }"
                                        title="{{ $canUpdateWorkflow ? '' : 'Kamu tidak punya izin memindahkan status' }}"
                                        class="mt-2 inline-flex items-center gap-1 text-[11px] font-medium px-3 py-1.5 rounded-lg transition-colors
                                            {{ $canUpdateWorkflow ? 'bg-[#044b46] text-white hover:bg-[#033b37]' : 'bg-[#f2f3f6] text-[#c3c7cb] cursor-not-allowed' }}">
                                        <span class="material-symbols-outlined text-[13px]">build</span> Kerjakan Revisi
                                    </button>
                                @elseif ($revision->status === 'in_progress')
                                    <button type="button" disabled
                                        class="mt-2 inline-flex items-center gap-1.5 bg-[#dde4f7] text-[#3452a8] text-[11px] font-medium px-3 py-1.5 rounded-lg cursor-not-allowed">
                                        <span class="inline-block w-3 h-3 border-2 border-[#3452a8]/30 border-t-[#3452a8] rounded-full animate-spin"></span>
                                        Sedang Revisi
                                    </button>
                                @endif
                            </div>
                        @empty
                            <p class="text-xs text-[#9aa0a4] italic">Belum ada revisi.</p>
                        @endforelse
                    </div>

                    @if (in_array($workflow->current_status, ['waiting_review', 'revision']))
                        <form action="{{ route('content-revision.store', $contentItem) }}" method="POST" class="flex gap-2">
                            @csrf
                            <input type="text" name="revision_note" required placeholder="Tulis catatan revisi..."
                                class="flex-1 border border-[#eef0f4] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                            <button type="submit"
                                class="bg-[#044b46] text-white text-xs font-medium px-4 py-2 rounded-lg hover:bg-[#033b37]">Kirim</button>
                        </form>
                    @else
                        <p class="text-[11px] text-[#9aa0a4] italic">Catatan revisi cuma bisa ditambahkan saat status Waiting Review atau Revision.</p>
                    @endif
                </div>

                @if ($contentItem->is_posted)
                    <div class="card p-5">
                        <h3 class="text-sm font-semibold text-[#14181a] mb-3">Publication</h3>
                        @foreach ($contentItem->publications as $pub)
                            <div class="text-xs text-[#5c6266] border-b border-[#f2f3f6] py-2 last:border-0">
                                <p class="font-medium text-[#14181a]">{{ $pub->platform->name }} —
                                    {{ $pub->published_at->format('d M Y, H:i') }}</p>
                                @if ($pub->post_url)
                                    <a href="{{ $pub->post_url }}" target="_blank"
                                        class="text-[#044b46] underline">{{ $pub->post_url }}</a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @elseif ($workflow->current_status === 'scheduled')
                    @php $canPublish = auth()->user()->hasPermissionTo('publishing', 'manage'); @endphp
                    <div id="record-publication" class="card p-5 scroll-mt-6">
                        <h3 class="text-sm font-semibold text-[#14181a] mb-4">Record Publication</h3>
                        @unless ($canPublish)
                            <div class="flex items-start gap-2 bg-[#fdf6ec] text-[#8a6423] text-xs p-3 rounded-lg mb-3.5">
                                <span class="material-symbols-outlined text-[16px] shrink-0">info</span>
                                <span>Hanya SMO yang bisa mencatat data publikasi. Hubungi SMO yang bertanggung jawab untuk client ini.</span>
                            </div>
                        @endunless
                        <form action="{{ route('content-publication.store', $contentItem) }}" method="POST" class="space-y-3">
                            @csrf
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Platform</label>
                                    <div class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs bg-[#f7f8fc] text-[#5c6266]">
                                        {{ $contentItem->platform->name ?? '-' }}
                                    </div>
                                    <input type="hidden" name="platform_id" value="{{ $contentItem->platform_id }}">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Tanggal
                                        Publish</label>
                                    <input type="datetime-local" name="published_at" required
                                        class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Link Post</label>
                                <input type="url" name="post_url" placeholder="https://..."
                                    class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Caption Final</label>
                                <textarea name="caption_final" rows="2"
                                    class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40"></textarea>
                            </div>
                            <button type="submit" @disabled(! $canPublish)
                                title="{{ $canPublish ? '' : 'Hanya SMO yang bisa mencatat data publikasi' }}"
                                class="text-xs font-medium px-4 py-2 rounded-lg transition-colors
                                    {{ $canPublish ? 'bg-[#044b46] text-white hover:bg-[#033b37]' : 'bg-[#f2f3f6] text-[#c3c7cb] cursor-not-allowed' }}">
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
                        <h3 class="text-sm font-semibold text-[#14181a]">PIC Assignment</h3>
                        <button type="button" @click="showReassignModal = true"
                                class="inline-flex items-center gap-1 bg-[#f0f5f4] text-[#044b46] text-xs font-medium px-3 py-1.5 rounded-lg hover:bg-[#e4ede9] transition-colors">
                            <span class="material-symbols-outlined text-[14px]">sync_alt</span> Reassign
                        </button>
                    </div>
                    <div class="space-y-3">
                        @forelse ($contentItem->assignments as $assignment)
                            <div class="flex items-center gap-2">
                                @if ($assignment->user->avatar_url)
                                    <img src="{{ $assignment->user->avatar_url }}" class="w-8 h-8 rounded-full object-cover">
                                @else
                                    <div
                                        class="w-8 h-8 rounded-full bg-[#044b46] text-white text-xs font-semibold flex items-center justify-center">
                                        {{ strtoupper(substr($assignment->user->name, 0, 1)) }}</div>
                                @endif
                                <div>
                                    <p class="text-xs font-medium text-[#14181a]">{{ $assignment->user->name }}</p>
                                    <p class="text-[10px] text-[#9aa0a4]">
                                        {{ ucwords(str_replace('_', ' ', $assignment->assignment_role)) }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-[#9aa0a4] italic">Belum ada PIC.</p>
                        @endforelse
                    </div>
                </div>

                @unless (in_array($workflow->current_status, ['uploaded', 'cancelled']))
                    @include('content-items.partials.status-management', [
                        'contentItem' => $contentItem,
                        'workflow' => $workflow,
                        'canUpdateWorkflow' => $canUpdateWorkflow,
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
                            <h3 class="text-sm font-semibold text-[#14181a] mb-3">AI Delay Risk</h3>

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
                                <div class="bg-[#fdf6ec] rounded-lg p-3 mb-3 flex items-start gap-2.5">
                                    <span class="material-symbols-outlined text-[#b8873a] text-[16px] mt-0.5">group</span>
                                    <div class="flex-1">
                                        <p class="text-xs text-[#8a6423] mb-2">PIC saat ini sedang menangani banyak task aktif. Pertimbangkan reassign ke PIC yang lebih longgar.</p>
                                        <button type="button" @click="showReassignModal = true"
                                                class="inline-flex items-center gap-1 bg-[#8a6423] text-white text-xs font-medium px-3 py-1.5 rounded-lg hover:bg-[#735220] transition-colors">
                                            <span class="material-symbols-outlined text-[14px]">sync_alt</span> Reassign PIC
                                        </button>
                                    </div>
                                </div>
                            @endif

                            <p class="text-[10px] text-[#9aa0a4]">
                                Dihitung {{ $latestRisk->created_at->diffForHumans() }}
                            </p>

                            @if ($contentItem->delayRiskScores->count() > 1)
                                @php
                                    $riskTrend = $contentItem->delayRiskScores->reverse()->values()->map(fn ($s) => [
                                        'label' => $s->created_at->format('d/m'),
                                        'value' => $s->risk_score,
                                    ]);
                                @endphp
                                <div class="mt-4 pt-4 border-t border-[#eef0f4]">
                                    <p class="text-[10px] font-semibold text-[#9aa0a4] uppercase mb-2">Tren Skor Risiko</p>
                                    <x-trend-chart :trend="$riskTrend" :show-total="false" />
                                </div>
                            @endif

                            <p class="text-[10px] text-[#c3c7cb] mt-2 italic">
                                Skor ini estimasi otomatis (bukan keputusan final), gunakan sebagai sinyal awal untuk prioritas.
                            </p>
                        </div>
                    @endif

                    <div class="card p-5">
                        <h3 class="text-sm font-semibold text-[#14181a] mb-3">Status History</h3>
                        <div class="space-y-3">
                            @forelse ($contentItem->statusLogs->sortByDesc('changed_at') as $log)
                                <div class="flex gap-3">
                                    <div class="w-1.5 h-1.5 rounded-full bg-[#044b46] mt-1.5 flex-shrink-0"></div>
                                    <div>
                                        <p class="text-xs text-[#5c6266]">
                                            <span
                                                class="font-medium text-[#14181a]">{{ $log->from_status ? ($statusLabels[$log->from_status] ?? $log->from_status) : 'Dibuat' }}</span>
                                            →
                                            <span
                                                class="font-medium text-[#14181a]">{{ $statusLabels[$log->to_status] ?? $log->to_status }}</span>
                                        </p>
                                        <p class="text-[10px] text-[#9aa0a4]">{{ $log->changedBy->name ?? '-' }} ·
                                            {{ $log->changed_at->format('d M, H:i') }}</p>
                                        @if ($log->notes)
                                            <p class="text-[10px] text-[#9aa0a4] italic mt-0.5">{{ $log->notes }}</p>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="text-xs text-[#9aa0a4] italic">Belum ada histori.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

        {{-- Modal Reassign PIC --}}
        <div x-show="showReassignModal" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
            <div class="absolute inset-0 bg-[#14181a]/40" @click="showReassignModal = false"></div>

            <div x-show="showReassignModal" x-transition
                 class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
                <div class="flex items-center justify-between px-6 py-5 border-b border-[#eef0f4]">
                    <div>
                        <h3 class="font-display text-lg font-semibold text-[#14181a]">Reassign PIC</h3>
                        <p class="text-xs text-[#9aa0a4] mt-0.5">{{ $contentItem->title }}</p>
                    </div>
                    <button type="button" @click="showReassignModal = false" class="text-[#9aa0a4] hover:text-[#5c6266]">
                        <span class="material-symbols-outlined text-[19px]">close</span>
                    </button>
                </div>

                <form action="{{ route('content-items.reassign', $contentItem) }}" method="POST">
                    @csrf @method('PATCH')
                    <div class="px-6 py-5">
                        <p class="text-xs font-semibold text-[#9aa0a4] uppercase mb-3">Pilih PIC Baru</p>
                        <p class="text-[11px] text-[#9aa0a4] mb-3">Diurutkan dari yang task aktifnya paling sedikit.</p>

                        <div class="space-y-2 max-h-72 overflow-y-auto">
                            @foreach ($reassignCandidates as $candidate)
                                <label class="flex items-center justify-between gap-3 p-3 border border-[#eef0f4] rounded-lg hover:bg-[#f7f8fc] cursor-pointer">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <input type="radio" name="user_id" value="{{ $candidate->id }}"
                                               {{ $workflow->current_pic_id === $candidate->id ? 'checked' : '' }}
                                               class="border-[#dadfe0] text-[#044b46] focus:ring-[#044b46]">
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-[#14181a] truncate">{{ $candidate->name }}</p>
                                            <p class="text-xs text-[#9aa0a4]">{{ $candidate->role->name ?? '-' }}</p>
                                        </div>
                                    </div>
                                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full shrink-0
                                        {{ $candidate->active_task_count > 8 ? 'bg-[#fdf2f1] text-[#b3423e]' : 'bg-[#f0f5f4] text-[#0f7a5f]' }}">
                                        {{ $candidate->active_task_count }} task aktif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[#eef0f4]">
                        <button type="submit" class="bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                            Simpan
                        </button>
                        <button type="button" @click="showReassignModal = false" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">
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
             class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
            <div class="absolute inset-0 bg-[#14181a]/40" @click="confirmAction = null"></div>

            <div x-show="confirmAction" x-transition
                 class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
                <div class="flex items-center justify-between px-6 py-5 border-b border-[#eef0f4]">
                    <div>
                        <h3 class="font-display text-lg font-semibold text-[#14181a]" x-text="confirmAction?.title"></h3>
                        <p class="text-xs text-[#9aa0a4] mt-0.5">{{ $contentItem->title }}</p>
                    </div>
                    <button type="button" @click="confirmAction = null" class="text-[#9aa0a4] hover:text-[#5c6266]">
                        <span class="material-symbols-outlined text-[19px]">close</span>
                    </button>
                </div>

                <div class="px-6 py-5">
                    <p class="text-sm text-[#5c6266]" x-text="confirmAction?.message"></p>

                    <template x-if="confirmAction?.extra">
                        <p class="mt-3 bg-[#f7f8fc] rounded-lg p-3 text-sm font-medium text-[#14181a]" x-text="confirmAction?.extra"></p>
                    </template>

                    <template x-if="confirmAction?.withNotes">
                        <div class="mt-3">
                            <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5" x-text="confirmAction?.notesLabel"></label>
                            <textarea x-model="confirmNotes" rows="2" placeholder="Tulis alasan..."
                                class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40"></textarea>
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

                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[#eef0f4]">
                        <button type="submit"
                            :class="confirmAction?.danger ? 'bg-[#b3423e] text-white hover:bg-[#9c3733]' : 'bg-[#044b46] text-white hover:bg-[#033b37]'"
                            class="text-sm font-medium px-5 py-2.5 rounded-lg transition-colors"
                            x-text="confirmAction?.confirmLabel || 'Ya, Lanjutkan'"></button>
                        <button type="button" @click="confirmAction = null" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">
                            Batal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
