@extends('layouts.client')
@section('title', 'Dashboard')
@section('content')

    <div class="p-4 sm:p-5 space-y-4">

        {{-- a. Jumbotron - ringkasan singkat identitas client --}}
        <div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 sm:p-5 shadow-[0_1px_2px_rgba(20,24,26,0.03)] flex items-center gap-3.5">
            <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-[var(--brand-tint)] text-[var(--brand)] flex items-center justify-center text-xl font-semibold shrink-0 overflow-hidden">
                @if ($client->logo_url)
                    <img src="{{ $client->logo_url }}" alt="" class="w-full h-full object-cover">
                @else
                    {{ strtoupper(substr($client->brand_name, 0, 1)) }}
                @endif
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <h1 class="font-display text-lg sm:text-xl font-semibold text-[var(--text-primary)] truncate">{{ $client->brand_name }}</h1>
                    <span class="text-[10px] font-bold px-2 py-1 rounded uppercase shrink-0
                        {{ $client->status === 'active' ? 'text-[var(--success-text)] bg-[var(--success-tint)]' : ($client->status === 'past_due' ? 'text-[var(--danger-text)] bg-[var(--danger-tint)]' : 'text-[var(--text-secondary)] bg-[var(--surface-muted)]') }}">
                        {{ match($client->status) { 'active' => 'Aktif', 'past_due' => 'Jatuh Tempo', 'paused' => 'Dijeda', default => ucfirst($client->status) } }}
                    </span>
                </div>
                <p class="text-xs sm:text-sm text-[var(--text-muted)] mt-0.5 truncate">
                    {{ $client->category->name ?? '-' }}
                    @if ($client->activePackage)
                        &middot; Paket {{ $client->activePackage->package_name_snapshot }}
                    @endif
                </p>
            </div>
        </div>

        {{-- b. 4 stat card, masing-masing bisa diklik menuju halaman terkait --}}
        @php
            $statIconChips = ['bg-[var(--brand-tint)] text-[var(--brand)]', 'bg-[var(--warning-tint)] text-[var(--warning-text)]', 'bg-[var(--success-tint)] text-[var(--success-text)]', 'bg-[var(--info-tint)] text-[var(--info-text)]'];
        @endphp
        <div class="grid grid-cols-2 gap-3">
            @foreach ($stats as $stat)
                <a href="{{ $stat['link'] }}"
                   class="block bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-3.5 sm:p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)] hover:shadow-[0_4px_16px_-4px_rgba(20,24,26,0.08)] transition-shadow">
                    <div class="w-8 h-8 rounded-lg {{ $statIconChips[$loop->index % 4] }} flex items-center justify-center mb-3">
                        <span class="material-symbols-outlined text-[16px]">{{ $stat['icon'] }}</span>
                    </div>
                    <p class="text-[11px] text-[var(--text-muted)] mb-1">{{ $stat['label'] }}</p>
                    <p class="font-display text-xl sm:text-2xl font-semibold text-[var(--text-primary)]">{{ $stat['value'] }}</p>
                </a>
            @endforeach
        </div>

        {{-- c. Persetujuan - dulu halaman "Approval" tersendiri, sekarang jadi
             satu bagian di dalam Dashboard. --}}
        <div id="persetujuan" class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)] scroll-mt-32">
            <p class="text-sm font-semibold text-[var(--text-primary)] mb-3">Persetujuan</p>

            @if ($pendingApprovalItems->isEmpty() && $reviewedApprovalItems->isEmpty())
                <div class="text-center py-8">
                    <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">task_alt</span>
                    <p class="text-sm text-[var(--text-muted)]">Tidak ada konten yang perlu ditinjau saat ini.</p>
                    <p class="text-xs text-[var(--text-muted)] mt-1">Konten baru akan muncul di sini begitu tim selesai mengerjakannya.</p>
                </div>
            @else
                @if ($pendingApprovalItems->isNotEmpty())
                    <div class="space-y-3">
                        @foreach ($pendingApprovalItems as $item)
                            <a href="{{ route('client.portal.approval.show', ['token' => $portalToken, 'contentItem' => $item]) }}"
                               class="block bg-[var(--surface-page)] rounded-xl border border-[var(--border)] p-3.5 hover:bg-[var(--surface-muted)] transition-colors">
                                <div class="flex justify-between items-start mb-2">
                                    <span class="text-[10px] font-bold text-[var(--warning-text)] bg-[var(--warning-tint)] px-2 py-1 rounded uppercase">Menunggu Persetujuan</span>
                                    <span class="text-[10px] text-[var(--text-muted)]">Tenggat: {{ $item->deadline_at->format('d M') }}</span>
                                </div>
                                <p class="text-sm font-semibold text-[var(--text-primary)]">{{ $item->title }}</p>
                                <p class="text-xs text-[var(--text-muted)] mt-1">{{ $item->contentType->name ?? '-' }} &middot; {{ $item->platform->name ?? '-' }}</p>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if ($reviewedApprovalItems->isNotEmpty())
                    <p class="text-xs font-semibold text-[var(--text-muted)] uppercase mt-5 mb-3">Menunggu Pengecekan Tim ({{ $reviewedApprovalItems->count() }})</p>
                    <div class="space-y-3">
                        @foreach ($reviewedApprovalItems as $item)
                            <div class="bg-[var(--surface-page)] rounded-xl border border-[var(--border)] p-3.5 opacity-70">
                                <div class="flex justify-between items-start mb-2">
                                    <span class="text-[10px] font-bold text-[var(--success-text)] bg-[var(--success-tint)] px-2 py-1 rounded uppercase">Sudah Anda Setujui</span>
                                    <span class="text-[10px] text-[var(--text-muted)]">Tenggat: {{ $item->deadline_at->format('d M') }}</span>
                                </div>
                                <p class="text-sm font-semibold text-[var(--text-primary)]">{{ $item->title }}</p>
                                <p class="text-xs text-[var(--text-muted)] mt-1">{{ $item->contentType->name ?? '-' }} &middot; {{ $item->platform->name ?? '-' }} &middot; Menunggu pengecekan tim internal</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        {{-- d. Konten Terbaru - "Lihat semua" menuju Kalender, bukan Riwayat. --}}
        <div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-semibold text-[var(--text-primary)]">Konten Terbaru</p>
                <a href="{{ route('client.portal.calendar', $portalToken) }}" class="text-xs font-medium text-[var(--brand)] hover:underline">Lihat semua</a>
            </div>
            @if ($recentItems->isEmpty())
                <p class="text-sm text-[var(--text-muted)] text-center py-6">Belum ada konten.</p>
            @else
                <div class="space-y-3">
                    @foreach ($recentItems as $item)
                        <a href="{{ route('client.portal.approval.show', ['token' => $portalToken, 'contentItem' => $item['id']]) }}"
                           class="flex items-center justify-between gap-3 {{ !$loop->last ? 'pb-3 border-b border-[var(--border)]' : '' }}">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-[var(--text-primary)] truncate">{{ $item['title'] }}</p>
                                <p class="text-xs text-[var(--text-muted)] mt-0.5">{{ $item['type'] }} &middot; {{ $item['deadline']->format('d M Y') }}</p>
                            </div>
                            <span class="text-[10px] font-medium text-[var(--text-secondary)] bg-[var(--surface-muted)] px-2 py-1 rounded shrink-0 whitespace-nowrap">{{ $item['status'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

    </div>
@endsection
