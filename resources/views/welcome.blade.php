<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>523 Studio</title>

    <link rel="icon" href="{{ asset('images/logo.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..500,0..1&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .font-display { font-family: 'Fraunces', serif; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined'; font-weight: normal; font-style: normal; font-size: 24px; line-height: 1; }
    </style>
</head>
<body class="bg-[#f7f8fc] text-[#14181a] antialiased">

    <div class="min-h-screen flex flex-col">

        {{-- Header --}}
        <header class="px-6 md:px-10 py-6 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <img src="{{ asset('images/logo.png') }}" alt="523 Studio" class="h-8 w-auto">
            </div>
            <a href="{{ route('login') }}"
               class="text-sm font-medium text-[#5c6266] hover:text-[#14181a] px-4 py-2 rounded-lg hover:bg-white transition-colors">
                Login Tim Internal
            </a>
        </header>

        {{-- Hero --}}
        <main class="flex-1 flex items-center px-6 md:px-10">
            <div class="max-w-6xl mx-auto w-full grid md:grid-cols-2 gap-14 items-center py-12">

                <div>
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-[#044b46] bg-[#f0f5f4] px-3 py-1.5 rounded-full mb-6">
                        <span class="material-symbols-outlined text-[15px]">auto_awesome</span>
                        Dasbor Operasional Kreatif
                    </span>

                    <h1 class="font-display text-[40px] md:text-[52px] font-semibold leading-[1.1] tracking-tight mb-5">
                        Satu dasbor buat<br>seluruh alur kerja<br><span class="text-[#044b46]">523 Studio</span>
                    </h1>

                    <p class="text-[#5c6266] text-base md:text-lg leading-relaxed mb-9 max-w-md">
                        Content plan, production workflow, performa konten, sampai analisis strategi berbasis AI —
                        tim internal dan client mengelola semuanya di satu tempat.
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <a href="{{ route('login') }}"
                           class="inline-flex items-center gap-2 bg-[#044b46] hover:bg-[#033b37] text-white text-sm font-medium px-6 py-3.5 rounded-xl transition-all active:scale-[0.98] shadow-sm">
                            Login Tim Internal
                            <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
                        </a>
                        <a href="{{ route('client.login') }}"
                           class="inline-flex items-center gap-2 bg-white hover:bg-[#f0f5f4] text-[#14181a] text-sm font-medium px-6 py-3.5 rounded-xl border border-[#eef0f4] transition-all active:scale-[0.98]">
                            Login Client
                        </a>
                    </div>
                </div>

                <div class="hidden md:flex items-center justify-center">
                    <div class="relative w-full max-w-md aspect-square rounded-[2rem] bg-white border border-[#eef0f4] shadow-[0_20px_60px_-15px_rgba(4,75,70,0.15)] flex items-center justify-center overflow-hidden">
                        <div class="absolute -top-16 -right-16 w-56 h-56 rounded-full bg-[#f0f5f4]"></div>
                        <div class="absolute -bottom-20 -left-10 w-64 h-64 rounded-full bg-[#f7f8fc]"></div>
                        <img src="{{ asset('images/logo.png') }}" alt="523 Studio" class="relative w-2/3 h-auto">
                    </div>
                </div>

            </div>
        </main>

        {{-- Footer --}}
        <footer class="px-6 md:px-10 py-6 text-center md:text-left">
            <p class="text-xs text-[#9aa0a4]">&copy; {{ date('Y') }} 523 Studio. Internal use only.</p>
        </footer>
    </div>

</body>
</html>
