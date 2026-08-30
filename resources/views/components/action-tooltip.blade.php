{{-- Bubble tooltip custom - dipakai lewat @include di dalam elemen yang
     sudah punya x-data="tooltipHover('Label')" (lihat definisi fungsinya di
     resources/views/layouts/app.blade.php). SATU instance per tombol - lihat
     catatan di layouts/app.blade.php kenapa tidak dibagi ke banyak tombol
     sekaligus (state dibagi menyebabkan tooltip tombol lama sempat kelihatan
     sekilas di posisi lama saat pindah hover ke tombol lain). --}}
<template x-teleport="body">
    <div x-show="show" x-cloak x-transition.opacity.duration.100ms
        class="pointer-events-none fixed z-[100] whitespace-nowrap"
        :style="`top: ${top}px; left: ${left}px; transform: translate(-50%, -100%);`">
        <div class="relative bg-[var(--brand-solid)] text-white text-xs font-medium px-2.5 py-1.5 rounded-md shadow-lg">
            <span x-text="text"></span>
            <span class="absolute top-full left-1/2 -translate-x-1/2 w-0 h-0 border-x-[5px] border-x-transparent border-t-[6px] border-t-[var(--brand)]"></span>
        </div>
    </div>
</template>
