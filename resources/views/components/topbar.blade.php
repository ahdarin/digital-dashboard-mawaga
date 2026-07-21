<header class="h-16 bg-white/80 backdrop-blur border-b border-gray-100">
    <div class="h-full flex items-center justify-between px-6">

        {{-- Search --}}
        <div class="flex-1 max-w-md">
            <div class="relative">
                <span class="material-symbols-outlined absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400 text-[18px]">
                    search
                </span>
                <input
                    type="text"
                    placeholder="Cari..."
                    class="w-full pl-10 pr-4 py-2 text-sm bg-gray-50 rounded-lg border-0 focus:outline-none focus:ring-2 focus:ring-[#044b46]/30 focus:bg-white transition-colors duration-150">
            </div>
        </div>

        {{-- Right side --}}
        <div class="flex items-center gap-4">

            <button class="relative w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-50 transition-colors duration-150">
                <span class="material-symbols-outlined text-gray-500 text-[20px]">notifications</span>
                <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-[#044b46] rounded-full"></span>
            </button>

            <div class="flex items-center gap-3 pl-3 border-l border-gray-100">
                <div class="w-9 h-9 rounded-full bg-[#044b46] text-white flex items-center justify-center text-sm font-semibold shrink-0">
                    {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
                </div>
                <div class="hidden md:block leading-tight">
                    <p class="text-sm font-semibold text-[#191c1c]">{{ auth()->user()->name ?? 'User' }}</p>
                    <p class="text-xs text-gray-400">{{ auth()->user()->role->name ?? '-' }}</p>
                </div>
            </div>

        </div>
    </div>
</header>