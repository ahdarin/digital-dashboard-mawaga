{{-- Anti-flash tema (Tahap C) - HARUS di-@include sedini mungkin di <head>,
     sebelum <style>/<link> apa pun, di SEMUA halaman yang render <html>
     sendiri (sama seperti partials._theme-tokens - lihat memori
     dark-mode-plan). Script sinkron (bukan defer/async) supaya
     data-theme keburu keset di <html> SEBELUM <style> di bawahnya
     di-apply browser - kalau nggak, halaman sempat kekilat terang
     sesaat sebelum jadi gelap.

     localStorage jadi sumber utama (instan, jalan bahkan sebelum request
     ke server). Kalau localStorage kosong (browser/device baru) dan user
     lagi login, dipakai preferensi tersimpan di DB (users.preferences)
     sebagai seed awal - baru abis itu localStorage yang jadi acuan buat
     kunjungan berikutnya. Halaman tanpa sesi (login, welcome, portal
     klien) otomatis nggak punya server value, jatuh ke localStorage-only
     atau default 'system'. --}}
@php
    $__serverTheme = auth()->check() ? auth()->user()->themePreference() : null;
@endphp
<script>
(function () {
    try {
        var stored = localStorage.getItem('theme');
        var serverTheme = @json($__serverTheme);
        var theme = stored || serverTheme || 'system';
        if (!stored && serverTheme) { localStorage.setItem('theme', serverTheme); }
        if (theme === 'light' || theme === 'dark') {
            document.documentElement.setAttribute('data-theme', theme);
        }
    } catch (e) {}
})();
</script>
