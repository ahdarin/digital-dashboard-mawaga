@extends('layouts.app')
@section('title', 'Publishing Tracker')
@section('content')
    <div x-data="{ search: '', matches(...fields) { if (!this.search) return true; const s = this.search.toLowerCase(); return fields.some(f => f.toLowerCase().includes(s)); } }"
        class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 mb-6">
            <div>
                <h1 class="font-display text-[28px] font-semibold text-[var(--text-primary)]">Publishing Tracker</h1>
                <p class="text-sm text-[var(--text-muted)] mt-1">Riwayat konten yang telah dipublikasikan</p>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <select name="platform_id" form="filter-client-form" onchange="this.form.submit()"
                    class="border border-[var(--border)] rounded-lg px-3 py-2 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 h-[40px]">
                    <option value="">Semua Platform</option>
                    @foreach ($platformOptions as $platform)
                        <option value="{{ $platform->id }}" {{ (string) $selectedPlatformId === (string) $platform->id ? 'selected' : '' }}>{{ $platform->name }}</option>
                    @endforeach
                </select>
                <select name="client_id" form="filter-client-form" onchange="this.form.submit()"
                    class="border border-[var(--border)] rounded-lg px-3 py-2 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 h-[40px]">
                    <option value="">Semua Klien</option>
                    @foreach ($clientOptions as $client)
                        <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>{{ $client->name }}</option>
                    @endforeach
                </select>
                <form id="filter-client-form" method="GET" action="{{ route('publishing-tracker.index') }}"></form>

                <div class="relative">
                    <span
                        class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[19px]">search</span>
                    <input x-model="search"
                        class="pl-10 pr-4 h-[40px] bg-[var(--surface-card)] border border-[var(--border)] rounded-lg text-sm focus:outline-none focus:border-[#044b46]/40 w-full sm:w-64"
                        placeholder="Cari konten..." type="text">
                </div>
            </div>
        </div>

        @include('publishing-tracker.partials.table')
    </div>
@endsection