@extends('layouts.app')

@section('title', 'Produksi')

@section('content')
@if (($tab ?? 'board') === 'board' && ! ($viewExplicit ?? false))
    {{-- Drag-and-drop kartu Kanban pakai HTML5 Drag & Drop API, yang tidak
         berfungsi di layar sentuh - jadi kalau belum ada pilihan Papan/List
         eksplisit dan layarnya sekecil HP, arahkan ke tampilan List (yang
         sudah sepenuhnya responsif, termasuk filter & pencarian) sebelum
         papan Kanban sempat ke-render. Pilihan eksplisit tetap dihormati -
         script ini cuma jalan kalau belum ada ?view= di URL. --}}
    <script>
        (function () {
            if (window.matchMedia('(max-width: 639px)').matches) {
                var url = new URL(window.location.href);
                url.searchParams.set('view', 'list');
                window.location.replace(url.toString());
            }
        })();
    </script>
@endif
<div class="flex flex-col {{ ($tab === 'board' && ($view ?? 'board') === 'board') ? 'h-[calc(100vh-64px)]' : '' }}">

    <header class="px-4 sm:px-6 lg:px-8 pt-5 flex-shrink-0">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[var(--text-primary)]">Produksi</h1>
                <p class="text-[var(--text-secondary)] text-sm mt-1">Alur produksi, revisi, dan riwayat tayang konten.</p>
            </div>
        </div>

        {{-- Tab switcher --}}
        <div class="flex items-center h-10 bg-[var(--surface-muted)] rounded-lg p-1 w-fit mb-5">
            <a href="{{ route('production-workflow.index') }}"
               class="flex items-center h-full text-xs font-medium px-4 rounded-md transition-colors {{ $tab === 'board' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]' }}">
                Papan Alur Produksi
            </a>
            <a href="{{ route('production-workflow.index', ['tab' => 'revisions']) }}"
               class="flex items-center h-full text-xs font-medium px-4 rounded-md transition-colors {{ $tab === 'revisions' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]' }}">
                Revisi
            </a>
            <a href="{{ route('production-workflow.index', ['tab' => 'published']) }}"
               class="flex items-center h-full text-xs font-medium px-4 rounded-md transition-colors {{ $tab === 'published' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]' }}">
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
                    class="border border-[var(--border)] rounded-lg px-3 py-2 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 h-[40px]">
                <option value="">Semua Klien</option>
                @foreach ($clientOptions as $client)
                    <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>{{ $client->name }}</option>
                @endforeach
            </select>

            <div class="relative flex-1 min-w-[160px]">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[19px]">search</span>
                <input x-model="search" class="pl-10 pr-4 h-[40px] bg-[var(--surface-card)] border border-[var(--border)] rounded-lg text-sm focus:outline-none focus:border-[#044b46]/40 w-full sm:w-64" placeholder="Cari konten..." type="text">
            </div>

            @if ($view === 'board')
                <button type="button" @click="toggleRiskSort()"
                        class="flex items-center gap-1.5 h-[40px] px-3.5 rounded-lg text-sm font-medium border transition-colors whitespace-nowrap"
                        :class="riskSortActive ? 'bg-[var(--danger-tint)] border-[var(--danger-border)] text-[var(--danger-text)]' : 'bg-[var(--surface-card)] border-[var(--border)] text-[var(--text-secondary)]'">
                    <span class="material-symbols-outlined text-[17px]">sort</span>
                    Risiko Tertinggi
                </button>
            @else
                @php $statusLabels = \App\Support\WorkflowTransitions::labels(); @endphp
                <select name="status" form="filter-form" onchange="this.form.submit()"
                        class="border border-[var(--border)] rounded-lg px-3 py-2 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 h-[40px]">
                    <option value="">Semua Status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" {{ $selectedStatus === $status ? 'selected' : '' }}>{{ $statusLabels[$status] ?? $status }}</option>
                    @endforeach
                </select>
            @endif

            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[17px] pointer-events-none">calendar_month</span>
                <input type="text" name="month" form="filter-form" value="{{ $selectedMonth }}"
                       data-flatpickr="month-combined" data-autosubmit="true" placeholder="Semua Bulan"
                       class="border border-[var(--border)] rounded-lg pl-9 pr-3 py-2 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 h-[40px] w-[150px]" readonly>
            </div>
            @if ($selectedMonth)
                <a href="{{ request()->fullUrlWithQuery(['month' => null]) }}" class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-primary)]">Reset bulan</a>
            @endif

            <div class="flex items-center h-9 bg-[var(--surface-muted)] rounded-lg p-1 sm:ml-auto">
                <a href="{{ request()->fullUrlWithQuery(['view' => 'board']) }}"
                   class="flex items-center h-full text-xs font-medium px-3 rounded-md {{ $view === 'board' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)]' }}">
                    <span class="material-symbols-outlined text-[15px] align-middle">view_kanban</span> Papan
                </a>
                <a href="{{ request()->fullUrlWithQuery(['view' => 'list']) }}"
                   class="flex items-center h-full text-xs font-medium px-3 rounded-md {{ $view === 'list' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)]' }}">
                    <span class="material-symbols-outlined text-[15px] align-middle">view_list</span> List
                </a>
            </div>
        </div>
    </div>

    @if ($view === 'list')
        @include('production-workflow.partials.list-tab')
    @else

    <div x-show="toast" x-transition role="status" aria-live="polite" class="fixed top-6 right-6 z-50 bg-[var(--overlay-solid)] text-white px-4 py-2.5 rounded-lg shadow-lg text-sm" x-text="toast" style="display: none;"></div>

    <div class="flex-1 overflow-x-auto p-4 flex items-start gap-4 bg-[var(--surface-page)] thin-autohide-scrollbar">
        @php $statusLabels = \App\Support\WorkflowTransitions::labels(); @endphp

        @foreach ($statuses as $status)
            @php
                $columnColors = ['#044b46', '#3452a8', '#0e7490', '#b8873a', '#b3427e', '#7c5cbf', '#0f7a5f', '#b3423e', '#0a8f76', '#6b7280'];
                $dotColor = $columnColors[$loop->index % count($columnColors)];
            @endphp
            <div class="flex-shrink-0 w-[290px] h-full flex flex-col bg-[var(--surface-card)] rounded-xl border border-[var(--border)]"
                 x-on:dragover.prevent x-on:drop="onDrop($event, '{{ $status }}')">

                <div class="p-3 border-b border-[var(--border)] flex items-center justify-between flex-shrink-0">
                    <h3 class="text-xs font-semibold text-[var(--text-secondary)] flex items-center gap-2">
                        <div class="w-1.5 h-1.5 rounded-full" style="background-color: {{ $dotColor }}"></div>
                        {{ $statusLabels[$status] }}
                    </h3>
                    <span class="badge badge-neutral" data-column-count>{{ $board[$status]->count() }}</span>
                </div>

                <div class="p-2.5 overflow-y-auto flex-1 space-y-2.5 kanban-column thin-autohide-scrollbar" style="min-height: 100px;"
                     data-column="{{ $status }}"
                     x-bind:data-expanded="(expandedColumns['{{ $status }}'] || search.length > 0) ? 'true' : 'false'">
                    @forelse ($board[$status] as $item)
                        @php
                            $isOverdue = $item->workflow->is_overdue;
                            $isPinned = $pinnedIds->contains($item->id);
                        @endphp

                        <div draggable="{{ $canUpdateWorkflow ? 'true' : 'false' }}"
                             x-on:dragstart="onDragStart($event, {{ $item->id }}, '{{ addslashes($item->title) }}', {{ $item->platform_id ?? 'null' }})"
                             x-show="matchesSearch('{{ addslashes($item->title) }}')"
                             data-risk="{{ $item->latestDelayRisk->risk_score ?? 0 }}" data-order="{{ $item->boardOrder }}"
                             data-item-id="{{ $item->id }}" data-content-type="{{ $item->contentType->name ?? '' }}"
                             data-footage-captured-at="{{ $item->footage_captured_at?->format('d M Y, H:i') }}"
                             class="rounded-lg border shadow-sm hover:shadow-md transition-shadow overflow-hidden {{ $canUpdateWorkflow ? 'cursor-move' : '' }} {{ $isOverdue ? 'bg-[var(--danger-tint)] border-[var(--danger-border-strong)]' : ($isPinned ? 'bg-[var(--brand-tint)] border-[var(--brand-border)]' : 'bg-[var(--surface-card)] border-[var(--border)]') }}">

                            @if ($item->is_urgent)
                                <div class="bg-[var(--danger-solid)] text-white text-[10px] font-bold uppercase tracking-wider py-1 px-2 overflow-hidden whitespace-nowrap flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-[12px] shrink-0">bolt</span>
                                    <span>Jobdesk Tambahan &bull; Jobdesk Tambahan &bull; Jobdesk Tambahan &bull; Jobdesk Tambahan &bull; Jobdesk Tambahan</span>
                                </div>
                            @endif

                            <div class="p-3.5">
                            <div class="flex justify-between items-start gap-1 flex-wrap mb-2">
                                <span class="text-[11px] bg-[var(--surface-muted)] text-[var(--text-secondary)] px-2 py-1 rounded inline-flex items-center gap-1">
                                    @if ($isPinned)
                                        <span class="material-symbols-outlined text-[12px] text-[var(--brand)]" style="font-variation-settings: 'FILL' 1">push_pin</span>
                                    @endif
                                    {{ $item->contentType->name ?? '-' }}
                                </span>
                                @if ($item->workflow->client_reviewed_at && $status === 'waiting_review')
                                    <span data-client-approved-badge class="text-[10px] text-[var(--success-text)] font-semibold flex items-center gap-1 bg-[var(--success-tint)] px-2 py-0.5 rounded whitespace-nowrap">
                                        <span class="material-symbols-outlined text-[12px]">check_circle</span> Klien Setuju
                                    </span>
                                @endif
                                @if ($item->latestDelayRisk)
                                    @php
                                        $riskBadgeClasses = [
                                            'high' => 'badge-danger',
                                            'medium' => 'badge-warning',
                                            'low' => 'badge-success',
                                        ];
                                        $riskBadgeClass = $riskBadgeClasses[$item->latestDelayRisk->risk_level] ?? $riskBadgeClasses['low'];
                                    @endphp
                                    <span class="badge {{ $riskBadgeClass }} font-semibold ml-1" title="{{ $item->latestDelayRisk->top_factor }}">
                                        Risiko {{ $item->latestDelayRisk->risk_score }}%
                                    </span>
                                @endif
                            </div>
                            <h4 class="text-sm font-semibold text-[var(--text-primary)] mb-1">{{ $item->title }}</h4>
                            <p class="text-xs text-[var(--text-muted)] mb-3">{{ $item->client->name ?? '-' }}</p>

                            {{-- Video di In Progress bisa selesai syuting belakangan setelah proses
                                 edit sudah/lagi berjalan - penanda ini bantu tim lihat progres riil
                                 tanpa harus nunggu/pindah status dulu. --}}
                            @if ($status === 'in_progress' && ($item->contentType->name ?? '') === 'Video')
                                <div data-footage-toggle data-item-id="{{ $item->id }}" class="footage-toggle-fade mb-2.5">
                                    @if ($item->footage_captured_at)
                                        <div class="flex items-center justify-between gap-1 text-[10px] font-medium text-[var(--success-text)] bg-[var(--success-tint)] px-2 py-1.5 rounded-lg">
                                            <span class="flex items-center gap-1">
                                                <span class="material-symbols-outlined text-[13px]">check_circle</span> Sudah di-take ({{ $item->footage_captured_at->format('d M Y, H:i') }})
                                            </span>
                                            <button type="button" x-on:click.stop="unmarkFootageCaptured({{ $item->id }}, $event)"
                                                class="text-[var(--warning-text)] hover:underline shrink-0">Batalkan</button>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-1">
                                            <input type="text" data-take-date data-flatpickr="datetime" autocomplete="off" value="{{ now()->format('Y-m-d H:i') }}"
                                                x-on:click.stop x-on:mousedown.stop
                                                class="bg-[var(--surface-card)] flex-1 min-w-0 border border-[var(--border)] rounded-lg px-1.5 py-1 text-[10px] focus:outline-none focus:border-[#044b46]/40">
                                            <span x-data="tooltipHover('Tandai Sudah Di-take')" class="contents">
                                                <button type="button" x-on:click.stop="markFootageCaptured({{ $item->id }}, $event)"
                                                    x-on:mouseenter="onEnter($event)" x-on:mouseleave="onLeave()"
                                                    aria-label="Tandai Sudah Di-take"
                                                    class="flex items-center justify-center shrink-0 border border-[#044b46]/30 text-[var(--brand)] w-7 h-7 rounded-lg hover:bg-[var(--brand-tint)] transition-colors">
                                                    <span class="material-symbols-outlined text-[15px]">videocam</span>
                                                </button>
                                                @include('components.action-tooltip')
                                            </span>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            {{-- Info jadwal upload di posisi yang sama dengan penanda "Sudah
                                 Di-take" - biar tim langsung lihat rencana tayangnya tanpa
                                 harus buka detail konten. --}}
                            @if ($status === 'scheduled' && $item->scheduled_upload_at)
                                <div data-scheduled-info data-item-id="{{ $item->id }}" class="flex items-center gap-1 text-[10px] font-medium text-[var(--brand)] bg-[var(--brand-tint)] px-2 py-1.5 rounded-lg mb-2.5">
                                    <span class="material-symbols-outlined text-[13px]">event_available</span> Terjadwal tayang {{ $item->scheduled_upload_at->format('d M Y, H:i') }}
                                </div>
                            @endif

                            <div data-card-footer class="flex items-center justify-between pt-2.5 border-t border-[var(--surface-muted)]">
                                <div class="flex items-center gap-1 {{ $isOverdue ? 'text-[var(--danger-text)]' : 'text-[var(--text-muted)]' }}">
                                    <span class="material-symbols-outlined text-[15px]">{{ $isOverdue ? 'warning' : 'schedule' }}</span>
                                    <span class="text-xs {{ $isOverdue ? 'font-semibold' : '' }}">{{ $item->deadline_at->format('M d') }}</span>
                                </div>

                                <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                                    <div class="flex items-center -space-x-2 cursor-pointer" x-on:mouseenter="open = true" x-on:mouseleave="open = false" x-on:click.stop="open = !open">
                                        @forelse ($item->assignments->take(3) as $assignment)
                                            @if ($assignment->user->avatar_url)
                                                <img src="{{ $assignment->user->avatar_url }}" alt="{{ $assignment->user->name }}" referrerpolicy="no-referrer" class="w-6 h-6 rounded-full ring-2 ring-[var(--surface-card)] object-cover">
                                            @else
                                                <div class="w-6 h-6 rounded-full bg-[var(--brand-solid)] text-white text-[10px] font-semibold flex items-center justify-center ring-2 ring-[var(--surface-card)]">
                                                    {{ strtoupper(substr($assignment->user->name, 0, 1)) }}
                                                </div>
                                            @endif
                                        @empty
                                            @php $legacyPic = $picResolver->resolve($item); @endphp
                                            @if ($legacyPic['name'])
                                                <div class="w-6 h-6 rounded-full bg-[var(--surface-muted)] text-[var(--text-secondary)] text-[10px] font-semibold flex items-center justify-center ring-2 ring-[var(--surface-card)]" title="{{ $legacyPic['name'] }} (belum memiliki akun)">
                                                    {{ strtoupper(substr($legacyPic['name'], 0, 1)) }}
                                                </div>
                                            @else
                                                <span class="text-xs text-[var(--text-muted)] italic">Belum ada Penanggung Jawab</span>
                                            @endif
                                        @endforelse
                                        @if ($item->assignments->count() > 3)
                                            <div class="w-6 h-6 rounded-full bg-[var(--surface-muted)] text-[var(--text-secondary)] text-[9px] font-semibold flex items-center justify-center ring-2 ring-[var(--surface-card)]">+{{ $item->assignments->count() - 3 }}</div>
                                        @endif
                                    </div>

                                    <div x-show="open" x-transition x-on:mouseenter="open = true" x-on:mouseleave="open = false"
                                         class="absolute z-10 bottom-full right-0 mb-2 w-56 bg-[var(--surface-card)] border border-[var(--border)] rounded-lg shadow-lg p-3" style="display: none;">
                                        <p class="text-[10px] font-semibold text-[var(--text-muted)] uppercase mb-2">Penanggung Jawab</p>
                                        <div class="space-y-2">
                                            @forelse ($item->assignments as $assignment)
                                                <div class="flex items-center gap-2">
                                                    @if ($assignment->user->avatar_url)
                                                        <img src="{{ $assignment->user->avatar_url }}" alt="" referrerpolicy="no-referrer" class="w-7 h-7 rounded-full object-cover">
                                                    @else
                                                        <div class="w-7 h-7 rounded-full bg-[var(--brand-solid)] text-white text-xs font-semibold flex items-center justify-center">
                                                            {{ strtoupper(substr($assignment->user->name, 0, 1)) }}
                                                        </div>
                                                    @endif
                                                    <div>
                                                        <p class="text-xs font-medium text-[var(--text-primary)]">{{ $assignment->user->name }}</p>
                                                        <p class="text-[10px] text-[var(--text-muted)]">{{ ucwords(str_replace('_', ' ', $assignment->assignment_role)) }}</p>
                                                    </div>
                                                </div>
                                            @empty
                                                @if ($legacyPic['name'])
                                                    <div class="flex items-center gap-2">
                                                        <div class="w-7 h-7 rounded-full bg-[var(--surface-muted)] text-[var(--text-secondary)] text-xs font-semibold flex items-center justify-center">
                                                            {{ strtoupper(substr($legacyPic['name'], 0, 1)) }}
                                                        </div>
                                                        <div>
                                                            <p class="text-xs font-medium text-[var(--text-primary)]">{{ $legacyPic['name'] }}</p>
                                                            <p class="text-[10px] text-[var(--text-muted)] italic">PIC operasional - belum memiliki akun</p>
                                                        </div>
                                                    </div>
                                                @else
                                                    <p class="text-xs text-[var(--text-muted)] italic">Belum ada Penanggung Jawab ditugaskan</p>
                                                @endif
                                            @endforelse
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-2.5 pt-2 border-t border-[var(--surface-muted)] text-right">
                                <a href="{{ route('content-items.show', $item) }}" class="text-xs text-[var(--brand)] font-medium hover:underline">Lihat Detail →</a>
                            </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center p-6 border border-dashed border-[var(--border-strong)] rounded-lg">
                            <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">note_add</span>
                            <p class="text-xs text-[var(--text-muted)]">Drop cards here</p>
                        </div>
                    @endforelse

                    @if ($board[$status]->count() > 8)
                        <button type="button" x-show="!search"
                                @click="expandedColumns['{{ $status }}'] = !expandedColumns['{{ $status }}']"
                                class="column-more-toggle w-full text-center text-xs font-medium text-[var(--brand)] hover:underline py-1.5">
                            <span x-show="!expandedColumns['{{ $status }}']">+{{ $board[$status]->count() - 8 }} lainnya</span>
                            <span x-show="expandedColumns['{{ $status }}']" x-cloak>Sembunyikan</span>
                        </button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Modal drag-drop: waiting_review -> revision butuh catatan revisi dulu --}}
    <div x-show="revisionModal" x-cloak x-on:keydown.escape.window="revisionModal = null" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-[#14181a]/40" @click="revisionModal = null"></div>
        <div x-show="revisionModal" x-transition role="dialog" aria-modal="true" aria-labelledby="revision-note-modal-title" x-trap="!!revisionModal" class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                <div>
                    <h3 id="revision-note-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)]">Tambah Catatan Revisi</h3>
                    <p class="text-xs text-[var(--text-muted)] mt-0.5" x-text="revisionModal?.title"></p>
                </div>
                <button type="button" @click="revisionModal = null" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                    <span class="material-symbols-outlined text-[19px]">close</span>
                </button>
            </div>
            <div class="px-6 py-5">
                <label for="kanban_revision_note" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Catatan Revisi</label>
                <textarea id="kanban_revision_note" x-model="revisionNote" rows="3" placeholder="Tulis catatan revisi..."
                    class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40"></textarea>
            </div>
            <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                <button type="button" @click="confirmRevisionModal()"
                        class="btn-primary">
                    Pindahkan ke Status Revisi
                </button>
                <button type="button" @click="revisionModal = null" class="btn-secondary">
                    Batal
                </button>
            </div>
        </div>
    </div>

    {{-- Modal drag-drop: scheduled -> uploaded butuh data publikasi dulu --}}
    <div x-show="publicationModal" x-cloak x-on:keydown.escape.window="publicationModal = null" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-[#14181a]/40" @click="publicationModal = null"></div>
        <div x-show="publicationModal" x-transition role="dialog" aria-modal="true" aria-labelledby="publication-modal-title" x-trap="!!publicationModal" class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                <div>
                    <h3 id="publication-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)]">Catat Publikasi</h3>
                    <p class="text-xs text-[var(--text-muted)] mt-0.5" x-text="publicationModal?.title"></p>
                </div>
                <button type="button" @click="publicationModal = null" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                    <span class="material-symbols-outlined text-[19px]">close</span>
                </button>
            </div>
            <div class="px-6 py-5 space-y-3">
                <div>
                    <label for="kanban_pub_published_at" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Tanggal Publish</label>
                    <input id="kanban_pub_published_at" type="text" x-model="pubForm.published_at" data-flatpickr="datetime" autocomplete="off"
                        class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                </div>
                <div>
                    <label for="kanban_pub_post_url" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Link Post</label>
                    <input id="kanban_pub_post_url" type="url" x-model="pubForm.post_url" placeholder="https://..."
                        class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                </div>
                <div>
                    <label for="kanban_pub_caption_final" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Caption Final</label>
                    <textarea id="kanban_pub_caption_final" x-model="pubForm.caption_final" rows="2"
                        class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40"></textarea>
                </div>
            </div>
            <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                <button type="button" @click="confirmPublicationModal()"
                        class="btn-primary">
                    Tandai Uploaded
                </button>
                <button type="button" @click="publicationModal = null" class="btn-secondary">
                    Batal
                </button>
            </div>
        </div>
    </div>

    {{-- Modal drag-drop: approved -> scheduled butuh rencana tanggal & jam upload dulu --}}
    <div x-show="scheduledModal" x-cloak x-on:keydown.escape.window="scheduledModal = null" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-[#14181a]/40" @click="scheduledModal = null"></div>
        <div x-show="scheduledModal" x-transition role="dialog" aria-modal="true" aria-labelledby="scheduled-modal-title" x-trap="!!scheduledModal" class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                <div>
                    <h3 id="scheduled-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)]">Jadwalkan Upload</h3>
                    <p class="text-xs text-[var(--text-muted)] mt-0.5" x-text="scheduledModal?.title"></p>
                </div>
                <button type="button" @click="scheduledModal = null" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                    <span class="material-symbols-outlined text-[19px]">close</span>
                </button>
            </div>
            <div class="px-6 py-5">
                <label for="kanban_scheduled_upload_at" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Rencana Tanggal &amp; Jam Upload</label>
                <input id="kanban_scheduled_upload_at" type="text" x-model="scheduledUploadAt" data-flatpickr="datetime" autocomplete="off"
                    class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
            </div>
            <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                <button type="button" @click="confirmScheduledModal()"
                        class="btn-primary">
                    Jadwalkan
                </button>
                <button type="button" @click="scheduledModal = null" class="btn-secondary">
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
        scheduledModal: null,
        scheduledUploadAt: '',
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

            if (toStatus === 'scheduled') {
                this.scheduledUploadAt = '';
                this.scheduledModal = { itemId, title };
                return;
            }

            this.submitStatusChange(itemId, toStatus, {});
        },
        confirmRevisionModal() {
            if (!this.revisionNote.trim()) {
                this.toast = 'Perpindahan ke Revision dibatalkan - catatan revisi wajib diisi.';
                setTimeout(() => this.toast = '', 3000);
                return;
            }
            // Modal cuma ditutup & catatan cuma direset setelah server
            // konfirmasi sukses (lewat callback onSuccess) - kalau ditutup
            // duluan di sini, catatan yang udah ditulis user bakal hilang
            // percuma tiap kali transisi ditolak server (mis. status udah
            // berubah duluan dari device lain).
            this.submitStatusChange(this.revisionModal.itemId, 'revision', { revision_note: this.revisionNote }, () => {
                this.revisionModal = null;
                this.revisionNote = '';
            });
        },
        confirmPublicationModal() {
            if (!this.pubForm.published_at) {
                this.toast = 'Perpindahan ke Uploaded dibatalkan - tanggal publish wajib diisi.';
                setTimeout(() => this.toast = '', 3000);
                return;
            }
            // Sama seperti confirmRevisionModal() - modal & form cuma
            // dibersihkan setelah sukses, biar data yang udah diisi user
            // nggak hilang kalau server menolak transisinya.
            this.submitStatusChange(this.publicationModal.itemId, 'uploaded', {
                platform_id: this.publicationModal.platformId,
                published_at: this.pubForm.published_at,
                post_url: this.pubForm.post_url,
                caption_final: this.pubForm.caption_final,
            }, () => {
                this.publicationModal = null;
            });
        },
        confirmScheduledModal() {
            if (!this.scheduledUploadAt) {
                this.toast = 'Perpindahan ke Terjadwal Tayang dibatalkan - rencana tanggal & jam upload wajib diisi.';
                setTimeout(() => this.toast = '', 3000);
                return;
            }
            this.submitStatusChange(this.scheduledModal.itemId, 'scheduled', { scheduled_upload_at: this.scheduledUploadAt }, () => {
                this.scheduledModal = null;
            });
        },
        submitStatusChange(itemId, toStatus, extra, onSuccess) {
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
            .then((data) => {
                this.toast = 'Status berhasil diperbarui';
                setTimeout(() => this.toast = '', 2500);
                this.moveCardToColumn(itemId, toStatus, data);
                if (onSuccess) onSuccess();
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
        moveCardToColumn(itemId, toStatus, data = {}) {
            const card = document.querySelector(`[data-item-id="${itemId}"]`);
            const targetColumn = document.querySelector(`[data-column="${toStatus}"]`);
            if (!card || !targetColumn) { return; }

            const sourceColumn = card.closest('.kanban-column');
            const footer = card.querySelector('[data-card-footer]');

            if (toStatus !== 'in_progress') {
                card.querySelector('[data-footage-toggle]')?.remove();
            } else if (footer && !card.querySelector('[data-footage-toggle]') && card.dataset.contentType === 'Video') {
                // Video baru masuk Sedang Dikerjakan lewat drag-drop - langsung
                // tampilkan penanda "Sudah Di-take" tanpa nunggu refresh.
                // Ngikutin footage_captured_at yang sudah ada di kartu, kalau
                // sebelumnya sempat ditandai lalu keluar-masuk in_progress lagi.
                const capturedAt = card.dataset.footageCapturedAt;
                const wrapper = document.createElement('div');
                wrapper.setAttribute('data-footage-toggle', '');
                wrapper.setAttribute('data-item-id', itemId);
                wrapper.className = 'footage-toggle-fade mb-2.5';
                wrapper.innerHTML = capturedAt ? this.footageCapturedMarkup(itemId, capturedAt) : this.footageInputMarkup(itemId);
                footer.parentElement.insertBefore(wrapper, footer);
                window.Alpine?.initTree(wrapper);
                window.initFlatpickrs?.(wrapper);
            }

            if (toStatus !== 'waiting_review') {
                card.querySelector('[data-client-approved-badge]')?.remove();
            }

            if (toStatus !== 'scheduled') {
                card.querySelector('[data-scheduled-info]')?.remove();
            } else if (footer && !card.querySelector('[data-scheduled-info]') && data.scheduled_upload_at) {
                // Sama seperti footage toggle di atas - info jadwal tayang
                // langsung tampil begitu kartu masuk kolom Terjadwal Tayang,
                // tanpa nunggu refresh.
                const info = document.createElement('div');
                info.setAttribute('data-scheduled-info', '');
                info.setAttribute('data-item-id', itemId);
                info.className = 'flex items-center gap-1 text-[10px] font-medium text-[var(--brand)] bg-[var(--brand-tint)] px-2 py-1.5 rounded-lg mb-2.5';
                info.innerHTML = `<span class="material-symbols-outlined text-[13px]">event_available</span> Terjadwal tayang ${data.scheduled_upload_at}`;
                footer.parentElement.insertBefore(info, footer);
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
        // Markup badge/form footage-toggle - dipakai bareng oleh
        // moveCardToColumn() (drag masuk in_progress) dan
        // submitFootageCaptured() (tandai/batalkan manual), biar dua jalur
        // itu nggak punya salinan HTML yang bisa kebablasan beda.
        footageInputMarkup(itemId, dateValue = null) {
            const value = dateValue || (() => {
                const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
                return now.toISOString().slice(0, 10) + ' ' + now.toISOString().slice(11, 16);
            })();
            return `<div class="flex items-center gap-1">
                        <input type="text" data-take-date data-flatpickr="datetime" autocomplete="off" value="${value}"
                            x-on:click.stop x-on:mousedown.stop
                            class="bg-[var(--surface-card)] flex-1 min-w-0 border border-[var(--border)] rounded-lg px-1.5 py-1 text-[10px] focus:outline-none focus:border-[#044b46]/40">
                        <span x-data="tooltipHover('Tandai Sudah Di-take')" class="contents">
                            <button type="button" x-on:click.stop="markFootageCaptured(${itemId}, $event)"
                                x-on:mouseenter="onEnter($event)" x-on:mouseleave="onLeave()"
                                aria-label="Tandai Sudah Di-take"
                                class="flex items-center justify-center shrink-0 border border-[#044b46]/30 text-[var(--brand)] w-7 h-7 rounded-lg hover:bg-[var(--brand-tint)] transition-colors">
                                <span class="material-symbols-outlined text-[15px]">videocam</span>
                            </button>
                            <template x-teleport="body">
                                <div x-show="show" x-cloak
                                    class="pointer-events-none fixed z-[100] whitespace-nowrap"
                                    :style="\`top: \${top}px; left: \${left}px; transform: translate(-50%, -100%);\`">
                                    <div class="relative bg-[var(--brand-solid)] text-white text-xs font-medium px-2.5 py-1.5 rounded-md shadow-lg">
                                        <span x-text="text"></span>
                                        <span class="absolute top-full left-1/2 -translate-x-1/2 w-0 h-0 border-x-[5px] border-x-transparent border-t-[6px] border-t-[var(--brand)]"></span>
                                    </div>
                                </div>
                            </template>
                        </span>
                    </div>`;
        },
        footageCapturedMarkup(itemId, dateStr) {
            return `<div class="flex items-center justify-between gap-1 text-[10px] font-medium text-[var(--success-text)] bg-[var(--success-tint)] px-2 py-1.5 rounded-lg">
                        <span class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-[13px]">check_circle</span> Sudah di-take (${dateStr})
                        </span>
                        <button type="button" x-on:click.stop="unmarkFootageCaptured(${itemId}, $event)"
                            class="text-[var(--warning-text)] hover:underline shrink-0">Batalkan</button>
                    </div>`;
        },
        toggleEmptyPlaceholder(column) {
            if (!column) return;
            const hasCards = column.querySelectorAll(':scope > [data-item-id]').length > 0;
            let placeholder = column.querySelector(':scope > [data-empty-placeholder]');
            if (!hasCards && !placeholder) {
                placeholder = document.createElement('div');
                placeholder.setAttribute('data-empty-placeholder', '');
                placeholder.className = 'text-center p-6 border border-dashed border-[var(--border-strong)] rounded-lg';
                placeholder.innerHTML = '<span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">note_add</span><p class="text-xs text-[var(--text-muted)]">Drop cards here</p>';
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
                    wrapper.innerHTML = method === 'PATCH'
                        ? this.footageCapturedMarkup(itemId, data.footage_captured_at ?? '')
                        : this.footageInputMarkup(itemId);
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