@extends('layouts.app')
@section('title', $contentItem->title)

@section('content')
    @php
        $workflow = $contentItem->workflow;
        $statusLabels = \App\Support\WorkflowTransitions::labels();
    @endphp

    <div class="p-8 max-w-6xl">

        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <a href="{{ url()->previous() }}" class="text-[#9aa0a4] hover:text-[#5c6266]">
                    <span class="material-symbols-outlined">arrow_back</span>
                </a>
                <div>
                    <p class="text-xs text-[#9aa0a4]">{{ $contentItem->client->name ?? '-' }} / Item #{{ $contentItem->id }}
                    </p>
                    <h2 class="font-display text-xl font-semibold text-[#14181a]">{{ $contentItem->title }}</h2>
                </div>
            </div>
            <span class="text-xs font-medium px-3 py-1.5 rounded-full bg-[#f0f5f4] text-[#044b46]">
                {{ $statusLabels[$workflow->current_status] ?? $workflow->current_status }}
            </span>
        </div>

        @if (session('status'))
            <div class="bg-[#f0f5f4] text-[#044b46] text-sm p-3.5 rounded-lg mb-6">{{ session('status') }}</div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

            <div class="lg:col-span-2 space-y-5">

                @include('content-items.partials.ai-brief', ['contentItem' => $contentItem])

                <div class="card p-5">
                    <h3 class="text-sm font-semibold text-[#14181a] mb-3">Ide Awal (Brief Mentah)</h3>
                    <p class="text-sm text-[#5c6266] whitespace-pre-line mb-4">
                        {{ $contentItem->brief ?: 'Belum ada brief.' }}</p>
                    <div class="grid grid-cols-2 gap-4 text-xs">
                        <div>
                            <p class="text-[#9aa0a4] uppercase font-medium mb-1">Platform</p>
                            <p class="text-[#5c6266]">{{ $contentItem->platform->name ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-[#9aa0a4] uppercase font-medium mb-1">Deadline</p>
                            <p class="text-[#5c6266]">{{ $contentItem->deadline_at->format('d M Y, H:i') }}</p>
                        </div>
                    </div>
                </div>

                <div class="card p-5">
                    <h3 class="text-sm font-semibold text-[#14181a] mb-4">Revision Log
                        ({{ $contentItem->revisions->count() }})</h3>

                    <div class="space-y-2.5 mb-4">
                        @forelse ($contentItem->revisions as $revision)
                            <div
                                class="border border-[#eef0f4] rounded-lg p-3 {{ $revision->status === 'open' ? 'bg-[#fdf6ec]' : 'bg-[#f7f8fc]' }}">
                                <div class="flex items-center justify-between mb-1">
                                    <p class="text-xs font-medium text-[#14181a]">Revisi #{{ $revision->revision_round }} -
                                        {{ $revision->requestedBy->name ?? '-' }}</p>
                                    <span
                                        class="text-[10px] px-2 py-0.5 rounded-full {{ $revision->status === 'open' ? 'bg-[#f7e8cf] text-[#8a6423]' : 'bg-[#f0f5f4] text-[#0f7a5f]' }}">{{ $revision->status }}</span>
                                </div>
                                <p class="text-xs text-[#5c6266]">{{ $revision->revision_note }}</p>
                                @if ($revision->status === 'open')
                                    <form action="{{ route('content-revision.resolve', [$contentItem, $revision]) }}" method="POST"
                                        class="mt-2">
                                        @csrf @method('PATCH')
                                        <button class="text-[10px] font-medium text-[#044b46] hover:underline">Tandai
                                            Selesai</button>
                                    </form>
                                @endif
                            </div>
                        @empty
                            <p class="text-xs text-[#9aa0a4] italic">Belum ada revisi.</p>
                        @endforelse
                    </div>

                    <form action="{{ route('content-revision.store', $contentItem) }}" method="POST" class="flex gap-2">
                        @csrf
                        <input type="text" name="revision_note" required placeholder="Tulis catatan revisi..."
                            class="flex-1 border border-[#eef0f4] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                        <button type="submit"
                            class="bg-[#044b46] text-white text-xs font-medium px-4 py-2 rounded-lg hover:bg-[#033b37]">Kirim</button>
                    </form>
                </div>

                @if ($contentItem->is_posted)
                    <div class="card p-5">
                        <h3 class="text-sm font-semibold text-[#14181a] mb-3">Publikasi</h3>
                        @foreach ($contentItem->publications as $pub)
                            <div class="text-xs text-[#5c6266] border-b border-[#f2f3f6] py-2 last:border-0">
                                <p class="font-medium text-[#14181a]">{{ $pub->platform->name }} —
                                    {{ $pub->published_at->format('d M Y, H:i') }}</p>
                                @if ($pub->post_url)
                                    <a href="{{ $pub->post_url }}" target="_blank"
                                        class="text-[#044b46] underline">{{ $pub->post_url }}</a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @elseif ($workflow->current_status === 'scheduled')
                    <div class="card p-5">
                        <h3 class="text-sm font-semibold text-[#14181a] mb-4">Catat Publikasi</h3>
                        <form action="{{ route('content-publication.store', $contentItem) }}" method="POST" class="space-y-3">
                            @csrf
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Platform</label>
                                    <div class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs bg-[#f7f8fc] text-[#5c6266]">
                                        {{ $contentItem->platform->name ?? '-' }}
                                    </div>
                                    <input type="hidden" name="platform_id" value="{{ $contentItem->platform_id }}">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Tanggal
                                        Publish</label>
                                    <input type="datetime-local" name="published_at" required
                                        class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Link Post</label>
                                <input type="url" name="post_url" placeholder="https://..."
                                    class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40">
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Caption Final</label>
                                <textarea name="caption_final" rows="2"
                                    class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-[#044b46]/40"></textarea>
                            </div>
                            <button type="submit"
                                class="bg-[#044b46] text-white text-xs font-medium px-4 py-2 rounded-lg hover:bg-[#033b37]">Simpan
                                &amp; Tandai Uploaded</button>
                        </form>
                    </div>
                @endif
            </div>

            <div class="space-y-5">
                @include('content-items.partials.ai-brief-discussion', ['contentItem' => $contentItem])

                <div class="card p-5">
                    <h3 class="text-sm font-semibold text-[#14181a] mb-3">PIC Penugasan</h3>
                    <div class="space-y-3">
                        @forelse ($contentItem->assignments as $assignment)
                            <div class="flex items-center gap-2">
                                @if ($assignment->user->avatar_url)
                                    <img src="{{ $assignment->user->avatar_url }}" class="w-8 h-8 rounded-full object-cover">
                                @else
                                    <div
                                        class="w-8 h-8 rounded-full bg-[#044b46] text-white text-xs font-semibold flex items-center justify-center">
                                        {{ strtoupper(substr($assignment->user->name, 0, 1)) }}</div>
                                @endif
                                <div>
                                    <p class="text-xs font-medium text-[#14181a]">{{ $assignment->user->name }}</p>
                                    <p class="text-[10px] text-[#9aa0a4]">
                                        {{ ucwords(str_replace('_', ' ', $assignment->assignment_role)) }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-[#9aa0a4] italic">Belum ada PIC.</p>
                        @endforelse
                    </div>
                </div>

                    @if ($contentItem->delayRiskScores->isNotEmpty())
                        @php
                            $latestRisk = $contentItem->delayRiskScores->first();
                            $riskColors = [
                                'high' => ['bg' => '#fdf2f1', 'text' => '#b3423e', 'label' => 'Risiko Tinggi'],
                                'medium' => ['bg' => '#fdf6ec', 'text' => '#8a6423', 'label' => 'Risiko Sedang'],
                                'low' => ['bg' => '#f0f5f4', 'text' => '#0f7a5f', 'label' => 'Risiko Rendah'],
                            ];
                            $riskColor = $riskColors[$latestRisk->risk_level] ?? $riskColors['low'];
                        @endphp
                        <div class="card p-5">
                            <h3 class="text-sm font-semibold text-[#14181a] mb-3">AI Delay Risk</h3>

                            <div class="rounded-lg p-3 mb-3" style="background-color: {{ $riskColor['bg'] }};">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-semibold" style="color: {{ $riskColor['text'] }};">
                                        {{ $riskColor['label'] }}
                                    </span>
                                    <span class="text-2xl font-bold" style="color: {{ $riskColor['text'] }};">
                                        {{ $latestRisk->risk_score }}%
                                    </span>
                                </div>
                                @if ($latestRisk->top_factor)
                                    <p class="text-xs" style="color: {{ $riskColor['text'] }};">
                                        Faktor utama: {{ $latestRisk->top_factor }}
                                    </p>
                                @endif
                            </div>

                            <p class="text-[10px] text-[#9aa0a4]">
                                Dihitung {{ $latestRisk->created_at->diffForHumans() }}
                            </p>

                            <p class="text-[10px] text-[#c3c7cb] mt-2 italic">
                                Skor ini estimasi otomatis (bukan keputusan final), gunakan sebagai sinyal awal untuk prioritas.
                            </p>
                        </div>
                    @endif

                    <div class="card p-5">
                        <h3 class="text-sm font-semibold text-[#14181a] mb-3">Status History</h3>
                        <div class="space-y-3">
                            @forelse ($contentItem->statusLogs->sortByDesc('changed_at') as $log)
                                <div class="flex gap-3">
                                    <div class="w-1.5 h-1.5 rounded-full bg-[#044b46] mt-1.5 flex-shrink-0"></div>
                                    <div>
                                        <p class="text-xs text-[#5c6266]">
                                            <span
                                                class="font-medium text-[#14181a]">{{ $log->from_status ? ($statusLabels[$log->from_status] ?? $log->from_status) : 'Dibuat' }}</span>
                                            →
                                            <span
                                                class="font-medium text-[#14181a]">{{ $statusLabels[$log->to_status] ?? $log->to_status }}</span>
                                        </p>
                                        <p class="text-[10px] text-[#9aa0a4]">{{ $log->changedBy->name ?? '-' }} ·
                                            {{ $log->changed_at->format('d M, H:i') }}</p>
                                        @if ($log->notes)
                                            <p class="text-[10px] text-[#9aa0a4] italic mt-0.5">{{ $log->notes }}</p>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="text-xs text-[#9aa0a4] italic">Belum ada histori.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
@endsection
