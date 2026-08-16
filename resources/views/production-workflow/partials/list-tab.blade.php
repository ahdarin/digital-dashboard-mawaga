@php
    $statusLabels = \App\Support\WorkflowTransitions::labels();

    $sortLink = function (string $column, string $label) use ($sortColumn, $sortDir) {
        $nextDir = ($sortColumn === $column && $sortDir === 'asc') ? 'desc' : 'asc';
        $isActive = $sortColumn === $column;
        $icon = ! $isActive ? 'unfold_more' : ($sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward');

        return '<a href="'.request()->fullUrlWithQuery(['sort' => $column, 'dir' => $nextDir]).'" '
            .'class="inline-flex items-center gap-1 hover:text-[#14181a] '.($isActive ? 'text-[#044b46]' : '').'">'
            .$label
            .'<span class="material-symbols-outlined text-[14px]">'.$icon.'</span>'
            .'</a>';
    };
@endphp
<div class="px-4 sm:px-6 lg:px-8 pb-8">
    <div class="card overflow-hidden hidden sm:block">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-[#f7f8fc]">
                    <tr class="text-[#767c80] text-[11px] uppercase tracking-wide">
                        <th class="px-6 py-3 font-medium whitespace-nowrap">{!! $sortLink('title', 'Konten') !!}</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('client', 'Klien') !!}</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('type', 'Tipe') !!}</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('status', 'Status') !!}</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('pic', 'Penanggung Jawab') !!}</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('deadline', 'Deadline') !!}</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('risk', 'Risiko Terlambat') !!}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($listItems as $item)
                        @php
                            $isOverdue = $item->workflow->is_overdue;
                            $risk = $item->latestDelayRisk;
                            $riskBadgeClasses = [
                                'high' => 'badge-danger',
                                'medium' => 'badge-warning',
                                'low' => 'badge-success',
                            ];
                            $riskBadgeClass = $risk ? ($riskBadgeClasses[$risk->risk_level] ?? $riskBadgeClasses['low']) : null;
                        @endphp
                        <tr x-show="matchesSearch('{{ addslashes($item->title) }}')"
                            class="border-t border-[#f2f3f6] transition-colors {{ $isOverdue ? 'bg-[#fdf2f1] hover:bg-[#fbe4e2]' : 'hover:bg-[#f7f8fc]' }}">
                            <td class="px-6 py-3.5 relative {{ $item->is_urgent ? 'pl-8' : '' }}">
                                @if ($item->is_urgent)
                                    <div class="absolute left-0 top-0 bottom-0 w-5 bg-[#b3423e] flex items-center justify-center overflow-hidden" title="Jobdesk Tambahan">
                                        <span class="text-white text-[8px] font-bold uppercase tracking-wider whitespace-nowrap" style="transform: rotate(-90deg);">Tambahan</span>
                                    </div>
                                @endif
                                <a href="{{ route('content-items.show', $item) }}" class="font-medium text-[#14181a] hover:underline">{{ $item->title }}</a>
                            </td>
                            <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->client->name ?? '-' }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->contentType->name ?? '-' }}</td>
                            <td class="px-4 py-3.5" onclick="event.stopPropagation()">
                                <div class="flex items-center gap-1.5 whitespace-nowrap">
                                    <span class="badge badge-success">
                                        {{ $statusLabels[$item->workflow->current_status] ?? $item->workflow->current_status }}
                                    </span>
                                </div>

                                @if (($item->contentType->name ?? '') === 'Video' && $item->workflow->current_status === 'in_progress')
                                    <div data-footage-toggle data-item-id="{{ $item->id }}" class="footage-toggle-fade mt-1.5 w-[180px]">
                                        @if ($item->footage_captured_at)
                                            <div class="flex items-center justify-between gap-1 text-[10px] font-medium text-[#0f7a5f] bg-[#f0f5f4] px-2 py-1.5 rounded-lg">
                                                <span class="flex items-center gap-1 whitespace-nowrap">
                                                    <span class="material-symbols-outlined text-[13px]">check_circle</span> Sudah di-take
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
                            </td>
                            <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->workflow->currentPic->name ?? 'Belum ditugaskan' }}</td>
                            <td class="px-4 py-3.5 whitespace-nowrap {{ $isOverdue ? 'text-[#b3423e] font-semibold' : 'text-[#5c6266]' }}">{{ $item->deadline_at->format('d M Y') }}</td>
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
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <span class="material-symbols-outlined text-[#d4d7db] text-[28px] mb-2 block">checklist</span>
                                <p class="text-sm text-[#767c80]">Tidak ada konten yang cocok dengan filter ini.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Mobile accordion cards - sama koleksi data, hanya tampil di bawah sm --}}
    <div class="sm:hidden space-y-3">
        @forelse ($listItems as $item)
            @php
                $isOverdue = $item->workflow->is_overdue;
                $risk = $item->latestDelayRisk;
                $riskBadgeClasses = [
                    'high' => 'badge-danger',
                    'medium' => 'badge-warning',
                    'low' => 'badge-success',
                ];
                $riskBadgeClass = $risk ? ($riskBadgeClasses[$risk->risk_level] ?? $riskBadgeClasses['low']) : null;
            @endphp
            <div x-data="{ open: false }"
                x-show="matchesSearch('{{ addslashes($item->title) }}')"
                class="card p-3.5 {{ $isOverdue ? 'bg-[#fdf2f1]' : '' }}">
                <button type="button" class="w-full text-left flex items-start justify-between gap-2 cursor-pointer" @click="open = !open" :aria-expanded="open">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            @if ($item->is_urgent)
                                <span class="text-[9px] font-bold uppercase tracking-wide text-white bg-[#b3423e] px-1.5 py-0.5 rounded">Tambahan</span>
                            @endif
                            <p class="font-medium text-[#14181a] truncate">{{ $item->title }}</p>
                        </div>
                        <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">
                            <span class="badge badge-success">
                                {{ $statusLabels[$item->workflow->current_status] ?? $item->workflow->current_status }}
                            </span>
                            @if ($risk)
                                <span class="badge {{ $riskBadgeClass }} font-semibold" title="{{ $risk->top_factor }}">
                                    {{ $risk->risk_score }}%
                                </span>
                            @endif
                        </div>
                    </div>
                    <span class="shrink-0 flex items-center justify-center w-7 h-7 rounded-lg text-[#767c80]">
                        <span class="material-symbols-outlined text-[20px] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                    </span>
                </button>

                <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[#f2f3f6] space-y-2" @click.stop>
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-[#767c80]">Info Penugasan</p>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#767c80]">Klien</span>
                        <span class="text-[#14181a] font-medium">{{ $item->client->name ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#767c80]">Tipe</span>
                        <span class="text-[#14181a] font-medium">{{ $item->contentType->name ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#767c80]">Penanggung Jawab</span>
                        <span class="text-[#14181a] font-medium">{{ $item->workflow->currentPic->name ?? 'Belum ditugaskan' }}</span>
                    </div>
                    @if ($risk && $risk->top_factor)
                        <div class="flex items-center justify-between text-xs gap-3">
                            <span class="text-[#767c80] shrink-0">Faktor Risiko</span>
                            <span class="text-[#14181a] font-medium text-right">{{ $risk->top_factor }}</span>
                        </div>
                    @endif

                    <p class="text-[10px] font-semibold uppercase tracking-wide text-[#767c80] pt-1">Jadwal</p>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#767c80]">Deadline</span>
                        <span class="{{ $isOverdue ? 'text-[#b3423e] font-semibold' : 'text-[#14181a] font-medium' }}">{{ $item->deadline_at->format('d M Y') }}</span>
                    </div>

                    @if (($item->contentType->name ?? '') === 'Video' && $item->workflow->current_status === 'in_progress')
                        <div data-footage-toggle data-item-id="{{ $item->id }}" class="footage-toggle-fade pt-1">
                            @if ($item->footage_captured_at)
                                <div class="flex items-center justify-between gap-1 text-[10px] font-medium text-[#0f7a5f] bg-[#f0f5f4] px-2 py-1.5 rounded-lg">
                                    <span class="flex items-center gap-1 whitespace-nowrap">
                                        <span class="material-symbols-outlined text-[13px]">check_circle</span> Sudah di-take
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

                    <a href="{{ route('content-items.show', $item) }}"
                        class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[#044b46] bg-[#f0f5f4] hover:bg-[#e4ede9] rounded-lg py-2 transition-colors">
                        Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>
            </div>
        @empty
            <div class="card p-8 text-center">
                <span class="material-symbols-outlined text-[#d4d7db] text-[28px] mb-2 block">checklist</span>
                <p class="text-sm text-[#767c80]">Tidak ada konten yang cocok dengan filter ini.</p>
            </div>
        @endforelse
    </div>
</div>
