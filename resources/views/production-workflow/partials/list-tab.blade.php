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
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-[#f7f8fc]">
                    <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                        <th class="px-6 py-3 font-medium whitespace-nowrap">{!! $sortLink('title', 'Content Item') !!}</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('client', 'Client') !!}</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('type', 'Tipe') !!}</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('status', 'Status') !!}</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('pic', 'Penanggung Jawab') !!}</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('deadline', 'Deadline') !!}</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{!! $sortLink('risk', 'Delay Risk') !!}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($listItems as $item)
                        @php
                            $isOverdue = $item->workflow->is_overdue;
                            $risk = $item->latestDelayRisk;
                            $riskColors = [
                                'high' => ['bg' => '#fdf2f1', 'text' => '#b3423e'],
                                'medium' => ['bg' => '#fdf6ec', 'text' => '#8a6423'],
                                'low' => ['bg' => '#f0f5f4', 'text' => '#0f7a5f'],
                            ];
                            $riskColor = $risk ? ($riskColors[$risk->risk_level] ?? $riskColors['low']) : null;
                        @endphp
                        <tr x-show="matchesSearch('{{ addslashes($item->title) }}')"
                            class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] cursor-pointer transition-colors"
                            onclick="window.location='{{ route('content-items.show', $item) }}'">
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="font-medium text-[#14181a]">{{ $item->title }}</p>
                                    @if ($item->is_urgent)
                                        <span class="text-[9px] text-white font-semibold flex items-center gap-0.5 bg-[#b3423e] px-1.5 py-0.5 rounded whitespace-nowrap">
                                            <span class="material-symbols-outlined text-[10px]">bolt</span> Tambahan
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->client->name ?? '-' }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->contentType->name ?? '-' }}</td>
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-1.5 whitespace-nowrap">
                                    <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-[#f0f5f4] text-[#044b46]">
                                        {{ $statusLabels[$item->workflow->current_status] ?? $item->workflow->current_status }}
                                    </span>
                                    @if ($isOverdue)
                                        <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-[#fdf2f1] text-[#b3423e]">Overdue</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->workflow->currentPic->name ?? 'Belum ditugaskan' }}</td>
                            <td class="px-4 py-3.5 whitespace-nowrap {{ $isOverdue ? 'text-[#b3423e] font-semibold' : 'text-[#5c6266]' }}">{{ $item->deadline_at->format('d M Y') }}</td>
                            <td class="px-4 py-3.5">
                                @if ($risk)
                                    <span class="text-xs font-semibold px-2.5 py-1 rounded-full"
                                          style="background-color: {{ $riskColor['bg'] }}; color: {{ $riskColor['text'] }};"
                                          title="{{ $risk->top_factor }}">
                                        {{ $risk->risk_score }}%
                                    </span>
                                @else
                                    <span class="text-xs text-[#c3c7cb]">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <span class="material-symbols-outlined text-[#d4d7db] text-[28px] mb-2 block">checklist</span>
                                <p class="text-sm text-[#9aa0a4]">Tidak ada konten yang cocok dengan filter ini.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
