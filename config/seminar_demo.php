<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email Login Demo Seminar
    |--------------------------------------------------------------------------
    |
    | Alamat Gmail ASLI yang dipakai untuk login live (Google OAuth) saat
    | demonstrasi seminar kerja praktik - di-assign ke akun CEO fiktif oleh
    | SeminarDemoSeeder (database/seeders/SeminarDemoSeeder.php). WAJIB diisi
    | sebelum menjalankan seeder tersebut - guard-nya menolak jalan kalau
    | kosong. Login sistem murni Google OAuth lookup-by-email
    | (App\Http\Controllers\Auth\GoogleAuthController) dan TIDAK PERNAH
    | auto-provision user baru, jadi alamat @example.test yang dipakai
    | user fiktif lain di seeder ini TIDAK BISA dipakai login sungguhan.
    |
    | Sengaja TIDAK di-hardcode di seeder/kode manapun (privasi) - isi lewat
    | .env:
    |   SEMINAR_DEMO_LOGIN_EMAIL=alamat.anda@gmail.com
    |
    */

    'login_email' => env('SEMINAR_DEMO_LOGIN_EMAIL'),

];
