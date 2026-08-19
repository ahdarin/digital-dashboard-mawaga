{{-- Shared "Brief Belum Final" queue body for the copywriter role.
     Used by home/index-copywriter and profile/show-copywriter.
     Expects: $briefQueueItems --}}
@php
    // Sama seperti user-work-summary.blade.php - pin cuma boleh diutak-atik
    // di Beranda sendiri atau profil sendiri.
    $showPinButton = ! isset($isOwnProfile) || $isOwnProfile;
@endphp
<div class="card overflow-hidden flex flex-col">
    <div class="p-5 pb-4 shrink-0">
        <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Brief Belum Final ({{ $briefQueueItems->count() }})</h2>
        <p class="text-xs text-[var(--text-muted)] mt-1">Konten yang briefnya belum diterapkan ke tim produksi - baik yang belum digarap sama sekali maupun yang masih draft/diskusi.</p>
    </div>
    <div class="overflow-auto max-h-[520px] thin-autohide-scrollbar hidden sm:block">
        <table class="w-full text-sm text-left">
            <thead class="bg-[var(--surface-page)] text-[var(--text-muted)] text-[11px] uppercase tracking-wide sticky top-0 z-10">
                <tr>
                    <th class="px-6 py-3 font-medium whitespace-nowrap">Konten</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Klien</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Status Brief</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Deadline</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($briefQueueItems as $item)
                    @php
                        $isPinned = $pinnedIds->contains($item->id);
                        $brief = $item->contentBriefDraft;
                        $briefStatus = match ($brief?->status) {
                            'discussing' => ['label' => 'Sedang Didiskusikan', 'class' => 'badge-warning'],
                            'draft' => ['label' => 'Draft', 'class' => 'badge-neutral'],
                            default => ['label' => 'Belum Dibuat', 'class' => 'badge-danger'],
                        };
                    @endphp
                    <tr class="border-t border-[var(--surface-muted)] transition-colors cursor-pointer {{ $isPinned ? 'bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)]' : 'hover:bg-[var(--surface-page)]' }}"
                        onclick="window.location='{{ route('content-items.show', $item) }}'">
                        <td class="px-6 py-3.5 font-medium text-[var(--text-primary)] whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                @if ($showPinButton)
                                    <x-pin-button :item="$item" :pinned="$isPinned" />
                                @endif
                                <span>{{ $item->title }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $item->client->name ?? '-' }}</td>
                        <td class="px-4 py-3.5">
                            <span class="badge {{ $briefStatus['class'] }}">
                                {{ $briefStatus['label'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $item->deadline_at->format('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-10 text-center text-[var(--text-muted)] text-sm">Semua brief sudah diterapkan ke tim produksi. Kerja bagus!</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Mobile accordion --}}
    <div class="sm:hidden p-3.5 space-y-3 max-h-[520px] overflow-auto thin-autohide-scrollbar">
        @forelse ($briefQueueItems as $item)
            @php
                $isPinned = $pinnedIds->contains($item->id);
                $brief = $item->contentBriefDraft;
                $briefStatus = match ($brief?->status) {
                    'discussing' => ['label' => 'Sedang Didiskusikan', 'class' => 'badge-warning'],
                    'draft' => ['label' => 'Draft', 'class' => 'badge-neutral'],
                    default => ['label' => 'Belum Dibuat', 'class' => 'badge-danger'],
                };
            @endphp
            <div class="card p-3.5 {{ $isPinned ? 'bg-[var(--brand-tint)]' : '' }}" x-data="{ open: false }">
                <div class="flex items-start gap-2">
                    @if ($showPinButton)
                        <x-pin-button :item="$item" :pinned="$isPinned" class="mt-0.5" />
                    @endif
                    <button type="button" class="flex-1 min-w-0 text-left flex items-start gap-2 cursor-pointer" @click="open = !open" :aria-expanded="open">
                    <div class="flex-1 min-w-0">
                        <p class="font-medium text-[var(--text-primary)] text-sm">{{ $item->title }}</p>
                        <div class="flex items-center gap-2 mt-1.5">
                            <span class="badge {{ $briefStatus['class'] }}">
                                {{ $briefStatus['label'] }}
                            </span>
                            <span class="text-xs text-[var(--text-secondary)] whitespace-nowrap">{{ $item->deadline_at->format('d M Y') }}</span>
                        </div>
                    </div>
                    <div class="w-7 h-7 shrink-0 flex items-center justify-center rounded-lg text-[var(--text-muted)]">
                        <span class="material-symbols-outlined text-[19px] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                    </div>
                    </button>
                </div>
                <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[var(--surface-muted)] space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Klien</span>
                        <span class="text-[var(--text-primary)] font-medium">{{ $item->client->name ?? '-' }}</span>
                    </div>
                    <a href="{{ route('content-items.show', $item) }}" class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] rounded-lg py-2 transition-colors">Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span></a>
                </div>
            </div>
        @empty
            <p class="px-2 py-10 text-center text-[var(--text-muted)] text-sm">Semua brief sudah diterapkan ke tim produksi. Kerja bagus!</p>
        @endforelse
    </div>
</div>
