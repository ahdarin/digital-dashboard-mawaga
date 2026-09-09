# PRODUCT REQUIREMENTS DOCUMENT
## 523 Studio Platform
### Creative Operations & Marketing Intelligence Platform

| Metadata | Nilai |
|---|---|
| Versi | 3.0 — rekonstruksi final as-built |
| Tanggal | 5 September 2026 |
| Status | FINAL / AS-BUILT; keputusan terbuka menunggu pengesahan manusia |
| Organisasi | 523 Studio / Metro Indonesian Software |
| Tim penyusun | Rekonstruksi berbasis audit repository; pengesahan pemilik produk dan tim KP belum dilakukan |
| Alias proyek akademik | Dashboard Digital Mawaga |
| Baseline sistem | `b4165ef142f4731adb11bcda0c0d96bc24edd2b0` |

Dokumen ini menggambarkan produk pada baseline tersebut. “Final” berarti hasil rekonstruksi sistem yang sudah dibangun, bukan klaim bahwa seluruh keputusan terbuka atau verifikasi provider telah selesai. Dokumen awal dipertahankan sebagai baseline historis. Spesifikasi teknis, bukti dan pengecualian terperinci ada pada [SRS](SRS_523_STUDIO_FINAL.md), [laporan rekonsiliasi](PRD_SRS_RECONCILIATION_REPORT.md) dan [matriks keterlacakan](REQUIREMENT_TRACEABILITY_MATRIX.md).

## 1. Ringkasan Eksekutif

523 Studio Platform menyatukan perencanaan konten klien, pengerjaan tim, review, pencatatan tayang dan pembacaan performa. Platform membantu tim mengetahui apa yang dipesan, apa yang siap dikerjakan, siapa yang menangani, keputusan apa yang sudah dibuat dan data performa mana yang benar-benar tersedia.

Pengguna internal bekerja sesuai peran dan klien yang ditugaskan. Klien menggunakan tautan portal untuk melihat pekerjaan serta memberi persetujuan atau revisi. Bantuan AI menyiapkan brief dan strategi, sedangkan Delay Risk membantu prioritisasi. KPI tim menjelaskan kontribusi bulanan berdasarkan ketepatan, kualitas dan bonus performa yang memiliki bukti data.

Nilai produk terletak pada hubungan antara paket, slot rencana, jejak pengerjaan, publikasi dan observasi performa. Platform mencatat serta membaca publikasi; distribusi langsung media ke akun sosial belum menjadi kemampuan produk.

## 2. Latar Belakang

### 2.1 Kondisi Operasional

Tim mengelola beberapa klien dengan paket bulanan dan pekerjaan lintas penulis, kreator, desainer serta pengelola media sosial. Materi, tenggat, hasil kerja dan keputusan review perlu dilacak dalam konteks klien yang sama.

### 2.2 Masalah yang Diselesaikan

Pencatatan terpisah menyulitkan pengecekan kesiapan slot, riwayat penolakan/revisi, beban pekerjaan dan status tayang. Angka performa juga mudah disalahartikan ketika total terkini, periode publikasi dan pertumbuhan harian diperlakukan sebagai hal yang sama.

### 2.3 Solusi Produk

Platform menyediakan alur rencana berbasis kuota, produksi berbasis tugas, review yang mencatat sumber keputusan, portal klien dan pembacaan analytics dengan konteks periode serta kelengkapan observasi. Otomasi membantu pekerjaan berulang, sementara keputusan konten dan penjadwalan tetap dilakukan manusia.

## 3. Tujuan Produk

1. Menelusuri pesanan bulanan dari paket ke slot dan pekerjaan produksi.
2. Menampilkan penanggung jawab, tenggat dan jejak keputusan untuk pekerjaan yang sedang berjalan.
3. Memisahkan persetujuan klien, persetujuan internal dan pencatatan publikasi.
4. Membaca performa dengan sumber, periode dan keterbatasan data yang dapat dijelaskan.
5. Menyediakan penilaian kontribusi bulanan yang menampilkan komponen dan ukuran sampelnya.

Indikator pada §12 merupakan ukuran yang dapat dihitung; baseline, target numerik dan pencapaian bisnis belum disahkan.

