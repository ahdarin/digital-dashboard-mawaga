@extends('layouts.client')
@section('title', $contentItem->title)
@section('content')

    @php
        $canReview = $contentItem->workflow->current_status === 'waiting_review' && ! $alreadyReviewed;
    @endphp

    <div x-data="{ showRevisionForm: false }">

        <div class="bg-[var(--surface-card)] border-b border-[var(--border)] px-4 sm:px-5 py-4 flex items-center gap-3">
            <a href="{{ url()->previous() }}" class="text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors shrink-0">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <div class="min-w-0">
                <p class="text-xs text-[var(--text-muted)] truncate">{{ $contentItem->contentType->name ?? '-' }}</p>
                <h1 class="font-display text-base font-semibold text-[var(--text-primary)] truncate">{{ $contentItem->title }}</h1>
            </div>
        </div>

        <div class="p-4 sm:p-5 space-y-4">

            <span class="inline-block text-[10px] font-bold px-2 py-1 rounded uppercase
                {{ $contentItem->workflow->current_status === 'uploaded' ? 'text-[var(--success-text)] bg-[var(--success-tint)]' : ($canReview ? 'text-[var(--warning-text)] bg-[var(--warning-tint)]' : 'text-[var(--info-text)] bg-[var(--info-tint)]') }}">
                {{ \App\Support\WorkflowTransitions::label($contentItem->workflow->current_status) }}
            </span>

            @if ($contentItem->is_posted && $contentItem->publications->isNotEmpty())
                <div class="space-y-2">
                    @foreach ($contentItem->publications as $pub)
                        @if ($pub->post_url)
                            <a href="{{ $pub->post_url }}" target="_blank" rel="noopener"
                               class="flex items-center gap-2.5 bg-[var(--brand-tint)] text-[var(--brand)] text-sm font-medium px-4 py-3 rounded-2xl hover:bg-[var(--brand-tint-hover)] transition-colors">
                                <span class="material-symbols-outlined text-[17px]">open_in_new</span>
                                Buka Konten{{ $pub->platform?->name ? ' di ' . $pub->platform->name : '' }}
                                @if ($pub->published_at)
                                    <span class="text-xs text-[var(--brand)]/70 ml-auto whitespace-nowrap">{{ $pub->published_at->format('d M Y') }}</span>
                                @endif
                            </a>
                        @endif
                    @endforeach
                </div>
            @endif

            <div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]">
                <p class="text-xs font-semibold text-[var(--text-muted)] uppercase mb-2">Caption / Copy</p>
                <p class="text-sm text-[var(--text-primary)] whitespace-pre-line">{{ $contentItem->caption_draft ?: 'Belum ada draft caption.' }}</p>
            </div>

            <div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)] grid grid-cols-2 gap-4 text-xs">
                <div>
                    <p class="text-[var(--text-muted)] uppercase font-semibold mb-1">Platform</p>
                    <p class="text-[var(--text-primary)]">{{ $contentItem->platform->name ?? '-' }}</p>
                </div>
                <div>
                    <p class="text-[var(--text-muted)] uppercase font-semibold mb-1">Deadline</p>
                    <p class="text-[var(--text-primary)]">{{ $contentItem->deadline_at->format('d M Y') }}</p>
                </div>
            </div>

            @if ($alreadyReviewed)
                <div class="bg-[var(--brand-tint)] border border-[var(--brand-tint-border)] text-[var(--brand)] text-sm p-4 rounded-2xl font-medium">
                    Anda sudah menyetujui konten ini. Menunggu pengecekan akhir dari tim internal sebelum resmi ditandai Disetujui.
                </div>
            @elseif ($canReview)
                <div class="grid grid-cols-2 gap-3">
                    <button x-on:click="showRevisionForm = true"
                            class="border border-[var(--border)] text-[var(--text-primary)] text-sm font-medium py-3 rounded-2xl hover:bg-[var(--surface-page)] transition-colors">
                        Request Revision
                    </button>
                    <form action="{{ route('client.approval.approve', $contentItem) }}" method="POST">
                        @csrf
                        <button type="submit" class="w-full bg-[var(--brand-solid)] hover:bg-[var(--brand-dark)] active:scale-[0.98] text-white text-sm font-medium py-3 rounded-2xl transition-all">
                            Approve Asset
                        </button>
                    </form>
                </div>

                <div x-show="showRevisionForm" x-transition class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]" style="display: none;">
                    <form action="{{ route('client.approval.request-revision', $contentItem) }}" method="POST" class="space-y-3">
                        @csrf
                        <label for="revision_note" class="block text-xs font-semibold text-[var(--text-muted)] uppercase">Catatan Revisi</label>
                        <textarea id="revision_note" name="revision_note" required rows="3"
                                  class="bg-[var(--surface-card)] w-full border border-[var(--border)] rounded-2xl px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[#044b46]/10 focus:border-[#044b46]/40 transition-all"
                                  placeholder="Jelaskan perubahan yang diinginkan..."></textarea>
                        <button type="submit" class="w-full bg-[var(--brand-solid)] hover:bg-[var(--brand-dark)] active:scale-[0.98] text-white text-sm font-medium py-2.5 rounded-2xl transition-all">
                            Kirim Revisi
                        </button>
                    </form>
                </div>
            @else
                {{-- Konten belum sampai tahap review (misal masih dikerjakan)
                     atau sudah lewat tahap review (misal sudah tayang) -
                     nggak ada aksi yang relevan, cukup tampil info. Halaman
                     ini juga dipakai Kalender & Riwayat, jadi status di sini
                     bisa apa saja, bukan cuma waiting_review. --}}
                <div class="bg-[var(--surface-muted)] text-[var(--text-secondary)] text-sm p-4 rounded-2xl">
                    Tidak ada tindakan yang perlu dilakukan untuk konten ini saat ini.
                </div>
            @endif
        </div>
    </div>
@endsection
