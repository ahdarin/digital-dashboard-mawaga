<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CEO Email
    |--------------------------------------------------------------------------
    |
    | Satu-satunya sumber kebenaran untuk "siapa CEO" - dulu di-hardcode
    | sebagai email literal terpisah di RoleSeeder.php dan DemoSeeder.php,
    | dan sempat drift (dua file beda email, satu file lagi comment yang
    | tidak lagi cocok dengan kode - audit Phase 4.2/4.3). Personal email
    | TIDAK BOLEH lagi jadi architectural constant tersebar di source code.
    |
    | Opsional. Kalau kosong, seeder yang butuh CEO context (RoleSeeder)
    | skip langkah bootstrap CEO dengan pesan console yang jelas - TIDAK
    | PERNAH diam-diam memilih User::first() sebagai gantinya.
    |
    | Tidak mengubah perilaku Google OAuth login sama sekali - login tetap
    | murni lookup User.email yang sudah ada (GoogleAuthController), tidak
    | pernah auto-create user atau auto-assign role CEO berdasarkan value
    | ini.
    |
    */

    'ceo_email' => env('CEO_EMAIL'),

];
