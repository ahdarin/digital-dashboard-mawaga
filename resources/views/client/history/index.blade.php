@extends('layouts.client')
@section('title', 'Riwayat Konten')
@section('content')

    <div class="p-4 sm:p-5">
        <form method="GET" class="flex items-center gap-2.5 mb-4 flex-wrap">
            <select name="type" onchange="this.form.submit()"
                    class="text-sm border border-[var(--border)] rounded-lg px-3 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 h-[40px]">
                <option value="">Semua Tipe</option>
                @foreach ($typeOptions as $type)
                    <option value="{{ $type->name }}" {{ request('type') === $type->name ? 'selected' : '' }}>{{ $type->name }}</option>
                @endforeach
            </select>
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[17px] pointer-events-none">calendar_month</span>
                <input type="text" name="month" value="{{ request('month') }}" data-flatpickr="month-combined" data-autosubmit="true" readonly
                       class="text-sm border border-[var(--border)] rounded-lg pl-9 pr-3 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 h-[40px] w-[150px]">
            </div>
            @if (request('type') || request('month'))
                <a href="{{ route('client.history') }}" class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Reset</a>
            @endif
        </form>

        <div class="space-y-3">
            @forelse ($items as $item)
                @php
                    $status = $item->workflow->current_status ?? null;
                    $statusChip = match (true) {
                        $status === 'uploaded' => 'text-[var(--success-text)] bg-[var(--success-tint)]',
                        $status === 'waiting_review' => 'text-[var(--warning-text)] bg-[var(--warning-tint)]',
                        default => 'text-[var(--info-text)] bg-[var(--info-tint)]',
                    };
                @endphp
                <a href="{{ route('client.approval.show', $item) }}"
                   class="block bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)] hover:shadow-[0_4px_16px_-4px_rgba(20,24,26,0.08)] transition-shadow">
                    <div class="flex justify-between items-start gap-2 mb-2">
                        <span class="text-[10px] font-bold px-2 py-1 rounded uppercase {{ $statusChip }}">
                            {{ $status ? \App\Support\WorkflowTransitions::label($status) : '-' }}
                        </span>
                        <span class="text-[10px] text-[var(--text-muted)] whitespace-nowrap">{{ $item->deadline_at->format('d M Y') }}</span>
                    </div>
                    <p class="text-sm font-semibold text-[var(--text-primary)]">{{ $item->title }}</p>
                    <p class="text-xs text-[var(--text-muted)] mt-1">{{ $item->contentType->name ?? '-' }}</p>
                </a>
            @empty
                <div class="text-center py-16">
                    <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[32px] mb-2 block">history</span>
                    <p class="text-sm text-[var(--text-muted)]">Belum ada konten yang cocok dengan filter ini.</p>
                </div>
            @endforelse
        </div>

        <div class="mt-4">
            {{ $items->links() }}
        </div>
    </div>
@endsection
