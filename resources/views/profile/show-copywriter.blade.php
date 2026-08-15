@extends('layouts.app')
@section('title', $user->name . ' - Profile')
@section('content')
@php
    $isOwnProfile = auth()->id() === $user->id;
@endphp

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px]">

    {{-- Header --}}
    <div class="flex items-center gap-4 mb-8 flex-wrap">
        <a href="{{ url()->previous() }}" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white text-[#9aa0a4] hover:text-[#14181a] transition-colors">
            <span class="material-symbols-outlined text-[19px]">arrow_back</span>
        </a>
        @if ($user->avatar_url)
            <img src="{{ $user->avatar_url }}" referrerpolicy="no-referrer" class="w-16 h-16 rounded-full object-cover">
        @else
            <div class="w-16 h-16 rounded-full bg-[#044b46] text-white text-xl font-semibold flex items-center justify-center">
                {{ strtoupper(substr($user->name, 0, 1)) }}
            </div>
        @endif
        <div>
            <div class="flex items-center gap-2">
                <h1 class="font-display text-2xl font-semibold text-[#14181a]">{{ $user->name }}</h1>
                @if ($isOwnProfile)
                    <span class="text-[10px] bg-[#f0f5f4] text-[#044b46] px-2 py-0.5 rounded-full font-semibold">Anda</span>
                @endif
            </div>
            <p class="text-sm text-[#5c6266]">{{ $user->role->name ?? '-' }}</p>
            <p class="text-xs text-[#9aa0a4] mt-1">Bergabung sejak {{ $user->created_at->format('d M Y') }}</p>
        </div>
    </div>

    <div class="card overflow-hidden flex flex-col">
        <div class="p-5 pb-4 shrink-0">
            <h2 class="font-display text-base font-semibold text-[#14181a]">Brief Belum Final ({{ $briefQueueItems->count() }})</h2>
            <p class="text-xs text-[#9aa0a4] mt-1">Konten yang briefnya belum diterapkan ke tim produksi - baik yang belum digarap sama sekali maupun yang masih draft/diskusi.</p>
        </div>
        <div class="overflow-auto max-h-[520px] thin-autohide-scrollbar">
            <table class="w-full text-sm text-left">
                <thead class="bg-[#f7f8fc] text-[#9aa0a4] text-[11px] uppercase tracking-wide sticky top-0 z-10">
                    <tr>
                        <th class="px-6 py-3 font-medium whitespace-nowrap">Content Item</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Client</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Status Brief</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Deadline</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($briefQueueItems as $item)
                        @php
                            $brief = $item->contentBriefDraft;
                            $briefStatus = match ($brief?->status) {
                                'discussing' => ['label' => 'Sedang Didiskusikan', 'class' => 'bg-[#fdf6ec] text-[#b8873a]'],
                                'draft' => ['label' => 'Draft', 'class' => 'bg-[#f2f3f6] text-[#9aa0a4]'],
                                default => ['label' => 'Belum Dibuat', 'class' => 'bg-[#fdf2f1] text-[#b3423e]'],
                            };
                        @endphp
                        <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] transition-colors cursor-pointer"
                            onclick="window.location='{{ route('content-items.show', $item) }}'">
                            <td class="px-6 py-3.5 font-medium text-[#14181a] whitespace-nowrap">{{ $item->title }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->client->name ?? '-' }}</td>
                            <td class="px-4 py-3.5">
                                <span class="text-xs font-medium px-2.5 py-1 rounded-full {{ $briefStatus['class'] }} whitespace-nowrap">
                                    {{ $briefStatus['label'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->deadline_at->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-10 text-center text-[#9aa0a4] text-sm">Semua brief sudah diterapkan ke tim produksi. Kerja bagus!</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
