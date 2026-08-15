@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

    <div class="p-4 sm:p-6 lg:p-8 max-w-[1400px]">

        {{-- Welcome banner: sengaja dipisah dari "Overview" di bawahnya - satu-satunya
        elemen non-data di halaman ini, biar dashboard nggak kaku angka melulu. --}}
        @php
            $greetingHour = now()->hour;
            // Satu sumber kebenaran buat "jam berapa sekarang" - dipakai bareng
            // buat teks sapaan DAN milih pool reminder, biar keduanya selalu
            // nyambung (nggak mungkin sapaan "Selamat pagi" tapi reminder-nya
            // versi malam).
            $timeOfDay = match (true) {
                $greetingHour >= 4 && $greetingHour < 11 => 'pagi',
                $greetingHour >= 11 && $greetingHour < 15 => 'siang',
                $greetingHour >= 15 && $greetingHour < 18 => 'sore',
                default => 'malam',
            };
            $greeting = 'Selamat ' . $timeOfDay;

            // Reminder dipisah per waktu (bukan 1 daftar campur semua) biar isinya
            // relevan sama jam buka dashboard-nya - jangan sampai "istirahat
            // malam" muncul jam 9 pagi. Tiap pool jumlah pesannya PAS sama jumlah
            // jam di periode itu (pagi 7 jam, siang 4 jam, sore 3 jam, malam 10
            // jam) dan digilir per JAM (bukan per hari) - jadi tiap jam beda
            // kalimat, tapi tetap sama kalau halaman di-refresh di jam yang sama.
            $reminderPools = [
                'pagi' => [ // jam 04-10
                    ['icon' => 'bedtime', 'text' => 'Masih pagi buta, kalau bangun sepagi ini pastikan istirahat tadi malam cukup.'],
                    ['icon' => 'wb_twilight', 'text' => 'Awali hari dengan minum air putih dulu, jangan langsung kopi aja.'],
                    ['icon' => 'local_cafe', 'text' => 'Jangan lupa sarapan dulu ya, biar energinya cukup buat kerja seharian.'],
                    ['icon' => 'self_improvement', 'text' => 'Sempatkan stretching sebentar, biar badan nggak kaku dari pagi.'],
                    ['icon' => 'checklist', 'text' => 'Cek dulu prioritas hari ini sebelum mulai, biar nggak keteteran pas siang.'],
                    ['icon' => 'wb_sunny', 'text' => 'Pagi ini waktu terbaik buat kerjain yang paling penting duluan.'],
                    ['icon' => 'task_alt', 'text' => 'Sebentar lagi siang, pastikan target pagi ini udah mulai jalan.'],
                ],
                'siang' => [ // jam 11-14
                    ['icon' => 'restaurant', 'text' => 'Sebentar lagi waktunya makan siang, mulai rapikan kerjaan yang lagi jalan.'],
                    ['icon' => 'lunch_dining', 'text' => 'Jangan lupa makan siang, jangan ditunda-tunda gara-gara sibuk.'],
                    ['icon' => 'self_improvement', 'text' => 'Ngantuk abis makan siang itu wajar, boleh jalan-jalan sebentar biar segar lagi.'],
                    ['icon' => 'visibility', 'text' => 'Istirahatin mata sebentar, efek natap layar seharian ke mata itu nyata.'],
                ],
                'sore' => [ // jam 15-17
                    ['icon' => 'self_improvement', 'text' => 'Biasanya energi mulai turun jam segini, coba stretching atau jalan sebentar.'],
                    ['icon' => 'local_drink', 'text' => 'Jangan lupa minum air putih, jangan sampai dehidrasi pas lagi fokus kerja.'],
                    ['icon' => 'task_alt', 'text' => 'Hampir waktunya pulang, rapikan dulu yang lagi dikerjakan biar besok gampang lanjutnya.'],
                ],
                'malam' => [ // jam 18-23, lanjut 00-03
                    ['icon' => 'task_alt', 'text' => 'Waktunya wrap-up kerjaan, rapikan dulu sebelum benar-benar berhenti hari ini.'],
                    ['icon' => 'restaurant', 'text' => 'Sudah makan malam belum? Jangan sampai kelewat gara-gara masih fokus kerja.'],
                    ['icon' => 'favorite', 'text' => 'Luangin waktu buat diri sendiri atau keluarga, kerjaan bisa lanjut besok.'],
                    ['icon' => 'bedtime', 'text' => 'Kalau udah selesai, jangan lupa istirahat, badan juga butuh recharge.'],
                    ['icon' => 'nightlight', 'text' => 'Jangan begadang cuma buat ngejar kerjaan yang sebenarnya bisa ditunda ke besok.'],
                    ['icon' => 'bedtime', 'text' => 'Udah larut, waktunya rehat - istirahat cukup malam ini biar besok bisa mulai dengan fresh.'],
                    ['icon' => 'nightlight', 'text' => 'Sudah tengah malam, kalau masih terjaga pastikan itu memang penting, bukan sekadar scroll-scroll.'],
                    ['icon' => 'bedtime', 'text' => 'Jam segini harusnya udah istirahat, kesehatan tetap nomor satu, kerjaan nomor dua.'],
                    ['icon' => 'nightlight', 'text' => 'Masih bangun jam segini? Coba dipertimbangkan lagi, besok pasti butuh tenaga penuh.'],
                    ['icon' => 'wb_twilight', 'text' => 'Sebentar lagi pagi, kalau belum tidur juga, ini saatnya benar-benar istirahat dulu.'],
                ],
            ];

            // Offset jam relatif ke awal periode-nya masing-masing (bukan jam
            // absolut 0-23), biar setiap jam di dalam 1 periode dapat pesan
            // yang berbeda tanpa tabrakan.
            $hourOffset = match ($timeOfDay) {
                'pagi' => $greetingHour - 4,
                'siang' => $greetingHour - 11,
                'sore' => $greetingHour - 15,
                default => $greetingHour >= 18 ? $greetingHour - 18 : $greetingHour + 6,
            };

            $reminderPool = $reminderPools[$timeOfDay];
            $dailyReminder = $reminderPool[$hourOffset] ?? $reminderPool[0];

            // Kecepatan running text disesuaikan panjang kalimatnya - biar
            // yang pendek nggak kerasa lambat & yang panjang nggak kerasa
            // buru-buru kelewatan sebelum sempat kebaca.
            $marqueeDuration = max(9, min(20, (int) round(mb_strlen($dailyReminder['text']) / 6)));
        @endphp
        <div class="mb-6 rounded-2xl bg-[#044b46] px-5 sm:px-7 py-4 sm:py-5 flex items-center justify-between gap-5 flex-wrap">
            <div>
                <p class="text-white/70 text-sm">{{ $greeting }}, {{ auth()->user()->name ?? 'Tim 523' }}</p>
                <h2 class="font-display text-xl font-semibold text-white mt-0.5">Selamat datang kembali di 523 Studio</h2>
            </div>
            <div class="flex items-center gap-2.5 bg-white/10 rounded-full pl-3.5 pr-4 py-2 shrink-0">
                <span class="material-symbols-outlined text-white text-[18px] shrink-0">{{ $dailyReminder['icon'] }}</span>
                <div class="w-56 sm:w-72 overflow-hidden">
                    <div class="marquee-track" style="--marquee-duration: {{ $marqueeDuration }}s">
                        <p class="text-sm text-white/90 whitespace-nowrap pr-12">{{ $dailyReminder['text'] }}</p>
                        <p class="text-sm text-white/90 whitespace-nowrap pr-12" aria-hidden="true">{{ $dailyReminder['text'] }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-2 mb-7">
            <div>
                <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[#14181a]">Overview</h1>
                <p class="text-[#5c6266] text-sm mt-1">Ringkasan aktivitas tim dan klien hari ini.</p>
            </div>

            <div x-data="{ now: new Date() }" x-init="setInterval(() => now = new Date(), 1000)"
                class="sm:text-right shrink-0">
                <p class="text-sm text-[#5c6266]"
                    x-text="now.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })">
                </p>
                <p class="font-display text-2xl font-semibold text-[#14181a]"
                    x-text="now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })"></p>
            </div>
        </div>

        @if (! empty($nextSteps))
            <div class="card p-5 mb-6 border-l-4 border-l-[#044b46]">
                <p class="text-[10px] font-semibold text-[#9aa0a4] uppercase tracking-wide mb-3">Langkah Berikutnya</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    @foreach ($nextSteps as $step)
                        <a href="{{ $step['route'] }}" class="flex items-start gap-2.5 p-3 rounded-lg bg-[#f7f8fc] hover:bg-[#f0f5f4] transition-colors">
                            <span class="material-symbols-outlined text-[#044b46] text-[18px] shrink-0">{{ $step['icon'] }}</span>
                            <span class="text-sm text-[#14181a] leading-snug">{{ $step['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="flex flex-col lg:flex-row gap-5 items-stretch lg:items-start">

            <div class="flex-1 min-w-0 space-y-5">

                {{-- Stat cards --}}
                @php
                    $statStyles = [
                        ['chip' => 'bg-[#f0f5f4]', 'icon' => 'text-[#044b46]'],
                        ['chip' => 'bg-[#eef2fb]', 'icon' => 'text-[#3452a8]'],
                        ['chip' => 'bg-[#e8f2f7]', 'icon' => 'text-[#0e7490]'],
                        ['chip' => 'bg-[#fdf2f1]', 'icon' => 'text-[#b3423e]'],
                    ];
                @endphp
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @foreach ($stats as $stat)
                        @php $c = $statStyles[$loop->index % 4]; @endphp
                        <div class="card p-5">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-9 h-9 rounded-lg {{ $c['chip'] }} flex items-center justify-center">
                                    <span
                                        class="material-symbols-outlined {{ $c['icon'] }} text-[18px]">{{ $stat['icon'] }}</span>
                                </div>
                                <span class="text-sm text-[#5c6266]">{{ $stat['label'] }}</span>
                            </div>

                            <p class="font-display text-[28px] font-semibold text-[#14181a] mb-2">{{ $stat['value'] }}</p>

                            <p class="text-xs font-medium flex items-center gap-1
                                    {{ $stat['trend'] === 'up' ? 'text-[#0f7a5f]' : '' }}
                                    {{ $stat['trend'] === 'down' ? 'text-[#b3423e]' : '' }}
                                    {{ $stat['trend'] === 'flat' ? 'text-[#9aa0a4]' : '' }}">
                                @if ($stat['trend'] === 'up')
                                    <span class="material-symbols-outlined text-[13px]">trending_up</span>
                                @elseif ($stat['trend'] === 'down')
                                    <span class="material-symbols-outlined text-[13px]">trending_down</span>
                                @else
                                    <span>&mdash;</span>
                                @endif
                                {{ $stat['change'] }}
                            </p>
                        </div>
                    @endforeach
                </div>

                {{-- Performance chart --}}
                <div class="card p-6">
                    <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1">Content Performance</h2>
                    <p class="text-xs text-[#9aa0a4] mb-6">Jumlah konten berdasarkan deadline, 7 bulan terakhir</p>

                    @php
                        $max = max(collect($performance)->max('value'), 1);
                        $peak = collect($performance)->sortByDesc('value')->keys()->first();
                    @endphp

                    <div class="flex items-end justify-between gap-4 h-48">
                        @foreach ($performance as $i => $bar)
                            <div class="flex-1 flex flex-col items-center gap-2.5">
                                <span class="text-xs font-medium text-[#9aa0a4]">{{ $bar['value'] }}</span>
                                <div class="w-full max-w-12 rounded-t-[3px] transition-all duration-300 {{ $i === $peak && $bar['value'] > 0 ? 'bg-[#044b46]' : 'bg-[#dbe6e4]' }}"
                                    style="height: {{ max(($bar['value'] / $max) * 100, 3) }}%"></div>
                                <span class="text-xs text-[#9aa0a4]">{{ $bar['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Views trend (domain PIC 3) --}}
                <div class="card p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="font-display text-lg font-semibold text-[#14181a]">Views Trend</h2>
                        <form method="GET">
                            <select name="period" onchange="this.form.submit()"
                                class="text-sm border border-[#eef0f4] rounded-lg px-3 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
                                <option value="7" {{ $period === 7 ? 'selected' : '' }}>7 Hari</option>
                                <option value="30" {{ $period === 30 ? 'selected' : '' }}>30 Hari</option>
                                <option value="90" {{ $period === 90 ? 'selected' : '' }}>90 Hari</option>
                            </select>
                        </form>
                    </div>
                    <p class="text-xs text-[#9aa0a4] mb-5">Total views seluruh konten, {{ $period }} hari terakhir.</p>
                    <x-trend-chart :trend="$viewsTrend" />
                </div>

                {{-- Recent projects --}}
                <div class="card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-display text-lg font-semibold text-[#14181a]">Recent Projects</h2>
                        <a href="{{ Route::has('production-workflow.index') ? route('production-workflow.index') : '#' }}"
                            class="text-sm font-medium text-[#044b46] hover:underline">Lihat semua</a>
                    </div>

                    @if ($recentItems->isEmpty())
                        <p class="text-sm text-[#9aa0a4] py-6 text-center">Belum ada konten yang tercatat.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left">
                                <thead>
                                    <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                                        <th class="pb-2.5 font-medium">Title</th>
                                        <th class="pb-2.5 font-medium">Client</th>
                                        <th class="pb-2.5 font-medium">Type</th>
                                        <th class="pb-2.5 font-medium">Deadline</th>
                                        <th class="pb-2.5 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($recentItems as $item)
                                        <tr class="border-t border-[#f2f3f6]">
                                            <td class="py-3 pr-4 font-medium text-[#14181a] whitespace-nowrap">{{ $item['title'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $item['client'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $item['type'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">
                                                {{ $item['deadline'] ? $item['deadline']->translatedFormat('d M Y') : '-' }}</td>
                                            <td class="py-3 pr-4">
                                                <span
                                                    class="text-xs px-2 py-1 rounded-full whitespace-nowrap {{ $item['is_overdue'] ? 'bg-[#fdf2f1] text-[#b3423e]' : 'bg-[#f0f5f4] text-[#044b46]' }}">{{ $item['status'] }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                {{-- Top performing content (teaser Analytics) --}}
                <div class="card p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="font-display text-lg font-semibold text-[#14181a]">Top Performing Content</h2>
                        <a href="{{ Route::has('analytics') ? route('analytics') : '#' }}"
                            class="text-sm font-medium text-[#044b46] hover:underline">Lihat Analytics</a>
                    </div>
                    <p class="text-xs text-[#9aa0a4] mb-4">Konten dengan views tertinggi bulan ini, lintas semua client.</p>

                    @if ($topContent->isEmpty())
                        <p class="text-sm text-[#9aa0a4] py-6 text-center">Belum ada data performa konten bulan ini.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left">
                                <thead>
                                    <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                                        <th class="pb-2.5 font-medium">Title</th>
                                        <th class="pb-2.5 font-medium">Client</th>
                                        <th class="pb-2.5 font-medium">Platform</th>
                                        <th class="pb-2.5 font-medium">Views</th>
                                        <th class="pb-2.5 font-medium">Engagement</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($topContent as $item)
                                        <tr class="border-t border-[#f2f3f6]">
                                            <td class="py-3 pr-4 font-medium text-[#14181a] whitespace-nowrap">{{ $item['title'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $item['client'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $item['platform'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ number_format($item['views']) }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $item['engagement_rate'] }}%</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                {{-- Top client ranking (Executive Dashboard, PRD 7.3.3) --}}
                <div class="card p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="font-display text-lg font-semibold text-[#14181a]">Top Client</h2>
                        <a href="{{ Route::has('client-management.index') ? route('client-management.index') : '#' }}"
                            class="text-sm font-medium text-[#044b46] hover:underline">Lihat semua client</a>
                    </div>
                    <p class="text-xs text-[#9aa0a4] mb-4">Client dengan performa views tertinggi bulan ini.</p>

                    @if ($topClients->isEmpty())
                        <p class="text-sm text-[#9aa0a4] py-6 text-center">Belum ada data performa client bulan ini.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left">
                                <thead>
                                    <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                                        <th class="pb-2.5 font-medium">Client</th>
                                        <th class="pb-2.5 font-medium">Views</th>
                                        <th class="pb-2.5 font-medium">Engagement</th>
                                        <th class="pb-2.5 font-medium">Jumlah Konten</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($topClients as $client)
                                        <tr class="border-t border-[#f2f3f6]">
                                            <td class="py-3 pr-4 font-medium text-[#14181a] whitespace-nowrap">{{ $client['name'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ number_format($client['views']) }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $client['engagement_rate'] }}%</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $client['content_count'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

            </div>

            {{-- Kolom kanan --}}
            <div class="w-full lg:w-[320px] shrink-0 flex flex-col gap-5">

                <div class="card p-6 flex flex-col">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-display text-base font-semibold text-[#14181a]">AI Insights</h2>
                        <span class="material-symbols-outlined text-[#044b46] text-[18px]">auto_awesome</span>
                    </div>
                    <div class="space-y-3">
                        @forelse ($insights as $insight)
                            <div class="bg-[#f7f8fc] rounded-lg p-3.5">
                                <p class="text-sm font-medium text-[#14181a] flex gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#044b46] mt-1.5 shrink-0"></span>
                                    {{ $insight['title'] }}
                                </p>
                                <p class="text-xs text-[#9aa0a4] mt-1 pl-3.5">{{ $insight['description'] }}</p>
                            </div>
                        @empty
                            <p class="text-sm text-[#9aa0a4] text-center py-4">Belum cukup data untuk insight.</p>
                        @endforelse
                    </div>
                </div>

                <div class="card p-6 flex flex-col">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-display text-base font-semibold text-[#14181a]">Needs Attention</h2>
                        <span class="material-symbols-outlined text-[#b3423e] text-[18px]">priority_high</span>
                    </div>

                    <div class="space-y-3 flex-1">
                        @forelse ($attentionItems as $item)
                            <div class="bg-[#f7f8fc] rounded-lg p-3.5">
                                <p class="text-sm font-medium text-[#14181a] flex gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#b3423e] mt-1.5 shrink-0"></span>
                                    {{ $item['title'] }}
                                </p>
                                <p class="text-xs text-[#9aa0a4] mt-1 pl-3.5">{{ $item['client'] }} &middot; Penanggung Jawab:
                                    {{ $item['pic'] }} &middot; {{ $item['status'] }}</p>
                            </div>
                        @empty
                            <div class="text-center py-8">
                                <span class="material-symbols-outlined text-[#0f7a5f] text-[28px]">check_circle</span>
                                <p class="text-sm text-[#9aa0a4] mt-2">Tidak ada item overdue. Semua on track.</p>
                            </div>
                        @endforelse
                    </div>

                    <a href="{{ Route::has('production-workflow.index') ? route('production-workflow.index') : '#' }}"
                        class="mt-4 w-full bg-[#f7f8fc] text-[#044b46] text-sm font-medium py-2.5 rounded-lg hover:bg-[#f0f5f4] transition-colors flex items-center justify-center gap-1.5">
                        Buka Production Workflow
                        <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>

                <div class="card p-6 flex flex-col">
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="font-display text-base font-semibold text-[#14181a]">High Risk (AI Prediction)</h2>
                        <span class="material-symbols-outlined text-[#b3423e] text-[18px]">report</span>
                    </div>
                    <p class="text-xs text-[#9aa0a4] mb-4">Belum overdue, tapi diprediksi berisiko terlambat — cegah sebelum
                        kejadian.</p>

                    <div class="space-y-3 flex-1">
                        @forelse ($highRiskItems as $item)
                            <a href="{{ route('content-items.show', $item['id']) }}"
                                class="block bg-[#f7f8fc] rounded-lg p-3.5 hover:bg-[#fdf2f1] transition-colors">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-medium text-[#14181a] flex gap-2 min-w-0">
                                        <span class="w-1.5 h-1.5 rounded-full bg-[#b3423e] mt-1.5 shrink-0"></span>
                                        <span class="truncate">{{ $item['title'] }}</span>
                                    </p>
                                    <span
                                        class="text-xs font-semibold text-[#b3423e] shrink-0">{{ $item['risk_score'] }}%</span>
                                </div>
                                <p class="text-xs text-[#9aa0a4] mt-1 pl-3.5">{{ $item['client'] }} &middot; Penanggung Jawab:
                                    {{ $item['pic'] }}</p>
                                <p class="text-xs text-[#9aa0a4] pl-3.5">{{ $item['top_factor'] }}</p>
                            </a>
                        @empty
                            <div class="text-center py-8">
                                <span class="material-symbols-outlined text-[#0f7a5f] text-[28px]">verified</span>
                                <p class="text-sm text-[#9aa0a4] mt-2">Tidak ada item risiko tinggi saat ini.</p>
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- Akurasi prediksi AI Delay Risk (teaser Team Performance) --}}
                <div class="card p-6 flex flex-col">
                    <div class="flex items-center gap-3 mb-1">
                        <div class="w-9 h-9 rounded-lg bg-[#eef2fb] flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-[#3452a8] text-[18px]">verified</span>
                        </div>
                        <h2 class="font-display text-base font-semibold text-[#14181a]">AI Prediction Accuracy</h2>
                    </div>

                    @if ($riskAccuracy['total_evaluated'] === 0)
                        <p class="text-sm text-[#9aa0a4] mt-3">Belum ada cukup data (butuh konten yang sudah upload dan pernah
                            dapat skor risiko).</p>
                    @else
                        @if ($riskAccuracy['high_risk_accuracy'] !== null)
                            <div class="flex items-baseline gap-2 mt-3">
                                <p class="font-display text-2xl font-semibold text-[#14181a]">
                                    {{ $riskAccuracy['high_risk_accuracy'] }}%</p>
                                <p class="text-xs text-[#5c6266]">prediksi <strong>High Risk</strong> benar-benar terlambat</p>
                            </div>
                        @else
                            <p class="text-sm text-[#9aa0a4] mt-3">Belum ada konten dengan prediksi High Risk yang sudah selesai
                                upload.</p>
                        @endif
                    @endif

                    <a href="{{ route('team-performance.index') }}"
                        class="mt-4 w-full bg-[#f7f8fc] text-[#044b46] text-sm font-medium py-2.5 rounded-lg hover:bg-[#f0f5f4] transition-colors flex items-center justify-center gap-1.5">
                        Lihat detail lengkap
                        <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>

            </div>

        </div>
    </div>

@endsection