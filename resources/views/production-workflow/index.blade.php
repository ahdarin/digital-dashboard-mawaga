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
        <div class="flex gap-3">
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-[20px]">search</span>
                <input x-model="search" class="pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-[#044b46] w-64" placeholder="Search tasks..." type="text">
            </div>
        </div>
    </header>

    {{-- Toast notifikasi sederhana --}}
    <div x-show="toast" x-transition class="fixed top-6 right-6 z-50 bg-[#044b46] text-white px-4 py-2 rounded-lg shadow-lg text-sm" x-text="toast" style="display: none;"></div>

    {{-- Kanban Area --}}
    <div class="flex-1 overflow-x-auto p-4 flex items-start gap-4 bg-gray-50">
        @php
            $statusLabels = [
                'brief_ready' => 'Brief Ready',
                'in_progress' => 'In Progress',
                'waiting_review' => 'Waiting Review',
                'revision' => 'Revision',
                'approved' => 'Approved',
                'scheduled' => 'Scheduled',
                'uploaded' => 'Uploaded',
                'cancelled' => 'Cancelled',
            ];
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
                        <div
                            draggable="true"
                            x-on:dragstart="onDragStart($event, {{ $item->id }})"
                            x-show="matchesSearch('{{ addslashes($item->title) }}')"
                            class="bg-white p-4 rounded-lg border shadow-sm hover:shadow-md transition-shadow cursor-move {{ $item->deadline_at->isPast() && $status !== 'uploaded' ? 'border-2 border-red-400' : 'border-gray-200' }}"
                        >
                            <div class="flex justify-between items-start mb-2">
                                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">
                                    {{ $item->contentType->name ?? '-' }}
                                </span>
                                @if ($item->deadline_at->isPast() && $status !== 'uploaded')
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
                                <a href="#" class="text-xs text-[#044b46] font-semibold hover:underline">Detail</a>
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
            .then(res => {
                if (!res.ok) throw new Error('Gagal update status');
                return res.json();
            })
            .then(() => {
                this.toast = 'Status berhasil diperbarui';
                setTimeout(() => window.location.reload(), 600);
            })
            .catch(() => {
                this.toast = 'Gagal memindahkan kartu';
                setTimeout(() => this.toast = '', 2500);
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