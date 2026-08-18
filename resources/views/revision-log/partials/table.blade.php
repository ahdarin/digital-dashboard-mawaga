{{-- Shared revision table (desktop table + mobile accordion + pagination).
     Expects: $revisions, $selectedStatus --}}
<div class="card overflow-hidden">
    <div class="overflow-x-auto hidden sm:block">
        <table class="w-full text-sm text-left">
            <thead class="bg-[#f7f8fc]">
                <tr class="text-[#767c80] text-[11px] uppercase tracking-wide">
                    <th class="px-6 py-3 font-medium whitespace-nowrap">Konten</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Klien</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Round</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Catatan</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Diminta Oleh</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $revisionStatusStyles = [
                        'open' => ['class' => 'badge-warning', 'label' => 'Open'],
                        'in_progress' => ['class' => 'badge-info', 'label' => 'Sedang Dikerjakan'],
                        'resolved' => ['class' => 'badge-success', 'label' => 'Resolved'],
                    ];
                @endphp
                @forelse ($revisions as $revision)
                    @php $revStatusStyle = $revisionStatusStyles[$revision->status] ?? $revisionStatusStyles['resolved']; @endphp
                    <tr x-show="matches('{{ addslashes($revision->contentItem->title) }}', '{{ addslashes($revision->contentItem->client->name ?? '') }}', '{{ addslashes($revision->revision_note) }}')"
                        onclick="window.location='{{ route('content-items.show', $revision->contentItem) }}'"
                        class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] transition-colors cursor-pointer">
                        <td class="px-6 py-3.5 font-medium text-[#14181a] whitespace-nowrap">
                            {{ $revision->contentItem->title }}
                        </td>
                        <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $revision->contentItem->client->name ?? '-' }}</td>
                        <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">Revisi #{{ $revision->revision_round }}</td>
                        <td class="px-4 py-3.5 text-[#5c6266] max-w-xs truncate">{{ $revision->revision_note }}</td>
                        <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $revision->requestedBy->name ?? '-' }}</td>
                        <td class="px-4 py-3.5">
                            <span class="badge {{ $revStatusStyle['class'] }}">{{ $revStatusStyle['label'] }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <span class="material-symbols-outlined text-[#d4d7db] text-[28px] mb-2 block">task_alt</span>
                            <p class="text-sm text-[#767c80]">Tidak ada revisi {{ $selectedStatus !== 'all' ? 'dengan status ini' : '' }} ditemukan.</p>
                            <p class="text-xs text-[#767c80] mt-1">Revisi muncul di sini begitu ada catatan revisi ditambahkan dari halaman konten atau papan produksi.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Mobile accordion --}}
    <div class="sm:hidden p-3.5 space-y-3">
        @forelse ($revisions as $revision)
            @php $revStatusStyle = $revisionStatusStyles[$revision->status] ?? $revisionStatusStyles['resolved']; @endphp
            <div x-show="matches('{{ addslashes($revision->contentItem->title) }}', '{{ addslashes($revision->contentItem->client->name ?? '') }}', '{{ addslashes($revision->revision_note) }}')"
                class="card p-3.5" x-data="{ open: false }">
                <button type="button" class="w-full text-left flex items-start gap-2 cursor-pointer" @click="open = !open" :aria-expanded="open">
                    <div class="flex-1 min-w-0">
                        <p class="font-medium text-[#14181a] text-sm">{{ $revision->contentItem->title }}</p>
                        <div class="flex items-center gap-1.5 flex-wrap mt-1.5">
                            <span class="badge {{ $revStatusStyle['class'] }}">{{ $revStatusStyle['label'] }}</span>
                            <span class="text-xs text-[#5c6266] whitespace-nowrap">Revisi #{{ $revision->revision_round }}</span>
                        </div>
                    </div>
                    <div class="w-7 h-7 shrink-0 flex items-center justify-center rounded-lg text-[#767c80]">
                        <span class="material-symbols-outlined text-[19px] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                    </div>
                </button>
                <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[#f2f3f6] space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#767c80]">Klien</span>
                        <span class="text-[#14181a] font-medium">{{ $revision->contentItem->client->name ?? '-' }}</span>
                    </div>
                    <div class="text-xs">
                        <span class="text-[#767c80] block mb-1">Catatan</span>
                        <span class="text-[#14181a] font-medium whitespace-pre-line">{{ $revision->revision_note }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#767c80]">Diminta Oleh</span>
                        <span class="text-[#14181a] font-medium">{{ $revision->requestedBy->name ?? '-' }}</span>
                    </div>
                    <a href="{{ route('content-items.show', $revision->contentItem) }}" class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[#044b46] bg-[#f0f5f4] hover:bg-[#e4ede9] rounded-lg py-2 transition-colors">Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span></a>
                </div>
            </div>
        @empty
            <div class="px-2 py-12 text-center">
                <span class="material-symbols-outlined text-[#d4d7db] text-[28px] mb-2 block">task_alt</span>
                <p class="text-sm text-[#767c80]">Tidak ada revisi {{ $selectedStatus !== 'all' ? 'dengan status ini' : '' }} ditemukan.</p>
                <p class="text-xs text-[#767c80] mt-1">Revisi muncul di sini begitu ada catatan revisi ditambahkan dari halaman konten atau papan produksi.</p>
            </div>
        @endforelse
    </div>
</div>

<div class="mt-5">{{ $revisions->links() }}</div>
