{{-- Tombol pin personal - reusable di tabel Task (Beranda/Profil) & tabel
     List Produksi. State pinned di-render server-side (biar konsisten sama
     urutan/highlight baris yang juga dihitung server), lalu di-toggle
     optimistic via fetch tanpa reload halaman. Expects: $item (ContentItem),
     $pinned (bool, default false).

     Tooltip hover & pesan error di-teleport ke <body> dan diposisikan fixed
     lewat getBoundingClientRect (pola yang sama dipakai tooltip sidebar saat
     collapsed) - supaya nggak ke-clip sama overflow tabel/card leluhurnya. --}}
@props(['item', 'pinned' => false])
<div
    x-data="{
        pinned: @js($pinned),
        loading: false,
        errorMsg: null,
        tip: { show: false, text: '', top: 0, left: 0 },
        error: { top: 0, left: 0 },
        showTip(event) {
            if (this.errorMsg) return;
            const rect = event.currentTarget.getBoundingClientRect();
            this.tip = {
                show: true,
                text: this.pinned ? 'Lepas pin' : 'Pin konten ini',
                top: rect.top + rect.height / 2,
                left: rect.right + 8,
            };
        },
        hideTip() { this.tip.show = false },
        toggle(event) {
            this.tip.show = false;
            if (this.loading) return;
            this.loading = true;
            const rect = event.currentTarget.getBoundingClientRect();
            this.error = { top: rect.bottom + 6, left: rect.left };
            const wasPinned = this.pinned;
            this.pinned = !wasPinned;
            fetch('{{ route('content-items.pin', $item) }}', {
                method: wasPinned ? 'DELETE' : 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
            }).then(async (res) => {
                if (! res.ok) {
                    this.pinned = wasPinned;
                    const data = await res.json().catch(() => ({}));
                    this.errorMsg = data.message || 'Gagal memperbarui pin.';
                    setTimeout(() => { this.errorMsg = null }, 3500);
                }
            }).catch(() => {
                this.pinned = wasPinned;
                this.errorMsg = 'Gagal memperbarui pin. Periksa koneksi Anda.';
                setTimeout(() => { this.errorMsg = null }, 3500);
            }).finally(() => { this.loading = false });
        }
    }"
    {{ $attributes->merge(['class' => 'relative shrink-0']) }}
>
    <button type="button" @click.stop="toggle($event)" :aria-pressed="pinned"
        @mouseenter="showTip($event)" @mouseleave="hideTip()"
        class="w-7 h-7 flex items-center justify-center rounded-lg transition-colors"
        :class="pinned ? 'text-[var(--brand)] bg-[var(--brand-tint)]' : 'text-[var(--text-idle)] hover:text-[var(--text-muted)] hover:bg-[var(--surface-page)]'">
        <span class="material-symbols-outlined text-[17px]" :style="pinned && `font-variation-settings: 'FILL' 1`">push_pin</span>
    </button>

    <template x-teleport="body">
        <div x-show="tip.show" x-cloak x-transition.opacity.duration.100ms
            class="pointer-events-none fixed z-[100] whitespace-nowrap"
            :style="`top: ${tip.top}px; left: ${tip.left}px; transform: translateY(-50%);`">
            <div class="relative bg-[var(--brand)] text-white text-xs font-medium px-2.5 py-1.5 rounded-md shadow-lg">
                <span x-text="tip.text"></span>
                <span class="absolute top-1/2 left-0 -translate-x-full -translate-y-1/2 w-0 h-0 border-y-[5px] border-y-transparent border-r-[6px] border-r-[var(--brand)]"></span>
            </div>
        </div>
    </template>

    <template x-teleport="body">
        <div x-show="errorMsg" x-cloak x-transition.opacity @click.stop
            class="fixed z-[100] w-max max-w-[220px]"
            :style="`top: ${error.top}px; left: ${error.left}px;`">
            <div class="bg-[var(--text-primary)] text-white text-[11px] leading-snug px-2.5 py-1.5 rounded-lg shadow-lg">
                <span x-text="errorMsg"></span>
            </div>
        </div>
    </template>
</div>
