@extends('layouts.app')
@section('title', $contentPlan->client->name . ' - Content Plan')
@section('content')
<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    {{-- Header — susunan sama seperti /content-plan: judul & konteks di kiri,
         aksi utama di kanan (bukan tumpuk banyak tombol sekaligus). --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-7">
        <div class="flex items-start gap-3">
            <a href="{{ route('content-plan.index') }}" title="Kembali ke daftar Content Plan"
               class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[var(--surface-card)] text-[var(--text-secondary)] transition-colors shrink-0 mt-0.5">
                <span class="material-symbols-outlined text-[19px]">arrow_back</span>
            </a>
            <div>
                <p class="text-xs text-[var(--text-muted)] mb-1">
                    <a href="{{ route('content-plan.index') }}" class="hover:text-[var(--brand)]">Rencana Konten</a> /
                    {{ \Carbon\Carbon::create()->month($contentPlan->month)->translatedFormat('F') }} {{ $contentPlan->year }}
                </p>
                <div class="flex items-center gap-3 flex-wrap">
                    <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[var(--text-primary)]">{{ $contentPlan->client->name }}</h1>
                    <span class="badge
                        {{ $contentPlan->status === 'approved' ? 'badge-success' : '' }}
                        {{ $contentPlan->status === 'draft' ? 'badge-neutral' : '' }}
                        {{ $contentPlan->status === 'pending' ? 'badge-warning' : '' }}
                        {{ $contentPlan->status === 'rejected' ? 'badge-danger' : '' }}">
                        {{ $contentPlan->status === 'pending' ? 'Diajukan' : ($contentPlan->status === 'draft' ? 'Draf' : ($contentPlan->status === 'rejected' ? 'Ditolak' : 'Disetujui')) }}
                    </span>
                    @if ($contentPlan->status === 'approved' && $contentPlan->created_by === $contentPlan->approved_by)
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-[var(--warning-tint)] text-[var(--warning-text)] uppercase" title="Pembuat rencana ini juga yang menyetujuinya">
                            Disetujui Sendiri
                        </span>
                    @endif
                </div>
                <p class="text-[var(--text-secondary)] text-sm mt-1">
                    @if ($contentPlan->clientPackage)
                        Target: {{ $contentPlan->clientPackage->monthly_content_quota }} Content /
                        {{ $contentPlan->clientPackage->monthly_design_quota }} Design
                    @else
                        {{-- client_package_id NULL = data paket belum tercatat, BUKAN client
                             tanpa paket - jangan tampilkan "0 Content / 0 Design" (Langkah 2). --}}
                        <span class="text-[var(--text-muted)] italic">Paket belum tercatat</span>
                    @endif
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap shrink-0" x-data="{ rejectOpen: {{ $errors->has('rejection_note') ? 'true' : 'false' }} }">
            @if ($contentPlan->status === 'pending' && auth()->user()->hasPermissionTo('content_plan', 'approve'))
                <button type="button" @click="rejectOpen = true" class="btn-danger">Tolak</button>
                <form action="{{ route('content-plan.approve', $contentPlan) }}" method="POST">
                    @csrf @method('PATCH')
                    <button class="btn-primary">
                        <span class="material-symbols-outlined text-[16px]">check</span> Setujui Rencana
                    </button>
                </form>

                <template x-teleport="body">
                    <div x-show="rejectOpen" x-cloak x-on:keydown.escape.window="rejectOpen = false" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                        <div class="absolute inset-0 bg-[#14181a]/40" @click="rejectOpen = false"></div>
                        <div x-show="rejectOpen" x-transition role="dialog" aria-modal="true" class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md">
                            <form action="{{ route('content-plan.reject', $contentPlan) }}" method="POST">
                                @csrf @method('PATCH')
                                <div class="px-6 py-5 border-b border-[var(--border)]">
                                    <h3 class="font-display text-lg font-semibold text-[var(--text-primary)]">Tolak Rencana</h3>
                                    <p class="text-xs text-[var(--text-muted)] mt-0.5">Catatan ini akan tersimpan sebagai riwayat dan membantu pembuat rencana memperbaikinya sebelum diajukan ulang.</p>
                                </div>
                                <div class="px-6 py-5">
                                    <label for="rejection_note" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Alasan Penolakan <span class="text-[var(--danger-text)]">*</span></label>
                                    <textarea id="rejection_note" name="rejection_note" rows="4" required
                                        class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('rejection_note') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}"
                                        placeholder="Jelaskan apa yang perlu diperbaiki...">{{ old('rejection_note') }}</textarea>
                                    @error('rejection_note') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                                    <button type="submit" class="btn-danger">Tolak Rencana</button>
                                    <button type="button" @click="rejectOpen = false" class="btn-secondary">Batal</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </template>
            @endif

            @if ($contentPlan->status === 'rejected' && auth()->user()->hasPermissionTo('content_plan', 'create'))
                <form action="{{ route('content-plan.reopen', $contentPlan) }}" method="POST">
                    @csrf @method('PATCH')
                    <button class="btn-primary whitespace-nowrap">
                        <span class="material-symbols-outlined text-[16px]">undo</span> Kembalikan ke Draf & Perbaiki
                    </button>
                </form>
            @endif

            @if (auth()->user()->hasPermissionTo('content_plan', 'create'))
                <a href="{{ route('content-plan.items.create', $contentPlan) }}" class="btn-primary whitespace-nowrap">
                    <span class="material-symbols-outlined text-[16px]">add</span> Tambah Konten
                </a>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="bg-[var(--brand-tint)] text-[var(--brand)] text-sm p-3.5 rounded-lg mb-5">{{ session('status') }}</div>
    @endif

    <div class="card overflow-hidden hidden sm:block">
      <div class="overflow-x-auto">
        <table class="w-full table-fixed text-sm text-left">
            <thead class="bg-[var(--surface-page)]">
                <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                    <th class="w-[30%] px-6 py-3 font-medium">Detail Item</th>
                    <th class="w-[13%] px-4 py-3 font-medium">Kategori</th>
                    <th class="w-[10%] px-4 py-3 font-medium">Platform</th>
                    <th class="w-[11%] px-4 py-3 font-medium whitespace-nowrap">Deadline</th>
                    <th class="w-[17%] px-4 py-3 font-medium">Penanggung Jawab</th>
                    <th class="w-[13%] px-6 py-3 font-medium">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr class="border-t border-[var(--surface-muted)] hover:bg-[var(--surface-page)] transition-colors cursor-pointer"
                        onclick="navigateTo('{{ route('content-items.show', $item) }}')">
                        <td class="px-6 py-3.5">
                            <p class="font-medium text-[var(--text-primary)] line-clamp-2" title="{{ $item->title }}">{{ $item->title }}</p>
                            <p class="text-xs text-[var(--text-muted)] mt-0.5 truncate">{{ $item->contentPillar->name ?? '-' }} &middot; ID: {{ $item->id }}</p>
                        </td>
                        <td class="px-4 py-3.5">
                            @php
                                $typeColor = match ($item->contentType->name ?? null) {
                                    'Video' => '#3452a8',
                                    'Desain' => '#b3427e',
                                    default => '#9aa0a4',
                                };
                            @endphp
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full" style="background-color: {{ $typeColor }}14; color: {{ $typeColor }}">
                                {{ $item->contentType->name ?? '-' }}
                            </span>
                            @if ($item->content_format)
                                <span class="block text-[10px] text-[var(--text-muted)] mt-1">{{ $item->content_format }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3.5 text-[var(--text-secondary)] truncate">{{ $item->platform->name ?? '-' }}</td>
                        <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $item->deadline_at->format('d M Y') }}</td>
                        <td class="px-4 py-3.5">
                            @php $pic = $picResolver->resolve($item); @endphp
                            @if ($pic['name'])
                                <span class="text-xs text-[var(--text-secondary)] block truncate" title="{{ $pic['name'] }}">{{ $pic['name'] }}</span>
                                @if (! $pic['has_account'])
                                    <span class="block text-[10px] text-[var(--text-muted)] italic">Belum memiliki akun</span>
                                @endif
                            @else
                                <span class="text-xs text-[var(--text-muted)] italic">Belum ditugaskan</span>
                            @endif
                        </td>
                        <td class="px-6 py-3.5">
                            <span class="badge {{ $item->workflow?->is_overdue ? 'badge-danger' : 'badge-success' }}">
                                {{ $item->workflow ? \App\Support\WorkflowTransitions::label($item->workflow->current_status) : 'Planned' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-10 text-center text-[var(--text-muted)] text-sm">Belum ada content item. Klik "Tambah Konten" buat mulai.</td></tr>
                @endforelse
            </tbody>
        </table>
      </div>
    </div>

    {{-- Mobile accordion cards - sama koleksi data, hanya tampil di bawah sm --}}
    <div class="sm:hidden space-y-3">
        @forelse ($items as $item)
            @php
                $typeColor = match ($item->contentType->name ?? null) {
                    'Video' => '#3452a8',
                    'Desain' => '#b3427e',
                    default => '#9aa0a4',
                };
                $pic = $picResolver->resolve($item);
            @endphp
            <div x-data="{ open: false }" class="card p-3.5">
                <button type="button" class="w-full text-left flex items-start justify-between gap-2 cursor-pointer" @click="open = !open" :aria-expanded="open">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-[var(--text-primary)] truncate">{{ $item->title }}</p>
                        <p class="text-xs text-[var(--text-muted)] mt-0.5">{{ $item->contentPillar->name ?? '-' }} &middot; ID: {{ $item->id }}</p>
                        <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">
                            <span class="badge {{ $item->workflow?->is_overdue ? 'badge-danger' : 'badge-success' }}">
                                {{ $item->workflow ? \App\Support\WorkflowTransitions::label($item->workflow->current_status) : 'Planned' }}
                            </span>
                            <span class="text-xs text-[var(--text-secondary)]">{{ $item->deadline_at->format('d M Y') }}</span>
                        </div>
                    </div>
                    <span class="shrink-0 flex items-center justify-center w-7 h-7 rounded-lg text-[var(--text-muted)]">
                        <span class="material-symbols-outlined text-[20px] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                    </span>
                </button>

                <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[var(--surface-muted)] space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Kategori</span>
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full" style="background-color: {{ $typeColor }}14; color: {{ $typeColor }}">
                            {{ $item->contentType->name ?? '-' }}
                        </span>
                    </div>
                    @if ($item->content_format)
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-[var(--text-muted)]">Format</span>
                            <span class="text-[var(--text-primary)] font-medium">{{ $item->content_format }}</span>
                        </div>
                    @endif
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Platform</span>
                        <span class="text-[var(--text-primary)] font-medium">{{ $item->platform->name ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Penanggung Jawab</span>
                        @if ($pic['name'])
                            <span class="text-right">
                                <span class="text-[var(--text-primary)] font-medium block">{{ $pic['name'] }}</span>
                                @if (! $pic['has_account'])
                                    <span class="text-[10px] text-[var(--text-muted)] italic">Belum memiliki akun</span>
                                @endif
                            </span>
                        @else
                            <span class="text-[var(--text-muted)] italic">Belum ada</span>
                        @endif
                    </div>
                    <a href="{{ route('content-items.show', $item) }}"
                        class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] rounded-lg py-2 transition-colors">
                        Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>
            </div>
        @empty
            <div class="card p-8 text-center text-[var(--text-muted)] text-sm">Belum ada content item. Klik "Tambah Konten" buat mulai.</div>
        @endforelse
    </div>

    {{-- KI-13 - riwayat keputusan (Ajukan/Setujui/Tolak/Kembalikan ke Draf).
         Selalu ditampilkan kalau ada entri, supaya catatan penolakan lama
         tetap terlihat walau rencana sudah dikembalikan ke Draf & diajukan
         ulang berkali-kali - bukan cuma status akhir saja. --}}
    @if ($contentPlan->statusLogs->isNotEmpty())
        <div class="card p-5 mt-6">
            <h2 class="text-sm font-semibold text-[var(--text-primary)] mb-3">Riwayat Keputusan</h2>
            <div class="space-y-3">
                @foreach ($contentPlan->statusLogs as $log)
                    @php
                        $logLabel = fn ($s) => match ($s) {
                            'draft' => 'Draf', 'pending' => 'Menunggu Persetujuan',
                            'approved' => 'Disetujui', 'rejected' => 'Ditolak', default => $s,
                        };
                    @endphp
                    <div class="flex items-start gap-3 text-sm">
                        <span class="material-symbols-outlined text-[16px] text-[var(--text-muted)] mt-0.5">
                            {{ $log->to_status === 'rejected' ? 'cancel' : ($log->to_status === 'approved' ? 'check_circle' : 'sync_alt') }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[var(--text-primary)]">
                                @if ($log->from_status)
                                    {{ $logLabel($log->from_status) }} &rarr; {{ $logLabel($log->to_status) }}
                                @else
                                    {{ $logLabel($log->to_status) }}
                                @endif
                                <span class="text-[var(--text-muted)]">oleh {{ $log->changedByUser->name ?? '-' }}</span>
                            </p>
                            @if ($log->notes)
                                <p class="text-xs text-[var(--text-secondary)] mt-0.5 whitespace-pre-line">{{ $log->notes }}</p>
                            @endif
                            <p class="text-[11px] text-[var(--text-muted)] mt-0.5">{{ $log->changed_at->translatedFormat('d M Y, H:i') }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- "Ajukan Rencana" diletakkan setelah daftar konten - user meninjau
         seluruh isi rencana dulu sebelum diajukan, bukan tombol yang
         menumpuk di header sebelum konten sempat dilihat. --}}
    @if ($contentPlan->status === 'draft' && auth()->user()->hasPermissionTo('content_plan', 'create'))
        <div class="card p-5 mt-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-[var(--text-primary)]">Sudah siap diajukan?</p>
                <p class="text-xs text-[var(--text-muted)] mt-0.5">Setelah diajukan, rencana ini akan menunggu persetujuan Manager/CEO.</p>
            </div>
            <form action="{{ route('content-plan.submit', $contentPlan) }}" method="POST">
                @csrf @method('PATCH')
                <button class="btn-primary whitespace-nowrap">
                    <span class="material-symbols-outlined text-[16px]">send</span> Ajukan Rencana
                </button>
            </form>
        </div>
    @endif
</div>
@endsection
