@php
    $statusLabels = \App\Support\WorkflowTransitions::labels();

    $sortLink = function (string $column, string $label) use ($sortColumn, $sortDir) {
        $nextDir = ($sortColumn === $column && $sortDir === 'asc') ? 'desc' : 'asc';
        $isActive = $sortColumn === $column;
        $icon = ! $isActive ? 'unfold_more' : ($sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward');

        return '<a href="'.request()->fullUrlWithQuery(['sort' => $column, 'dir' => $nextDir]).'" '
            .'class="inline-flex items-center gap-1 hover:text-[var(--text-primary)] '.($isActive ? 'text-[var(--brand)]' : '').'">'
            .$label
            .'<span class="material-symbols-outlined text-[14px]">'.$icon.'</span>'
            .'</a>';
    };
@endphp
<div class="px-4 sm:px-6 lg:px-8 pb-8">
    <div class="card overflow-hidden hidden sm:block">
        <div class="overflow-x-auto">
            <table class="w-full table-fixed text-sm text-left">
                <thead class="bg-[var(--surface-page)]">
                    <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                        <th class="w-[22%] px-6 py-3 font-medium whitespace-nowrap">{!! $sortLink('title', 'Konten') !!}</th>
                        <th class="w-[12%] px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('client', 'Klien') !!}</th>
                        <th class="w-[9%] px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('type', 'Tipe') !!}</th>
                        <th class="w-[15%] px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('status', 'Status') !!}</th>
                        <th class="w-[14%] px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('pic', 'PIC') !!}</th>
                        <th class="w-[11%] px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('deadline', 'Deadline') !!}</th>
                        <th class="w-[17%] px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('risk', 'Risiko') !!}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($listItems as $item)
                        @php
                            $isOverdue = $item->workflow->is_overdue;
                            $isPinned = $pinnedIds->contains($item->id);
                            $risk = $item->latestDelayRisk;
                            $riskBadgeClasses = [
                                'high' => 'badge-danger',
                                'medium' => 'badge-warning',
                                'low' => 'badge-success',
                            ];
                            $riskBadgeClass = $risk ? ($riskBadgeClasses[$risk->risk_level] ?? $riskBadgeClasses['low']) : null;
                        @endphp
                        <tr x-show="matchesSearch('{{ addslashes($item->title) }}')"
                            onclick="navigateTo('{{ route('content-items.show', $item) }}')"
                            class="border-t border-[var(--surface-muted)] transition-colors cursor-pointer {{ $isOverdue ? 'bg-[var(--danger-tint)] hover:bg-[var(--danger-tint-hover)]' : ($isPinned ? 'bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)]' : 'hover:bg-[var(--surface-page)]') }}">
                            <td class="px-6 py-3.5 relative {{ $item->is_urgent ? 'pl-8' : '' }}">
                                @if ($item->is_urgent)
                                    <div class="absolute left-0 top-0 bottom-0 w-5 bg-[var(--danger-solid)] flex items-center justify-center overflow-hidden" title="Jobdesk Tambahan">
                                        <span class="text-white text-[8px] font-bold uppercase tracking-wider whitespace-nowrap" style="transform: rotate(-90deg);">Tambahan</span>
                                    </div>
                                @endif
                                <div class="flex items-center gap-2 min-w-0">
                                    <x-pin-button :item="$item" :pinned="$isPinned" />
                                    <p class="font-medium text-[var(--text-primary)] line-clamp-2" title="{{ $item->title }}">{{ $item->title }}</p>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)] truncate" title="{{ $item->client->name ?? '-' }}">{{ $item->client->name ?? '-' }}</td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)] truncate">{{ $item->contentType->name ?? '-' }}</td>
                            <td class="px-4 py-3.5" onclick="event.stopPropagation()">
                                <div class="flex items-center gap-1.5 whitespace-nowrap">
                                    <span class="badge badge-success">
                                        {{ $statusLabels[$item->workflow->current_status] ?? $item->workflow->current_status }}
                                    </span>
                                </div>

                                @if (($item->contentType->name ?? '') === 'Video' && $item->workflow->current_status === 'in_progress')
                                    <div data-footage-toggle data-item-id="{{ $item->id }}" class="footage-toggle-fade mt-1.5 w-full max-w-[180px]">
                                        @if ($item->footage_captured_at)
                                            <div class="flex items-center justify-between gap-1 text-[10px] font-medium text-[var(--success-text)] bg-[var(--success-tint)] px-2 py-1.5 rounded-lg">
                                                <span class="flex items-center gap-1 whitespace-nowrap">
                                                    <span class="material-symbols-outlined text-[13px]">check_circle</span> Sudah di-take
                                                </span>
                                                <button type="button" x-on:click.stop="unmarkFootageCaptured({{ $item->id }}, $event)"
                                                    class="text-[var(--warning-text)] hover:underline shrink-0">Batalkan</button>
                                            </div>
                                        @else
                                            <div class="flex items-center gap-1">
                                                <input type="text" data-take-date data-flatpickr="datetime" autocomplete="off" value="{{ now()->format('Y-m-d H:i') }}"
                                                    x-on:click.stop x-on:mousedown.stop
                                                    class="bg-[var(--surface-card)] flex-1 min-w-0 border border-[var(--border)] rounded-lg px-1.5 py-1 text-[10px] focus:outline-none focus:border-[#044b46]/40">
                                                <button type="button" x-on:click.stop="markFootageCaptured({{ $item->id }}, $event)"
                                                    x-on:mouseenter="showTooltip($event, 'Tandai Sudah Di-take')" x-on:mouseleave="hideTooltip()"
                                                    aria-label="Tandai Sudah Di-take"
                                                    class="flex items-center justify-center shrink-0 border border-[#044b46]/30 text-[var(--brand)] w-7 h-7 rounded-lg hover:bg-[var(--brand-tint)] transition-colors">
                                                    <span class="material-symbols-outlined text-[15px]">videocam</span>
                                                </button>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            @php $listPic = $picResolver->resolve($item); @endphp
                            <td class="px-4 py-3.5 text-[var(--text-secondary)] truncate" title="{{ $listPic['name'] ?? 'Belum ditugaskan' }}">
                                {{ $listPic['name'] ?? 'Belum ditugaskan' }}
                                @if ($listPic['name'] && ! $listPic['has_account'])
                                    <span class="block text-[10px] text-[var(--text-muted)] italic">Belum memiliki akun</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 whitespace-nowrap {{ $isOverdue ? 'text-[var(--danger-text)] font-semibold' : 'text-[var(--text-secondary)]' }}">{{ $item->deadline_at->format('d M Y') }}</td>
                            <td class="px-4 py-3.5">
                                @if ($risk)
                                    <span class="badge {{ $riskBadgeClass }} font-semibold" title="{{ $risk->top_factor }}">
                                        {{ $risk->risk_score }}%
                                    </span>
                                    @if ($risk->top_factor)
                                        <p class="text-[10px] text-[var(--text-muted)] mt-1 max-w-[160px] truncate">{{ $risk->top_factor }}</p>
                                    @endif
                                @else
                                    <span class="text-xs text-[var(--text-muted)]">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">checklist</span>
                                <p class="text-sm text-[var(--text-muted)]">Tidak ada konten yang cocok dengan filter ini.</p>
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
                $isPinned = $pinnedIds->contains($item->id);
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
                class="card p-3.5 {{ $isOverdue ? 'bg-[var(--danger-tint)]' : ($isPinned ? 'bg-[var(--brand-tint)]' : '') }}">
                <div class="flex items-start gap-2">
                    <x-pin-button :item="$item" :pinned="$isPinned" class="mt-0.5" />
                    <button type="button" class="flex-1 min-w-0 text-left flex items-start justify-between gap-2 cursor-pointer" @click="open = !open" :aria-expanded="open">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            @if ($item->is_urgent)
                                <span class="text-[9px] font-bold uppercase tracking-wide text-white bg-[var(--danger-solid)] px-1.5 py-0.5 rounded">Tambahan</span>
                            @endif
                            <p class="font-medium text-[var(--text-primary)] truncate">{{ $item->title }}</p>
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
                    <span class="shrink-0 flex items-center justify-center w-7 h-7 rounded-lg text-[var(--text-muted)]">
                        <span class="material-symbols-outlined text-[20px] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                    </span>
                    </button>
                </div>

                <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[var(--surface-muted)] space-y-2" @click.stop>
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">Info Penugasan</p>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Klien</span>
                        <span class="text-[var(--text-primary)] font-medium">{{ $item->client->name ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Tipe</span>
                        <span class="text-[var(--text-primary)] font-medium">{{ $item->contentType->name ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Penanggung Jawab</span>
                        @php $mobilePic = $picResolver->resolve($item); @endphp
                        <span class="text-right">
                            <span class="text-[var(--text-primary)] font-medium block">{{ $mobilePic['name'] ?? 'Belum ditugaskan' }}</span>
                            @if ($mobilePic['name'] && ! $mobilePic['has_account'])
                                <span class="text-[10px] text-[var(--text-muted)] italic">Belum memiliki akun</span>
                            @endif
                        </span>
                    </div>
                    @if ($risk && $risk->top_factor)
                        <div class="flex items-center justify-between text-xs gap-3">
                            <span class="text-[var(--text-muted)] shrink-0">Faktor Risiko</span>
                            <span class="text-[var(--text-primary)] font-medium text-right">{{ $risk->top_factor }}</span>
                        </div>
                    @endif

                    <p class="text-[10px] font-semibold uppercase tracking-wide text-[var(--text-muted)] pt-1">Jadwal</p>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Deadline</span>
                        <span class="{{ $isOverdue ? 'text-[var(--danger-text)] font-semibold' : 'text-[var(--text-primary)] font-medium' }}">{{ $item->deadline_at->format('d M Y') }}</span>
                    </div>

                    @if (($item->contentType->name ?? '') === 'Video' && $item->workflow->current_status === 'in_progress')
                        <div data-footage-toggle data-item-id="{{ $item->id }}" class="footage-toggle-fade pt-1">
                            @if ($item->footage_captured_at)
                                <div class="flex items-center justify-between gap-1 text-[10px] font-medium text-[var(--success-text)] bg-[var(--success-tint)] px-2 py-1.5 rounded-lg">
                                    <span class="flex items-center gap-1 whitespace-nowrap">
                                        <span class="material-symbols-outlined text-[13px]">check_circle</span> Sudah di-take
                                    </span>
                                    <button type="button" x-on:click.stop="unmarkFootageCaptured({{ $item->id }}, $event)"
                                        class="text-[var(--warning-text)] hover:underline shrink-0">Batalkan</button>
                                </div>
                            @else
                                <div class="flex items-center gap-1">
                                    <input type="text" data-take-date data-flatpickr="datetime" autocomplete="off" value="{{ now()->format('Y-m-d H:i') }}"
                                        x-on:click.stop x-on:mousedown.stop
                                        class="bg-[var(--surface-card)] flex-1 min-w-0 border border-[var(--border)] rounded-lg px-1.5 py-1 text-[10px] focus:outline-none focus:border-[#044b46]/40">
                                    <button type="button" x-on:click.stop="markFootageCaptured({{ $item->id }}, $event)"
                                                    x-on:mouseenter="showTooltip($event, 'Tandai Sudah Di-take')" x-on:mouseleave="hideTooltip()"
                                                    aria-label="Tandai Sudah Di-take"
                                        class="flex items-center justify-center shrink-0 border border-[#044b46]/30 text-[var(--brand)] w-7 h-7 rounded-lg hover:bg-[var(--brand-tint)] transition-colors">
                                        <span class="material-symbols-outlined text-[15px]">videocam</span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endif

                    <a href="{{ route('content-items.show', $item) }}"
                        class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] rounded-lg py-2 transition-colors">
                        Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>
            </div>
        @empty
            <div class="card p-8 text-center">
                <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">checklist</span>
                <p class="text-sm text-[var(--text-muted)]">Tidak ada konten yang cocok dengan filter ini.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $listItems->links() }}
    </div>
</div>