## 4. Pengguna dan Peran

| Peran | Tujuan | Kebutuhan | Cakupan | Batasan |
| --- | --- | --- | --- | --- |
| CEO | Mengawasi seluruh operasi | Visibilitas dan keputusan lintas modul | Semua klien | Tetap mengikuti validasi alur; koreksi khusus tersedia |
| Manager | Mengelola orang, klien dan keputusan | Onboarding, roster, rencana, approval, laporan dan KPI | Semua klien | Form publikasi khusus tidak tersedia; Kanban masih menerima pencatatan dengan hak workflow |
| SMO | Mengulas rencana dan mengelola tayang/performa | Approval, produksi, publikasi, analytics dan AI Strategy | Klien yang ditugaskan | Tidak membuat rencana/brief biasa, mengelola pengguna atau membuka Performa Tim |
| Copywriter | Menyiapkan rencana dan brief | Slot, informasi, caption draft dan AI Brief | Klien yang ditugaskan | Tidak melakukan transisi produksi atau approve |
| Content Creator | Mengerjakan produksi konten | Tugas, link hasil, PIC dan revisi | Klien yang ditugaskan | Tidak membuat rencana atau approve; pencatatan lewat Kanban tetap mungkin |
| Graphic Designer | Mengerjakan materi desain | Tugas, link hasil, PIC dan revisi | Klien yang ditugaskan | Tidak membuat rencana atau approve; pencatatan lewat Kanban tetap mungkin |
| Admin | Mengamati sistem | Seluruh menu dan klien | Semua klien | Tanpa pengelolaan entity bisnis; tetap boleh laporan dan tindakan pribadi tertentu |
| Klien melalui Portal Klien | Melihat pekerjaan dan memberi feedback | Kalender, riwayat, performa, Setuju/Minta Revisi | Satu klien sesuai token | Bukan akun internal; tidak mengubah rencana, tenggat atau pengguna |

Satu orang dapat memiliki beberapa role. Hak efektif merupakan gabungan role tersebut. Admin bukan pembatas yang membatalkan hak dari role tambahan. Keanggotaan tim klien berbeda dari penanggung jawab satu konten.

## 5. Prinsip Produk

- Satu catatan operasional yang menautkan klien, paket, konten dan histori keputusan.
- Pekerjaan bergerak melalui status yang jelas dengan keputusan manusia.
- Hak akses mengikuti tindakan dan cakupan klien, dengan pengecualian yang disebutkan secara terbuka.
- Angka performa dibaca bersama periode publikasi dan observasinya.
- AI memberi bantuan yang dapat ditinjau; hasil AI bukan persetujuan otomatis atau jaminan keberhasilan.
- Riwayat operasional dipertahankan sesuai kebijakan setiap entitas; tidak dijanjikan penghapusan lunak universal.
- Integrasi menunjukkan progres dan keterbatasannya; kesiapan kode dibedakan dari keberhasilan layanan langsung.

## 6. Ruang Lingkup Produk

### In Scope

Sembilan belas modul pada §7 mencakup operasi konten, portal klien, analytics, intelligence, KPI, kehadiran dan laporan. Pencatatan manual tetap tersedia pada bagian yang mendukungnya meskipun koneksi sosial belum siap.

### Out of Scope

CRM lead/sales, revenue pipeline, invoice/payroll, iklan berbayar, ROI/ROAS, social listening, competitor scraping, inbox/DM/comment management, direct social posting, penyimpanan/penyuntingan berkas produksi lengkap, pendaftaran mandiri dan undangan email otomatis. Tidak ada komitmen roadmap atau tanggal peluncuran kemampuan tersebut.

### External Dependencies

Login internal memerlukan Google; AI Brief/AI Strategy memerlukan Gemini; data Instagram/TikTok memerlukan konfigurasi, izin dan akses provider. Proses otomatis memerlukan layanan runtime yang aktif, dan Delay Risk memerlukan model beserta lingkungan inferensinya. Audit kode bukan verifikasi consent atau App Review live.

## 7. Modul Produk

### 7.1 Authentication & Beranda

