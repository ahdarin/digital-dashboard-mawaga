@extends('layouts.app')

@section('title', 'Production Workflow Board')

@section('content')
<div x-data="kanbanBoard()" class="flex flex-col h-full">

    <header class="px-8 py-5 flex-shrink-0 flex items-center justify-between">
        <div>
            <h1 class="font-display text-[28px] font-semibold text-[#14181a]">Production Workflow Board</h1>
            <p class="text-sm text-[#9aa0a4] mt-1">Active Content Pipeline</p>
        </div>

        <div class="flex items-center gap-3">
            <select name="client_id" form="filter-client-form" onchange="this.form.submit()"
                    class="border border-[#eef0f4] rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40 h-[40px]">
                <option value="">Semua Client</option>
                @foreach ($clientOptions as $client)
                    <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>{{ $client->name }}</option>
                @endforeach
            </select>
            <form id="filter-client-form" method="GET" action="{{ route('production-workflow.index') }}"></form>

            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#c3c7cb] text-[19px]">search</span>
                <input x-model="search" class="pl-10 pr-4 h-[40px] bg-white border border-[#eef0f4] rounded-lg text-sm focus:outline-none focus:border-[#044b46]/40 w-64" placeholder="Search tasks..." type="text">
            </div>

            <!-- <a href="#" class="bg-[#044b46] text-white text-sm font-medium px-4 h-[40px] rounded-lg hover:bg-[#033b37] transition-colors flex items-center gap-2">
                <span class="material-symbols-outlined text-[17px]">add</span> New Task
            </a> -->
        </div>
    </header>

    <div x-show="toast" x-transition class="fixed top-6 right-6 z-50 bg-[#14181a] text-white px-4 py-2.5 rounded-lg shadow-lg text-sm" x-text="toast" style="display: none;"></div>

    <div class="flex-1 overflow-x-auto p-4 flex items-start gap-4 bg-[#f7f8fc]">
        @php $statusLabels = \App\Support\WorkflowTransitions::labels(); @endphp

        @foreach ($statuses as $status)
            @php
                $columnColors = ['#044b46', '#3452a8', '#0e7490', '#b8873a', '#b3427e', '#7c5cbf', '#0f7a5f', '#b3423e', '#0a8f76', '#6b7280'];
                $dotColor = $columnColors[$loop->index % count($columnColors)];
            @endphp
            <div class="flex-shrink-0 w-[290px] h-full flex flex-col bg-white rounded-xl border border-[#eef0f4]"
                 x-on:dragover.prevent x-on:drop="onDrop($event, '{{ $status }}')">

                <div class="p-3 border-b border-[#eef0f4] flex items-center justify-between">
                    <h3 class="text-xs font-semibold text-[#5c6266] flex items-center gap-2">
                        <div class="w-1.5 h-1.5 rounded-full" style="background-color: {{ $dotColor }}"></div>
                        {{ $statusLabels[$status] }}
                    </h3>
                    <span class="text-xs bg-[#f2f3f6] text-[#5c6266] px-2 py-0.5 rounded-full">{{ $board[$status]->count() }}</span>
                </div>

                <div class="p-2.5 overflow-y-auto flex-1 space-y-2.5" style="min-height: 100px;">
                    @forelse ($board[$status] as $item)
                        @php $isOverdue = $item->workflow->is_overdue; @endphp

                        <div draggable="true" x-on:dragstart="onDragStart($event, {{ $item->id }})"
                             x-show="matchesSearch('{{ addslashes($item->title) }}')"
                             class="bg-white p-3.5 rounded-lg border shadow-sm hover:shadow-md transition-shadow cursor-move {{ $isOverdue ? 'border-[#e39a96]' : 'border-[#eef0f4]' }}">

                            <div class="flex justify-between items-start mb-2">
                                <span class="text-[11px] bg-[#f2f3f6] text-[#5c6266] px-2 py-1 rounded">{{ $item->contentType->name ?? '-' }}</span>
                                @if ($isOverdue)
                                    <span class="text-[10px] text-[#b3423e] font-semibold flex items-center gap-1 bg-[#fdf2f1] px-2 py-0.5 rounded">
                                        <span class="material-symbols-outlined text-[12px]">warning</span> Overdue
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
                                            <span class="text-xs text-[#c3c7cb] italic">Belum ada PIC</span>
                                        @endforelse
                                        @if ($item->assignments->count() > 3)
                                            <div class="w-6 h-6 rounded-full bg-[#f2f3f6] text-[#5c6266] text-[9px] font-semibold flex items-center justify-center ring-2 ring-white">+{{ $item->assignments->count() - 3 }}</div>
                                        @endif
                                    </div>

                                    <div x-show="open" x-transition x-on:mouseenter="open = true" x-on:mouseleave="open = false"
                                         class="absolute z-10 bottom-full right-0 mb-2 w-56 bg-white border border-[#eef0f4] rounded-lg shadow-lg p-3" style="display: none;">
                                        <p class="text-[10px] font-semibold text-[#9aa0a4] uppercase mb-2">PIC Penugasan</p>
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
                                                <p class="text-xs text-[#9aa0a4] italic">Belum ada PIC ditugaskan</p>
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
                </div>
            </div>
        @endforeach
    </div>
</div>

<script>
function kanbanBoard() {
    return {
        search: '',
        toast: '',
        draggedItemId: null,
        onDragStart(event, itemId) {
            this.draggedItemId = itemId;
            event.dataTransfer.effectAllowed = 'move';
        },
        onDrop(event, toStatus) {
            if (!this.draggedItemId) return;
            const itemId = this.draggedItemId;
            this.draggedItemId = null;
            fetch(`/production-workflow/${itemId}/status`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ to_status: toStatus }),
            })
            .then(async (res) => {
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Gagal memindahkan kartu');
                return data;
            })
            .then(() => {
                this.toast = 'Status berhasil diperbarui';
                setTimeout(() => window.location.reload(), 600);
            })
            .catch((err) => {
                this.toast = err.message;
                setTimeout(() => this.toast = '', 3000);
            });
        },
        matchesSearch(title) {
            if (!this.search) return true;
            return title.toLowerCase().includes(this.search.toLowerCase());
        },
    }
}
</script>
@endsection