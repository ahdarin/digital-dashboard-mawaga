{{-- Token warna (tokenisasi dark mode - lihat memori dark-mode-plan).

     Di-@include di dalam tag <style> tiap halaman yang render <html> sendiri
     (layouts/app.blade.php DAN halaman standalone seperti auth/login,
     client/*, errors/403, welcome - lihat masing-masing) - kalau nggak,
     var(--brand) dkk di halaman itu nggak resolve ke apa-apa.

     Struktur 3 state (belum ada toggle-nya - itu Tahap C):
     - :root polos = palet terang, berlaku selalu sebagai default.
     - @media (prefers-color-scheme: dark) menimpanya kalau OS user disetel
       gelap, DIJAGA dengan :not([data-theme="light"]) supaya nanti kalau
       Tahap C nambah toggle eksplisit "Terang", pilihan itu menang lawan
       preferensi OS.
     - :root[data-theme="dark"] menimpa lagi supaya toggle eksplisit "Gelap"
       menang juga walau OS-nya terang. Atribut data-theme belum pernah
       diset di mana pun sekarang (Tahap C) - blok ini cuma disiapkan
       duluan supaya Tahap C nggak perlu balik ubah file ini lagi. --}}
:root {
    /* Beritahu browser tema aktifnya - tanpa ini, UI bawaan browser (native
       scrollbar, ikon date-picker bawaan, dsb) TETAP dirender versi terang
       walau semua warna halaman sudah gelap, karena browser nggak bisa
       nebak dari warna page, cuma dari properti ini. Ikut mekanisme
       terang/gelap yang sama kayak token warna di bawah - lihat blok
       @media & [data-theme] di bagian bawah file ini. */
    color-scheme: light;

    /* Teks */
    --text-primary: #14181a;
    --text-secondary: #5c6266;
    --text-muted: #767c80;
    --text-placeholder: #9aa0a4;
    --text-idle: #c3c7cb;
    --icon-disabled: #d4d7db;

    /* Brand (teal 523 Studio) */
    --brand: #044b46;
    --brand-dark: #033b37;
    /* --brand-solid SENGAJA nggak didefinisikan ulang di palet gelap (lihat
       _theme-tokens-dark.blade.php) - nilainya harus tetap sama di kedua
       tema. Dipakai khusus buat permukaan solid (kartu hero, bubble chat,
       tombol) yang teksnya text-white literal, BUKAN var(--text-*) - kalau
       --brand ikut terang di dark mode (perlu, biar kebaca sebagai
       teks/ikon polos di halaman gelap), teks putih di atas permukaan
       solid itu jadi nyaris nggak kebaca. --brand-dark yang dipakai buat
       hover state permukaan solid ini juga sama-sama sengaja nggak
       ditimpa di palet gelap, karena pemakaiannya selalu bareng
       --brand-solid. Lihat catatan lebih lengkap di memori dark-mode-plan. */
    --brand-solid: #044b46;
    --brand-tint: #f0f5f4;
    --brand-tint-hover: #e4ede9;
    --brand-tint-soft: #e2ece9;
    --brand-tint-border: #dbe6e4;
    --brand-border: #8fb8b3;
    --brand-muted: #a9c4bf;

    /* Sukses (dipisah dari brand-tint walau kebetulan sama nilainya
       sekarang - lihat catatan di dark-mode-plan) */
    --success-text: #0f7a5f;
    --success-tint: #f0f5f4;
    --success-tint-soft: #eaf5f0;
    --success-tint-soft-2: #f7fbf9;
    --success-strong: #9fd8c4;

    /* Danger */
    --danger-text: #b3423e;
    --danger-solid: #b3423e; {{-- sama alasan --brand-solid, lihat komentar di atas --}}
    --danger-tint: #fdf2f1;
    --danger-tint-hover: #fbe4e2;
    --danger-border: #f5d9d7;
    --danger-border-strong: #e39a96;
    --danger-border-soft: #f3b6b2;
    --danger-dark: #96352f;
    --field-error-border: #f5a19b;

    /* Warning */
    --warning-text: #8a6423;
    --warning-solid: #8a6423;
    --warning-tint: #fdf6ec;
    --warning-tint-soft: #f7f0e0;
    --warning-tint-soft-2: #f7e8cf;
    --warning-border: #e4c98f;
    --warning-strong: #b8873a;
    --warning-dark: #735220;

    /* Info */
    --info-text: #3452a8;
    --info-solid: #3452a8;
    --info-tint: #eef2fb;
    --info-tint-soft: #e2e8f8;
    --info-tint-alt: #e8f2f7;
    --info-border: #dde4f7;
    --info-dark: #2c4590;
    --info-strong: #0e7490;

    /* Netral / permukaan */
    --border: #eef0f4;
    --border-strong: #dadfe0;
    --surface-page: #f7f8fc;
    --surface-card: #fff;
    --surface-muted: #f2f3f6;
    --surface-muted-2: #f0f2f5;
    --surface-subtle: #fafbfc;
    --surface-subtle-2: #fafcfb;

    /* Overlay gelap (tooltip/toast) - SENGAJA nggak ikut ditimpa di palet
       gelap (lihat _theme-tokens-dark.blade.php), sama alasannya kayak
       --brand-solid: dipakai sebagai bg solid dengan teks putih literal
       (`text-white`) di atasnya - kalau ikut jadi terang di dark mode
       (seperti --text-primary yang jadi hampir putih), teks putihnya
       nggak kebaca sama sekali. */
    --overlay-solid: #14181a;
}

@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
        @include('partials._theme-tokens-dark')
    }
}

:root[data-theme="dark"] {
    @include('partials._theme-tokens-dark')
}
