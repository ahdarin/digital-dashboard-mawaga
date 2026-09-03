# KPI System - Attribution Rules

> **Koreksi lanjutan 2026-09-02**: audit ulang menemukan atribusi versi sebelumnya masih memakai `content_item_assignments.created_at <= period_end` TANPA batas bawah (assignment lama ikut terhitung ulang setiap bulan), memaksa satu role "utama" per user, dan menyimpan baris operasional selalu `client_id=NULL` (filter klien tidak pernah menampilkan staf produksi). Dokumen ini menjelaskan atribusi HASIL KOREKSI LANJUTAN - berbasis AKTIVITAS AKTOR yang benar-benar terbukti di periode yang dihitung.

Diimplementasikan di `App\Kpi\Services\KpiRoleContextResolver` (SIAPA dapat kredit apa, per role, berbasis aktivitas) + `App\Kpi\Services\KpiAttributionService` (content item mana yang outcome-nya relevan untuk periode ini). Service scoring lain (process, outcome, portfolio) TIDAK PERNAH query tabel atribusi langsung - semua lewat dua service ini.

## Source of Truth per Role

| Role | Source of truth | Aktivitas yang menentukan "eligible periode ini" | Butuh jadi PIC? |
|---|---|---|---|
| Copywriter | `content_brief_drafts.created_by` | `created_at` brief di dalam periode | **TIDAK** |
| Content Creator | `content_item_assignments` (PIC, tipe konten Video) | Content item itu punya `content_status_logs` (exclude koreksi) di dalam periode | Ya (PIC-nya) |
| Graphic Designer | `content_item_assignments` (PIC, tipe konten Desain) | Sama seperti Content Creator | Ya (PIC-nya) |
| SMO | `content_publications.published_by` (HANYA `recorded_via='manual'`) | `published_at` publication di dalam periode | **TIDAK** |
| Manager/CEO (operasional, sbg PIC) | `content_item_assignments` (fallback role kalau content type tidak match Content Creator/Graphic Designer) | Sama seperti Content Creator | Ya (PIC-nya) |
| Manager/CEO (leadership) | `content_status_logs.changed_by_user_id`, `to_status IN (approved,revision)`, `approval_type IS NULL` | `changed_at` keputusan di dalam periode | Tidak relevan (bukan PIC, tapi decision-maker) |

## Kenapa `assignment_role` Tidak Dipakai sebagai Sinyal

Audit `git grep` menemukan **setiap** baris `content_item_assignments` di seluruh codebase (Content Plan, quick-create urgent, import legacy, reassign PIC) selalu ditulis `assignment_role = 'primary'` - tidak ada satu pun jalur yang pernah menulis nilai lain. Kolom ini TIDAK membawa informasi diferensial apa pun hari ini, jadi role Content Creator/Graphic Designer tetap ditentukan dari **tipe konten** content item (Video/Desain) yang match dengan role EXISTING yang dimiliki user - ini bukan "fallback langka", itu satu-satunya mekanisme yang benar-benar berjalan.

## Aturan Inti

