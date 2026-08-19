{{-- Shared publication table (desktop table + mobile accordion + pagination).
     Expects: $publications --}}
<div class="card overflow-hidden">
  <div class="overflow-x-auto hidden sm:block">
    <table class="w-full text-sm text-left">
        <thead class="bg-[var(--surface-page)]">
            <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                <th class="px-6 py-3 font-medium whitespace-nowrap">Konten</th>
                <th class="px-4 py-3 font-medium whitespace-nowrap">Klien</th>
                <th class="px-4 py-3 font-medium whitespace-nowrap">Platform</th>
                <th class="px-4 py-3 font-medium whitespace-nowrap">Tanggal Tayang</th>
                <th class="px-4 py-3 font-medium whitespace-nowrap">Diunggah Oleh</th>
                <th class="px-4 py-3 font-medium whitespace-nowrap">Link</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($publications as $pub)
                <tr x-show="matches('{{ addslashes($pub->contentItem->title) }}', '{{ addslashes($pub->contentItem->client->name ?? '') }}')"
                    onclick="window.location='{{ route('content-items.show', $pub->contentItem) }}'"
                    class="border-t border-[var(--surface-muted)] hover:bg-[var(--surface-page)] transition-colors cursor-pointer">
                    <td class="px-6 py-3.5 font-medium text-[var(--text-primary)] whitespace-nowrap">
                        {{ $pub->contentItem->title }}
                    </td>
                    <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $pub->contentItem->client->name ?? '-' }}</td>
                    <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $pub->platform->name ?? '-' }}</td>
                    <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $pub->published_at->format('d M Y, H:i') }}</td>
                    <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $pub->publishedBy->name ?? '-' }}</td>
                    <td class="px-4 py-3.5 whitespace-nowrap" onclick="event.stopPropagation()">
                        @if ($pub->post_url)
                            <a href="{{ $pub->post_url }}" target="_blank"
                                class="inline-flex items-center gap-1 bg-[var(--brand)] text-white text-xs font-medium px-3 py-1.5 rounded-lg hover:bg-[var(--brand-dark)] transition-colors">
                                <span class="material-symbols-outlined text-[13px]">open_in_new</span> Lihat Post
                            </a>
                        @else
                            <span class="text-[var(--text-muted)] text-xs">-</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center">
                        <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">rocket_launch</span>
                        <p class="text-sm text-[var(--text-muted)]">Belum ada konten yang dipublikasikan.</p>
                        <p class="text-xs text-[var(--text-muted)] mt-1">Konten muncul di sini otomatis begitu ditandai Uploaded di papan Produksi atau lewat Record Publication.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
  </div>

  {{-- Mobile accordion --}}
  <div class="sm:hidden p-3.5 space-y-3">
      @forelse ($publications as $pub)
          <div x-show="matches('{{ addslashes($pub->contentItem->title) }}', '{{ addslashes($pub->contentItem->client->name ?? '') }}')"
              class="card p-3.5" x-data="{ open: false }">
              <button type="button" class="w-full text-left flex items-start gap-2 cursor-pointer" @click="open = !open" :aria-expanded="open">
                  <div class="flex-1 min-w-0">
                      <p class="font-medium text-[var(--text-primary)] text-sm">{{ $pub->contentItem->title }}</p>
                      <div class="flex items-center gap-1.5 flex-wrap mt-1.5">
                          <span class="badge badge-neutral">{{ $pub->platform->name ?? '-' }}</span>
                          <span class="text-xs text-[var(--text-secondary)] whitespace-nowrap">{{ $pub->published_at->format('d M Y, H:i') }}</span>
                      </div>
                  </div>
                  <div class="w-7 h-7 shrink-0 flex items-center justify-center rounded-lg text-[var(--text-muted)]">
                      <span class="material-symbols-outlined text-[19px] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                  </div>
              </button>
              <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[var(--surface-muted)] space-y-2">
                  <div class="flex items-center justify-between text-xs">
                      <span class="text-[var(--text-muted)]">Klien</span>
                      <span class="text-[var(--text-primary)] font-medium">{{ $pub->contentItem->client->name ?? '-' }}</span>
                  </div>
                  <div class="flex items-center justify-between text-xs">
                      <span class="text-[var(--text-muted)]">Diunggah Oleh</span>
                      <span class="text-[var(--text-primary)] font-medium">{{ $pub->publishedBy->name ?? '-' }}</span>
                  </div>
                  @if ($pub->post_url)
                      <a href="{{ $pub->post_url }}" target="_blank" @click.stop
                          class="flex items-center justify-center gap-1.5 w-full bg-[var(--brand)] text-white text-xs font-medium px-3 py-2 rounded-lg hover:bg-[var(--brand-dark)] transition-colors">
                          <span class="material-symbols-outlined text-[13px]">open_in_new</span> Lihat Post
                      </a>
                  @endif
                  <a href="{{ route('content-items.show', $pub->contentItem) }}"
                      class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] rounded-lg py-2 transition-colors">
                      Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                  </a>
              </div>
          </div>
      @empty
          <div class="px-2 py-12 text-center">
              <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">rocket_launch</span>
              <p class="text-sm text-[var(--text-muted)]">Belum ada konten yang dipublikasikan.</p>
              <p class="text-xs text-[var(--text-muted)] mt-1">Konten muncul di sini otomatis begitu ditandai Uploaded di papan Produksi atau lewat Record Publication.</p>
          </div>
      @endforelse
  </div>
</div>

<div class="mt-5">{{ $publications->links() }}</div>