**Tujuan dan masalah:** Menyatukan pintu masuk dan pekerjaan yang perlu ditindaklanjuti.

**Pengguna:** Seluruh pengguna internal; Dashboard untuk CEO, Manager, SMO, Admin.

**Kemampuan utama:** Login Google berdasarkan akun terdaftar, Beranda pribadi, absensi, pin dan Dashboard operasional.

**Aturan bisnis:** Akun aktif dan akses login harus memenuhi pemeriksaan login. Beranda berbeda dari Dashboard manajemen.

**Ketergantungan:** Google dan administrasi akun.

**Batasan:** Pencabutan sesi aktif belum otomatis; Dashboard tidak menyajikan ROI/penjualan.

### 7.2 Client & Package Management

**Tujuan dan masalah:** Menghindari data klien dan kuota paket yang tersebar.

**Pengguna:** CEO/Manager mengelola; internal membaca sesuai cakupan.

**Kemampuan utama:** Nama/kategori/logo/tautan aset, paket aktif dan histori, tim penanganan, kontrol portal dan koneksi sosial.

**Aturan bisnis:** Klien berhistori dijeda saat penghapusan; paket baru menyimpan kuota tersendiri.

**Ketergantungan:** Master kategori/paket dan roster.

**Batasan:** Kontak/harga/Brand Name terpisah tidak menjadi field final; jeda dan akses portal terpisah.

### 7.3 User & Team Management

**Tujuan dan masalah:** Menetapkan orang yang bekerja dan klien yang menjadi tanggung jawabnya.

**Pengguna:** CEO dan Manager; Admin sebagai pembaca.

**Kemampuan utama:** Tambah Pengguna, multi-role, Aktif/Nonaktif, Akses Login, Assign Klien dan pengalihan pekerjaan aktif.

**Aturan bisnis:** Role digabung; status akun berbeda dari izin login. Penonaktifan tidak menghapus riwayat.

**Ketergantungan:** Identitas Google dan roster klien.

**Batasan:** Tidak ada undangan email otomatis; perubahan status belum mencabut semua sesi aktif.

### 7.4 Content Planning

**Tujuan dan masalah:** Menyelaraskan pesanan bulanan dengan paket dan kesiapan produksi.

**Pengguna:** CEO/Manager/Copywriter menyusun; CEO/Manager/SMO memutuskan.

**Kemampuan utama:** Pembuatan slot C/D dari kuota, informasi/brief, Ajukan Rencana, Setujui/Tolak, buka kembali, deadline, Kirim ke Produksi dan kalender.

**Aturan bisnis:** Rencana biasa memerlukan paket aktif dan pemeriksaan duplikasi bulan. Persetujuan rencana terpisah dari pengiriman batch.

**Ketergantungan:** Paket, master, brief dan roster.

**Batasan:** Keunikan rencana belum dijamin terhadap permintaan bersamaan. Jobdesk Tambahan langsung masuk produksi dan bukan slot kuota biasa.

### 7.5 AI Brief

**Tujuan dan masalah:** Membantu menyiapkan arahan produksi yang dapat diperiksa manusia.

**Pengguna:** CEO, Manager, Copywriter.

**Kemampuan utama:** Brief manual, generasi dan bantuan AI per field, scenes/naskah, talent/properti, diskusi, apply/revert terakhir, finalisasi/buka kembali, estimasi kelayakan dan kompleksitas.

**Aturan bisnis:** Tanggal kerja/upload ditetapkan manusia; finalisasi brief tidak otomatis menyetujui rencana atau mengubah workflow.

**Ketergantungan:** Gemini untuk fitur AI; manual tetap tersedia.

**Batasan:** Penilaian kelayakan bukan jaminan penyelesaian; keberhasilan/ketepatan AI live belum divalidasi.

### 7.6 Production Workflow

**Tujuan dan masalah:** Membuat pekerjaan, penanggung jawab dan keterlambatan terlihat.

**Pengguna:** Internal sesuai hak baca/transisi.

**Kemampuan utama:** Sembilan status, papan produksi, detail konten, link hasil, tanda footage, pergantian PIC, histori dan koreksi manajemen.

