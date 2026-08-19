<div x-data="{ search: '', matches(...fields) { if (!this.search) return true; const s = this.search.toLowerCase(); return fields.some(f => f.toLowerCase().includes(s)); } }"
    class="px-4 sm:px-6 lg:px-8 pb-8">

    <div class="flex items-center gap-3 flex-wrap mb-5">
        <select name="client_id" form="filter-revisions-form" onchange="this.form.submit()"
            class="border border-[var(--border)] rounded-lg px-3 py-2 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 h-[40px]">
            <option value="">Semua Klien</option>
            @foreach ($clientOptions as $client)
                <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>{{ $client->name }}</option>
            @endforeach
        </select>

        <select name="status" form="filter-revisions-form" onchange="this.form.submit()"
            class="border border-[var(--border)] rounded-lg px-3 py-2 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 h-[40px]">
            @foreach (['open' => 'Open', 'in_progress' => 'Sedang Dikerjakan', 'resolved' => 'Resolved', 'all' => 'Semua'] as $value => $label)
                <option value="{{ $value }}" {{ $selectedStatus === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        <input type="hidden" name="tab" value="revisions" form="filter-revisions-form">
        <form id="filter-revisions-form" method="GET" action="{{ route('production-workflow.index') }}"></form>

        <div class="relative">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[19px]">search</span>
            <input x-model="search"
                class="pl-10 pr-4 h-[40px] bg-[var(--surface-card)] border border-[var(--border)] rounded-lg text-sm focus:outline-none focus:border-[#044b46]/40 w-full sm:w-64"
                placeholder="Cari konten atau catatan revisi..." type="text">
        </div>
    </div>

    @include('revision-log.partials.table')
</div>
