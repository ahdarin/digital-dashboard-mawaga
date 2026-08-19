{{-- Nilai palet gelap (Tahap B) - satu token per baris, sama persis
     namanya dengan _theme-tokens.blade.php (palet terang) supaya
     pemakaian var(--x) di seluruh view otomatis ikut ganti tanpa disentuh.

     Basis netral (surface/border/teks) sengaja diberi sedikit undertone
     teal (bukan abu-abu murni) biar selaras sama warna brand, bukan
     kebetulan icy-blue/warm-gray generik. Tint-tint (bg badge dsb)
     dibikin gelap & desaturasi, bukan cuma dibalik, karena harus tetap
     jadi latar buat teks semantik terang di atasnya.

     --brand/--danger-text/--warning-text/--info-text/--success-text
     SENGAJA cuma dinaikkan SEDANG (bukan terang-terang amat) - kelimanya
     dipakai dobel: (a) sebagai teks/ikon langsung di atas permukaan gelap
     (butuh terang biar kebaca), TAPI JUGA (b) sebagai bg tombol/badge
     dengan teks putih literal di atasnya (`text-white`, bukan token, jadi
     nggak ikut ganti warna - lihat tombol Google login/tombol
     Approve/badge notifikasi). Kalau dibikin terlalu terang (mis. mint
     cerah #3ecfb8), kasus (a) bagus tapi (b) putih-di-atas-mint jadi nyaris
     nggak kebaca. Nilai di bawah dipilih di titik tengah: kontras teks vs
     latar gelap tetap lolos ~4.5-6:1, KONTRAS putih-di-atasnya tetap masuk
     akal ~3-4:1 (nggak sempurna AA buat teks kecil, tapi jelas kebaca -
     lihat catatan di dark-mode-plan soal app ini bukan produk publik yang
     diaudit aksesibilitas ketat). --}}

/* Teks */
--text-primary: #eef1f0;
--text-secondary: #b7c0be;
--text-muted: #8a9492;
--text-placeholder: #66716f;
--text-idle: #57615f;
--icon-disabled: #3f4a48;

/* Brand (teal 523 Studio) */
--brand: #17a394;
--brand-dark: #128074;
--brand-tint: #17332f;
--brand-tint-hover: #1f3d38;
--brand-tint-soft: #18342f;
--brand-tint-border: #2c4e48;
--brand-border: #3a7268;
--brand-muted: #6f8f89;

/* Sukses */
--success-text: #1f9d6b;
--success-tint: #142c22;
--success-tint-soft: #183226;
--success-tint-soft-2: #152e24;
--success-strong: #a8e6cc;

/* Danger */
--danger-text: #d9564f;
--danger-tint: #2e1917;
--danger-tint-hover: #3a201d;
--danger-border: #5c2e2a;
--danger-border-strong: #7a3c36;
--danger-border-soft: #4a2622;
--danger-dark: #7a352e;
--field-error-border: #6b332e;

/* Warning */
--warning-text: #a97a35;
--warning-tint: #2e2414;
--warning-tint-soft: #362a18;
--warning-tint-soft-2: #33271a;
--warning-border: #5c4826;
--warning-strong: #d1a04a;
--warning-dark: #7a5f30;

/* Info */
--info-text: #5a82d6;
--info-tint: #1a2436;
--info-tint-soft: #202c40;
--info-tint-alt: #1e2c34;
--info-border: #33456b;
--info-dark: #3a5aa8;
--info-strong: #35a8c2;

/* Netral / permukaan */
--border: #2a3332;
--border-strong: #3a4544;
--surface-page: #0f1413;
--surface-card: #171d1c;
--surface-muted: #1f2625;
--surface-muted-2: #232b2a;
--surface-subtle: #141a19;
--surface-subtle-2: #161c1b;
