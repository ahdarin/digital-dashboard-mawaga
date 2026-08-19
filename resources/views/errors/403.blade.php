<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    @include('partials._theme-init-script')
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Akses Ditolak - 523 Studio</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" />
    <style>
        @include('partials._theme-tokens')
        .material-symbols-outlined { font-family: 'Material Symbols Outlined'; font-weight: normal; font-style: normal; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center bg-[var(--surface-page)] p-6">
    <div class="max-w-md w-full text-center">
        <div class="w-16 h-16 mx-auto mb-5 rounded-2xl bg-[var(--danger-tint)] text-[var(--danger-text)] flex items-center justify-center">
            <span class="material-symbols-outlined text-[30px]">lock</span>
        </div>
        <h1 class="font-semibold text-xl text-[var(--text-primary)] mb-2">Kamu Tidak Punya Akses ke Halaman Ini</h1>
        <p class="text-sm text-[var(--text-secondary)] mb-8">
            {{ $exception->getMessage() ?: 'Role kamu saat ini belum diizinkan untuk membuka halaman ini. Kalau menurutmu ini keliru, hubungi Manager atau CEO untuk cek ulang izin akunmu.' }}
        </p>
        <div class="flex items-center justify-center gap-3">
            @auth
                <a href="{{ url('/') }}" class="bg-[var(--brand-solid)] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[var(--brand-dark)] transition-colors">
                    Kembali ke Beranda
                </a>
            @else
                <a href="{{ route('login') }}" class="bg-[var(--brand-solid)] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[var(--brand-dark)] transition-colors">
                    Ke Halaman Login
                </a>
            @endauth
        </div>
    </div>
</body>
</html>
