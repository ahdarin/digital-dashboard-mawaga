@extends('layouts.app')
@section('title', 'Riwayat AI Strategy — ' . $client->name)
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-4xl mx-auto">

    <div class="flex items-center gap-2 text-xs text-[#767c80] mb-3">
        <a href="{{ route('analytics', ['client_id' => $client->id]) }}" class="hover:text-[#044b46] font-medium">Performa</a>
        <span class="material-symbols-outlined text-[13px]">chevron_right</span>
        <span>{{ $client->name }}</span>
        <span class="material-symbols-outlined text-[13px]">chevron_right</span>
        <span class="text-[#5c6266] font-medium">AI Strategy History</span>
    </div>

    <div class="flex items-center gap-3 mb-7">
        <a href="{{ route('analytics', ['client_id' => $client->id]) }}" class="text-[#767c80] hover:text-[#5c6266]">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
            <h1 class="font-display text-[26px] font-semibold text-[#14181a]">Riwayat Analisis AI Strategy</h1>
            <p class="text-[#5c6266] mt-0.5 text-sm">Semua analisis yang pernah digenerate buat {{ $client->name }}, termasuk yang udah ketiban "Generate Ulang".</p>
        </div>
    </div>

    @if (session('ai_success'))
        <div class="mb-5 bg-[#f0f5f4] border border-[#dbe6e4] rounded-lg p-4 text-sm font-medium text-[#044b46] flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">check_circle</span>
            {{ session('ai_success') }}
        </div>
    @endif

    @if (session('ai_error'))
        <div class="mb-5 bg-[#fdf2f1] border border-[#f5d9d7] rounded-lg p-4 text-sm font-medium text-[#b3423e] flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">error</span>
            {{ session('ai_error') }}
        </div>
    @endif

    @if ($insights->isEmpty())
        <div class="card p-16 flex flex-col items-center justify-center text-center">
            <div class="w-14 h-14 rounded-full bg-[#f0f5f4] flex items-center justify-center mb-4">
                <span class="material-symbols-outlined text-[#044b46] text-[26px]">history</span>
            </div>
            <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1.5">Belum pernah ada analisis</h2>
            <p class="text-sm text-[#5c6266] max-w-sm">Generate analisis pertama dulu lewat halaman Analytics.</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($insights as $i => $insight)
                <div class="card p-5">
                    <div class="flex items-start justify-between gap-4 mb-3">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-display text-base font-semibold text-[#14181a]">
                                {{ $insight->period_start?->translatedFormat('F Y') ?? '-' }}
                            </span>

                            @if ($i === 0)
                                <span class="badge badge-info">Terbaru</span>
                            @endif

                            <span class="badge {{ $insight->status === 'completed' ? 'badge-success' : 'badge-danger' }}">
                                {{ $insight->status === 'completed' ? 'Berhasil' : 'Gagal' }}
                            </span>

                            @if ($insight->applied_at)
                                <span class="text-[10px] font-medium px-2 py-0.5 rounded-full bg-[#f7fbf9] text-[#0f7a5f] border border-[#eaf5f0] flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[12px]">check_circle</span>
                                    Diterapkan {{ $insight->applied_at->diffForHumans() }}
                                </span>
                            @endif
                        </div>

                        <span class="text-xs text-[#767c80] shrink-0">{{ $insight->created_at->translatedFormat('d M Y, H:i') }}</span>
                    </div>

                    @if ($insight->status === 'completed')
                        <p class="text-sm text-[#14181a] leading-relaxed mb-3">{{ Str::limit($insight->summary, 220) }}</p>
                        <p class="text-xs text-[#767c80] mb-4">
                            {{ count($insight->action_items ?? []) }} action item &middot;
                            {{ count($insight->content_ideas ?? []) }} ide konten &middot;
                            digenerate oleh {{ $insight->generatedBy->name ?? '-' }}
                        </p>

                        <div class="flex items-center gap-2">
                            @if (! $insight->applied_at)
                                <form action="{{ route('analytics.ai-strategy.apply', $insight) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn-primary">
                                        <span class="material-symbols-outlined text-[14px]">bolt</span>
                                        Terapkan ke Content Plan
                                    </button>
                                </form>
                            @else
                                <form action="{{ route('analytics.ai-strategy.revert', $insight) }}" method="POST"
                                      onsubmit="return appConfirm(this, 'Yakin mau tarik kembali? Semua draft content item yang dibuat dari analisis ini bakal dihapus (kalau belum ada progress).', { danger: true })">
                                    @csrf
                                    <button type="submit" class="btn-danger">
                                        <span class="material-symbols-outlined text-[14px]">undo</span>
                                        Tarik Kembali
                                    </button>
                                </form>
                            @endif

                            @if ($i === 0)
                                <a href="{{ route('analytics', ['client_id' => $client->id]) }}" class="text-xs font-medium text-[#5c6266] hover:text-[#14181a] px-3.5 py-2">
                                    Lihat detail &amp; diskusi &rarr;
                                </a>
                            @endif
                        </div>
                    @else
                        <p class="text-sm text-[#b3423e]">{{ $insight->error_message ?? 'Gagal generate, nggak ada detail error.' }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>

@endsection
