@extends('layouts.app')
@section('title', 'Unmatched TikTok Video')
@section('content')
    <div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">
        <div class="flex items-center gap-3 mb-2">
            <a href="{{ $returnTo }}"
               class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[var(--surface-card)] text-[var(--text-secondary)] transition-colors shrink-0">
                <span class="material-symbols-outlined text-[19px]">arrow_back</span>
            </a>
            <div>
                <h1 class="font-display text-[26px] font-semibold text-[var(--text-primary)]">Unmatched TikTok Video</h1>
                <p class="text-sm text-[var(--text-muted)] mt-0.5">
                    {{ $apiIntegration->client->name }} &middot; &commat;{{ $apiIntegration->external_username ?? '-' }}
                </p>
            </div>
        </div>

        @if (session('import_success'))
            <div class="mb-5 bg-[var(--brand-tint)] border border-[var(--brand-tint-border)] rounded-lg p-4">
                <p class="text-sm font-medium text-[var(--brand)] flex items-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">check_circle</span>
                    {{ session('import_success') }}
                </p>
            </div>
        @endif

        @if (session('import_error'))
            <div class="mb-5 bg-[var(--danger-tint)] border border-[var(--danger-border)] rounded-lg p-4">
                <p class="text-sm font-medium text-[var(--danger-text)] flex items-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">error</span>
                    {{ session('import_error') }}
                </p>
            </div>
        @endif

        <p class="text-sm text-[var(--text-muted)] mb-1">
            {{ $totalSnapshotted }} video tersimpan dari sync sebelumnya, {{ count($unmatched) }} belum terhubung ke content item.
        </p>
        <p class="text-xs text-[var(--text-muted)] mb-6">
            {{ $lastFetchedAt ? 'Data per sync terakhir: '.$lastFetchedAt->diffForHumans() : 'Belum pernah sync.' }}
            Halaman ini tidak menarik data baru dari TikTok - jalankan Sync di halaman Client Detail untuk data terkini.
        </p>

        <div class="card overflow-hidden">
            @if (empty($unmatched))
                <div class="px-6 py-12 text-center">
                    <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[26px] mb-2 block">check_circle</span>
                    <p class="text-sm text-[var(--text-muted)]">Semua video TikTok akun ini sudah terhubung ke content item.</p>
                </div>
            @else
                <div class="divide-y divide-[var(--surface-muted)]">
                    @foreach ($unmatched as $media)
                        {{-- id="post-{external_post_id}" - konsisten dengan pola
                             Instagram, dipakai action "Hubungkan Konten" dari
                             Performance Table/Top Content buat preselect. --}}
                        <div id="post-{{ $media['id'] }}" class="p-5 flex flex-col sm:flex-row sm:items-start gap-4 scroll-mt-4"
                             x-data="{ open: false, preselected: window.location.hash === '#post-{{ $media['id'] }}' }"
                             x-init="if (preselected) { open = true; $nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' })) }"
                             :class="preselected ? 'ring-2 ring-[var(--brand)] rounded-lg' : ''">
                            <div class="w-16 h-16 rounded-lg bg-[var(--surface-muted)] flex items-center justify-center shrink-0 text-[var(--text-muted)] overflow-hidden">
                                @if ($media['thumbnail_url'])
                                    <img src="{{ $media['thumbnail_url'] }}" alt="" class="w-full h-full object-cover"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                                    <span class="material-symbols-outlined text-[24px]" style="display:none">broken_image</span>
                                @else
                                    <span class="material-symbols-outlined text-[24px]">play_circle</span>
                                @endif
                            </div>

                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-[var(--text-primary)] line-clamp-2">{{ $media['caption'] }}</p>
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1.5 text-xs text-[var(--text-muted)]">
                                    <span>{{ $media['timestamp']?->translatedFormat('d M Y, H:i') ?? '-' }}</span>
                                    <span>&middot;</span>
                                    <span>{{ $media['format'] ?? '-' }}</span>
                                    @if ($media['permalink'])
                                        <span>&middot;</span>
                                        <a href="{{ $media['permalink'] }}" target="_blank" rel="noopener noreferrer" class="text-[var(--brand)] hover:underline">Lihat video</a>
                                    @endif
                                </div>
                                @if ($media['ambiguous_reason'])
                                    <p class="text-xs text-[var(--warning-text,#92600a)] mt-1.5">
                                        <span class="material-symbols-outlined text-[13px] align-middle">warning</span>
                                        {{ $media['ambiguous_reason'] }}
                                    </p>
                                @endif
                            </div>

                            <button type="button" @click="open = !open"
                                    class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity shrink-0 self-start">
                                Link to Content
                            </button>

                            <div x-show="open" x-cloak x-transition class="w-full sm:w-auto sm:basis-full order-last">
                                <form action="{{ route('publishing-tracker.tiktok.link', $apiIntegration) }}" method="POST"
                                      class="mt-3 flex flex-col sm:flex-row items-stretch sm:items-center gap-2 bg-[var(--surface-page)] rounded-lg p-3">
                                    @csrf
                                    <input type="hidden" name="external_post_id" value="{{ $media['id'] }}">
                                    <input type="hidden" name="permalink" value="{{ $media['permalink'] }}">
                                    <input type="hidden" name="timestamp" value="{{ $media['timestamp']?->toDateTimeString() }}">
                                    <input type="hidden" name="caption" value="{{ $media['caption'] }}">

                                    <select name="content_item_id" required
                                            class="flex-1 text-sm border border-[var(--border)] rounded-lg px-3 py-2 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                        <option value="">Pilih content item ({{ $apiIntegration->client->name }})...</option>
                                        @foreach ($contentItemOptions as $option)
                                            <option value="{{ $option->id }}">#{{ $option->id }} - {{ $option->title }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit"
                                            class="text-sm font-medium text-white bg-[var(--brand)] px-4 py-2 rounded-lg hover:opacity-90 transition-opacity shrink-0">
                                        Simpan
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
