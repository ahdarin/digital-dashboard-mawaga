<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mawaga Intel | Sign In</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-white antialiased">

    <a href="/" class="fixed top-8 left-8 z-50 flex items-center justify-center w-12 h-12 text-[#0d8276] md:text-white transition-all group">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 group-hover:-translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
    </a>

    <div class="min-h-screen flex flex-col md:flex-row">

        <div class="hidden md:flex md:w-1/2 relative overflow-hidden items-center px-12 lg:px-24">
            <div id="silk-container" class="absolute inset-0 z-0"></div>

            <div class="relative z-10">
                <div class="flex items-center gap-3 mb-8">
                    <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center">
                        <span class="text-[#0d8276] font-bold text-sm">M</span>
                    </div>
                    <span class="text-white text-xl font-bold">Mawaga Intel</span>
                </div>
                <h1 class="text-white text-5xl lg:text-7xl font-bold mb-6 leading-tight">Welcome <br> <span class="text-[#7DF5F4]">Back</span></h1>
                <p class="text-gray-400 text-lg max-w-md leading-relaxed">
                    Masuk untuk mengelola operasional kreatif 523 Studio — client, konten, dan alur kerja produksi dalam satu dasbor.
                </p>
            </div>
        </div>

        <div class="flex-1 flex items-center justify-center p-6 bg-gray-200 md:bg-gray-50">
            <div class="w-full max-w-md bg-white rounded-[2rem] p-8 md:p-10 shadow-2xl md:shadow-none border border-gray-100">

                <div class="mb-8">
                    <h2 class="text-3xl font-bold text-gray-900 mb-2">Sign In</h2>
                    <p class="text-gray-500">Khusus tim internal 523 Studio</p>
                </div>

                @if ($errors->any())
                    <div class="bg-red-50 text-red-600 p-4 rounded-xl mb-6 text-sm font-medium border border-red-100">
                        {{ $errors->first() }}
                    </div>
                @endif

                <a href="{{ route('auth.google') }}"
                   class="w-full bg-[#0d8276] hover:bg-[#0a6b61] text-white font-bold py-4 rounded-2xl flex items-center justify-center gap-3 transition-all shadow-lg shadow-teal-900/20">
                    <svg class="w-5 h-5" viewBox="0 0 24 24">
                        <path fill="#fff" d="M23.5 12.27c0-.85-.07-1.67-.2-2.45H12v4.64h6.47c-.28 1.5-1.13 2.77-2.4 3.62v3h3.88c2.27-2.09 3.55-5.17 3.55-8.81z"/>
                        <path fill="#fff" d="M12 24c3.24 0 5.95-1.08 7.93-2.92l-3.88-3c-1.08.72-2.45 1.15-4.05 1.15-3.11 0-5.75-2.1-6.69-4.92H1.3v3.09C3.26 21.3 7.31 24 12 24z"/>
                        <path fill="#fff" d="M5.31 14.31c-.24-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.64H1.3C.47 8.24 0 10.06 0 12s.47 3.76 1.3 5.36l4.01-3.05z"/>
                        <path fill="#fff" d="M12 4.75c1.76 0 3.35.6 4.6 1.79l3.44-3.44C17.94 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.3 6.64l4.01 3.05C6.25 6.86 8.89 4.75 12 4.75z"/>
                    </svg>
                    Login dengan Google
                </a>

                <p class="text-xs text-gray-400 mt-6 text-center">
                    {{-- Klien 523 Studio? <a href="{{ route('client.login') ?? '#' }}" class="text-[#0d8276] underline font-semibold">Login di sini</a> --}}
                    Klien 523 Studio? <a href="#" class="text-[#0d8276] underline font-semibold">Login di sini</a>
                </p>
            </div>
        </div>
    </div>

    <script src="{{ asset('js/silk.js') }}"></script>
</body>
</html>