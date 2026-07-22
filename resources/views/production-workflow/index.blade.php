@extends('layouts.app')

@section('title', 'Production Workflow Board')

@section('content')
<div x-data="kanbanBoard()" class="flex flex-col h-full">

    {{-- Header --}}
    <header class="px-8 py-6 bg-white border-b border-gray-200 flex-shrink-0 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-[#191c1c]">Production Workflow Board</h2>
            <p class="text-sm text-gray-500 mt-1">Active Content Pipeline</p>
        </div>

        
        <div class="flex items-center gap-3">
            <select name="client_id" form="filter-client-form" onchange="this.form.submit()"
                    class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46] h-[42px]">
                <option value="">Semua Client</option>
                @foreach ($clientOptions as $client)
                    <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>
                        {{ $client->name }}
                    </option>
                @endforeach
            </select>
            <form id="filter-client-form" method="GET" action="{{ route('production-workflow.index') }}"></form>

            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-[20px]">search</span>
                <input x-model="search" class="pl-10 pr-4 h-[42px] bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-[#044b46] w-64" placeholder="Search tasks..." type="text">
            </div>

            <a href="#"
                class="bg-[#044b46] text-white text-sm font-semibold px-4 h-[42px] rounded-lg hover:bg-[#044b46]/90 flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px]">add</span>
                New Task
            </a>
        </div>
    </header>

    {{-- Toast notifikasi sederhana --}}
    <div x-show="toast" x-transition class="fixed top-6 right-6 z-50 bg-[#044b46] text-white px-4 py-2 rounded-lg shadow-lg text-sm" x-text="toast" style="display: none;"></div>

    {{-- Kanban Area --}}
    <div class="flex-1 overflow-x-auto p-4 flex items-start gap-4 bg-gray-50">
        @php
            $statusLabels = \App\Support\WorkflowTransitions::labels();
        @endphp

        @foreach ($statuses as $status)
            <div
                class="flex-shrink-0 w-[300px] h-full flex flex-col bg-gray-100 rounded-xl border border-gray-200"
                x-on:dragover.prevent
                x-on:drop="onDrop($event, '{{ $status }}')"
            >
                <div class="p-3 border-b border-gray-200 flex items-center justify-between rounded-t-xl">
                    <h3 class="text-xs font-semibold text-gray-700 flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full bg-[#044b46]"></div>
                        {{ $statusLabels[$status] }}
                    </h3>
                    <span class="text-xs bg-gray-200 text-gray-600 px-2 py-0.5 rounded-full">
                        {{ $board[$status]->count() }}
                    </span>
                </div>

                <div class="p-3 overflow-y-auto flex-1 space-y-3" style="min-height: 100px;">

                    @forelse ($board[$status] as $item)

                        @php $isOverdue = $item->workflow->is_overdue; @endphp

                        <div
                            draggable="true"
                            x-on:dragstart="onDragStart($event, {{ $item->id }})"
                            x-show="matchesSearch('{{ addslashes($item->title) }}')"
                            class="bg-white p-4 rounded-lg border shadow-sm hover:shadow-md transition-shadow cursor-move {{ $isOverdue ? 'border-2 border-red-400' : 'border-gray-200' }}"
                        >
                            <div class="flex justify-between items-start mb-2">
                                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">
                                    {{ $item->contentType->name ?? '-' }}
                                </span>
                                @if ($isOverdue)
                                    <span class="text-xs text-red-600 font-bold flex items-center gap-1 bg-red-50 px-2 py-0.5 rounded">
                                        <span class="material-symbols-outlined text-[14px]">warning</span> Overdue
                                    </span>
                                @endif
                            </div>
                            <h4 class="text-sm font-semibold text-[#191c1c] mb-1">{{ $item->title }}</h4>
                            <p class="text-xs text-gray-500 mb-3">{{ $item->client->name ?? '-' }}</p>
                            <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                                <div class="flex items-center gap-1 text-gray-500">
                                    <span class="material-symbols-outlined text-[16px]">schedule</span>
                                    <span class="text-xs">{{ $item->deadline_at->format('M d') }}</span>
                                </div>

                                <div class="relative" x-data="{ open: false }">
                                    <div class="flex items-center -space-x-2 cursor-pointer"
                                        x-on:mouseenter="open = true"
                                        x-on:mouseleave="open = false">
                                        @forelse ($item->assignments->take(3) as $assignment)
                                            @if ($assignment->user->avatar_url)
                                                <img src="{{ $assignment->user->avatar_url }}"
                                                    referrerpolicy="no-referrer"
                                                    class="w-6 h-6 rounded-full ring-2 ring-white object-cover">
                                            @else
                                                <div class="w-6 h-6 rounded-full bg-[#044b46] text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white">
                                                    {{ strtoupper(substr($assignment->user->name, 0, 1)) }}
                                                </div>
                                            @endif
                                        @empty
                                            <span class="text-xs text-gray-300 italic">Belum ada PIC</span>
                                        @endforelse

                                        @if ($item->assignments->count() > 3)
                                            <div class="w-6 h-6 rounded-full bg-gray-200 text-gray-600 text-[9px] font-bold flex items-center justify-center ring-2 ring-white">
                                                +{{ $item->assignments->count() - 3 }}
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Popover --}}
                                    <div x-show="open" x-transition
                                        x-on:mouseenter="open = true"
                                        x-on:mouseleave="open = false"
                                        class="absolute z-10 bottom-full right-0 mb-2 w-56 bg-white border border-gray-200 rounded-lg shadow-lg p-3"
                                        style="display: none;">
                                        <p class="text-xs font-bold text-gray-700 uppercase mb-2">PIC Penugasan</p>
                                        <div class="space-y-2">
                                            @forelse ($item->assignments as $assignment)
                                                <div class="flex items-center gap-2">
                                                    @if ($assignment->user->avatar_url)
                                                        <img src="{{ $assignment->user->avatar_url }}" 
                                                            referrerpolicy="no-referrer"
                                                            class="w-7 h-7 rounded-full object-cover">
                                                    @else
                                                        <div class="w-7 h-7 rounded-full bg-[#044b46] text-white text-xs font-bold flex items-center justify-center">
                                                            {{ strtoupper(substr($assignment->user->name, 0, 1)) }}
                                                        </div>
                                                    @endif
                                                    <div>
                                                        <p class="text-xs font-semibold text-gray-800">{{ $assignment->user->name }}</p>
                                                        <p class="text-[10px] text-gray-400">{{ ucwords(str_replace('_', ' ', $assignment->assignment_role)) }}</p>
                                                    </div>
                                                </div>
                                            @empty
                                                <p class="text-xs text-gray-400 italic">Belum ada PIC ditugaskan</p>
                                            @endforelse
                                        </div>
                                    </div>
                                </div>

                            </div>
                            <div class="mt-3 pt-2 border-t border-gray-100 text-right">
                                <a href="{{ route('production-workflow.show', $item) }}" class="text-xs text-[#044b46] font-semibold hover:underline">
                                    Lihat Detail →
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="text-center p-6 border-2 border-dashed border-gray-300 rounded-lg">
                            <span class="material-symbols-outlined text-gray-300 text-[32px] mb-2 block">note_add</span>
                            <p class="text-xs text-gray-400">Drop cards here</p>
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