**Aturan bisnis:** Draft dilepas lewat batch normal; uploaded/cancelled mengakhiri alur normal. Koreksi status merupakan pengecualian beralasan.

**Ketergantungan:** Rencana, brief, PIC dan jadwal.

**Batasan:** Link hasil/PIC bukan guard wajib untuk semua perpindahan; koreksi tidak merekonstruksi semua fakta publikasi/revisi.

### 7.7 Revision & Approval

**Tujuan dan masalah:** Memisahkan umpan balik dari keputusan kelayakan tayang.

**Pengguna:** Pelaksana workflow, approver internal, klien.

**Kemampuan utama:** Catatan revisi internal/klien, round, mulai pengerjaan, penyelesaian dan persetujuan internal.

**Aturan bisnis:** Revisi terbuka menghalangi approve internal. Review klien dan approve internal mempunyai arti berbeda.

**Ketergantungan:** Workflow dan portal.

**Batasan:** Internal dapat approve tanpa cap Setuju klien; review ulang klien setelah revisi memerlukan keputusan lanjutan.

### 7.8 Client Portal

**Tujuan dan masalah:** Memberi klien visibilitas dan sarana feedback pada pekerjaan miliknya.

**Pengguna:** Klien pemegang tautan; CEO/Manager mengelola akses.

**Kemampuan utama:** Dashboard, kalender, riwayat tayang, analytics, Setuju dan Minta Revisi.

**Aturan bisnis:** Setuju hanya mencatat review dan tetap Menunggu Persetujuan; token hanya memetakan satu klien.

**Ketergantungan:** Tautan portal aktif dan data operasional.

**Batasan:** Token tidak kedaluwarsa otomatis; klien tidak membuat konten/mengubah deadline. Jeda klien belum mematikan portal.

### 7.9 Publication

**Tujuan dan masalah:** Mencatat kapan dan di mana hasil benar-benar tayang.

**Pengguna:** CEO/SMO pada formulir; pelaksana workflow pada jalur Kanban.

**Kemampuan utama:** Penjadwalan manual, pencatatan beberapa platform, tanggal tayang, caption final, URL opsional dan penautan post sosial.

**Aturan bisnis:** Status normal bergerak Disetujui → Terjadwal Tayang → Sudah Tayang; setiap catatan publikasi memerlukan platform dan waktu.

**Ketergantungan:** Workflow, platform dan data post provider untuk matching.

**Batasan:** Aplikasi tidak melakukan direct social posting. Hak publikasi Kanban lebih luas daripada formulir; keputusan penyelarasan masih terbuka.

### 7.10 Content Analytics

**Tujuan dan masalah:** Membedakan performa konten terpilih dari pertumbuhan yang benar-benar teramati.

**Pengguna:** CEO, Manager, SMO, Admin.

**Kemampuan utama:** Pemilihan satu klien/platform, Bulan/Rentang, ringkasan, tabel, detail, metrik terkini, pertumbuhan harian/periode, data-through, import/export dan progres sync.

**Aturan bisnis:** API memilih konten berdasarkan tanggal tayang; CSV berdasarkan tanggal record. Total terkini tidak sama dengan pertumbuhan periode.

**Ketergantungan:** Data API/CSV dan observasi historis.

**Batasan:** Gap observasi diberi konteks, bukan angka buatan. Post belum tertaut dapat ada di Performa tetapi tidak semua laporan/portal.

### 7.11 Audience Analytics

**Tujuan dan masalah:** Memahami audiens tanpa mencampur data yang sumbernya berbeda.

**Pengguna:** Pembaca Performa dan klien pada portalnya.

**Kemampuan utama:** Followers, reach dan demografi yang tersedia; import CSV audiens serta pemisahan sumber.

**Aturan bisnis:** Snapshot followers bukan angka yang dijumlahkan antartanggal; data API dan manual tidak saling menimpa.

**Ketergantungan:** Izin dan jenis metrik provider.

**Batasan:** TikTok pada integrasi ini tidak memberi demografi/jam aktif; data kosong bukan bukti audiens nol.

### 7.12 AI Strategy

