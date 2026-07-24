@extends('layouts.app')
@section('title', $user->name . ' - Profile')
@section('content')
@php
    $statusLabels = \App\Support\WorkflowTransitions::labels();
    $isOwnProfile = auth()->id() === $user->id;
@endphp

<div class="p-8 max-w-5xl">

    {{-- Header --}}
    <div class="flex items-center gap-4 mb-8">
        <a href="{{ url()->previous() }}" class="text-gray-400 hover:text-gray-600">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        @if ($user->avatar_url)
            <img src="{{ $user->avatar_url }}" referrerpolicy="no-referrer" class="w-16 h-16 rounded-full object-cover">
        @else
            <div class="w-16 h-16 rounded-full bg-[#044b46] text-white text-xl font-bold flex items-center justify-center">
                {{ strtoupper(substr($user->name, 0, 1)) }}
            </div>
        @endif
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-bold text-[#191c1c]">{{ $user->name }}</h2>
                @if ($isOwnProfile)
                    <span class="text-[10px] bg-[#044b46]/10 text-[#044b46] px-2 py-0.5 rounded-full font-semibold">Anda</span>
                @endif
            </div>
            <p class="text-sm text-gray-500">{{ $user->role->name ?? '-' }}</p>
            <p class="text-xs text-gray-400 mt-1">Bergabung sejak {{ $user->created_at->format('d M Y') }}</p>
        </div>
    </div>

    {{-- Kartu Ringkasan --}}
    <div class="grid grid-cols-3 gap-4 mb-8">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Active Tasks</p>
            <p class="text-3xl font-bold text-[#191c1c]">{{ $activeCount }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Overdue</p>
            <p class="text-3xl font-bold {{ $overdueCount > 0 ? 'text-red-600' : 'text-[#191c1c]' }}">{{ $overdueCount }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Completion Rate Bulan Ini</p>
            <p class="text-3xl font-bold text-[#191c1c]">{{ $completionRate }}%</p>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-6">

        {{-- Client yang ditangani --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-bold text-gray-700 mb-4">Client yang Ditangani</h3>
            <div class="space-y-3">
                @forelse ($assignedClients as $client)
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-gray-100 text-gray-600 text-xs font-bold flex items-center justify-center">
                            {{ strtoupper(substr($client->brand_name, 0, 1)) }}
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-800">{{ $client->name }}</p>
                            <p class="text-[10px] text-gray-400">{{ $client->brand_name }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-gray-400 italic">Belum ada client ditugaskan.</p>
                @endforelse
            </div>
        </div>

        {{-- Task List --}}
        <div class="col-span-2 bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="p-5 pb-0">
                <h3 class="text-sm font-bold text-gray-700 mb-4">Task ({{ $assignments->count() }})</h3>
            </div>
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="px-4 py-2">Content Item</th>
                        <th class="px-4 py-2">Client</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2">Deadline</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($assignments as $assignment)
                        @php $item = $assignment->contentItem; @endphp
                        <tr class="border-t border-gray-100 hover:bg-gray-50 cursor-pointer"
                            onclick="window.location='{{ route('production-workflow.show', $item) }}'">
                            <td class="px-4 py-3 font-medium">{{ $item->title }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $item->client->name ?? '-' }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-1 rounded-full bg-[#044b46]/10 text-[#044b46]">
                                    {{ $statusLabels[$item->workflow->current_status] ?? $item->workflow->current_status }}
                                </span>
                                @if ($item->workflow->is_overdue)
                                    <span class="text-xs text-red-600 font-semibold ml-1">Overdue</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-500">{{ $item->deadline_at->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400 text-sm">Belum ada task ditugaskan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection