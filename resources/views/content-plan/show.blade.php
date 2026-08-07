@extends('layouts.app')
@section('title', $contentPlan->client->name . ' - Content Plan')
@section('content')
<div class="p-8 max-w-[1400px]">

    <div class="flex items-center justify-between mb-6">
        <div class="flex items-start gap-3">
            <a href="{{ route('content-plan.index') }}" title="Kembali ke daftar Content Plan"
               class="w-9 h-9 flex items-center justify-center rounded-lg border border-[#eef0f4] text-[#5c6266] shrink-0 mt-0.5">
                <span class="material-symbols-outlined text-[20px]">arrow_back</span>
            </a>
            <div>
                <p class="text-xs text-[#9aa0a4] mb-1">
                    <a href="{{ route('content-plan.index') }}" class="hover:text-[#044b46]">Content Plan</a> /
                    {{ \Carbon\Carbon::create()->month($contentPlan->month)->translatedFormat('F') }} {{ $contentPlan->year }}
                </p>
                <div class="flex items-center gap-3">
                    <h1 class="font-display text-2xl font-semibold text-[#14181a]">{{ $contentPlan->client->name }}</h1>
                    <span class="text-xs px-2.5 py-1 rounded-full
                        {{ $contentPlan->status === 'approved' ? 'bg-[#f0f5f4] text-[#0f7a5f]' : '' }}
                        {{ $contentPlan->status === 'draft' ? 'bg-[#f2f3f6] text-[#9aa0a4]' : '' }}
                        {{ $contentPlan->status === 'pending' ? 'bg-[#fdf6ec] text-[#b8873a]' : '' }}
                        {{ $contentPlan->status === 'rejected' ? 'bg-[#fdf2f1] text-[#b3423e]' : '' }}">
                        {{ $contentPlan->status === 'pending' ? 'Pending Approval' : ucfirst($contentPlan->status) }}
                    </span>
                </div>
                <p class="text-xs text-[#9aa0a4] mt-1">
                    Target: {{ $contentPlan->clientPackage->monthly_content_quota ?? 0 }} Content /
                    {{ $contentPlan->clientPackage->monthly_design_quota ?? 0 }} Design
                </p>
            </div>
        </div>

        <div class="flex flex-col items-end gap-2">
            @if ($contentPlan->status === 'pending' || $contentPlan->status === 'draft')
                <div class="flex items-center gap-2">
                    <form action="{{ route('content-plan.reject', $contentPlan) }}" method="POST">
                        @csrf @method('PATCH')
                        <button class="text-sm font-medium text-[#b3423e] px-4 py-2.5 hover:bg-[#fdf2f1] rounded-lg transition-colors">Reject</button>
                    </form>
                    <form action="{{ route('content-plan.approve', $contentPlan) }}" method="POST">
                        @csrf @method('PATCH')
                        <button class="flex items-center gap-1.5 bg-[#044b46] text-white text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                            <span class="material-symbols-outlined text-[16px]">check</span> Approve Plan
                        </button>
                    </form>
                </div>
            @endif

            <div class="flex items-center h-9 bg-[#f2f3f6] rounded-lg p-1">
                <a href="{{ route('content-plan.show', ['contentPlan' => $contentPlan, 'view' => 'table']) }}"
                   class="flex items-center h-full text-xs font-medium px-3 rounded-md {{ $view === 'table' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4]' }}">Table</a>
                <a href="{{ route('content-plan.show', ['contentPlan' => $contentPlan, 'view' => 'calendar']) }}"
                   class="flex items-center h-full text-xs font-medium px-3 rounded-md {{ $view === 'calendar' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4]' }}">Calendar</a>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="bg-[#f0f5f4] text-[#044b46] text-sm p-3.5 rounded-lg mb-5">{{ session('status') }}</div>
    @endif

    <div class="flex items-center justify-between mb-4">
        <h2 class="font-display text-lg font-semibold text-[#14181a]">Content Items</h2>
        <a href="{{ route('content-plan.items.create', $contentPlan) }}" class="flex items-center h-9 gap-1.5 bg-[#044b46] text-white text-sm font-medium px-4 rounded-lg hover:bg-[#033b37] transition-colors">
            <span class="material-symbols-outlined text-[16px]">add</span> Tambah Content Item
        </a>
    </div>

    @if ($view === 'calendar')
        @include('content-plan.partials.calendar-grid')
    @else
        <div class="card overflow-hidden">
            <table class="w-full text-sm text-left">
                <thead class="bg-[#f7f8fc]">
                    <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                        <th class="px-6 py-3 font-medium">Item Details</th>
                        <th class="px-4 py-3 font-medium">Kategori</th>
                        <th class="px-4 py-3 font-medium">Platform</th>
                        <th class="px-4 py-3 font-medium">Timeline</th>
                        <th class="px-4 py-3 font-medium">Assignee</th>
                        <th class="px-6 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] cursor-pointer transition-colors" onclick="window.location='{{ route('content-items.show', $item) }}'">
                            <td class="px-6 py-3.5">
                                <p class="font-medium text-[#14181a]">{{ $item->title }}</p>
                                <p class="text-xs text-[#9aa0a4] mt-0.5">{{ $item->contentPillar->name ?? '-' }} &middot; ID: {{ $item->id }}</p>
                            </td>
                            <td class="px-4 py-3.5">
                                @php
                                    $typeColor = match ($item->contentType->name ?? null) {
                                        'Video' => '#3452a8',
                                        'Desain' => '#b3427e',
                                        default => '#9aa0a4',
                                    };
                                @endphp
                                <span class="text-xs font-medium px-2 py-0.5 rounded-full" style="background-color: {{ $typeColor }}14; color: {{ $typeColor }}">
                                    {{ $item->contentType->name ?? '-' }}
                                </span>
                            </td>
                            <td class="px-4 py-3.5 text-[#5c6266]">{{ $item->platform->name ?? '-' }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266]">{{ $item->deadline_at->format('d M Y') }}</td>
                            <td class="px-4 py-3.5">
                                @php $pic = $item->assignments->first()?->user; @endphp
                                @if ($pic)
                                    <span class="text-xs text-[#5c6266]">{{ $pic->name }}</span>
                                @else
                                    <span class="text-xs text-[#c3c7cb] italic">Belum ada PIC</span>
                                @endif
                            </td>
                            <td class="px-6 py-3.5">
                                <span class="text-xs px-2 py-1 rounded-full {{ $item->workflow?->is_overdue ? 'bg-[#fdf2f1] text-[#b3423e]' : 'bg-[#f0f5f4] text-[#044b46]' }}">
                                    {{ \App\Support\WorkflowTransitions::label($item->workflow?->current_status ?? 'planned') }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-10 text-center text-[#9aa0a4] text-sm">Belum ada content item. Klik "Tambah Content Item" buat mulai.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection