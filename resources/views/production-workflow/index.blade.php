@extends('layouts.app')

@section('title', 'Produksi')

@section('content')
<div class="flex flex-col {{ $tab === 'board' ? 'h-[calc(100vh-64px)]' : '' }}">

    <header class="px-4 sm:px-6 lg:px-8 pt-5 flex-shrink-0">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[#14181a]">Produksi</h1>
                <p class="text-[#5c6266] text-sm mt-1">Alur produksi, revisi, dan riwayat tayang konten.</p>
            </div>
            @include('partials.urgent-content-modal')
        </div>

        {{-- Tab switcher --}}
        <div class="flex items-center h-10 bg-[#f2f3f6] rounded-lg p-1 w-fit mb-5">
            <a href="{{ route('production-workflow.index') }}"
               class="flex items-center h-full text-xs font-medium px-4 rounded-md transition-colors {{ $tab === 'board' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4] hover:text-[#14181a]' }}">
                Papan Alur Produksi
            </a>
            <a href="{{ route('production-workflow.index', ['tab' => 'revisions']) }}"
               class="flex items-center h-full text-xs font-medium px-4 rounded-md transition-colors {{ $tab === 'revisions' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4] hover:text-[#14181a]' }}">
                Revisi
            </a>
            <a href="{{ route('production-workflow.index', ['tab' => 'published']) }}"
               class="flex items-center h-full text-xs font-medium px-4 rounded-md transition-colors {{ $tab === 'published' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4] hover:text-[#14181a]' }}">
                Sudah Tayang
            </a>
        </div>
    </header>

    @if ($tab === 'revisions')
        @include('production-workflow.partials.revisions-tab')
    @elseif ($tab === 'published')
        @include('production-workflow.partials.published-tab')
    @else
    <div x-data="kanbanBoard()" class="flex flex-col flex-1 min-h-0">
    <div class="px-4 sm:px-6 lg:px-8 flex-shrink-0">
        <form id="filter-form" method="GET" action="{{ route('production-workflow.index') }}">
            <input type="hidden" name="view" value="{{ $view }}">
            @if ($sortColumn !== 'deadline' || $sortDir !== 'asc')
                <input type="hidden" name="sort" value="{{ $sortColumn }}">
                <input type="hidden" name="dir" value="{{ $sortDir }}">
            @endif
        </form>

        <div class="flex items-center gap-3 mb-2.5 flex-wrap">
            <select name="client_id" form="filter-form" onchange="this.form.submit()"
                    class="border border-[#eef0f4] rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40 h-[40px]">
                <option value="">Semua Client</option>
                @foreach ($clientOptions as $client)
                    <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>{{ $client->name }}</option>
                @endforeach
            </select>

            <div class="relative flex-1 min-w-[160px]">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#c3c7cb] text-[19px]">search</span>
                <input x-model="search" class="pl-10 pr-4 h-[40px] bg-white border border-[#eef0f4] rounded-lg text-sm focus:outline-none focus:border-[#044b46]/40 w-full sm:w-64" placeholder="Cari konten..." type="text">
            </div>

            @if ($view === 'board')
                <button type="button" @click="toggleRiskSort()"
                        class="flex items-center gap-1.5 h-[40px] px-3.5 rounded-lg text-sm font-medium border transition-colors whitespace-nowrap"
                        :class="riskSortActive ? 'bg-[#fdf2f1] border-[#f5d9d7] text-[#b3423e]' : 'bg-white border-[#eef0f4] text-[#5c6266]'">
                    <span class="material-symbols-outlined text-[17px]">sort</span>
                    Risiko Tertinggi
                </button>
            @endif

            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#c3c7cb] text-[17px] pointer-events-none">calendar_month</span>
                <input type="text" name="month" form="filter-form" value="{{ $selectedMonth }}"
                       data-flatpickr="month-combined" data-autosubmit="true" placeholder="Semua Bulan"
                       class="border border-[#eef0f4] rounded-lg pl-9 pr-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40 h-[40px] w-[150px]" readonly>
            </div>
            @if ($selectedMonth)
                <a href="{{ request()->fullUrlWithQuery(['month' => null]) }}" class="text-xs font-medium text-[#9aa0a4] hover:text-[#14181a]">Reset bulan</a>
            @endif

            <div class="flex items-center h-9 bg-[#f2f3f6] rounded-lg p-1 sm:ml-auto">
                <a href="{{ request()->fullUrlWithQuery(['view' => 'board']) }}"
                   class="flex items-center h-full text-xs font-medium px-3 rounded-md {{ $view === 'board' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4]' }}">
                    <span class="material-symbols-outlined text-[15px] align-middle">view_kanban</span> Papan
                </a>
                <a href="{{ request()->fullUrlWithQuery(['view' => 'list']) }}"
                   class="flex items-center h-full text-xs font-medium px-3 rounded-md {{ $view === 'list' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4]' }}">
                    <span class="material-symbols-outlined text-[15px] align-middle">view_list</span> List
                </a>
            </div>
        </div>
    </div>

    @if ($view === 'list')
        @include('production-workflow.partials.list-tab')
    @else

    <div x-show="toast" x-transition class="fixed top-6 right-6 z-50 bg-[#14181a] text-white px-4 py-2.5 rounded-lg shadow-lg text-sm" x-text="toast" style="display: none;"></div>

    <div class="flex-1 overflow-x-auto p-4 flex items-start gap-4 bg-[#f7f8fc] thin-autohide-scrollbar">
        @php $statusLabels = \App\Support\WorkflowTransitions::labels(); @endphp

        @foreach ($statuses as $status)
            @php
                $columnColors = ['#044b46', '#3452a8', '#0e7490', '#b8873a', '#b3427e', '#7c5cbf', '#0f7a5f', '#b3423e', '#0a8f76', '#6b7280'];
                $dotColor = $columnColors[$loop->index % count($columnColors)];
            @endphp
            <div class="flex-shrink-0 w-[290px] h-full flex flex-col bg-white rounded-xl border border-[#eef0f4]"
                 x-on:dragover.prevent x-on:drop="onDrop($event, '{{ $status }}')">

                <div class="p-3 border-b border-[#eef0f4] flex items-center justify-between flex-shrink-0">
                    <h3 class="text-xs font-semibold text-[#5c6266] flex items-center gap-2">
                        <div class="w-1.5 h-1.5 rounded-full" style="background-color: {{ $dotColor }}"></div>
                        {{ $statusLabels[$status] }}
                    </h3>
                    <span class="text-xs bg-[#f2f3f6] text-[#5c6266] px-2 py-0.5 rounded-full" data-column-count>{{ $board[$status]->count() }}</span>
                </div>

                <div class="p-2.5 overflow-y-auto flex-1 space-y-2.5 kanban-column thin-autohide-scrollbar" style="min-height: 100px;"
                     data-column="{{ $status }}"
                     x-bind:data-expanded="(expandedColumns['{{ $status }}'] || search.length > 0) ? 'true' : 'false'">
                    @forelse ($board[$status] as $item)
                        @php $isOverdue = $item->workflow->is_overdue; @endphp

                        <div draggable="{{ $canUpdateWorkflow ? 'true' : 'false' }}"
                             x-on:dragstart="onDragStart($event, {{ $item->id }}, '{{ addslashes($item->title) }}', {{ $item->platform_id ?? 'null' }})"
                             x-show="matchesSearch('{{ addslashes($item->title) }}')"
                             data-risk="{{ $item->latestDelayRisk->risk_score ?? 0 }}" data-order="{{ $item->boardOrder }}"
                             data-item-id="{{ $item->id }}"
                             class="bg-white p-3.5 rounded-lg border shadow-sm hover:shadow-md transition-shadow {{ $canUpdateWorkflow ? 'cursor-move' : '' }} {{ $isOverdue ? 'border-[#e39a96]' : 'border-[#eef0f4]' }}">

                            <div class="flex justify-between items-start gap-1 flex-wrap mb-2">
                                <span class="text-[11px] bg-[#f2f3f6] text-[#5c6266] px-2 py-1 rounded">{{ $item->contentType->name ?? '-' }}</span>
                                @if ($item->is_urgent)
                                    <span class="text-[10px] text-white font-semibold flex items-center gap-1 bg-[#b3423e] px-2 py-0.5 rounded whitespace-nowrap">
                                        <span class="material-symbols-outlined text-[12px]">bolt</span> Jobdesk Tambahan
                                    </span>
                                @endif
                                @if ($isOverdue)
                                    <span class="text-[10px] text-[#b3423e] font-semibold flex items-center gap-1 bg-[#fdf2f1] px-2 py-0.5 rounded">
                                        <span class="material-symbols-outlined text-[12px]">warning</span> Overdue
                                    </span>
                                @endif
                                @if ($item->workflow->client_reviewed_at && $status === 'waiting_review')
                                    <span data-client-approved-badge class="text-[10px] text-[#0f7a5f] font-semibold flex items-center gap-1 bg-[#f0f5f4] px-2 py-0.5 rounded whitespace-nowrap">
                                        <span class="material-symbols-outlined text-[12px]">check_circle</span> Klien Setuju
                                    </span>
                                @endif
                                @if ($item->latestDelayRisk)
                                    @php
                                        $riskColors = [
                                            'high' => ['bg' => '#fdf2f1', 'text' => '#b3423e'],
                                            'medium' => ['bg' => '#fdf6ec', 'text' => '#8a6423'],
                                            'low' => ['bg' => '#f0f5f4', 'text' => '#0f7a5f'],
                                        ];
                                        $riskColor = $riskColors[$item->latestDelayRisk->risk_level] ?? $riskColors['low'];
                                    @endphp
                                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full ml-1"
                                        style="background-color: {{ $riskColor['bg'] }}; color: {{ $riskColor['text'] }};"
                                        title="{{ $item->latestDelayRisk->top_factor }}">
                                        {{ $item->latestDelayRisk->risk_score }}% Risk
                                    </span>
                                @endif
                            </div>
                            <h4 class="text-sm font-semibold text-[#14181a] mb-1">{{ $item->title }}</h4>
                            <p class="text-xs text-[#9aa0a4] mb-3">{{ $item->client->name ?? '-' }}</p>

                            {{-- Video di In Progress bisa selesai syuting belakangan setelah proses
                                 edit sudah/lagi berjalan - penanda ini bantu tim lihat progres riil
                                 tanpa harus nunggu/pindah status dulu. --}}
                            @if ($status === 'in_progress' && ($item->contentType->name ?? '') === 'Video')
                                <div data-footage-toggle data-item-id="{{ $item->id }}" class="footage-toggle-fade mb-2.5">
                                    @if ($item->footage_captured_at)
                                        <div class="flex items-center justify-between gap-1 text-[10px] font-medium text-[#0f7a5f] bg-[#f0f5f4] px-2 py-1.5 rounded-lg">
                                            <span class="flex items-center gap-1">
                                                <span class="material-symbols-outlined text-[13px]">check_circle</span> Sudah di-take ({{ $item->footage_captured_at->format('d M Y, H:i') }})
                                            </span>
                                            <button type="button" x-on:click.stop="unmarkFootageCaptured({{ $item->id }}, $event)"
                                                class="text-[#8a6423] hover:underline shrink-0">Batalkan</button>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-1">
                                            <input type="text" data-take-date data-flatpickr="datetime" autocomplete="off" value="{{ now()->format('Y-m-d H:i') }}"
                                                x-on:click.stop x-on:mousedown.stop
                                                class="flex-1 min-w-0 border border-[#eef0f4] rounded-lg px-1.5 py-1 text-[10px] focus:outline-none focus:border-[#044b46]/40">
                                            <button type="button" x-on:click.stop="markFootageCaptured({{ $item->id }}, $event)" title="Tandai Sudah Di-take"
                                                class="flex items-center justify-center shrink-0 border border-[#044b46]/30 text-[#044b46] w-7 h-7 rounded-lg hover:bg-[#f0f5f4] transition-colors">
                                                <span class="material-symbols-outlined text-[15px]">videocam</span>
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            <div class="flex items-center justify-between pt-2.5 border-t border-[#f2f3f6]">
                                <div class="flex items-center gap-1 text-[#9aa0a4]">
                                    <span class="material-symbols-outlined text-[15px]">schedule</span>
                                    <span class="text-xs">{{ $item->deadline_at->format('M d') }}</span>
                                </div>

                                <div class="relative" x-data="{ open: false }">
                                    <div class="flex items-center -space-x-2 cursor-pointer" x-on:mouseenter="open = true" x-on:mouseleave="open = false">
                                        @forelse ($item->assignments->take(3) as $assignment)
                                            @if ($assignment->user->avatar_url)
                                                <img src="{{ $assignment->user->avatar_url }}" referrerpolicy="no-referrer" class="w-6 h-6 rounded-full ring-2 ring-white object-cover">
                                            @else
                                                <div class="w-6 h-6 rounded-full bg-[#044b46] text-white text-[10px] font-semibold flex items-center justify-center ring-2 ring-white">
                                                    {{ strtoupper(substr($assignment->user->name, 0, 1)) }}
                                                </div>
                                            @endif
                                        @empty
                                            <span class="text-xs text-[#c3c7cb] italic">Belum ada Penanggung Jawab</span>
                                        @endforelse
                                        @if ($item->assignments->count() > 3)
                                            <div class="w-6 h-6 rounded-full bg-[#f2f3f6] text-[#5c6266] text-[9px] font-semibold flex items-center justify-center ring-2 ring-white">+{{ $item->assignments->count() - 3 }}</div>
                                        @endif
                                    </div>

                                    <div x-show="open" x-transition x-on:mouseenter="open = true" x-on:mouseleave="open = false"
                                         class="absolute z-10 bottom-full right-0 mb-2 w-56 bg-white border border-[#eef0f4] rounded-lg shadow-lg p-3" style="display: none;">
                                        <p class="text-[10px] font-semibold text-[#9aa0a4] uppercase mb-2">Penanggung Jawab</p>
                                        <div class="space-y-2">
                                            @forelse ($item->assignments as $assignment)
                                                <div class="flex items-center gap-2">
                                                    @if ($assignment->user->avatar_url)
                                                        <img src="{{ $assignment->user->avatar_url }}" referrerpolicy="no-referrer" class="w-7 h-7 rounded-full object-cover">
                                                    @else
                                                        <div class="w-7 h-7 rounded-full bg-[#044b46] text-white text-xs font-semibold flex items-center justify-center">
                                                            {{ strtoupper(substr($assignment->user->name, 0, 1)) }}
                                                        </div>
                                                    @endif
                                                    <div>
                                                        <p class="text-xs font-medium text-[#14181a]">{{ $assignment->user->name }}</p>
                                                        <p class="text-[10px] text-[#9aa0a4]">{{ ucwords(str_replace('_', ' ', $assignment->assignment_role)) }}</p>
                                                    </div>
                                                </div>
                                            @empty
                                                <p class="text-xs text-[#9aa0a4] italic">Belum ada Penanggung Jawab ditugaskan</p>
                                            @endforelse
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-2.5 pt-2 border-t border-[#f2f3f6] text-right">
                                <a href="{{ route('content-items.show', $item) }}" class="text-xs text-[#044b46] font-medium hover:underline">Lihat Detail →</a>
                            </div>
                        </div>
                    @empty
                        <div class="text-center p-6 border border-dashed border-[#dadfe0] rounded-lg">
                            <span class="material-symbols-outlined text-[#d4d7db] text-[28px] mb-2 block">note_add</span>
                            <p class="text-xs text-[#c3c7cb]">Drop cards here</p>
                        </div>
                    @endforelse

                    @if ($board[$status]->count() > 8)
                        <button type="button" x-show="!search"
                                @click="expandedColumns['{{ $status }}'] = !expandedColumns['{{ $status }}']"
                                class="column-more-toggle w-full text-center text-xs font-medium text-[#044b46] hover:underline py-1.5">
                            <span x-show="!expandedColumns['{{ $status }}']">+{{ $board[$status]->count() - 8 }} lainnya</span>
                            <span x-show="expandedColumns['{{ $status }}']" x-cloak>Sembunyikan</span>
                        </button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Modal drag-drop: waiting_review -> revision butuh catatan revisi dulu --}}
    <div x-show="revisionModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-[#14181a]/40" @click="revisionModal = null"></div>
        <div x-show="revisionModal" x-transition class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
            <div class="flex items-center justify-between px-6 py-5 border-b border-[#eef0f4]">
                <div>
                    <h3 class="font-display text-lg font-semibold text-[#14181a]">Tambah Catatan Revisi</h3>
                    <p class="text-xs text-[#9aa0a4] mt-0.5" x-text="revisionModal?.title"></p>
                </div>
                <button type="button" @click="revisionModal = null" class="text-[#9aa0a4] hover:text-[#5c6266]">
                    <span class="material-symbols-outlined text-[19px]">close</span>
                </button>
            </div>
            <div class="px-6 py-5">
                <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Catatan Revisi</label>
                <textarea x-model="revisionNote" rows="3" placeholder="Tulis catatan revisi..."
                    class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40"></textarea>
            </div>
            <div class="flex items-center gap-3 px-6 py-4 border-t border-[#eef0f4]">
                <button type="button" @click="confirmRevisionModal()"
                        class="bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                    Pindahkan ke Revision
                </button>
                <button type="button" @click="revisionModal = null" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">
                    Batal
                </button>
            </div>
        </div>
    </div>

    {{-- Modal drag-drop: scheduled -> uploaded butuh data publikasi dulu --}}
    <div x-show="publicationModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-[#14181a]/40" @click="publicationModal = null"></div>
        <div x-show="publicationModal" x-transition class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
            <div class="flex items-center justify-between px-6 py-5 border-b border-[#eef0f4]">
                <div>
                    <h3 class="font-display text-lg font-semibold text-[#14181a]">Catat Publikasi</h3>
                    <p class="text-xs text-[#9aa0a4] mt-0.5" x-text="publicationModal?.title"></p>
                </div>
                <button type="button" @click="publicationModal = null" class="text-[#9aa0a4] hover:text-[#5c6266]">
                    <span class="material-symbols-outlined text-[19px]">close</span>
                </button>
            </div>
            <div class="px-6 py-5 space-y-3">
                <div>
                    <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Tanggal Publish</label>
                    <input type="text" x-model="pubForm.published_at" data-flatpickr="datetime" autocomplete="off"
                        class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                </div>
                <div>
                    <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Link Post</label>
                    <input type="url" x-model="pubForm.post_url" placeholder="https://..."
                        class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                </div>
                <div>
                    <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Caption Final</label>
                    <textarea x-model="pubForm.caption_final" rows="2"
                        class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40"></textarea>
                </div>
            </div>
            <div class="flex items-center gap-3 px-6 py-4 border-t border-[#eef0f4]">
                <button type="button" @click="confirmPublicationModal()"
                        class="bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                    Tandai Uploaded
                </button>
                <button type="button" @click="publicationModal = null" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">
                    Batal
                </button>
            </div>
        </div>
    </div>

