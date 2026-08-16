<div x-data="{ search: '', matches(...fields) { if (!this.search) return true; const s = this.search.toLowerCase(); return fields.some(f => f.toLowerCase().includes(s)); } }"
    class="px-4 sm:px-6 lg:px-8 pb-8">

    <div class="flex items-center gap-3 flex-wrap mb-5">
        <select name="platform_id" form="filter-published-form" onchange="this.form.submit()"
            class="border border-[#eef0f4] rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40 h-[40px]">
            <option value="">Semua Platform</option>
            @foreach ($platformOptions as $platform)
                <option value="{{ $platform->id }}" {{ (string) $selectedPlatformId === (string) $platform->id ? 'selected' : '' }}>{{ $platform->name }}</option>
            @endforeach
        </select>
        <select name="client_id" form="filter-published-form" onchange="this.form.submit()"
            class="border border-[#eef0f4] rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40 h-[40px]">
            <option value="">Semua Klien</option>
            @foreach ($clientOptions as $client)
                <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>{{ $client->name }}</option>
            @endforeach
        </select>
        <input type="hidden" name="tab" value="published" form="filter-published-form">
        <form id="filter-published-form" method="GET" action="{{ route('production-workflow.index') }}"></form>

        <div class="relative">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#767c80] text-[19px]">search</span>
            <input x-model="search"
                class="pl-10 pr-4 h-[40px] bg-white border border-[#eef0f4] rounded-lg text-sm focus:outline-none focus:border-[#044b46]/40 w-full sm:w-64"
                placeholder="Cari konten..." type="text">
        </div>
    </div>

    @include('publishing-tracker.partials.table')
</div>
