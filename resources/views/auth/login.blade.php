<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>523 Studio | Masuk</title>

    <link rel="icon" href="{{ asset('images/favicon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f7f8fc; color: #14181a; }
        .font-display { font-family: 'Fraunces', serif; }
    </style>
</head>
<body class="min-h-screen antialiased">

    <a href="{{ url('/') }}" class="fixed top-6 left-6 md:top-8 md:left-8 z-20 flex items-center justify-center w-10 h-10 rounded-full text-[#767c80] hover:text-[#14181a] hover:bg-white transition-colors group">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 group-hover:-translate-x-0.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
    </a>

    <div class="relative min-h-screen flex items-center justify-center px-6 py-12 overflow-hidden">
        <div class="absolute -top-24 -right-24 w-80 h-80 rounded-full bg-[#f0f5f4]"></div>
        <div class="absolute -bottom-28 -left-16 w-96 h-96 rounded-full bg-white"></div>

        <div class="relative w-full max-w-md">

            <div class="bg-white border border-[#eef0f4] rounded-3xl shadow-[0_1px_2px_rgba(20,24,26,0.03),0_20px_50px_-20px_rgba(20,24,26,0.12)] p-8 md:p-10">

                <img src="{{ asset('images/logo.png') }}" alt="523 Studio" class="h-8 w-auto mb-8">

                <h1 class="font-display text-3xl font-semibold text-[#14181a] mb-1.5">Masuk</h1>
                <p class="text-[#5c6266] text-sm mb-8">Khusus tim internal 523 Studio</p>

                @if ($errors->any())
                    <div class="bg-[#fdf2f1] border border-[#f5d9d7] text-[#b3423e] p-3.5 rounded-xl mb-6 text-sm font-medium">
                        {{ $errors->first() }}
                    </div>
                @endif

                <a href="{{ route('auth.google') }}"
                   class="group w-full bg-[#044b46] hover:bg-[#033b37] active:scale-[0.98] text-white font-medium py-3.5 rounded-xl flex items-center justify-center gap-3 transition-all duration-200">
                    <svg class="w-5 h-5" viewBox="0 0 24 24">
                        <path fill="#fff" d="M23.5 12.27c0-.85-.07-1.67-.2-2.45H12v4.64h6.47c-.28 1.5-1.13 2.77-2.4 3.62v3h3.88c2.27-2.09 3.55-5.17 3.55-8.81z"/>
                        <path fill="#fff" d="M12 24c3.24 0 5.95-1.08 7.93-2.92l-3.88-3c-1.08.72-2.45 1.15-4.05 1.15-3.11 0-5.75-2.1-6.69-4.92H1.3v3.09C3.26 21.3 7.31 24 12 24z"/>
                        <path fill="#fff" d="M5.31 14.31c-.24-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.64H1.3C.47 8.24 0 10.06 0 12s.47 3.76 1.3 5.36l4.01-3.05z"/>
                        <path fill="#fff" d="M12 4.75c1.76 0 3.35.6 4.6 1.79l3.44-3.44C17.94 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.3 6.64l4.01 3.05C6.25 6.86 8.89 4.75 12 4.75z"/>
                    </svg>
                    Login dengan Google
                </a>
            </div>

            <p class="text-center text-xs text-[#767c80] mt-8">&copy; {{ date('Y') }} 523 Studio</p>
        </div>
    </div>

</body>
</html>
