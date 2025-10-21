# RELIPROVE - Aplikasi berbasis website sertifikasi internal perusahaan (PHP + MySQL)

### Dashboard Peserta 
<img width="2879" height="1248" alt="Screenshot 2025-10-21 105956" src="https://github.com/user-attachments/assets/fc1543cf-9e30-4457-ad3e-755544c06d8e" />

### Dashboard Asesor 
<img width="2860" height="1587" alt="Screenshot 2025-10-21 105630" src="https://github.com/user-attachments/assets/a188058b-a0e6-407f-9333-427150c51f5b" />

### Dashboard Admin 
<img width="2856" height="1581" alt="Screenshot 2025-10-21 105824" src="https://github.com/user-attachments/assets/adae420a-ad4a-4257-852b-5b113e582d3d" />

---

## 1. Tujuan Strategis Sistem

**RELIPROVE** adalah platform digital berbasis **PHP + MySQL** yang dirancang untuk mengelola **sertifikasi magang** dan **sertifikasi kompetensi individual** secara profesional dan terverifikasi digital.

### Tujuan Utama
- Menerbitkan sertifikat magang dan kompetensi secara digital  
- Mencakup peserta **eksternal (magang)** dan **karyawan internal perusahaan**  
- Penilaian berbasis **bukti kerja (jobdesk + timeline)**  
- Divalidasi oleh **internal assessor** dan disahkan secara digital  
- Terverifikasi melalui **QR Code & nomor seri unik**  
- Mendukung **multibahasa** dan **audit trail aktif**

---

## 2. Kategori & Posisi Sertifikasi (Final Internasional Relevance)

### A. KATEGORI IT

| Subkategori | Posisi Sertifikasi |
|--------------|--------------------|
| **Software Engineering** | Software Engineer, Full Stack Developer |
| **Front-End Development** | Front-End Developer, UI Engineer, React Engineer |
| **Back-End Development** | Back-End Developer, API Developer, PHP Developer |
| **Data & Analysis** | Data Analyst, Data Engineer, Business Intelligence Specialist |
| **Artificial Intelligence** | AI Engineer, ML Engineer, NLP Engineer, Computer Vision Engineer |
| **System & Cloud** | DevOps Engineer, Cloud Engineer, System Architect |
| **QA & Testing** | QA Engineer, Automation Tester, Manual Tester |
| **Product & Analyst** | Product Analyst, Business Analyst |

### B. KATEGORI CREATIVE

| Subkategori | Posisi Sertifikasi |
|--------------|--------------------|
| **Visual & Illustration** | Illustrator, Digital Artist, Storyboard Designer |
| **Logo & Identity** | Logo Designer, Visual Identity Specialist |
| **Brand & Communication** | Brand Designer, Brand Strategist, Communication Designer |
| **UI/UX Design** | UI Designer, UX Researcher, Interaction Designer |
| **Art & Direction** | Art Director, Creative Director |
| **Motion & Multimedia** | Motion Designer, Video Editor, Multimedia Specialist |

---

## 3. Roles & Akses Terstruktur

| Role | Akses |
|------|-------|
| **Super Admin** | CRUD semua modul, kelola user, audit log, generate sertifikat, kelola template |
| **Assessor** | Lihat peserta, beri skor & komentar, upload hasil penilaian |
| **Peserta** | Registrasi, pilih posisi, upload dokumen, lacak asesmen, unduh sertifikat |
| **Viewer Publik** | Hanya bisa verifikasi sertifikat (via QR atau kode unik) |

---

## 4. Dokumen Wajib Peserta

- CV *(optional)*  
- Timeline proyek (PDF)  
- Jobdesk lengkap (PDF)  
- Link portofolio / file ZIP  
- Bukti keikutsertaan *(opsional)*

---

## 5. Jenis Sertifikat & Atribut

| Jenis Sertifikat | Dasar Penerbitan | Masa Berlaku | Ditandatangani Oleh |
|------------------|------------------|---------------|---------------------|
| **Magang General** | Kehadiran + dokumen lengkap | Seumur hidup | HR Manager / Supervisor |
| **Kompetensi Individual** | Bukti kerja + asesmen valid | 2 Tahun | Kepala Divisi + Lead Assessor |

### Atribut Sertifikat
- Nama peserta  
- Nomor sertifikat unik  
- Kategori & posisi  
- QR verifikasi  
- Logo perusahaan  
- Bahasa Inggris  
- Tanda tangan digital  
- Masa berlaku & status VALID/EXPIRED  

---

## 6. Struktur Database (14 Tabel)