**Tujuan dan masalah:** Menyusun usulan konten dari performa bulan yang dipilih.

**Pengguna:** CEO/Manager/SMO membuat; Admin membaca.

**Kemampuan utama:** Analisis per bulan/platform, riwayat, diskusi, action items, split, ide dan penerapan satu ide ke slot Draf.

**Aturan bisnis:** Terapkan satu ide memperbarui slot milik klien yang sama tanpa menambah item pada jalur itu.

**Ketergantungan:** Gemini, data performa dan slot draft.

**Batasan:** Jalur Apply massal lama masih dapat membuat item tambahan; Revert lama bukan undo edit slot. Keduanya dicatat sebagai perilaku tersisa yang perlu keputusan.

### 7.13 Delay Risk

**Tujuan dan masalah:** Membantu prioritisasi pekerjaan yang berisiko terlambat.

**Pengguna:** Tim operasional dan manajemen.

**Kemampuan utama:** Prediksi ML tersimpan, kategori rendah/sedang/tinggi, faktor penjelas dan panel evaluasi.

**Aturan bisnis:** Skor risiko adalah probabilitas model, bukan Nilai KPI pegawai atau kepastian keterlambatan.

**Ketergantungan:** Model tersimpan, Python dan input operasional.

**Batasan:** Belum ada validasi end-to-end/akurasi produksi; data/model tidak tersedia tidak boleh diganti skor fiktif.

### 7.14 Team Performance & KPI

**Tujuan dan masalah:** Memberi dasar penilaian kontribusi bulanan yang dapat dijelaskan.

**Pengguna:** CEO, Manager, Admin; tiap pengguna melihat KPI sendiri.

**Kemampuan utama:** Nilai KPI, Ketepatan Kerja, Kualitas Kerja, Bonus Performa, ukuran sampel, perbandingan anggota, tren enam bulan dan kartu profil.

**Aturan bisnis:** Dasar 60% ketepatan +40% kualitas, ditambah bonus dan dibatasi 100. Konten diatribusikan ke semua kontributor yang memenuhi sumber atribusi.

**Ketergantungan:** Publikasi pertama, log kerja, revisi dan snapshot D+7 untuk bonus.

**Batasan:** CSV saja tidak memberi bonus; sampel kecil sementara. Seleksi snapshot lintas platform dan invalidasi hasil lama belum seluruhnya menjamin konsistensi.

### 7.15 Attendance

**Tujuan dan masalah:** Mencatat kehadiran aktual tanpa menebak jam yang hilang.

**Pengguna:** Semua internal mencatat; CEO/Manager/Admin meninjau.

**Kemampuan utama:** Check-in/check-out, status keterlambatan/pulang, tampilan harian dan rekap bulanan.

**Aturan bisnis:** Senin–Jumat 11:00–17:00 dengan toleransi 15 menit; checkout kosong tetap kosong.

**Ketergantungan:** Waktu aplikasi dan akun internal.

**Batasan:** Tidak ada kalender libur nasional/koreksi absensi manual; kehadiran bukan formula KPI. Endpoint pribadi masih dapat dipakai Admin.

### 7.16 Reports

**Tujuan dan masalah:** Menyediakan ringkasan operasional dan performa yang dapat dibagikan.

**Pengguna:** CEO, Manager, SMO, Admin.

**Kemampuan utama:** Progres Operasional dan Performa Konten, periode/klien, PDF/Excel dan riwayat pribadi.

**Aturan bisnis:** Progres menurut deadline; performa menurut cohort tayang API/tanggal CSV. Riwayat hanya milik pembuat.

**Ketergantungan:** Data operasional/performa dan penyimpanan file.

**Batasan:** Riwayat pribadi tidak otomatis membuat file privat; laporan performa memerlukan satu klien dan tidak memakai filter platform tersendiri.

### 7.17 Notification & Search

**Tujuan dan masalah:** Mempercepat penemuan pekerjaan dan tindak lanjut.

**Pengguna:** Semua internal sesuai penerima dan cakupan.

**Kemampuan utama:** Notifikasi dalam aplikasi, status baca dan pencarian klien/pengguna/konten.

