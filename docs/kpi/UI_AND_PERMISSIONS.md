# KPI System - UI & Permissions

> **Koreksi produk 2026-09-02**: route assignment KPI khusus (`client-management.role-assignments.*`, `content-items.operational-assignments.*`) sudah **dihapus total** - tidak ada UI assignment/role/workflow-settings baru sama sekali untuk KPI. Filter "operational role" di tab Ringkasan/Anggota sekarang memakai `role_id` dari `roles` EXISTING.
>
> **Koreksi lanjutan 2026-09-02**: (1) istilah teknis di kartu KPI (Process/Direct Outcome/Portfolio/Composite/Coverage/Sample size) diganti istilah Indonesia yang umum dengan tooltip penjelasan - lihat "Istilah Kartu KPI" di bawah; (2) filter klien SEKARANG benar-benar menampilkan staf produksi (dulu `client_id` operasional selalu `NULL`, filter klien cuma pernah menampilkan leadership); (3) judul "Akurasi Prediksi AI Delay Risk" diganti "Ketepatan Prediksi Risiko Tinggi" (judul lama menyiratkan accuracy keseluruhan, padahal angka yang ditampilkan adalah precision khusus prediksi Risiko Tinggi); (4) referensi path dokumentasi developer (`docs/kpi/...`) dan istilah "Access role (RBAC)" dihapus dari halaman pengguna.

## Routes

| Route | Method | Middleware | Controller |
|---|---|---|---|
| `team-performance.index` | GET `/team-performance` | `permission:team_performance,view` | `TeamPerformanceController::index` (tab: ringkasan/anggota/kehadiran via query `?tab=`) |
| `team-performance.show` | GET `/team-performance/anggota/{user}` | `permission:team_performance,view` | `TeamPerformanceController::show` (detail per anggota per role) |

**Tidak ada route/permission baru dibuat sama sekali.** Kedua route di atas SUDAH ADA sebelum koreksi ini - tidak ada perubahan middleware/permission map (`PermissionSeeder` tidak disentuh). Sesuai keputusan produk: *"Perubahan yang terlihat pengguna hanya berada pada halaman Team Performance dan status refresh otomatisnya."*

## Scope Permission (Tidak Diubah)

- **CEO/Manager/Admin**: satu-satunya role dengan `team_performance,view` (map existing) - bisa buka `/team-performance` dan detail anggota siapa pun.
- **Role lain** (Copywriter, Content Creator, Graphic Designer, SMO): 403 - dibuktikan `TeamPerformanceControllerTest::test_content_creator_cannot_access_team_performance`.
- **Global role TIDAK dipakai sebagai atribusi operasional** - lihat `ATTRIBUTION_RULES.md`. Halaman ini menampilkan hasil KPI SIAPA PUN yang punya aktivitas nyata (brief/produksi/publish/decision), terlepas dari access role-nya.

## Istilah Kartu KPI (Bahasa Indonesia Umum, dengan Tooltip)

Koreksi lanjutan - label teknis diganti agar halaman tidak terasa seperti panel developer. Setiap kartu punya `title` attribute (tooltip hover browser standar) berisi penjelasan singkat:

| Istilah lama | Istilah baru | Tooltip |
|---|---|---|
| Process / Process Score | **Kualitas Proses** | Ketepatan dan kualitas alur kerja sesuai role. |
| Direct Outcome / Direct Content Outcome | **Hasil Konten** | Performa analytics konten yang ditangani. |
| Portfolio / Client Portfolio Outcome | **Performa Klien** | Perkembangan akun klien yang dibagikan ke seluruh PIC yang terlibat. |
| Composite | **Nilai KPI** | Skor gabungan akhir - TIDAK PERNAH ditampilkan sebagai angka kalau status Data Belum Cukup. |
| Coverage | **Kelengkapan Data** | Apakah jumlah dan kualitas data cukup untuk menyimpulkan KPI. |
| Sample size | **Jumlah Data** | Jumlah observasi (content item/publication/keputusan) yang mendasari angka ini. |

Kartu ringkasan tim JUGA diterjemahkan: "Publication Adherence" -> **Ketepatan Jadwal Publish**, "Workload Imbalance" -> **Ketimpangan Beban Kerja**, "Bottleneck" -> **Tahap Tersendat**, "Active Blocker" -> **Konten Terhambat**.

## Struktur Tab (`resources/views/team-performance/`)

Sesuai batasan eksplisit Fase 5: **tidak ada tab/kartu assignment, role, atau workflow-settings baru** - hanya tiga tab yang sudah ada, dengan konten yang diperkaya.

### 1. Ringkasan Tim (`partials/tab-ringkasan.blade.php`)
- Filter periode (bulan), klien, role (`role_id` dari `roles` EXISTING).
- Banner status otomatis:
  - **"sedang diperbarui otomatis di latar belakang"** kalau run sedang stale/di-trigger ulang.
  - **"menampilkan data periode X sementara pembaruan berjalan"** kalau memakai fallback snapshot dari periode lain.
  - **"Data KPI sedang disiapkan otomatis"** kalau belum pernah ada run sama sekali - TIDAK ADA instruksi command di layar mana pun.
- Kartu ringkasan: **Pemenuhan Kuota** (`ContentPlan`+`ClientPackage` quota EXISTING vs content yang sudah dilepas), **Handoff Tepat Waktu**, **Ketepatan Jadwal Publish**, **Ketimpangan Beban Kerja**, **Tahap Tersendat**, **Konten Terhambat**.
- Distribusi status (Sehat/Perlu Perhatian/Sementara/Data Belum Cukup) - **BUKAN leaderboard**, ini hitungan distribusi baris (user x role x client), tidak ada nama individu di kartu ini.
- **Ketepatan Prediksi Risiko Tinggi** (dulu "Akurasi Prediksi AI Delay Risk") - "Dari seluruh konten yang diprediksi berisiko tinggi, berapa persen yang benar-benar terlambat." - ditandai eksplisit "model health, bukan KPI karyawan", TIDAK disebut accuracy keseluruhan karena yang dihitung memang precision khusus kelas Risiko Tinggi.

### 2. Anggota (`partials/tab-anggota.blade.php`)
- Filter periode, klien, role, kelengkapan data.
- Satu kartu per **(user, role EXISTING, client)** - mendukung satu user beberapa role (dipisah per aktivitas, lihat `ATTRIBUTION_RULES.md`) & beberapa client (breakdown per-klien, koreksi lanjutan #4).
- **Filter klien SEKARANG benar-benar bekerja untuk staf produksi** - Copywriter/Content Creator/Graphic Designer/SMO yang punya aktivitas pada klien itu ikut tampil, bukan cuma leadership (dulu `client_id` operasional selalu `NULL`, filter klien hanya pernah cocok dengan baris leadership). Dibuktikan `TeamPerformanceControllerTest::test_client_filter_shows_production_staff_involved_with_that_client`.
- Breakdown Kualitas Proses/Hasil Konten/Performa Klien per kartu (lihat tabel istilah di atas).
- **Koreksi #13**: baris dengan status **Data Belum Cukup TIDAK PERNAH menampilkan angka Nilai KPI** (`Nilai KPI: Data belum cukup`, bukan angka mentah) - walau `composite_score` tetap tersimpan di DB untuk audit.
- **Diurutkan ALFABETIS** (nama), bukan skor - tidak ada ranking. Tidak ada satu "overall score" gabungan lintas role.

### 3. Detail Anggota (`show.blade.php`, route terpisah)
- Role selector (tab kecil per role/client, TANPA overall score gabungan).
- Breakdown lengkap tiap komponen skor + tabel "Rincian Kualitas Proses" mentah (untuk audit formula).
- Tabel "Konten yang Berkontribusi pada Hasil Konten" - dibaca LANGSUNG dari `component_breakdown` yang dipersist saat kalkulasi (`TeamPerformanceDashboardService::contentOutcomesForResult()`), bukan diturunkan ulang secara live - lebih akurat untuk audit (snapshot apa adanya, tidak berubah walau assignment/role user berubah belakangan).
- Empty state ("user tidak punya aktivitas KPI pada periode ini") menjelaskan dengan bahasa biasa ("Belum ada brief, produksi, publikasi, atau keputusan yang tercatat untuk periode ini") - TIDAK menyebut istilah "Access role (RBAC)" atau path dokumentasi developer.
- Sama aturan #13: Data Belum Cukup tidak pernah menampilkan angka Nilai KPI.

### 4. Kehadiran (`partials/tab-kehadiran.blade.php`)
**Tidak diubah sama sekali** - logic dan view attendance existing tetap identik, tetap terpisah total dari productivity KPI (cuti/izin tidak dianggap penurunan performa).

## Accessibility & Responsive

- `role="tablist"`/`role="tab"`/`aria-selected` pada navigasi tab.
- Layout grid responsive, konsisten dengan sistem Tailwind/CSS variable existing (`var(--surface-card)`, `var(--text-primary)`, dst - tidak ada token warna baru diperkenalkan).
- Dark/light theme otomatis ikut via CSS variable yang sudah ada.
- Empty state selalu eksplisit dan dalam bahasa pengguna biasa ("Data KPI sedang disiapkan otomatis") - TIDAK PERNAH menampilkan tabel kosong tanpa penjelasan, angka 0 untuk data unavailable, atau instruksi teknis/command developer/path dokumentasi (`docs/kpi/...`, `php artisan`, `kpi:calculate`). Dibuktikan `TeamPerformanceControllerTest` (`assertDontSee('docs/kpi')`, `assertDontSee('kpi:calculate')`, `assertDontSee('php artisan')`).
