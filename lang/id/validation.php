<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pesan Validasi Bahasa Indonesia
    |--------------------------------------------------------------------------
    |
    | Dipakai supaya pesan error form (required, email, max, dst) tampil
    | dalam Bahasa Indonesia buat pengguna, bukan bahasa Inggris bawaan
    | Laravel. Daftar aturan mengikuti struktur default Laravel.
    |
    */

    'accepted' => ':attribute harus disetujui.',
    'accepted_if' => ':attribute harus disetujui ketika :other bernilai :value.',
    'active_url' => ':attribute bukan URL yang valid.',
    'after' => ':attribute harus tanggal setelah :date.',
    'after_or_equal' => ':attribute harus tanggal setelah atau sama dengan :date.',
    'alpha' => ':attribute hanya boleh berisi huruf.',
    'alpha_dash' => ':attribute hanya boleh berisi huruf, angka, strip, dan garis bawah.',
    'alpha_num' => ':attribute hanya boleh berisi huruf dan angka.',
    'array' => ':attribute harus berupa array.',
    'ascii' => ':attribute hanya boleh berisi karakter alfanumerik dan simbol satu byte.',
    'before' => ':attribute harus tanggal sebelum :date.',
    'before_or_equal' => ':attribute harus tanggal sebelum atau sama dengan :date.',
    'between' => [
        'array' => ':attribute harus memiliki antara :min - :max item.',
        'file' => ':attribute harus antara :min - :max kilobita.',
        'numeric' => ':attribute harus antara :min - :max.',
        'string' => ':attribute harus antara :min - :max karakter.',
    ],
    'boolean' => ':attribute harus bernilai benar atau salah.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Kata sandi salah.',
    'date' => ':attribute bukan tanggal yang valid.',
    'date_equals' => ':attribute harus tanggal yang sama dengan :date.',
    'date_format' => ':attribute tidak cocok dengan format :format.',
    'decimal' => ':attribute harus memiliki :decimal desimal.',
    'declined' => ':attribute harus ditolak.',
    'declined_if' => ':attribute harus ditolak ketika :other bernilai :value.',
    'different' => ':attribute dan :other harus berbeda.',
    'digits' => ':attribute harus :digits digit.',
    'digits_between' => ':attribute harus antara :min - :max digit.',
    'dimensions' => ':attribute memiliki dimensi gambar yang tidak valid.',
    'distinct' => ':attribute memiliki nilai yang duplikat.',
    'doesnt_end_with' => ':attribute tidak boleh diakhiri dengan salah satu dari berikut: :values.',
    'doesnt_start_with' => ':attribute tidak boleh diawali dengan salah satu dari berikut: :values.',
    'email' => ':attribute harus berupa alamat email yang valid.',
    'ends_with' => ':attribute harus diakhiri dengan salah satu dari berikut: :values.',
    'enum' => ':attribute yang dipilih tidak valid.',
    'exists' => ':attribute yang dipilih tidak valid.',
    'extensions' => ':attribute harus memiliki salah satu ekstensi berikut: :values.',
    'file' => ':attribute harus berupa file.',
    'filled' => ':attribute wajib diisi.',
    'gt' => [
        'array' => ':attribute harus memiliki lebih dari :value item.',
        'file' => ':attribute harus lebih besar dari :value kilobita.',
        'numeric' => ':attribute harus lebih besar dari :value.',
        'string' => ':attribute harus lebih besar dari :value karakter.',
    ],
    'gte' => [
        'array' => ':attribute harus memiliki :value item atau lebih.',
        'file' => ':attribute harus lebih besar atau sama dengan :value kilobita.',
        'numeric' => ':attribute harus lebih besar atau sama dengan :value.',
        'string' => ':attribute harus lebih besar atau sama dengan :value karakter.',
    ],
    'image' => ':attribute harus berupa gambar.',
    'in' => ':attribute yang dipilih tidak valid.',
    'in_array' => ':attribute tidak ada di dalam :other.',
    'integer' => ':attribute harus berupa angka bulat.',
    'ip' => ':attribute harus berupa alamat IP yang valid.',
    'ipv4' => ':attribute harus berupa alamat IPv4 yang valid.',
    'ipv6' => ':attribute harus berupa alamat IPv6 yang valid.',
    'json' => ':attribute harus berupa JSON yang valid.',
    'list' => ':attribute harus berupa daftar (list).',
    'lowercase' => ':attribute harus berupa huruf kecil.',
    'lt' => [
        'array' => ':attribute harus memiliki kurang dari :value item.',
        'file' => ':attribute harus lebih kecil dari :value kilobita.',
        'numeric' => ':attribute harus lebih kecil dari :value.',
        'string' => ':attribute harus lebih kecil dari :value karakter.',
    ],
    'lte' => [
        'array' => ':attribute tidak boleh memiliki lebih dari :value item.',
        'file' => ':attribute harus lebih kecil atau sama dengan :value kilobita.',
        'numeric' => ':attribute harus lebih kecil atau sama dengan :value.',
        'string' => ':attribute harus lebih kecil atau sama dengan :value karakter.',
    ],
    'mac_address' => ':attribute harus berupa alamat MAC yang valid.',
    'max' => [
        'array' => ':attribute tidak boleh memiliki lebih dari :max item.',
        'file' => ':attribute tidak boleh lebih besar dari :max kilobita.',
        'numeric' => ':attribute tidak boleh lebih besar dari :max.',
        'string' => ':attribute tidak boleh lebih besar dari :max karakter.',
    ],
    'max_digits' => ':attribute tidak boleh memiliki lebih dari :max digit.',
    'mimes' => ':attribute harus berupa file berjenis: :values.',
    'mimetypes' => ':attribute harus berupa file berjenis: :values.',
    'min' => [
        'array' => ':attribute harus memiliki minimal :min item.',
        'file' => ':attribute harus minimal :min kilobita.',
        'numeric' => ':attribute harus minimal :min.',
        'string' => ':attribute harus minimal :min karakter.',
    ],
    'min_digits' => ':attribute harus memiliki minimal :min digit.',
    'missing' => ':attribute harus tidak ada.',
    'missing_if' => ':attribute harus tidak ada ketika :other bernilai :value.',
    'missing_unless' => ':attribute harus tidak ada kecuali :other bernilai :value.',
    'missing_with' => ':attribute harus tidak ada ketika :values ada.',
    'missing_with_all' => ':attribute harus tidak ada ketika :values ada.',
    'multiple_of' => ':attribute harus kelipatan dari :value.',
    'not_in' => ':attribute yang dipilih tidak valid.',
    'not_regex' => 'Format :attribute tidak valid.',
    'numeric' => ':attribute harus berupa angka.',
    'present' => ':attribute wajib ada.',
    'present_if' => ':attribute wajib ada ketika :other bernilai :value.',
    'present_unless' => ':attribute wajib ada kecuali :other bernilai :value.',
    'present_with' => ':attribute wajib ada ketika :values ada.',
    'present_with_all' => ':attribute wajib ada ketika :values ada.',
    'prohibited' => ':attribute dilarang.',
    'prohibited_if' => ':attribute dilarang ketika :other bernilai :value.',
    'prohibited_unless' => ':attribute dilarang kecuali :other bernilai :values.',
    'prohibits' => ':attribute melarang :other untuk diisi.',
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':attribute wajib diisi.',
    'required_array_keys' => ':attribute wajib memiliki entri untuk: :values.',
    'required_if' => ':attribute wajib diisi ketika :other bernilai :value.',
    'required_if_accepted' => ':attribute wajib diisi ketika :other disetujui.',
    'required_if_declined' => ':attribute wajib diisi ketika :other ditolak.',
    'required_unless' => ':attribute wajib diisi kecuali :other bernilai :values.',
    'required_with' => ':attribute wajib diisi ketika :values ada.',
    'required_with_all' => ':attribute wajib diisi ketika :values ada.',
    'required_without' => ':attribute wajib diisi ketika :values tidak ada.',
    'required_without_all' => ':attribute wajib diisi ketika tidak ada satupun dari :values yang ada.',
    'same' => ':attribute dan :other harus sama.',
    'size' => [
        'array' => ':attribute harus memiliki :size item.',
        'file' => ':attribute harus berukuran :size kilobita.',
        'numeric' => ':attribute harus berukuran :size.',
        'string' => ':attribute harus berukuran :size karakter.',
    ],
    'starts_with' => ':attribute harus diawali dengan salah satu dari berikut: :values.',
    'string' => ':attribute harus berupa teks.',
    'timezone' => ':attribute harus berupa zona waktu yang valid.',
    'unique' => ':attribute sudah pernah dipakai.',
    'uploaded' => ':attribute gagal diunggah.',
    'uppercase' => ':attribute harus berupa huruf besar.',
    'url' => 'Format :attribute tidak valid.',
    'ulid' => ':attribute harus berupa ULID yang valid.',
    'uuid' => ':attribute harus berupa UUID yang valid.',

    /*
    |--------------------------------------------------------------------------
    | Baris Bahasa Kustom
    |--------------------------------------------------------------------------
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Nama Atribut Kustom
    |--------------------------------------------------------------------------
    |
    | Supaya pesan error menyebut nama field yang dikenali pengguna
    | ("Judul wajib diisi") bukan nama kolom database mentah
    | ("title wajib diisi").
    |
    */

    'attributes' => [
        'title' => 'judul',
        'name' => 'nama',
        'brand_name' => 'nama brand',
        'brief' => 'brief',
        'email' => 'email',
        'phone_number' => 'nomor WhatsApp',
        'owner_name' => 'nama owner',
        'owner_email' => 'email owner',
        'owner_phone' => 'nomor WhatsApp owner',
        'client_id' => 'client',
        'client_ids' => 'client',
        'client_category_id' => 'kategori client',
        'content_type_id' => 'jenis konten',
        'content_pillar_id' => 'content pillar',
        'platform_id' => 'platform',
        'platform' => 'platform',
        'pic_id' => 'penanggung jawab',
        'pic_user_id' => 'penanggung jawab',
        'user_id' => 'pengguna',
        'rejection_note' => 'alasan penolakan',
        'role_ids' => 'role',
        'deadline_at' => 'deadline',
        'scheduled_upload_at' => 'tanggal & jam upload',
        'published_at' => 'tanggal publish',
        'post_url' => 'link post',
        'content_file_link' => 'link konten',
        'asset_link' => 'link aset',
        'caption_final' => 'caption',
        'revision_note' => 'catatan revisi',
        'notes' => 'catatan',
        'to_status' => 'status tujuan',
        'status' => 'status',
        'logo' => 'logo',
        'color' => 'warna penanda',
        'month' => 'bulan',
        'year' => 'tahun',
        'period_start' => 'awal periode',
        'period_end' => 'akhir periode',
        'format' => 'format',
        'talent' => 'talent',
        'properti' => 'properti',
        'hook_title' => 'hook / judul brief',
        'estimated_duration_seconds' => 'estimasi durasi',
        'estimated_slide_count' => 'estimasi jumlah slide',
    ],

];