1. `users`  
2. `categories`  
3. `positions`  
4. `documents`  
5. `registrations`  
6. `assessments`  
7. `certificates`  
8. `qr_codes`  
9. `admins_logs` *(Audit Trail)*  
10. `notifications`  
11. `template_certificates`  
12. `language_settings`  
13. `verification_logs`  
14. `system_settings`

---

## 7. Modul Utama & Fitur Kompleks

### Modul Peserta
- Multi-step form registrasi  
- Pilih kategori → posisi  
- Upload dokumen lengkap  
- Tracking status asesmen  
- Unduh sertifikat digital  

### Modul Asesor
- Daftar peserta sesuai bidang  
- Review dokumen (timeline, jobdesk)  
- Input skor & komentar  
- Upload hasil validasi  

### Modul Admin
- CRUD user, kategori, posisi  
- Validasi dokumen peserta  
- Penugasan assessor  
- Log semua aktivitas  
- Generate sertifikat + QR otomatis  

### Modul Sertifikat
- Generate QR otomatis  
- Auto-generate PDF (DomPDF)  
- Tanda tangan digital  
- Verifikasi: `/verifikasi.php?kode=[...]`

### Modul Multibahasa
- Kelola label & konten UI bilingual  
- Template sertifikat dua bahasa  

### Modul Notifikasi
- Email & notifikasi dashboard  
- Status registrasi, penilaian, sertifikat diterbitkan  

### Modul Audit & Keamanan
- Log aktivitas user  
- Validasi format upload (PDF/ZIP)  
- Pembatasan role  
- History update sertifikat  

---

## 8. Teknologi yang Digunakan

| Komponen | Tools |
|-----------|--------|
| Backend | PHP (MySQLi / PDO) |
| Database | MySQL |
| File Upload | Manual + sanitasi |
| PDF Generator | DomPDF |
| QR Generator | PHP QR Code |
| Frontend | HTML, CSS, JS (Bootstrap 5) |
| Keamanan Upload | `mime_content_type` + size limit |
| Multibahasa | Database + file language |

---

## 9. Struktur Folder Proyek

```

/
├── index.php
├── login.php
├── register.php
├── dashboard/
│   ├── peserta.php
│   ├── assessor.php
│   ├── admin.php
├── upload/
│   ├── timeline/
│   ├── jobdesk/
│   ├── portfolio/
│   ├── sertifikat/
├── includes/
│   ├── db.php
│   ├── auth.php
│   ├── helpers.php
├── cert/
│   ├── dompdf/
│   ├── template.php
├── qrcode/
│   ├── phpqrcode/
├── assets/
│   ├── css/
│   ├── js/
│   ├── screenshots/
├── verifikasi.php
├── logs/
│   ├── audit_log.txt

```

---

## 10. Alur Verifikasi QR Code

1. Sertifikat memiliki kode unik (contoh: `PT-IT-2025-007`)  
2. QR Code mengarah ke:
```

[https://sertifikasi.ptkamu.com/verifikasi.php?kode=PT-IT-2025-007](https://sertifikasi.ptkamu.com/verifikasi.php?kode=PT-IT-2025-007)

```
3. Sistem menampilkan hasil verifikasi:
- Nama peserta  
- Tipe sertifikat  
- Posisi sertifikasi  
- Status: **VALID / EXPIRED**

---

## 11. Fitur Tambahan (Opsional Namun Sudah Disiapkan)

- Download template timeline & jobdesk  
- Sistem review internal sebelum asesmen  
- Token email validasi  
- Cetak sertifikat manual (hardcopy)  
- Export laporan sertifikat ke Excel (Admin Only)

---

## 12. Responsivitas Dashboard Peserta

Dashboard peserta **sudah 100% responsif mobile**, dengan tata letak dinamis menggunakan Bootstrap 5 dan layout stacking otomatis, seperti ditunjukkan pada contoh berikut:

| Desktop | Mobile |
|----------|---------|
| <img width="2879" height="1248" alt="Screenshot 2025-10-21 105956" src="https://github.com/user-attachments/assets/fc1543cf-9e30-4457-ad3e-755544c06d8e" /> | <img width="659" height="1425" alt="Screenshot 2025-10-21 110047" src="https://github.com/user-attachments/assets/7b03cda1-b2b6-480c-9096-4fd590ede432" /> |

---

## 13. Kontributor
Seluruh proses perancangan dan pengembangan sistem ini, mulai dari **UI/UX Design, Front-End, Back-End, Database Architecture, hingga Dokumentasi Teknis dan Deployment** dikerjakan sepenuhnya oleh:

**Ferdy Salsabilla**  
*Full Stack Developer & System Architect*

---

## 14. Lisensi
Proyek ini dilisensikan di bawah **MIT License**.  
© 2025 RELIPROVE Certification Platform.

---
```
