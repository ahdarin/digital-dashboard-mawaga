{{-- Welcome banner - dipakai di Beranda (landing page semua user internal).
     Satu-satunya elemen non-data di halaman ini, biar nggak kaku angka melulu. --}}
@props(['isWorkday' => true, 'attendance' => null, 'lateMinutes' => 0])
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
    // relevan sama jam buka halamannya - jangan sampai "istirahat
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
<div class="mb-6 rounded-2xl bg-[var(--brand)] px-5 sm:px-7 py-4 sm:py-5 flex items-center justify-between gap-5 flex-wrap">
    <div>
        <p class="text-white/70 text-sm">{{ $greeting }}, {{ auth()->user()->name ?? 'Tim 523' }}</p>
        <h1 class="font-display text-xl font-semibold text-white mt-0.5">Selamat datang kembali di 523 Studio</h1>
    </div>
    <div class="flex items-center gap-2.5 bg-white/10 rounded-full pl-3.5 pr-4 py-2 w-full sm:w-auto sm:shrink-0">
        <span class="material-symbols-outlined text-white text-[18px] shrink-0">{{ $dailyReminder['icon'] }}</span>
        <div class="flex-1 min-w-0 sm:flex-none sm:w-72 overflow-hidden">
            <div class="marquee-track" style="--marquee-duration: {{ $marqueeDuration }}s">
                <p class="text-sm text-white/90 whitespace-nowrap pr-12">{{ $dailyReminder['text'] }}</p>
                <p class="text-sm text-white/90 whitespace-nowrap pr-12" aria-hidden="true">{{ $dailyReminder['text'] }}</p>
            </div>
        </div>
    </div>
</div>

<div x-data="{ now: new Date() }" x-init="setInterval(() => now = new Date(), 1000)"
    class="flex items-start justify-between mb-6 ml-3 flex-wrap gap-3">
    <div class="shrink-0">
        <p class="text-sm text-[var(--text-secondary)]"
            x-text="now.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })"></p>
        <p class="font-display text-2xl font-semibold text-[var(--text-primary)]"
            x-text="now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })"></p>
    </div>
    <x-attendance-widget :is-workday="$isWorkday" :attendance="$attendance" :late-minutes="$lateMinutes" />
</div>