**Aturan bisnis:** Klien/konten pencarian dibatasi roster; penerima notifikasi ditentukan event, tidak selalu roster.

**Ketergantungan:** Event operasional dan scheduler pengingat.

**Batasan:** Tidak ada WhatsApp/email otomatis. Pencarian pengguna aktif global dan notifikasi tertentu lintas roster perlu kebijakan privasi yang jelas.

### 7.18 Settings & Master Data

**Tujuan dan masalah:** Menjaga data pilihan dan konfigurasi operasional tetap konsisten.

**Pengguna:** CEO, Manager, SMO mengelola; Admin membaca.

**Kemampuan utama:** Pengaturan umum, Data Pilihan, Integrasi, template paket dan preferensi tema pribadi.

**Aturan bisnis:** Master yang sedang dipakai memiliki guard hapus; perubahan kuota template tidak menulis ulang snapshot paket.

**Ketergantungan:** Permission dan referensi data.

**Batasan:** Content Format adalah referensi yang tersedia, bukan CRUD tab; empat master umum menyediakan tambah/hapus, bukan seluruh operasi edit/status.

### 7.19 Social Media Integration

**Tujuan dan masalah:** Mengurangi pencatatan metrik manual sambil menunjukkan batas observasi.

**Pengguna:** CEO/Manager menghubungkan; CEO/Manager/SMO menyinkronkan.

**Kemampuan utama:** OAuth Instagram/TikTok, token per klien, sync progresif, matching, snapshot, riwayat, retry dan refresh token.

**Aturan bisnis:** Mode normal mengamati horizon rolling 90 hari; konten lama dipertahankan, bukan terus diperbarui tanpa batas.

**Ketergantungan:** Provider, izin akun, konfigurasi, worker dan scheduler.

**Batasan:** Implementasi ada; keberhasilan consent/App Review/live tidak disimpulkan dari kode atau test mock.

## 8. High-Level User Journey

1. CEO/Manager menyiapkan klien, memilih paket dan menugaskan tim. Portal dan integrasi sosial dapat diaktifkan sesuai kebutuhan.
2. CEO/Manager/Copywriter membuat rencana bulanan. Sistem menyediakan slot C/D sesuai paket aktif.
3. Tim penyusun mengisi informasi, platform dan brief; bantuan AI dapat digunakan tanpa menyerahkan keputusan tanggal kepada AI.
4. Rencana diajukan. CEO/Manager/SMO menyetujui atau menolak. Penolakan dapat dibuka kembali untuk diperbaiki dan diajukan ulang.
5. Pada rencana disetujui, approver menetapkan tenggat upload dan mengirim slot secara batch ke Produksi.
6. Pelaksana mengerjakan konten dan menyerahkan review. Klien dapat Setuju atau Minta Revisi; approver internal mengambil keputusan tersendiri. Revisi dikerjakan sampai selesai sebelum approve internal.
7. Konten disetujui dijadwalkan. Setelah tayang di platform sosial, publikasi dicatat atau ditautkan dengan post yang ditemukan integrasi.
8. Tim membaca Performa dengan klien/periode/platform, mengecek kelengkapan observasi, lalu menyusun AI Strategy pada bulan analisis pilihan dan menerapkan ide ke slot Draf yang dipilih.
9. Manajemen meninjau KPI bulanan dan menghasilkan laporan Progres Operasional atau Performa Konten.

**Jalur alternatif:** Jobdesk Tambahan dapat dibuat langsung Brief Ready tanpa paket/approval batch. Jalur Apply massal AI lama juga masih ada dan dapat membuat pekerjaan tambahan; ini bukan bukti semua penambahan konten dibatasi kuota. Koreksi status oleh CEO/Manager merupakan pengecualian yang harus dibaca bersama histori.

## 9. Product Business Rules

