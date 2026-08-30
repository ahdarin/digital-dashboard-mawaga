{{-- Bubble tooltip custom - muncul langsung saat hover, tanpa animasi.
     Dipakai lewat @include di dalam elemen yang sudah punya
     x-data="tooltipHover('Label')" (lihat resources/views/layouts/app.blade.php). --}}
<template x-teleport="body">
    <div x-show="show" x-cloak
        class="pointer-events-none fixed z-[100] whitespace-nowrap"
        :style="`top: ${top}px; left: ${left}px; transform: translate(-50%, -100%);`">
        <div class="relative bg-[var(--brand-solid)] text-white text-xs font-medium px-2.5 py-1.5 rounded-md shadow-lg">
            <span x-text="text"></span>
            <span class="absolute top-full left-1/2 -translate-x-1/2 w-0 h-0 border-x-[5px] border-x-transparent border-t-[6px] border-t-[var(--brand)]"></span>
        </div>
    </div>
</template>
