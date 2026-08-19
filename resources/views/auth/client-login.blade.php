<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    @include('partials._theme-init-script')
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>523 Studio | Client Login</title>

    <link rel="icon" href="{{ asset('images/favicon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @include('partials._theme-tokens')
        body { font-family: 'Inter', sans-serif; background-color: var(--surface-page); color: var(--text-primary); }
        .font-display { font-family: 'Fraunces', serif; }
    </style>
</head>
<body class="min-h-screen antialiased">

    <a href="{{ url('/') }}" class="fixed top-6 left-6 md:top-8 md:left-8 z-20 flex items-center justify-center w-10 h-10 rounded-full text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-card)] transition-colors group">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 group-hover:-translate-x-0.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
    </a>

    <div class="relative min-h-screen flex items-center justify-center px-6 py-12 overflow-hidden">
        <div class="absolute -top-24 -right-24 w-80 h-80 rounded-full bg-[var(--brand-tint)]"></div>
        <div class="absolute -bottom-28 -left-16 w-96 h-96 rounded-full bg-[var(--surface-card)]"></div>

        <div class="relative w-full max-w-md">

            <div class="bg-[var(--surface-card)] border border-[var(--border)] rounded-3xl shadow-[0_1px_2px_rgba(20,24,26,0.03),0_20px_50px_-20px_rgba(20,24,26,0.12)] p-8 md:p-10">

                <img src="{{ asset('images/logo.png') }}" alt="523 Studio" class="h-8 w-auto mb-8">

                <h1 class="font-display text-3xl font-semibold text-[var(--text-primary)] mb-1.5">Portal Client</h1>
                <p class="text-[var(--text-secondary)] text-sm mb-8">Kami akan kirim link login ke WhatsApp kamu.</p>

                @if (session('status'))
                    <div class="bg-[var(--brand-tint)] border border-[var(--brand-tint-border)] text-[var(--brand)] text-sm p-3.5 rounded-xl mb-6 font-medium">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="bg-[var(--danger-tint)] border border-[var(--danger-border)] text-[var(--danger-text)] p-3.5 rounded-xl mb-6 text-sm font-medium">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form action="{{ route('client.login.request') }}" method="POST" class="space-y-5">
                    @csrf
                    <div>
                        <label for="phone_number" class="block text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wide mb-2">Nomor WhatsApp</label>
                        <input id="phone_number" type="tel" name="phone_number" required
                               placeholder="08xxxxxxxxxx"
                               value="{{ old('phone_number') }}"
                               class="w-full bg-[var(--surface-page)] border border-[var(--border)] text-[var(--text-primary)] rounded-xl py-3.5 px-4 focus:outline-none focus:ring-4 focus:ring-[#044b46]/10 focus:border-[#044b46]/40 transition-all">
                    </div>

                    <button type="submit"
                            class="group w-full bg-[var(--brand-solid)] hover:bg-[var(--brand-dark)] active:scale-[0.98] text-white font-medium py-3.5 rounded-xl flex items-center justify-center gap-2 transition-all duration-200">
                        Kirim Link Login
                        <svg class="w-4 h-4 transition-transform duration-200 group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                        </svg>
                    </button>
                </form>
            </div>

            <p class="text-center text-xs text-[var(--text-muted)] mt-8">&copy; {{ date('Y') }} 523 Studio</p>
        </div>
    </div>

</body>
</html>