| Aturan | Perilaku dan batas saat ini |
|---|---|
| Rencana bulanan | Alur biasa memeriksa satu rencana per klien/bulan/tahun dan paket aktif; jaminan terhadap permintaan bersamaan belum lengkap. |
| Snapshot kuota | Perubahan template/paket berikutnya tidak menulis ulang kuota snapshot yang sudah dipakai. |
| Kesiapan produksi | Persetujuan rencana dan Kirim ke Produksi adalah tindakan berbeda; tenggat upload seluruh draft harus terisi saat release. |
| Cakupan | CEO/Manager/Admin global; role lainnya sesuai roster. Notifikasi/profil tertentu belum seluruhnya mengikuti scope yang sama. |
| Review | Setuju klien merekam review; approve internal memindahkan status dan diblokir revisi yang belum selesai. |
| Selesai | Sudah Tayang/Dibatalkan terminal pada alur normal; koreksi manajemen dapat melewatinya dengan alasan. |
| Hapus klien | Klien berhistori dijeda; klien kosong dapat dihapus. Akses portal dikendalikan terpisah. |
| Hak Admin | Pengamatan data bisnis disertai pengecualian pembuatan laporan dan tindakan pribadi. |
| Laporan | Riwayat milik pembuat. Privasi file memerlukan kebijakan tersendiri. |
| KPI | Periode bulanan berdasarkan publikasi pertama, dengan atribusi ke beberapa kontributor dan data hilang yang dibedakan dari nilai nol. |

## 10. Data & Intelligence Strategy

Data operasional mencatat paket, kesiapan slot, penanggung jawab, tenggat, revisi dan publikasi. Data performa API menyimpan observasi nyata; CSV menyediakan record manual yang tidak otomatis menjadi histori observasi harian. Performa dapat menampilkan nilai terkini dari konten yang tayang pada periode pilihan, sementara pertumbuhan dihitung dari observasi yang cukup.

AI Brief membantu arahan produksi. AI Strategy menggunakan performa bulan terpilih untuk usulan tindakan dan konten. Delay Risk menilai probabilitas terlambat dari ciri operasional, sedangkan KPI pegawai menghitung kontribusi dan hasil kerja. Keempatnya memiliki tujuan, sumber dan batas validitas berbeda.

Angka contoh/seeder tidak membuktikan keberhasilan produk atau akurasi model. Data yang hilang, belum tertaut atau terlambat disinkronkan perlu dibaca melalui konteks tampilan dan sumbernya; tidak semua agregat nol berarti semua metrik benar-benar diukur nol.

## 11. Non-Functional Product Requirements

| Kategori | Karakteristik yang ditemukan | Target/validasi yang masih diperlukan |
|---|---|---|
| Usability | Menu/status konsisten dan alur bertahap tersedia. | Pengujian tugas pengguna serta kasus kosong/error. |
| Security & privacy | Login terdaftar, permission, scope portal dan enkripsi token tersedia. | TLS/config produksi, pencabutan sesi dan kebijakan file/profil/notifikasi. |
| Reliability | Progres, antrean, retry dan histori observasi tersedia. | Verifikasi worker/scheduler/provider dan kegagalan end-to-end. |
| Performance | Pemrosesan sync dapat dibagi bertahap. | Target respons belum disahkan/diukur; angka lama kurang dari dua detik bukan pencapaian terverifikasi. |
| Auditability | Keputusan rencana dan status memiliki histori aktor/waktu. | Tidak menjanjikan semua perubahan tercatat atau log kebal penghapusan. |
| Maintainability | Domain dan pengujian dipisah dalam struktur aplikasi. | Keterlacakan dan pengujian regresi perlu dipertahankan pada perubahan berikutnya. |
| Responsiveness | Template internal/portal responsif tersedia. | Uji perangkat nyata, keyboard dan aksesibilitas belum menjadi sertifikasi. |
| Recovery & scale | Pemisahan proses runtime tersedia; histori dapat dipertahankan. | Retensi, backup/restore, kapasitas dan target availability perlu disahkan. |

## 12. Success Metrics

Ukuran berikut dapat digunakan saat data operasional nyata dan periode evaluasi disepakati. Belum ada baseline, target, atau klaim pencapaian yang disahkan oleh audit ini.

| Ukuran | Cara menghitung | Sumber produk | Status target |
|---|---|---|---|
| Kesiapan rencana | Slot yang memenuhi kelengkapan / seluruh slot rencana | Rencana dan brief | Belum ditetapkan |
| Realisasi pelepasan | Draft yang sudah dilepas / slot yang direncanakan, dengan jobdesk tambahan dipisah | Rencana dan histori workflow | Belum ditetapkan |
| Ketepatan kerja | Proporsi kontribusi tepat waktu menurut definisi KPI; tampilkan denominator valid | Publikasi/log kerja dan tenggat | Formula tersedia; target belum ditetapkan |
| Kualitas kerja | Proporsi kontribusi tanpa revisi internal | Revisi dan atribusi | Formula tersedia; target belum ditetapkan |
| Keterlacakan tayang | Post terhubung / post ditemukan per klien/platform | Publikasi dan hasil sinkronisasi | Belum ditetapkan |
| Kecukupan observasi | Jumlah/proporsi unit full, partial, unavailable pada periode | Performa dan coverage | Belum ditetapkan |
| Penyelesaian sinkronisasi | Task selesai/gagal serta usia observasi terakhir, bukan hanya waktu klik sync | Panel sync dan data-through | Belum ditetapkan |
| Tindak lanjut klien | Review klien yang tercatat beserta waktu tunggu pada konteks review | Portal dan histori | Siklus review ulang harus diputuskan dahulu |

## 13. Assumptions, Constraints & External Dependencies

Tim menjaga roster, paket, tenggat dan pencatatan publikasi tetap benar. Izin akses sosial dan layanan AI dikelola oleh pemilik akun yang berwenang. Provider dapat tidak menyediakan metrik tertentu; observasi masa lalu tidak selalu dapat dibuat ulang. Model ML memerlukan lingkungan kompatibel, dan penilaian akurasi memerlukan dataset berlabel yang sah.

Keputusan terbuka mencakup batas Admin/publikasi, dua Apply AI, keunikan rencana, pencabutan sesi, review ulang klien, cakupan profil/notifikasi, konsistensi KPI lintas platform, privasi laporan, target mutu, retensi dan pengesahan PIC. Rincian Q-01–Q-12 ada dalam laporan rekonsiliasi; dokumen ini tidak mengubah implementasi untuk menutupnya.

## 14. Academic Development Scope / PIC Ownership

Pembagian akademik adalah informasi pertanggungjawaban pengembangan, bukan menu atau batas otorisasi pengguna.

| PIC | Lingkup baseline historis | Bukti perkembangan | Keputusan rekonstruksi |
|---|---|---|---|
| Ghazi Fadhlullah | Client, planning dan AI planning (PIC 1) | PRD identitas dan tabel domain; identitas penulis terdapat pada git history | Pertahankan sebagai baseline akademik; kepemilikan akhir perlu pengesahan. |
| Ahda Rindang Al-Amin | Production/workflow, revision, publishing, team/report/risk (PIC 2) | Git menunjukkan kontribusi lintas domain termasuk slot otomatis, KPI dan perbaikan UI/alur | Tidak dibatasi hanya domain historis. |
| Surya Andika | Analytics/integrasi (PIC 3) | Git menunjukkan cohort analytics, progressive sync, retry dan pengetesan integrasi | Pertahankan hubungan domain dengan catatan kontribusi tim. |

PRD lama bagian utama menempatkan Ahda sebagai PIC 2 dan Surya sebagai PIC 3, tetapi bagian daftar halaman menukarnya. Rekonstruksi memakai bagian identitas/tabel utama sebagai baseline dan tidak menafsirkan jumlah commit sebagai bukti kepemilikan eksklusif. Pengesahan pembagian tugas akademik final tetap Q-11.

## 15. Final Product Scope Summary

Baseline mencakup 19 modul operasi konten dan intelligence yang saling terkait. Alur utama berjalan dari paket ke slot, brief, persetujuan rencana, deadline, produksi, review, pencatatan publikasi, analytics dan laporan. Portal serta KPI memberi visibilitas bagi klien dan manajemen.

Kemampuan yang bergantung provider, perilaku kompatibilitas lama dan keputusan terbuka telah dipisahkan dari alur utama. Status pengesahan keseluruhan adalah **NEEDS_CLARIFICATION**, dengan dokumen siap untuk review manusia tanpa perubahan application code.
