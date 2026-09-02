<?php

// Fixture statis untuk TeamClientSeeder - disalin dari state database dev
// (hasil real `content-planner:import-team` dari sheet GUIDE Content Planner
// lama + 14 Client yang sudah dibuat manual lewat UI), sudah dibersihkan
// dari akun testing/duplikat:
// - 'ahdarindang@gmail.com' (akun kedua Ahda, status 'invited', tidak pernah
//   dipakai) TIDAK diikutkan.
// - 'ghazifadhlullah31@gmail.com' (dipakai CEO role cuma untuk kemudahan
//   testing lokal, bukan roster resmi) TIDAK diikutkan.
// - Akun CEO resmi '523 Studio' (hello523studio@gmail.com) TIDAK diikutkan
//   di sini - itu tanggung jawab RoleSeeder (bootstrap wajib, bukan bagian
//   data historis).
//
// Client & Role direferensikan lewat NATURAL KEY (nama), bukan id - resolve
// ke id dilakukan oleh TeamClientSeeder saat run, supaya tidak bergantung
// pada urutan/nilai id yang beda antar environment.
return [
    'clients' => [
        ['name' => 'Yasmin International Boarding School', 'category' => 'Institusi'],
        ['name' => 'PT Guna Griya Abadi', 'category' => 'Korporat'],
        ['name' => 'LuxSuits', 'category' => 'UMKM'],
        ['name' => 'Top Scorer Arena', 'category' => 'UMKM'],
        ['name' => 'FTI UNAND', 'category' => 'Institusi'],
        ['name' => 'Indonesian Software', 'category' => 'UMKM'],
        ['name' => 'Oleo', 'category' => 'UMKM'],
        ['name' => 'Darwin', 'category' => 'UMKM'],
        ['name' => 'Uthie Cake', 'category' => 'UMKM'],
        ['name' => 'Sato', 'category' => 'UMKM'],
        ['name' => 'Tatitatu', 'category' => 'UMKM'],
        ['name' => 'Alfa Sport', 'category' => 'UMKM'],
        ['name' => 'Odamilk', 'category' => 'UMKM'],
        ['name' => 'Labertha', 'category' => 'UMKM'],
    ],

    'users' => [
        [
            'name' => 'Ahda',
            'email' => 'ahdaalamin2506@gmail.com',
            'role' => 'Admin',
            'status' => 'active',
            'login_enabled' => true,
            'source' => null,
            'clients' => [],
        ],
        [
            'name' => 'Surdik',
            'email' => 'surdik2811@gmail.com',
            'role' => 'CEO',
            'status' => 'active',
            'login_enabled' => true,
            'source' => 'content_planner_guide',
            'clients' => [
                'Yasmin International Boarding School', 'PT Guna Griya Abadi',
                'LuxSuits', 'Top Scorer Arena', 'FTI UNAND', 'Darwin',
            ],
        ],
        // Sisanya: staf produksi dari roster GUIDE lama - belum punya akses
        // login (login_enabled=false) & belum diberi role sistem, tapi tetap
        // person real yang tercatat pernah menangani client-client berikut
        // (bukan dummy - lihat catatan Section 29 di ImportContentPlannerTeam).
        [
            'name' => 'HAGI', 'email' => 'hagisiraj123@gmail.com',
            'role' => null, 'status' => 'active', 'login_enabled' => false,
            'source' => 'content_planner_guide', 'clients' => ['Odamilk'],
        ],
        [
            'name' => 'LOVI', 'email' => 'loviralovi@gmail.com',
            'role' => null, 'status' => 'active', 'login_enabled' => false,
            'source' => 'content_planner_guide', 'clients' => ['Oleo'],
        ],
        [
            'name' => 'RESTY', 'email' => 'restyanisa@gmail.com',
            'role' => null, 'status' => 'active', 'login_enabled' => false,
            'source' => 'content_planner_guide', 'clients' => ['Darwin', 'Sato', 'Odamilk'],
        ],
        [
            'name' => 'UUN', 'email' => 'qalamullah135@gmail.com',
            'role' => null, 'status' => 'active', 'login_enabled' => false,
            'source' => 'content_planner_guide',
            'clients' => ['LuxSuits', 'Top Scorer Arena', 'FTI UNAND', 'Sato', 'Alfa Sport'],
        ],
        [
            'name' => 'THIAH', 'email' => 'thiahamatullah26@gmail.com',
            'role' => null, 'status' => 'active', 'login_enabled' => false,
            'source' => 'content_planner_guide',
            'clients' => ['Yasmin International Boarding School', 'Oleo', 'Uthie Cake', 'Sato', 'Labertha'],
        ],
        [
            'name' => 'JOE', 'email' => 'joeyanakhalisha@gmail.com',
            'role' => null, 'status' => 'active', 'login_enabled' => false,
            'source' => 'content_planner_guide', 'clients' => ['PT Guna Griya Abadi', 'Tatitatu'],
        ],
        [
            'name' => 'RAHMAT', 'email' => 'rahmat21121@gmail.com',
            'role' => null, 'status' => 'active', 'login_enabled' => false,
            'source' => 'content_planner_guide', 'clients' => ['LuxSuits', 'Oleo', 'Uthie Cake'],
        ],
        [
            'name' => 'AFIFAH', 'email' => 'afifahsyahidahh@gmail.com',
            'role' => null, 'status' => 'active', 'login_enabled' => false,
            'source' => 'content_planner_guide',
            'clients' => ['Yasmin International Boarding School', 'Alfa Sport'],
        ],
        [
            'name' => 'ACA', 'email' => 'faniaokta031005@gmail.com',
            'role' => null, 'status' => 'active', 'login_enabled' => false,
            'source' => 'content_planner_guide', 'clients' => ['FTI UNAND', 'Uthie Cake', 'Tatitatu'],
        ],
        [
            'name' => 'FIKA', 'email' => 'fatikadamayanti99@gmail.com',
            'role' => null, 'status' => 'active', 'login_enabled' => false,
            'source' => 'content_planner_guide',
            'clients' => ['Yasmin International Boarding School', 'Oleo', 'Sato', 'Odamilk'],
        ],
        [
            'name' => 'VIO', 'email' => 'violinmtf@gmail.com',
            'role' => null, 'status' => 'active', 'login_enabled' => false,
            'source' => 'content_planner_guide', 'clients' => ['Labertha'],
        ],
    ],
];