1. **Company/client aggregate menghitung 1 content item 1 kali** untuk direct outcome - `KpiAttributionService::contentItemIdsPublishedInPeriod()`, `distinct content_item_id` dari `content_publications` yang `published_at`-nya di periode ini.
2. **Setiap PIC yang berkontribusi mendapat content outcome PENUH yang sama** - TIDAK dibagi rata dengan jumlah PIC. Satu `ContentOutcomeResult` per content item per window; setiap user yang content item-nya masuk grup atribusinya membaca skor yang SAMA itu.
3. **Copywriter TIDAK PERLU jadi PIC content_item_assignments sama sekali.** Brief yang dia tulis di periode ini sudah cukup - direct outcome-nya dihitung dari content item yang briefnya dia tulis (kalau item itu SUDAH dipublikasikan & dapat outcome score), bukan dari status PIC.
4. **SMO mendapat kredit HANYA dari publication yang benar-benar dia publikasikan sendiri** (`published_by`), BUKAN dari status PIC pada content item. Sebaliknya, PIC yang BUKAN publisher asli TIDAK mendapat baris KPI SMO untuk content itu, walau dia terdaftar sebagai PIC-nya.
5. **`published_by` HANYA dipercaya kalau `recorded_via='manual'`.** Publication yang dibuat OTOMATIS saat analytics sync (`InstagramAnalyticsSyncService`/`TikTokAnalyticsSyncService::getOrCreatePublication()`, dipanggil saat matching post historis) mengisi `published_by` dengan user yang KEBETULAN memicu sync itu - BUKAN publisher asli (aksi publish-nya terjadi di platform eksternal, di luar sistem ini). Memakai nilai itu sebagai atribusi SMO akan "menebak" - dilarang eksplisit oleh keputusan produk. `recorded_via` (migration `add_recorded_via_to_content_publications_table`) membedakan dua kasus ini secara struktural.
6. **Content Creator/Graphic Designer/Manager-CEO-sbg-PIC HANYA eligible kalau content item-nya MEMANG punya aktivitas produksi tercatat di periode ini** (`content_status_logs`, exclude `approval_type='correction'`). Assignment yang dibuat kapan pun tapi kontennya sudah tidak aktif (tidak ada transisi status apa pun) bulan ini TIDAK ikut terhitung - mencegah "assignment lama bocor ke bulan-bulan berikutnya" selama baris assignment-nya masih ada.
7. **Satu user dengan beberapa AKTIVITAS berbeda yang bisa dibuktikan pada content yang SAMA menghasilkan beberapa baris atribusi terpisah** - mis. user yang menulis brief (Copywriter) SEKALIGUS jadi PIC produksi (Content Creator) untuk content item yang sama, mendapat DUA baris `user_kpi_results` (role_id beda), bukan dipaksa satu role "utama" dengan priority fallback (desain lama).
8. **Setiap baris hasil operasional (Copywriter/Content Creator/Graphic Designer/SMO/Manager-CEO-sbg-PIC) SEKARANG per (user, role, client)** - `client_id` diisi dari klien content item yang jadi bukti aktivitasnya, TIDAK PERNAH `NULL` lagi (koreksi lanjutan #4: sebelumnya operasional selalu `client_id=NULL`, membuat filter klien di UI tidak pernah menampilkan staf produksi sama sekali). Satu user dengan aktivitas pada beberapa klien di periode yang sama mendapat baris breakdown per-klien.
9. **Eligibility leadership KPI** - HARUS ada keputusan/approval NYATA yang tercatat: `content_status_logs.changed_by_user_id` dengan `to_status IN ('approved', 'revision')` DAN `approval_type IS NULL` di periode KPI. Klien diturunkan dari `content_item.client_id` milik content yang keputusannya tercatat - BUKAN dari akses RBAC global.
10. **Manager/CEO yang PADA KLIEN YANG SAMA punya AKTIVITAS PRODUKSI (PIC) DAN LEADERSHIP (decision) di periode yang sama** - kedua sinyal itu di-**MERGE** jadi SATU baris (bukan salah satu diam-diam menimpa yang lain), karena kunci `(run, user, role, client)` cuma bisa menampung satu baris per kombinasi itu. Process score digabung dengan **weighted average berdasarkan sample size masing-masing** (angka nyata yang sudah dihitung, bukan tebakan); `direct_outcome_score` dari sisi produksi (leadership tidak punya komponen ini); `portfolio_outcome_score` identik dari kedua sisi (dihitung sekali per client). Kalau Manager/CEO punya aktivitas produksi di klien A dan leadership di klien B (BEDA klien), keduanya tetap jadi DUA baris terpisah seperti biasa - merge hanya terjadi saat (role, client) benar-benar sama persis.
11. **User join di tengah periode** - koreksi lanjutan menghapus ketergantungan pada `content_item_assignments.created_at` sebagai penentu eligibility; yang menentukan sekarang adalah AKTIVITAS-nya sendiri (brief dibuat/status log berubah/publication dibuat) di dalam periode. User yang baru bergabung di tengah bulan otomatis hanya eligible sejak aktivitas pertamanya benar-benar tercatat - tidak perlu logic khusus tambahan.
12. **Access role tanpa aktivitas apa pun (brief/produksi/publish/decision) di periode ini = TIDAK ADA baris `user_kpi_results`.** User yang cuma punya `roles` (RBAC) tanpa satu pun aktivitas nyata TIDAK PERNAH diproses.

## Contoh Konkret

**Skenario 1 - Multi-PIC**: Content "Video A" dikerjakan 2 orang - Budi dan Sari, keduanya role Content Creator (dua baris `content_item_assignments` untuk `content_item_id` yang sama), content item itu punya status log aktivitas bulan ini.

- `content_outcome_results`: **1 baris** (per measurement window) untuk Video A.
- `user_kpi_results` Budi (Content Creator): `direct_outcome_score` = skor Video A.
- `user_kpi_results` Sari (Content Creator): `direct_outcome_score` = skor Video A **juga** (bukan dibagi 2).

**Skenario 2 - Copywriter tanpa PIC**: Rani menulis brief content "Video B" bulan ini, tapi TIDAK PERNAH jadi PIC content_item_assignments untuk item itu (PIC-nya orang lain).

- `user_kpi_results` Rani: `role_id`=Copywriter, `client_id`=klien Video B, `direct_outcome_score` dari outcome Video B (kalau sudah dipublikasikan) - didapat MURNI dari authorship brief, tanpa perlu status PIC apa pun.

**Skenario 3 - SMO berdasarkan publish, bukan PIC**: Dewi (role SMO) mempublikasikan (Record Publication manual) content "Video C" bulan ini. Eko (Content Creator) adalah PIC Video C tapi TIDAK mempublikasikannya.

- `user_kpi_results` Dewi: `role_id`=SMO, metrik `publication_schedule_adherence_rate`/`publication_analytics_match_rate` dihitung dari publication yang BENAR-BENAR dia buat.
- Eko TIDAK mendapat baris KPI SMO untuk Video C sama sekali (dia bukan publisher) - tapi tetap dapat baris Content Creator seperti biasa dari status PIC-nya.

**Skenario 4 - Assignment lama tidak bocor**: Manager menugaskan Fajar sebagai PIC content "Video D" bulan Januari. Video D sudah `uploaded` sejak Januari - TIDAK ADA transisi status apa pun pada Video D di bulan September.

- Menghitung KPI September: Video D TIDAK ikut terhitung untuk Fajar sama sekali (tidak ada aktivitas produksi September), walau baris `content_item_assignments`-nya masih ada dari Januari.

**Skenario 5 - Manager dengan produksi + leadership di klien yang sama (merge)**: Manager Rani jadi PIC produksi 1 content DAN menyetujui (approve) 2 content lain, KEDUANYA untuk klien "Toko X" di bulan yang sama.

- `user_kpi_results`: **SATU baris** `(role_id=Manager, client_id=Toko X)` - process score gabungan (weighted average produksi+leadership), `direct_outcome_score` dari sisi produksi, `portfolio_outcome_score` Toko X (sama untuk kedua sisi), `component_breakdown.merged_production_and_leadership = true` untuk audit.

**Skenario 6 - Manager dengan produksi + leadership di klien BERBEDA (tidak merge)**: Sama seperti skenario 5, tapi produksi untuk klien A dan approval untuk klien B.

- `user_kpi_results`: **DUA baris terpisah** - `(Manager, client_id=A)` dan `(Manager, client_id=B)`.
