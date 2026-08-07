@extends('layouts.app')
@section('title', 'Content Plan Bulanan')
@section('content')
<div class="p-8 max-w-[1400px]">

    {{-- Bagian atas — TETAP, tidak berubah saat switch --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="font-display text-[32px] font-semibold text-[#14181a]">Content Plan Bulanan</h1>
            <p class="text-[#5c6266] text-sm mt-1">Kelola dan pantau target konten seluruh client aktif.</p>
        </div>
        <a href="{{ route('content-plan.create') }}" class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
            <span class="material-symbols-outlined text-[17px]">add</span> Buat Content Plan Baru
        </a>
    </div>

    @if (session('status'))
        <div class="bg-[#f0f5f4] text-[#044b46] text-sm p-3.5 rounded-lg mb-5">{{ session('status') }}</div>
    @endif

    {{-- Filter — TETAP, plus toggle Table/Calendar di ujung kanan --}}
    <form method="GET" class="flex items-center gap-3 mb-6">
        <input type="hidden" name="view" value="{{ $view }}">

        <select name="client_id" onchange="this.form.submit()" class="border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
            <option value="">Semua Client</option>
            @foreach ($clientOptions as $c)
                <option value="{{ $c->id }}" {{ (string) $selectedClientId === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
            @endforeach
        </select>
        <select name="month" onchange="this.form.submit()" class="border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
            @foreach (range(1,12) as $m)
                <option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}</option>
            @endforeach
        </select>
        <select name="year" onchange="this.form.submit()" class="border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
            @foreach (range(now()->year - 1, now()->year + 1) as $y)
                <option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>
            @endforeach
        </select>

        {{-- Toggle Table / Calendar --}}
        <div class="flex items-center bg-[#f2f3f6] rounded-lg p-1 ml-auto">
            <a href="{{ request()->fullUrlWithQuery(['view' => 'table']) }}"
               class="text-xs font-medium px-3 py-1.5 rounded-md {{ $view === 'table' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4]' }}">
                <span class="material-symbols-outlined text-[15px] align-middle">table_rows</span> Table
            </a>
            <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar']) }}"
               class="text-xs font-medium px-3 py-1.5 rounded-md {{ $view === 'calendar' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4]' }}">
                <span class="material-symbols-outlined text-[15px] align-middle">calendar_month</span> Calendar
            </a>
        </div>
    </form>

    {{-- Target cards — TETAP --}}
    <div class="grid grid-cols-2 gap-5 mb-6">
        <div class="card p-6">
            <p class="text-xs font-medium text-[#9aa0a4] uppercase mb-2">Target Konten vs Realisasi</p>
            <p class="font-display text-3xl font-semibold text-[#14181a] mb-3">{{ $realizedContent }} <span class="text-lg text-[#9aa0a4] font-normal">/ {{ $targetContent }}</span></p>
            @php $pct = $targetContent > 0 ? min(100, round($realizedContent / $targetContent * 100, 1)) : 0; @endphp
            <div class="flex items-center justify-between text-xs text-[#9aa0a4] mb-1.5">
                <span>Overall Progress</span><span>{{ $pct }}%</span>
            </div>
            <div class="w-full h-1.5 rounded-full bg-[#f2f3f6] overflow-hidden">
                <div class="h-full bg-[#044b46] rounded-full" style="width: {{ $pct }}%"></div>
            </div>
        </div>
        <div class="card p-6">
            <p class="text-xs font-medium text-[#9aa0a4] uppercase mb-2">Target Desain vs Realisasi</p>
            <p class="font-display text-3xl font-semibold text-[#14181a] mb-3">{{ $realizedDesign }} <span class="text-lg text-[#9aa0a4] font-normal">/ {{ $targetDesign }}</span></p>
            @php $pctD = $targetDesign > 0 ? min(100, round($realizedDesign / $targetDesign * 100, 1)) : 0; @endphp
            <div class="flex items-center justify-between text-xs text-[#9aa0a4] mb-1.5">
                <span>Overall Progress</span><span>{{ $pctD }}%</span>
            </div>
            <div class="w-full h-1.5 rounded-full bg-[#f2f3f6] overflow-hidden">
                <div class="h-full bg-[#3452a8] rounded-full" style="width: {{ $pctD }}%"></div>
            </div>
        </div>
    </div>

    {{-- BAGIAN BAWAH — INI YANG BERUBAH SAAT SWITCH --}}
    @if ($view === 'calendar')

        @include('content-plan.partials.calendar-grid')

    @else

        <div class="card overflow-hidden">
            <table class="w-full text-sm text-left">
                <thead class="bg-[#f7f8fc]">
                    <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                        <th class="px-6 py-3 font-medium">Client</th>
                        <th class="px-4 py-3 font-medium">Bulan/Tahun</th>
                        <th class="px-4 py-3 font-medium">Jumlah Item</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($plans as $plan)
                        <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] cursor-pointer transition-colors" onclick="window.location='{{ route('content-plan.show', $plan) }}'">
                            <td class="px-6 py-3.5 font-medium text-[#14181a]">{{ $plan->client->name ?? '-' }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266]">{{ \Carbon\Carbon::create()->month($plan->month)->translatedFormat('F') }} {{ $plan->year }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266]">{{ $plan->content_items_count }}</td>
                            <td class="px-4 py-3.5">
                                <span class="text-xs px-2.5 py-1 rounded-full
                                    {{ $plan->status === 'approved' ? 'bg-[#f0f5f4] text-[#0f7a5f]' : '' }}
                                    {{ $plan->status === 'draft' ? 'bg-[#f2f3f6] text-[#9aa0a4]' : '' }}
                                    {{ $plan->status === 'pending' ? 'bg-[#fdf6ec] text-[#b8873a]' : '' }}
                                    {{ $plan->status === 'rejected' ? 'bg-[#fdf2f1] text-[#b3423e]' : '' }}">
                                    {{ ucfirst($plan->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-right text-[#c3c7cb]">
                                <span class="material-symbols-outlined text-[17px]">chevron_right</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-10 text-center text-[#9aa0a4] text-sm">Belum ada content plan untuk periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-5">{{ $plans->links() }}</div>

    @endif
</div>
@endsection