<x-mail::message>
# Halo, {{ $invitedUser->name }}

Kamu diundang bergabung ke **523 Studio** sebagai **{{ $invitedUser->role->name ?? '-' }}**.

Akunmu sudah dibuat dengan email ini ({{ $invitedUser->email }}). Cara masuk:

1. Buka halaman login 523 Studio.
2. Klik **"Login dengan Google"**.
3. Masuk pakai akun Google dengan email yang sama seperti undangan ini.

Akunmu otomatis aktif begitu berhasil login pertama kali - tidak perlu bikin password baru.

<x-mail::button :url="url('/login')">
Masuk ke 523 Studio
</x-mail::button>

Kalau kamu tidak merasa meminta undangan ini, abaikan saja email ini.

Terima kasih,<br>
{{ config('app.name') }}
</x-mail::message>
