@extends('layouts.app')
@section('title', 'Content Plan Bulanan')
@section('content')
<div x-data="{ showCreateModal: {{ $errors->any() ? 'true' : 'false' }} }" class="p-8 max-w-[1400px]">

    {{-- Bagian atas — TETAP, tidak berubah saat switch --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="font-display text-[32px] font-semibold text-[#14181a]">Monthly Content Plan</h1>
            <p class="text-[#5c6266] text-sm mt-1">Kelola dan pantau target konten seluruh client aktif.</p>
        </div>
        @if (auth()->user()->hasPermissionTo('content_plan', 'create'))
            <button type="button" @click="showCreateModal = true" class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                <span class="material-symbols-outlined text-[17px]">add</span> Buat Content Plan Baru
            </button>
        @endif
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

        @if ($view === 'calendar')
            {{-- Filter Tipe & Tanggal khusus calendar — disatukan dengan filter utama agar tidak "meloncat" saat ganti client --}}
            <div class="flex items-center gap-1 bg-[#f2f3f6] rounded-lg p-1">
                <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'type' => 'all', 'date' => null]) }}"
                   class="px-3 py-1.5 rounded-md text-xs font-medium
                   {{ ($selectedType ?? 'all') === 'all' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4]' }}">
                    Semua
                </a>
                <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'type' => 'Desain']) }}"
                   class="px-3 py-1.5 rounded-md text-xs font-medium
                   {{ ($selectedType ?? '') === 'Desain' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4]' }}">
                    Desain
                </a>
                <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'type' => 'Video']) }}"
                   class="px-3 py-1.5 rounded-md text-xs font-medium
                   {{ ($selectedType ?? '') === 'Video' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4]' }}">
                    Video
                </a>
            </div>

            <div class="flex items-center gap-2" x-data="{
        open: false,
        selected: @js($selectedDate),
        get displayLabel() {
            if (!this.selected) return 'Semua tanggal';
            const d = new Date(this.selected);
            return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
        }
    }">
                <div class="relative">
                    <button type="button" x-on:click="open = !open"
                        class="flex items-center gap-2 border border-[#eef0f4] rounded-lg px-3 py-1.5 text-xs bg-white hover:border-[#044b46]/40 min-w-[130px]">
                        <span class="material-symbols-outlined text-[15px] text-[#9aa0a4]">calendar_today</span>
                        <span class="text-[#14181a]" x-text="displayLabel"></span>
                    </button>

                    <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition
                        class="absolute z-20 mt-2 w-72 bg-white rounded-xl border border-[#eef0f4] shadow-lg p-4">

                        {{-- Dropdown bulan & tahun — ganti langsung, tanpa navigasi panah --}}
                        <form method="GET" class="flex items-center gap-2 mb-3">
                            <input type="hidden" name="view" value="calendar">
                            <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                            <input type="hidden" name="type" value="{{ $selectedType ?? 'all' }}">

                            <select name="month" onchange="this.form.submit()"
                                class="flex-1 border border-[#eef0f4] rounded-lg px-2 py-1.5 text-xs bg-white focus:outline-none focus:border-[#044b46]/40">
                                @foreach (range(1, 12) as $m)
                                    <option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>
                                        {{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}
                                    </option>
                                @endforeach
                            </select>

                            <select name="year" onchange="this.form.submit()"
                                class="border border-[#eef0f4] rounded-lg px-2 py-1.5 text-xs bg-white focus:outline-none focus:border-[#044b46]/40">
                                @foreach (range(now()->year - 2, now()->year + 2) as $y)
                                    <option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>
                                @endforeach
                            </select>
                        </form>

                        {{-- Grid tanggal untuk bulan/tahun yang sedang aktif --}}
                        <div
                            class="grid grid-cols-7 gap-1 text-center text-[10px] font-medium text-[#9aa0a4] uppercase mb-1.5">
                            <div>Su</div>
                            <div>Mo</div>
                            <div>Tu</div>
                            <div>We</div>
                            <div>Th</div>
                            <div>Fr</div>
                            <div>Sa</div>
                        </div>

                        <form method="GET" id="dateForm">
                            <input type="hidden" name="view" value="calendar">
                            <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                            <input type="hidden" name="month" value="{{ $month }}">
                            <input type="hidden" name="year" value="{{ $year }}">
                            <input type="hidden" name="type" value="{{ $selectedType ?? 'all' }}">
                            <input type="hidden" name="date" id="dateHiddenInput" value="{{ $selectedDate }}">

                            @php
                                $daysInMonthPopup = \Carbon\Carbon::create($year, $month, 1)->daysInMonth;
                                $firstDayOfWeekPopup = \Carbon\Carbon::create($year, $month, 1)->dayOfWeek;
                            @endphp

                            <div class="grid grid-cols-7 gap-1">
                                @for ($i = 0; $i < $firstDayOfWeekPopup; $i++)
                                    <div></div>
                                @endfor
                                @for ($d = 1; $d <= $daysInMonthPopup; $d++)
                                    @php $optionDate = \Carbon\Carbon::create($year, $month, $d)->format('Y-m-d'); @endphp
                                    <button type="submit" name="date" value="{{ $optionDate }}"
                                        class="w-8 h-8 rounded-lg text-xs flex items-center justify-center hover:bg-[#f0f5f4]
                                            {{ $selectedDate === $optionDate ? 'bg-[#044b46] text-white font-semibold' : 'text-[#14181a]' }}">
                                        {{ $d }}
                                    </button>
                                @endfor
                            </div>

                            <div class="flex items-center justify-between mt-3 pt-3 border-t border-[#eef0f4]">
                                <button type="submit" name="date" value=""
                                    class="text-xs font-medium text-[#5c6266] hover:underline">Clear</button>
                                <button type="submit" name="date" value="{{ now()->format('Y-m-d') }}"
                                    class="text-xs font-medium text-[#044b46] hover:underline">Hari ini</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

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
            <p class="text-xs font-medium text-[#9aa0a4] uppercase mb-2">Content Target vs Realization</p>
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
            <p class="text-xs font-medium text-[#9aa0a4] uppercase mb-2">Design Target vs Realization</p>
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
                        <th class="px-4 py-3 font-medium">Month/Year</th>
                        <th class="px-4 py-3 font-medium">Item Count</th>
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

    {{-- Modal Buat Content Plan Baru --}}
    <div x-show="showCreateModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-[#14181a]/40" @click="showCreateModal = false"></div>

        <div x-show="showCreateModal" x-transition
             class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
            <div class="flex items-center justify-between px-6 py-5 border-b border-[#eef0f4]">
                <h3 class="font-display text-lg font-semibold text-[#14181a]">Create New Content Plan</h3>
                <button type="button" @click="showCreateModal = false" class="text-[#9aa0a4] hover:text-[#5c6266]">
                    <span class="material-symbols-outlined text-[19px]">close</span>
                </button>
            </div>

            <form action="{{ route('content-plan.store') }}" method="POST">
                @csrf
                <div class="px-6 py-5 space-y-4">
                    @error('client_id')
                        <div class="bg-[#fdf2f1] text-[#b3423e] text-xs p-3 rounded-lg">{{ $message }}</div>
                    @enderror

                    <div>
                        <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Client <span class="text-[#b3423e]">*</span></label>
                        <select name="client_id" required class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                            <option value="">Pilih client...</option>
                            @foreach ($clientOptions as $c)
                                <option value="{{ $c->id }}" {{ !$c->activePackage ? 'disabled' : '' }}>
                                    {{ $c->name }} {{ !$c->activePackage ? '(belum ada paket aktif)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Bulan</label>
                            <select name="month" required class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                                @foreach (range(1,12) as $m)
                                    <option value="{{ $m }}" {{ now()->month === $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-[#9aa0a4] uppercase mb-1.5">Tahun</label>
                            <select name="year" required class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                                @foreach (range(now()->year - 1, now()->year + 1) as $y)
                                    <option value="{{ $y }}" {{ now()->year === $y ? 'selected' : '' }}>{{ $y }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 px-6 py-4 border-t border-[#eef0f4]">
                    <button type="submit" class="bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                        Buat Plan
                    </button>
                    <button type="button" @click="showCreateModal = false" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection