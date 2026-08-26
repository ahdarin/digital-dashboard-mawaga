# Documentation Freeze Checklist

> Branch: `stabilization/pre-user-manual` · Commit dasar: `d637369` · Tanggal:
> 26 Agustus 2026. Lihat `docs/PRE_DOCUMENTATION_STABILIZATION_REPORT.md`
> (termasuk appendix "Final Pre-Merge Verification") dan
> `docs/USER_MANUAL_SOURCE_OF_TRUTH.md` untuk detail lengkap tiap item.

## Application

- [x] Core workflow stable — golden path, rejection path, dan revision path
      lulus sebagai satu alur berkesinambungan (`GoldenPathTest`, 82 assertion)
- [x] Authorization verified — 3 IDOR (Phase L) + re-audit menyeluruh
      (Final Pre-Merge Verification), nol temuan baru
- [x] Role access verified — 6 role × 10 halaman utama via direct URL
      (`RoleAccessMatrixTest`, 63 test case, PermissionSeeder produksi asli)
- [x] Client scope verified — crafted request (URL langsung + PATCH ke
      resource client lain) ditolak 403, data terbukti tidak berubah
- [x] Terminology standardized — sweep menyeluruh ke seluruh
      `resources/views/` termasuk PDF laporan client-facing; tabel referensi
      di `docs/USER_MANUAL_SOURCE_OF_TRUTH.md` ("Terminologi Resmi untuk
      Dokumentasi")
- [x] Runtime smoke test passed — 15 halaman utama + Portal Klien (token
      asli), read-only terhadap database development nyata, zero write
      dikonfirmasi eksplisit sebelum & sesudah

## Testing

- [x] Testing DB isolated — `digidaw_testing` (bukan `digidaw`), hard
      safeguard di `tests/TestCase.php` (abort kalau `APP_ENV` bukan
      `testing` atau nama DB tidak mengandung "test")
- [x] Full test suite passed — 148 test, 363 assertion, 0 failed, 0 skipped
- [x] Critical path regression tests available — Auth/login access, Client
      CRUD+scope+portal, Content Plan (create/urgent/reject/reopen/approve),
      AI Brief (tanggal invalid & valid), Production (render/reassign/
      transition/revision/publication), Analytics authorization (scoped vs
      global), Portal (approve/revisi/token invalid), OAuth Instagram/TikTok
      (jalur non-consent), Report generation, AI Strategy lifecycle,
      Dashboard scope, legacy route redirect

## Documentation Preparation

- [x] Source of Truth updated — re-audit lengkap (status KI-01...KI-20,
      3 temuan Phase L, terminology table, data safety checklist)
- [x] Screenshot-sensitive data identified — checklist 10 item ("Checklist
      Keamanan Data Dokumentasi" di source of truth)
- [x] External blockers documented — Instagram/TikTok/Delay Risk, terpisah
      jelas dari status kesiapan kode aplikasi
- [x] Demo data provenance checked — `KNOWN_SOURCE` (`DemoSeeder.php`,
      pre-existing sejak commit dasar), 1 catatan perlu konfirmasi user
      (nama client demo) sebelum screenshot final

## External Limitations

- **Instagram:** `EXTERNAL_BLOCKED` — kode OAuth+PKCE lengkap & teruji
  lewat jalur yang bisa diverifikasi tanpa consent manusia nyata; live
  consent bergantung Meta App Review (akun tester terdaftar manual).
- **TikTok:** `EXTERNAL_BLOCKED` — kode OAuth+PKCE lengkap & teruji sama
  seperti Instagram; TikTok Developer Portal masih berpotensi
  `unauthorized_client` (masalah registrasi app di provider, bukan kode
  aplikasi, berdasarkan histori debugging sesi-sesi sebelumnya).
- **Delay Risk:** *Operationally safe, model accuracy not validated in
  this sprint.* Kegagalan Python/model terbukti tidak membuat workflow
  utama crash (log + skip); akurasi prediksi model ML itu sendiri di luar
  cakupan verifikasi sesi ini (butuh data historis + model terlatih yang
  memadai untuk dinilai).

## Final Decision

`READY_TO_MERGE`

Tidak ada known fatal error, authorization leak, atau broken core workflow
yang tersisa. Test suite hijau penuh. Testing database aman & terisolasi
permanen. Golden path terverifikasi lewat automated integration test
berkesinambungan (batasan browser-based verification melekat pada desain
login Google-OAuth-only aplikasi, dinyatakan eksplisit, bukan dipalsukan).
Terminology sudah diselaraskan menyeluruh. External limitation (Instagram/
TikTok/Delay Risk) sudah dipisahkan jelas dari kesiapan kode.

**Satu item non-blocking yang perlu keputusan user sebelum screenshot
final:** konfirmasi apakah nama-nama client di `DemoSeeder.php` (Yasmin
International Boarding School, PT Guna Griya Abadi, LuxSuits, Top Scorer
Arena, FTI UNAND) aman dipublikasikan di buku panduan, mengingat komentar
asli seeder menyebut "mendekati portofolio riil 523 Studio".

Branch **tidak** di-push, **tidak** di-merge, **tidak** di-reset. Siap
direview dan di-merge manual oleh user.
