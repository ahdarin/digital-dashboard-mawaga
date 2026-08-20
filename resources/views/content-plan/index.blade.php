@extends('layouts.app')
@section('title', 'Content Plan Bulanan')
@section('content')
<div x-data="{ showCreateModal: {{ $errors->any() ? 'true' : 'false' }} }" class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    {{-- Bagian atas — TETAP, tidak berubah saat switch --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-7">
        <div>
            <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[var(--text-primary)]">Rencana Konten Bulanan</h1>
            <p class="text-[var(--text-secondary)] text-sm mt-1">Kelola dan pantau target konten seluruh client aktif.</p>
        </div>
        @if (auth()->user()->hasPermissionTo('content_plan', 'create'))
            <div class="flex items-center gap-2 flex-wrap">
                <button type="button" @click="showCreateModal = true" class="btn-primary">
                    <span class="material-symbols-outlined text-[17px]">add</span> Buat Content Plan Baru
                </button>
            </div>
        @endif
    </div>

    @if (session('status'))
        <div class="bg-[var(--brand-tint)] text-[var(--brand)] text-sm p-3.5 rounded-lg mb-5">{{ session('status') }}</div>
    @endif

    {{-- Filter — TETAP, plus toggle Table/Calendar di ujung kanan --}}
    <form method="GET" class="flex items-center gap-3 mb-6 flex-wrap">
        <input type="hidden" name="view" value="{{ $view }}">

        <select name="client_id" onchange="this.form.submit()" class="border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
            <option value="">Semua Klien</option>
            @foreach ($clientOptions as $c)
                <option value="{{ $c->id }}" {{ (string) $selectedClientId === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
            @endforeach
        </select>
        <input type="hidden" name="month" id="plan-month-input" value="{{ $month }}">
        <input type="hidden" name="year" id="plan-year-input" value="{{ $year }}">
        <div class="relative">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[17px] pointer-events-none">calendar_month</span>
            <input type="text" data-flatpickr="month" data-month-input="#plan-month-input" data-year-input="#plan-year-input" data-autosubmit="true"
                   class="border border-[var(--border)] rounded-lg pl-9 pr-3 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 w-[150px]" readonly>
        </div>

        {{-- Toggle Table / Calendar --}}
        <div class="flex items-center bg-[var(--surface-muted)] rounded-lg p-1 sm:ml-auto">
            <a href="{{ request()->fullUrlWithQuery(['view' => 'table']) }}"
               class="text-xs font-medium px-3 py-1.5 rounded-md {{ $view === 'table' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)]' }}">
                <span class="material-symbols-outlined text-[15px] align-middle">table_rows</span> Table
            </a>
            <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar']) }}"
               class="text-xs font-medium px-3 py-1.5 rounded-md {{ $view === 'calendar' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)]' }}">
                <span class="material-symbols-outlined text-[15px] align-middle">calendar_month</span> Calendar
            </a>
        </div>
    </form>

    {{-- Target cards — TETAP --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-5 mb-6">
        <div class="card p-3.5 sm:p-6">
            <p class="text-[10px] sm:text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5 sm:mb-2">Content Target vs Realization</p>
            <p class="font-display text-xl sm:text-3xl font-semibold text-[var(--text-primary)] mb-2 sm:mb-3">{{ $realizedContent }} <span class="text-sm sm:text-lg text-[var(--text-muted)] font-normal">/ {{ $targetContent }}</span></p>
            @php $pct = $targetContent > 0 ? min(100, round($realizedContent / $targetContent * 100, 1)) : 0; @endphp
            <div class="flex items-center justify-between text-[10px] sm:text-xs text-[var(--text-muted)] mb-1 sm:mb-1.5">
                <span>Overall Progress</span><span>{{ $pct }}%</span>
            </div>
            <div class="w-full h-1.5 rounded-full bg-[var(--surface-muted)] overflow-hidden">
                <div class="h-full bg-[var(--brand)] rounded-full" style="width: {{ $pct }}%"></div>
            </div>
        </div>
        <div class="card p-3.5 sm:p-6">
            <p class="text-[10px] sm:text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5 sm:mb-2">Design Target vs Realization</p>
            <p class="font-display text-xl sm:text-3xl font-semibold text-[var(--text-primary)] mb-2 sm:mb-3">{{ $realizedDesign }} <span class="text-sm sm:text-lg text-[var(--text-muted)] font-normal">/ {{ $targetDesign }}</span></p>
            @php $pctD = $targetDesign > 0 ? min(100, round($realizedDesign / $targetDesign * 100, 1)) : 0; @endphp
            <div class="flex items-center justify-between text-[10px] sm:text-xs text-[var(--text-muted)] mb-1 sm:mb-1.5">
                <span>Overall Progress</span><span>{{ $pctD }}%</span>
            </div>
            <div class="w-full h-1.5 rounded-full bg-[var(--surface-muted)] overflow-hidden">
                <div class="h-full bg-[var(--info-text)] rounded-full" style="width: {{ $pctD }}%"></div>
            </div>
        </div>
    </div>

    {{-- BAGIAN BAWAH — INI YANG BERUBAH SAAT SWITCH --}}
    @if ($view === 'calendar')

        @include('content-plan.partials.calendar-grid')

    @else

        <div class="card overflow-hidden">
          <div class="overflow-x-auto hidden sm:block">
            <table class="w-full text-sm text-left">
                <thead class="bg-[var(--surface-page)]">
                    <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                        <th class="px-6 py-3 font-medium whitespace-nowrap">Klien</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Bulan/Tahun</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Jumlah Item</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($plans as $plan)
                        <tr class="border-t border-[var(--surface-muted)] hover:bg-[var(--surface-page)] transition-colors cursor-pointer"
                            onclick="navigateTo('{{ route('content-plan.show', $plan) }}')">
                            <td class="px-6 py-3.5 font-medium text-[var(--text-primary)] whitespace-nowrap">
                                {{ $plan->client->name ?? '-' }}
                            </td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ \Carbon\Carbon::create()->month($plan->month)->translatedFormat('F') }} {{ $plan->year }}</td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $plan->content_items_count }}</td>
                            <td class="px-4 py-3.5">
                                <span class="badge
                                    {{ $plan->status === 'approved' ? 'badge-success' : '' }}
                                    {{ $plan->status === 'draft' ? 'badge-neutral' : '' }}
                                    {{ $plan->status === 'pending' ? 'badge-warning' : '' }}
                                    {{ $plan->status === 'rejected' ? 'badge-danger' : '' }}">
                                    {{ $plan->status === 'pending' ? 'Diajukan' : ($plan->status === 'draft' ? 'Draf' : ($plan->status === 'rejected' ? 'Ditolak' : 'Disetujui')) }}
                                </span>
                                @if ($plan->status === 'approved' && $plan->created_by === $plan->approved_by)
                                    <span class="text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-[var(--warning-tint)] text-[var(--warning-text)] uppercase ml-1" title="Pembuat rencana ini juga yang menyetujuinya">Sendiri</span>
                                @endif
                            </td>
                            <td class="px-6 py-3.5 text-right text-[var(--text-muted)]">
                                <span class="material-symbols-outlined text-[17px]">chevron_right</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">event_note</span>
                                <p class="text-sm text-[var(--text-muted)]">Belum ada content plan untuk periode ini.</p>
                                @if (auth()->user()->hasPermissionTo('content_plan', 'create'))
                                    <button type="button" @click="showCreateModal = true" class="text-xs text-[var(--brand)] font-medium hover:underline mt-1">Buat Content Plan Baru</button>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
          </div>

          {{-- Mobile accordion list --}}
          <div class="sm:hidden divide-y divide-[var(--surface-muted)]">
            @forelse ($plans as $plan)
                <div x-data="{ open: false }" class="px-4">
                    <button type="button" class="w-full text-left py-3.5 flex items-center justify-between gap-3 cursor-pointer" @click="open = !open" :aria-expanded="open">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-[var(--text-primary)] truncate">{{ $plan->client->name ?? '-' }}</p>
                            <p class="text-xs text-[var(--text-muted)] mt-0.5">{{ \Carbon\Carbon::create()->month($plan->month)->translatedFormat('F') }} {{ $plan->year }}</p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="badge
                                {{ $plan->status === 'approved' ? 'badge-success' : '' }}
                                {{ $plan->status === 'draft' ? 'badge-neutral' : '' }}
                                {{ $plan->status === 'pending' ? 'badge-warning' : '' }}
                                {{ $plan->status === 'rejected' ? 'badge-danger' : '' }}">
                                {{ $plan->status === 'pending' ? 'Diajukan' : ($plan->status === 'draft' ? 'Draf' : ($plan->status === 'rejected' ? 'Ditolak' : 'Disetujui')) }}
                            </span>
                            @if ($plan->status === 'approved' && $plan->created_by === $plan->approved_by)
                                <span class="text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-[var(--warning-tint)] text-[var(--warning-text)] uppercase" title="Pembuat rencana ini juga yang menyetujuinya">Sendiri</span>
                            @endif
                            <span class="material-symbols-outlined text-[var(--text-muted)] text-[18px] transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                        </div>
                    </button>
                    <div x-show="open" x-cloak x-transition class="pb-4 -mt-1 space-y-2 text-sm">
                        <div class="flex justify-between gap-3">
                            <span class="text-[var(--text-muted)]">Jumlah Item</span>
                            <span class="text-[var(--text-primary)] text-right">{{ $plan->content_items_count }}</span>
                        </div>
                        <a href="{{ route('content-plan.show', $plan) }}" class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] rounded-lg py-2 transition-colors">
                            Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                        </a>
                    </div>
                </div>
            @empty
                <div class="px-6 py-12 text-center">
                    <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">event_note</span>
                    <p class="text-sm text-[var(--text-muted)]">Belum ada content plan untuk periode ini.</p>
                    @if (auth()->user()->hasPermissionTo('content_plan', 'create'))
                        <button type="button" @click="showCreateModal = true" class="text-xs text-[var(--brand)] font-medium hover:underline mt-1">Buat Content Plan Baru</button>
                    @endif
                </div>
            @endforelse
          </div>
        </div>

        <div class="mt-5">{{ $plans->links() }}</div>

    @endif

    {{-- Modal Buat Content Plan Baru --}}
    <div x-show="showCreateModal" x-cloak
         x-on:keydown.escape.window="showCreateModal = false"
         class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-[#14181a]/40" @click="showCreateModal = false"></div>

        <div x-show="showCreateModal" x-transition
             role="dialog" aria-modal="true" aria-labelledby="create-content-plan-modal-title" x-trap="showCreateModal"
             class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                <h3 id="create-content-plan-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)]">Buat Rencana Konten Baru</h3>
                <button type="button" @click="showCreateModal = false" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                    <span class="material-symbols-outlined text-[19px]">close</span>
                </button>
            </div>

            <form action="{{ route('content-plan.store') }}" method="POST">
                @csrf
                <div class="px-6 py-5 space-y-4">
                    @error('client_id')
                        <div class="bg-[var(--danger-tint)] text-[var(--danger-text)] text-xs p-3 rounded-lg">{{ $message }}</div>
                    @enderror

                    <div>
                        <label for="create_plan_client_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Klien <span class="text-[var(--danger-text)]">*</span></label>
                        <select id="create_plan_client_id" name="client_id" required class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                            <option value="">Pilih client...</option>
                            @foreach ($clientOptions as $c)
                                <option value="{{ $c->id }}" {{ !$c->activePackage ? 'disabled' : '' }}>
                                    {{ $c->name }} {{ !$c->activePackage ? '(belum ada paket aktif)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="create_plan_month_year" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Bulan &amp; Tahun</label>
                        <input type="hidden" name="month" id="create-plan-month-input" value="{{ now()->month }}">
                        <input type="hidden" name="year" id="create-plan-year-input" value="{{ now()->year }}">
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[17px] pointer-events-none">calendar_month</span>
                            <input id="create_plan_month_year" type="text" required data-flatpickr="month" data-month-input="#create-plan-month-input" data-year-input="#create-plan-year-input"
                                   class="w-full border border-[var(--border)] rounded-lg pl-10 pr-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40" readonly>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                    <button type="submit" class="btn-primary">
                        Buat Plan
                    </button>
                    <button type="button" @click="showCreateModal = false" class="btn-secondary">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection