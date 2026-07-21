<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>523 Studio | Client Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">

    <div class="w-full max-w-sm">

        <div class="text-center mb-8">
            <div class="w-14 h-14 bg-[#0d8276] rounded-2xl flex items-center justify-center mx-auto mb-4">
                <span class="text-white font-bold text-xl">5</span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900">523 Studio</h1>
            <p class="text-gray-500 text-sm mt-1">Portal Klien</p>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">

            @if (session('status'))
                <div class="bg-teal-50 text-[#0d8276] text-sm p-3 rounded-xl mb-4 text-center font-medium">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="bg-red-50 text-red-600 text-sm p-3 rounded-xl mb-4 text-center font-medium">
                    {{ $errors->first() }}
                </div>
            @endif

            <h2 class="text-lg font-bold text-gray-900 mb-1">Masuk ke Dashboard</h2>
            <p class="text-sm text-gray-500 mb-6">Kami akan kirim link login ke WhatsApp Anda.</p>

            <form action="{{ route('client.login.request') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Nomor WhatsApp</label>
                    <input type="tel" name="phone_number" required
                           placeholder="08xxxxxxxxxx"
                           value="{{ old('phone_number') }}"
                           class="w-full bg-gray-50 border border-gray-200 text-gray-900 rounded-xl py-3.5 px-4 focus:outline-none focus:ring-2 focus:ring-[#0d8276]/20 focus:border-[#0d8276] transition-all">
                </div>

                <button type="submit"
                        class="w-full bg-[#0d8276] hover:bg-[#0a6b61] text-white font-semibold py-3.5 rounded-xl flex items-center justify-center gap-2 transition-all">
                    Kirim Link Login
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                    </svg>
                </button>
            </form>
        </div>

        <p class="text-xs text-gray-400 mt-6 text-center">
            Tim internal 523 Studio? <a href="{{ route('login') }}" class="text-[#0d8276] underline font-semibold">Login di sini</a>
        </p>
    </div>

</body>
</html>