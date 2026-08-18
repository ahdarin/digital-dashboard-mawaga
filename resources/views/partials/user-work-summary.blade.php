{{-- Shared work-summary body (summary cards, assigned clients, task list).
     Used by home/index and profile/show for the staff role.
     Expects: $activeCount, $overdueCount, $completionRate, $revisionCount,
              $assignedClients, $assignments, $statusLabels --}}
{{-- Kartu Ringkasan --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <div class="card p-5">
        <p class="text-xs text-[#767c80] mb-1">Task Aktif</p>
        <p class="font-display text-[26px] font-semibold text-[#14181a] [font-variant-numeric:tabular-nums]">{{ $activeCount }}</p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-[#767c80] mb-1">Terlambat</p>
        <p class="font-display text-[26px] font-semibold [font-variant-numeric:tabular-nums] {{ $overdueCount > 0 ? 'text-[#b3423e]' : 'text-[#14181a]' }}">{{ $overdueCount }}</p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-[#767c80] mb-1">Completion Rate Bulan Ini</p>
        <p class="font-display text-[26px] font-semibold text-[#14181a] [font-variant-numeric:tabular-nums]">{{ $completionRate }}%</p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-[#767c80] mb-1">Revisi Diterima</p>
        <p class="font-display text-[26px] font-semibold text-[#14181a] [font-variant-numeric:tabular-nums]">{{ $revisionCount }}</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

    {{-- Client yang ditangani --}}
    <div class="card p-5">
        <h2 class="font-display text-base font-semibold text-[#14181a] mb-4">Klien yang Ditangani</h2>
        <div class="space-y-3.5">
            @forelse ($assignedClients as $client)
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-full bg-[#f0f5f4] text-[#044b46] text-xs font-semibold flex items-center justify-center shrink-0">
                        {{ strtoupper(substr($client->brand_name, 0, 1)) }}
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-[#14181a]">{{ $client->name }}</p>
                        <p class="text-[10px] text-[#767c80]">{{ $client->brand_name }}</p>
                    </div>
                </div>
            @empty
                <p class="text-xs text-[#767c80] italic">Belum ada client ditugaskan.</p>
            @endforelse
        </div>
    </div>

    {{-- Task List --}}
    <div class="lg:col-span-2 card overflow-hidden flex flex-col">
        <div class="p-5 pb-4 shrink-0">
            <h2 class="font-display text-base font-semibold text-[#14181a]">Task ({{ $assignments->count() }})</h2>
        </div>
        <div class="overflow-auto max-h-[420px] thin-autohide-scrollbar hidden sm:block">
            <table class="w-full text-sm text-left">
                <thead class="bg-[#f7f8fc] text-[#767c80] text-[11px] uppercase tracking-wide sticky top-0 z-10">
                    <tr>
                        <th class="px-6 py-3 font-medium whitespace-nowrap">Konten</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Klien</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Deadline</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Risiko Terlambat</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($assignments as $assignment)
                        @php
                            $item = $assignment->contentItem;
                            $riskBadgeClasses = [
                                'high' => 'badge-danger',
                                'medium' => 'badge-warning',
                                'low' => 'badge-success',
                            ];
                            $risk = $item->latestDelayRisk;
                            $riskBadgeClass = $risk ? ($riskBadgeClasses[$risk->risk_level] ?? $riskBadgeClasses['low']) : null;
                        @endphp
                        <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] transition-colors cursor-pointer"
                            onclick="window.location='{{ route('content-items.show', $item) }}'">
                            <td class="px-6 py-3.5 font-medium text-[#14181a] whitespace-nowrap">
                                {{ $item->title }}
                            </td>
                            <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->client->name ?? '-' }}</td>
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-1.5 whitespace-nowrap">
                                    <span class="badge badge-success">
                                        {{ $statusLabels[$item->workflow->current_status] ?? $item->workflow->current_status }}
                                    </span>
                                    @if ($item->workflow->is_overdue)
                                        <span class="badge badge-danger">Terlambat</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->deadline_at->format('d M Y') }}</td>
                            <td class="px-4 py-3.5">
                                @if ($risk)
                                    <span class="badge {{ $riskBadgeClass }} font-semibold" title="{{ $risk->top_factor }}">
                                        {{ $risk->risk_score }}%
                                    </span>
                                    @if ($risk->top_factor)
                                        <p class="text-[10px] text-[#767c80] mt-1 max-w-[160px] truncate">{{ $risk->top_factor }}</p>
                                    @endif
                                @else
                                    <span class="text-xs text-[#767c80]">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-10 text-center text-[#767c80] text-sm">Belum ada task ditugaskan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile accordion --}}
        <div class="sm:hidden p-3.5 space-y-3 max-h-[420px] overflow-auto thin-autohide-scrollbar">
            @forelse ($assignments as $assignment)
                @php
                    $item = $assignment->contentItem;
                    $riskBadgeClasses = [
                        'high' => 'badge-danger',
                        'medium' => 'badge-warning',
                        'low' => 'badge-success',
                    ];
                    $risk = $item->latestDelayRisk;
                    $riskBadgeClass = $risk ? ($riskBadgeClasses[$risk->risk_level] ?? $riskBadgeClasses['low']) : null;
                @endphp
                <div class="card p-3.5" x-data="{ open: false }">
                    <button type="button" class="w-full text-left flex items-start gap-2 cursor-pointer" @click="open = !open" :aria-expanded="open">
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-[#14181a] text-sm">{{ $item->title }}</p>
                            <div class="flex items-center gap-1.5 flex-wrap mt-1.5">
                                <span class="badge badge-success">
                                    {{ $statusLabels[$item->workflow->current_status] ?? $item->workflow->current_status }}
                                </span>
                                @if ($item->workflow->is_overdue)
                                    <span class="badge badge-danger">Terlambat</span>
                                @endif
                                @if ($risk)
                                    <span class="badge {{ $riskBadgeClass }} font-semibold" title="{{ $risk->top_factor }}">
                                        {{ $risk->risk_score }}%
                                    </span>
                                @endif
                            </div>
                        </div>
                        <div class="w-7 h-7 shrink-0 flex items-center justify-center rounded-lg text-[#767c80]">
                            <span class="material-symbols-outlined text-[19px] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                        </div>
                    </button>
                    <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[#f2f3f6] space-y-2">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-[#767c80]">Klien</span>
                            <span class="text-[#14181a] font-medium">{{ $item->client->name ?? '-' }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-[#767c80]">Deadline</span>
                            <span class="text-[#14181a] font-medium">{{ $item->deadline_at->format('d M Y') }}</span>
                        </div>
                        @if ($risk && $risk->top_factor)
                            <div class="flex items-center justify-between text-xs gap-3">
                                <span class="text-[#767c80] shrink-0">Faktor Risiko</span>
                                <span class="text-[#14181a] font-medium text-right">{{ $risk->top_factor }}</span>
                            </div>
                        @endif
                        <a href="{{ route('content-items.show', $item) }}" class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[#044b46] bg-[#f0f5f4] hover:bg-[#e4ede9] rounded-lg py-2 transition-colors">Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span></a>
                    </div>
                </div>
            @empty
                <p class="px-2 py-10 text-center text-[#767c80] text-sm">Belum ada task ditugaskan.</p>
            @endforelse
        </div>
    </div>
</div>