</div>
    @endif
    @endif
</div>

<style>
    .kanban-column[data-expanded="false"] > [data-risk]:nth-child(n+9) {
        display: none !important;
    }
    .footage-toggle-fade {
        transition: opacity 180ms ease, transform 180ms ease;
        opacity: 1;
        transform: translateY(0) scale(1);
    }
    .footage-toggle-fade.footage-toggle-fade-out {
        opacity: 0;
        transform: translateY(-2px) scale(0.98);
    }
</style>

<script>
function kanbanBoard() {
    return {
        search: '',
        toast: '',
        draggedItemId: null,
        draggedItemTitle: '',
        draggedItemPlatformId: null,
        riskSortActive: false,
        expandedColumns: {},
        revisionModal: null,
        revisionNote: '',
        publicationModal: null,
        pubForm: { published_at: '', post_url: '', caption_final: '' },
        toggleRiskSort() {
            this.riskSortActive = !this.riskSortActive;
            document.querySelectorAll('.kanban-column').forEach((col) => {
                const cards = Array.from(col.querySelectorAll(':scope > [data-risk]'));
                cards.sort((a, b) => this.riskSortActive
                    ? Number(b.dataset.risk) - Number(a.dataset.risk)
                    : Number(a.dataset.order) - Number(b.dataset.order));
                const moreBtn = col.querySelector(':scope > .column-more-toggle');
                cards.forEach((card) => col.insertBefore(card, moreBtn || null));
            });
        },
        onDragStart(event, itemId, title, platformId) {
            this.draggedItemId = itemId;
            this.draggedItemTitle = title;
            this.draggedItemPlatformId = platformId;
            event.dataTransfer.effectAllowed = 'move';
        },
        // waiting_review -> revision dan scheduled -> uploaded butuh data
        // tambahan sebelum status beneran berubah, jadi ditahan dulu lewat
        // modal - bukan langsung fetch kayak transisi lain.
        onDrop(event, toStatus) {
            if (!this.draggedItemId) return;
            const itemId = this.draggedItemId;
            const title = this.draggedItemTitle;
            const platformId = this.draggedItemPlatformId;
            this.draggedItemId = null;

            if (toStatus === 'revision') {
                this.revisionNote = '';
                this.revisionModal = { itemId, title };
                return;
            }

            if (toStatus === 'uploaded') {
                this.pubForm = { published_at: '', post_url: '', caption_final: '' };
                this.publicationModal = { itemId, title, platformId };
                return;
            }

            this.submitStatusChange(itemId, toStatus, {});
        },
        confirmRevisionModal() {
            if (!this.revisionNote.trim()) {
                this.toast = 'Perpindahan ke Revision dibatalkan - catatan revisi wajib diisi.';
                setTimeout(() => this.toast = '', 3000);
                this.revisionModal = null;
                return;
            }
            this.submitStatusChange(this.revisionModal.itemId, 'revision', { revision_note: this.revisionNote });
            this.revisionModal = null;
        },
        confirmPublicationModal() {
            if (!this.pubForm.published_at) {
                this.toast = 'Perpindahan ke Uploaded dibatalkan - tanggal publish wajib diisi.';
                setTimeout(() => this.toast = '', 3000);
                this.publicationModal = null;
                return;
            }
            this.submitStatusChange(this.publicationModal.itemId, 'uploaded', {
                platform_id: this.publicationModal.platformId,
                published_at: this.pubForm.published_at,
                post_url: this.pubForm.post_url,
                caption_final: this.pubForm.caption_final,
            });
            this.publicationModal = null;
        },
        submitStatusChange(itemId, toStatus, extra) {
            fetch(`/production-workflow/${itemId}/status`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ to_status: toStatus, ...extra }),
            })
            .then(async (res) => {
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Gagal memindahkan kartu');
                return data;
            })
            .then(() => {
                this.toast = 'Status berhasil diperbarui';
                setTimeout(() => this.toast = '', 2500);
                this.moveCardToColumn(itemId, toStatus);
            })
            .catch((err) => {
                this.toast = err.message;
                setTimeout(() => this.toast = '', 3000);
            });
        },
        // Pindahkan kartu ke kolom tujuan langsung di DOM, tanpa reload
        // halaman penuh (yang bikin "getaran"/flash tiap kali drag-drop).
        // Beberapa badge yang cuma relevan buat status tertentu (footage
        // toggle, "Klien Setuju") disembunyikan kalau kartu keluar dari
        // kolom asalnya - bukan dibuat ulang, karena markup-nya butuh data
        // dari server yang nggak ada di sisi client.
        moveCardToColumn(itemId, toStatus) {
            const card = document.querySelector(`[data-item-id="${itemId}"]`);
            const targetColumn = document.querySelector(`[data-column="${toStatus}"]`);
            if (!card || !targetColumn) { return; }

            const sourceColumn = card.closest('.kanban-column');

            if (toStatus !== 'in_progress') {
                card.querySelector('[data-footage-toggle]')?.remove();
            }
            if (toStatus !== 'waiting_review') {
                card.querySelector('[data-client-approved-badge]')?.remove();
            }

            // Taruh di paling atas kolom tujuan - biar kelihatan langsung
            // kartu mana yang baru saja dipindahkan (urutan default: terbaru di atas).
            targetColumn.insertBefore(card, targetColumn.firstElementChild || null);
            window.Alpine?.initTree(card);

            this.updateColumnCount(sourceColumn);
            this.updateColumnCount(targetColumn);
            this.toggleEmptyPlaceholder(sourceColumn);
            this.toggleEmptyPlaceholder(targetColumn);
        },
        toggleEmptyPlaceholder(column) {
            if (!column) return;
            const hasCards = column.querySelectorAll(':scope > [data-item-id]').length > 0;
            let placeholder = column.querySelector(':scope > [data-empty-placeholder]');
            if (!hasCards && !placeholder) {
                placeholder = document.createElement('div');
                placeholder.setAttribute('data-empty-placeholder', '');
                placeholder.className = 'text-center p-6 border border-dashed border-[#dadfe0] rounded-lg';
                placeholder.innerHTML = '<span class="material-symbols-outlined text-[#d4d7db] text-[28px] mb-2 block">note_add</span><p class="text-xs text-[#c3c7cb]">Drop cards here</p>';
                column.appendChild(placeholder);
            } else if (hasCards && placeholder) {
                placeholder.remove();
            }
        },
        updateColumnCount(column) {
            if (!column) return;
            const count = column.querySelectorAll(':scope > [data-item-id]').length;
            const badge = column.closest('.rounded-xl')?.querySelector('[data-column-count]');
            if (badge) badge.textContent = count;
        },
        matchesSearch(title) {
            if (!this.search) return true;
            return title.toLowerCase().includes(this.search.toLowerCase());
        },
        markFootageCaptured(itemId, event) {
            const btn = event.currentTarget;
            const dateInput = btn.closest('[data-footage-toggle]')?.querySelector('[data-take-date]');
            this.submitFootageCaptured(itemId, btn, 'PATCH', 'Video ditandai sudah di-take', 'Gagal menandai footage sudah di-take', {
                footage_captured_at: dateInput?.value || null,
            });
        },
        unmarkFootageCaptured(itemId, event) {
            this.submitFootageCaptured(itemId, event.currentTarget, 'DELETE', 'Penandaan sudah di-take dibatalkan', 'Gagal membatalkan penandaan');
        },
        // Tanpa reload halaman penuh (bikin "getaran"/flash) - fade-out
        // wrapper, tukar markup badge<->tombol setelah request sukses, lalu
        // fade-in lagi. Alpine.initTree dipanggil biar x-on di markup baru
        // ikut ke-bind (Alpine tidak otomatis scan HTML yang disuntik manual).
        submitFootageCaptured(itemId, btn, method, successMessage, errorMessage, body = null) {
            const wrapper = btn.closest('[data-footage-toggle]');
            btn.disabled = true;

            fetch(`/content-items/${itemId}/footage-captured`, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: body ? JSON.stringify(body) : undefined,
            })
            .then((res) => {
                if (!res.ok) throw new Error(errorMessage);
                return res.json();
            })
            .then((data) => {
                this.toast = successMessage;
                setTimeout(() => this.toast = '', 2500);

                if (!wrapper) return;
                wrapper.classList.add('footage-toggle-fade-out');
                setTimeout(() => {
                    const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
                    const nowValue = now.toISOString().slice(0, 10) + ' ' + now.toISOString().slice(11, 16);
                    wrapper.innerHTML = method === 'PATCH'
                        ? `<div class="flex items-center justify-between gap-1 text-[10px] font-medium text-[#0f7a5f] bg-[#f0f5f4] px-2 py-1.5 rounded-lg">
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[13px]">check_circle</span> Sudah di-take (${data.footage_captured_at ?? ''})
                                </span>
                                <button type="button" x-on:click.stop="unmarkFootageCaptured(${itemId}, $event)"
                                    class="text-[#8a6423] hover:underline shrink-0">Batalkan</button>
                            </div>`
                        : `<div class="flex items-center gap-1">
                                <input type="text" data-take-date data-flatpickr="datetime" autocomplete="off" value="${nowValue}"
                                    x-on:click.stop x-on:mousedown.stop
                                    class="flex-1 min-w-0 border border-[#eef0f4] rounded-lg px-1.5 py-1 text-[10px] focus:outline-none focus:border-[#044b46]/40">
                                <button type="button" x-on:click.stop="markFootageCaptured(${itemId}, $event)" title="Tandai Sudah Di-take"
                                    class="flex items-center justify-center shrink-0 border border-[#044b46]/30 text-[#044b46] w-7 h-7 rounded-lg hover:bg-[#f0f5f4] transition-colors">
                                    <span class="material-symbols-outlined text-[15px]">videocam</span>
                                </button>
                            </div>`;
                    window.Alpine?.initTree(wrapper);
                    window.initFlatpickrs?.(wrapper);
                    wrapper.classList.remove('footage-toggle-fade-out');
                }, 180);
            })
            .catch((err) => {
                btn.disabled = false;
                this.toast = err.message;
                setTimeout(() => this.toast = '', 3000);
            });
        },
    }
}
</script>
@endsection