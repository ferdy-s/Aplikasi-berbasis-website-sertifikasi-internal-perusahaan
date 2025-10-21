<?php
include '../config/koneksi.php';
session_start();

if (!isset($_SESSION['id_pengguna'])) {
    header("Location: ../login.php");
    exit;
}

$id_pengguna = $_SESSION['id_pengguna'];
$pengguna = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM pengguna WHERE id_pengguna = '$id_pengguna'"));

$pendaftaran = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM pendaftaran WHERE id_pengguna = '$id_pengguna' ORDER BY id_pendaftaran DESC LIMIT 1"));
$id_pendaftaran = $pendaftaran['id_pendaftaran'] ?? 0;

$penilaian = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM penilaian WHERE id_pendaftaran = '$id_pendaftaran'"));
$sertifikat = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sertifikat WHERE id_pendaftaran = '$id_pendaftaran'"));

$nama = htmlspecialchars($pengguna['nama_lengkap']);
$email_pengguna = '';
$nama_pengguna = '';

if (isset($_SESSION['id_pengguna'])) {
    $id = intval($_SESSION['id_pengguna']);
    $result = mysqli_query($conn, "SELECT nama_lengkap, email FROM pengguna WHERE id_pengguna = $id LIMIT 1");
    if ($row = mysqli_fetch_assoc($result)) {
        $nama_pengguna = $row['nama_lengkap'];
        $email_pengguna = $row['email'];
    }
}

?>


<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Pengaturan | RELIPROVE</title>
    <link rel="icon" href="../aset/img/logo.png" type="image/png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../peserta/css/pengaturan.css">
</head>

<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-title">
            <h2 class="sidebar-brand d-none d-md-block">RELIPROVE</h2> <!-- ❌ Hidden on mobile -->
            <button class="sidebar-close d-md-none" onclick="document.getElementById('sidebar').classList.remove('active')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <a href="dashboard_peserta.php"><i class="fas fa-gauge"></i> Dashboard</a>
        <a href="form_registrasi.php"><i class="fas fa-file-lines"></i> Form Registrasi</a>
        <a href="unggah_dokumen.php"><i class="fas fa-upload"></i> Upload Dokumen</a>
        <a href="status_asesmen.php"><i class="fas fa-clipboard-check"></i> Status Asesmen</a>
        <a href="sertifikat.php"><i class="fas fa-graduation-cap"></i> Sertifikat</a>
        <a href="pengaturan.php" class="active"><i class="fas fa-gear"></i> Pengaturan</a>
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Keluar</a>
    </div>

    <div class="content">
        <div class="topbar">
            <div class="left-group">
                <div class="toggle-sidebar" onclick="document.getElementById('sidebar').classList.toggle('active')">
                    <i class="fas fa-bars"></i>
                </div>
                <h4 class="d-none d-md-block" style="color: white">PENGATURAN</h4>
                <h4 class="d-md-none">PENGATURAN</h4>
            </div>
            <div class="right-group">
                <div class="user-info">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($nama) ?>&background=9b5eff&color=fff&rounded=true&size=40" alt="avatar">
                    <div class="user-meta">
                        <strong><?= htmlspecialchars($nama) ?></strong><br>
                        <small>Peserta</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- ====== PENGATURAN ====== -->
        <div class="content-settings">

            <?php
            // Ambil data user aktif (sesuaikan dengan sessionmu)
            $id_pengguna = $_SESSION['id_pengguna'] ?? null;
            // Contoh query ringkas (ganti $conn sesuai koneksimu)
            // $pengguna = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM pengguna WHERE id_pengguna=".$id_pengguna));
            // Placeholder jika belum query:
            $pengguna = $pengguna ?? [
                'nama_lengkap' => $nama ?? 'Pengguna',
                'email' => $_SESSION['email'] ?? 'mail@example.com',
            ];
            ?>

            <div class="settings-card">
                <div class="settings-title"><i class="fas fa-gear"></i> PENGATURAN</div>

                <!-- Tabs -->
                <div class="settings-tabs" id="settingsTabs">
                    <button class="settings-tab active" data-target="#panel-profil"><i class="fas fa-user-circle"></i> Profil</button>
                    <button class="settings-tab" data-target="#panel-aduan"><i class="fas fa-headset"></i> Aduan Pengguna</button>
                    <button class="settings-tab" data-target="#panel-keamanan"><i class="fas fa-shield-halved"></i> Keamanan</button>
                    <button class="settings-tab" data-target="#panel-bantuan"><i class="fas fa-circle-question"></i> Bantuan</button>
                </div>
                <style>
                    .faq-wrapper {
                        background: #1c1c1c;
                        border-radius: 10px;
                        border: 1px solid #2a2a2a;
                        overflow: hidden;
                        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
                    }

                    .faq-item+.faq-item {
                        border-top: 1px solid #222;
                    }

                    .faq-toggle {
                        width: 100%;
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        background: transparent;
                        color: var(--text-light);
                        padding: 1rem 1.25rem;
                        border: none;
                        font-weight: 600;
                        cursor: pointer;
                        font-size: 1rem;
                        transition: background 0.2s ease;
                    }

                    .faq-toggle:hover {
                        background: rgba(255, 255, 255, 0.05);
                    }

                    .faq-toggle i.fa-chevron-down {
                        transition: transform 0.2s ease;
                    }

                    .faq-toggle[aria-expanded="true"] i.fa-chevron-down {
                        transform: rotate(180deg);
                    }

                    .faq-content {
                        max-height: 0;
                        overflow: hidden;
                        transition: max-height 0.25s ease, padding 0.25s ease;
                        background: linear-gradient(180deg, rgba(155, 94, 255, 0.06), transparent);
                        padding: 0 1.25rem;
                    }

                    .faq-item.open .faq-content {
                        max-height: 500px;
                        padding: 0.75rem 1.25rem 1rem;
                    }
                </style>
                <!-- Panel: Profil -->
                <div class="settings-panel active" id="panel-profil">
                    <br>
                    <div class="panel-intro" style="max-width:700px;margin:0 auto;">
                        <i class="fas fa-user-circle"></i>
                        <div>
                            <h3>Profil Pengguna</h3>
                            <p>
                                Saat ini, informasi profil kamu bersifat <strong>hanya baca</strong> dan tidak dapat diubah langsung di halaman ini.
                            </p>
                        </div>
                    </div>

                    <!-- FAQ Panduan -->
                    <div class="faq-wrapper" style="max-width:700px;margin:1.5rem auto 0;">
                        <div class="faq-item">
                            <button class="faq-toggle" aria-expanded="false">
                                <span><i class="fas fa-info-circle"></i> Panduan Memperbarui Profil</span>
                                <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="faq-content">
                                <ol style="margin:0;padding-left:1.2rem;line-height:1.6;">
                                    <li>Masuk ke menu <strong>Pengaturan</strong> → <strong>Aduan Pengguna</strong>.</li>
                                    <li>Pada kolom <strong>Modul</strong>, pilih <em>Umum</em>.</li>
                                    <li>Pada kolom <strong>Prioritas</strong>, pilih <em>Normal</em>.</li>
                                    <li>Isi <strong>Judul</strong> dan <strong>Deskripsi Aduan</strong> (contoh: Permintaan Update Profil).</li>
                                    <li>Klik <strong>Kirim</strong> untuk mengirim aduan ke admin.</li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <br>
                    <form>
                        <div class="form-grid">
                            <div class="field">
                                <label>Nama Lengkap</label>
                                <input type="text" value="<?= htmlspecialchars($pengguna['nama_lengkap']) ?>" readonly>
                            </div>
                            <div class="field">
                                <label>Email</label>
                                <input type="email" value="<?= htmlspecialchars($pengguna['email']) ?>" readonly>
                            </div>
                            <div class="divider full"></div>

                            <div class="full" style="display:flex;gap:.6rem;margin-top:.5rem;justify-content:flex-end;">
                                <button class="btn-ghost" type="button" onclick="location.href='pengaturan.php'">
                                    <i class="fas fa-right-from-bracket"></i> Kembali
                                </button>
                                <button class="btn-primary"
                                    type="button"
                                    onclick="window.location.href='pengaturan.php?tab=aduan'"
                                    style="
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:.4rem;
        padding:.75rem 1.25rem;
        font-size:1rem;
        border-radius:8px;
        width:auto;
    "
                                    id="btn-perbarui">
                                    <i class="fas fa-headset"></i> Admin
                                </button>

                            </div>
                        </div>
                    </form>

                </div>

                <!-- Panel: Aduan Pengguna -->
                <div class="settings-panel" id="panel-aduan">
                    <br>
                    <div class="panel-intro">
                        <i class="fas fa-headset"></i>
                        <div>
                            <h3>Aduan Pengguna</h3>
                            <p>Isi Form di bawah untuk mengajukan aduan anda.</p>
                        </div>
                    </div>
                    <br>
                    <form id="formAduan">
                        <div class="form-grid">
                            <div class="field half">
                                <label>Modul</label>
                                <select id="modul">
                                    <option value="Umum">Umum</option>
                                    <option value="Registrasi">Registrasi</option>
                                    <option value="Unggah Dokumen">Unggah Dokumen</option>
                                    <option value="Asesmen">Asesmen</option>
                                    <option value="Sertifikat">Sertifikat</option>
                                    <option value="Dashboard">Dashboard</option>
                                </select>
                            </div>
                            <div class="field half">
                                <label>Prioritas</label>
                                <select id="prioritas">
                                    <option value="Normal">Normal</option>
                                    <option value="Tinggi">Tinggi</option>
                                    <option value="Kritis">Kritis</option>
                                </select>
                            </div>

                            <div class="field full">
                                <label>Judul Aduan</label>
                                <input type="text" id="judul" placeholder="Contoh: Tidak bisa unggah dokumen" required>
                            </div>
                            <div class="field full">
                                <label>Deskripsi</label>
                                <textarea id="deskripsi" rows="5" placeholder="Jelaskan kendala yang kamu hadapi..." required></textarea>
                            </div>

                            <div class="full" style="display:flex;gap:.6rem;margin-top:.5rem;justify-content:flex-end;">
                                <button class="btn-ghost" type="button" onclick="location.href='pengaturan.php'">
                                    <i class="fas fa-right-from-bracket"></i> Batal
                                </button>
                                <button class="btn-primary" type="submit">
                                    <i class="fas fa-paper-plane"></i> Kirim
                                </button>
                            </div>
                        </div>
                    </form>
                </div>




                <!-- Panel: Keamanan -->

                <div class="settings-panel" id="panel-keamanan">
                    <br>
                    <div class="panel-intro">
                        <i class="fas fa-shield-halved"></i>
                        <div>
                            <h3>Keamanan Pengguna</h3>
                            <p>Isi Form di bawah untuk memperbarui informasi keamanan anda.</p>
                        </div>
                    </div>
                    <br>
                    <form action="../peserta/ganti_password.php" method="post">
                        <div class="form-grid">
                            <!-- Password Lama -->
                            <div class="field password-field">
                                <label>Password Saat Ini</label>
                                <div class="password-wrapper">
                                    <input type="password" name="password_lama" id="password_lama" required>
                                    <i class="fas fa-eye toggle-password" data-target="password_lama" aria-label="Tampilkan/samarkan password" role="button" tabindex="0"></i>
                                </div>
                            </div>

                            <!-- Password Baru -->
                            <div class="field">
                                <label>Password Baru</label>
                                <div class="password-wrapper">
                                    <input type="password" name="password_baru" id="password_baru" required>
                                    <i class="fas fa-eye toggle-password" data-target="password_baru" aria-label="Tampilkan/samarkan password" role="button" tabindex="0"></i>
                                </div>
                            </div>

                            <!-- Konfirmasi Password Baru -->
                            <div class="field">
                                <label>Konfirmasi Password Baru</label>
                                <div class="password-wrapper">
                                    <input type="password" name="password_konfirmasi" id="password_konfirmasi" required>
                                    <i class="fas fa-eye toggle-password" data-target="password_konfirmasi" aria-label="Tampilkan/samarkan password" role="button" tabindex="0"></i>
                                </div>
                            </div>

                            <div class="full" style="display:flex;gap:.6rem;margin-top:.5rem;justify-content:flex-end;">
                                <button class="btn-ghost" type="button" onclick="location.href='pengaturan.php'">
                                    <i class="fas fa-right-from-bracket"></i> Batal
                                </button>
                                <button class="btn-primary" type="submit">
                                    <i class="fas fa-key"></i> Update
                                </button>
                            </div>
                        </div>
                    </form>
                </div>





                <!-- Panel: Bantuan -->
                <div class="settings-panel" id="panel-bantuan">
                    <br>
                    <div class="panel-intro">
                        <i class="fas fa-circle-question"></i>
                        <div>
                            <h3>Bantuan & Dukungan</h3>
                            <p>Klik dropdown di bawah untuk melihat panduan, FAQ, dan kontak resmi.</p>
                        </div>
                    </div>

                    <!-- Accordion utama -->
                    <div class="bantuan-accordion" id="bantuanAccordion">

                        <!-- FAQ Lengkap -->
                        <div class="faq-item">
                            <button class="faq-toggle" aria-expanded="false">
                                <span><i class="fas fa-question-circle"></i> FAQ Lengkap</span>
                                <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="faq-content">
                                <ul class="faq-list">
                                    <li><b>Gagal unggah dokumen?</b> Pastikan ukuran &lt; 10MB, format sesuai (JPG/PNG/PDF), dan link Google Drive publik.</li>
                                    <li><b>Status asesmen tidak muncul?</b> Pastikan verifikasi dokumen “diterima” dan refresh dashboard.</li>
                                    <li><b>Lupa password?</b> Gunakan menu <em>Keamanan → Ganti Password</em>.</li>
                                    <li><b>Privasi nama di sertifikat?</b> Atur di menu <em>Bahasa & Privasi</em> → Visibilitas Nama.</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Kontak & Dukungan -->
                        <div class="faq-item">
                            <button class="faq-toggle" aria-expanded="false">
                                <span><i class="fas fa-headset"></i> Kontak & Dukungan</span>
                                <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="faq-content">
                                <ul class="faq-list">
                                    <li><b>Email:</b> <a href="mailto:support@reliprove.com">support@reliprove.com</a></li>
                                    <li><b>Jam Layanan:</b> Sen–Jum, 09.00–18.00 WIB</li>
                                    <li><b>Pusat Aduan:</b> Gunakan menu <em>Aduan Pengguna</em> di dashboard untuk keluhan resmi.</li>
                                    <li><b>Verifikasi Sertifikat:</b> <code>https://reliprove.com/verifikasi.php</code></li>
                                </ul>
                            </div>
                        </div>

                    </div>
                </div>


            </div>
        </div>

        <script>
            // Tab handler
            (function() {
                const tabs = document.querySelectorAll('.settings-tab');
                const panels = document.querySelectorAll('.settings-panel');
                tabs.forEach(tab => {
                    tab.addEventListener('click', () => {
                        tabs.forEach(t => t.classList.remove('active'));
                        panels.forEach(p => p.classList.remove('active'));
                        tab.classList.add('active');
                        const target = document.querySelector(tab.dataset.target);
                        target && target.classList.add('active');
                    });
                });

                // Auto-aktifkan tab berdasarkan hash (opsional: ?tab=keamanan)
                const params = new URLSearchParams(location.search);
                const tabParam = params.get('tab');
                if (tabParam) {
                    const btn = document.querySelector(`.settings-tab[data-target="#panel-${tabParam}"]`);
                    if (btn) btn.click();
                }
            })();
        </script>


        <div class="footer">
            &copy; <?= date('Y') ?> Created by PT. Reliable Future Technology
        </div>
    </div>

    <script>
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.toggle-sidebar');
            if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });
    </script>
    <script>
        document.querySelectorAll('#bantuanAccordion .faq-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const item = btn.closest('.faq-item');
                const content = item.querySelector('.faq-content');
                const expanded = btn.getAttribute('aria-expanded') === 'true';

                // Tutup semua
                document.querySelectorAll('#bantuanAccordion .faq-item').forEach(sib => {
                    sib.classList.remove('open');
                    sib.querySelector('.faq-toggle').setAttribute('aria-expanded', 'false');
                    sib.querySelector('.faq-content').style.maxHeight = null;
                });

                if (!expanded) {
                    item.classList.add('open');
                    content.style.maxHeight = content.scrollHeight + 'px';
                    btn.setAttribute('aria-expanded', 'true');
                }
            });
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.toggle-password').forEach(function(icon) {
                function toggle() {
                    var targetId = icon.getAttribute('data-target');
                    var input = document.getElementById(targetId);
                    if (!input) return;
                    var isPass = input.type === 'password';
                    input.type = isPass ? 'text' : 'password';
                    icon.classList.toggle('fa-eye');
                    icon.classList.toggle('fa-eye-slash');
                }
                icon.addEventListener('click', toggle);
                icon.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        toggle();
                    }
                });
            });
        });
    </script>
    <script>
        const NAMA_PESERTA = "<?= htmlspecialchars($nama_pengguna) ?>";
        const EMAIL_PESERTA = "<?= htmlspecialchars($email_pengguna) ?>";

        function nowWIB() {
            return new Date().toLocaleString('id-ID', {
                timeZone: 'Asia/Jakarta',
                hour12: false,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            }) + " WIB";
        }

        document.getElementById("formAduan").addEventListener("submit", function(e) {
            e.preventDefault();

            const judul = document.getElementById("judul").value.trim();
            const deskripsi = document.getElementById("deskripsi").value.trim();
            const modul = document.getElementById("modul").value;
            const prioritas = document.getElementById("prioritas").value;

            if (!judul || !deskripsi) {
                alert("Harap isi judul dan deskripsi sebelum mengirim.");
                return;
            }

            const waktu = nowWIB();
            const halaman = window.location.href;
            const agent = navigator.userAgent;

            const message = [
                "*ADUAN RELIPROVE*",
                "",
                "*Identitas*",
                `* Nama: ${NAMA_PESERTA || "—"}`,
                `* Email: ${EMAIL_PESERTA || "—"}`,
                "",
                "*Rangkuman Aduan*",
                `* Modul: ${modul}`,
                `* Prioritas: ${prioritas}`,
                `* Judul: ${judul}`,
                "",
                "*Deskripsi Detail*",
                deskripsi,
                "",
                "*Konteks Teknis*",
                `* Waktu: ${waktu}`,
                `* Halaman: ${halaman}`,
                `* Browser/OS: ${agent}`,
                "",
                "*Harapan Penanganan*",
                "* Mohon verifikasi masalah dan info langkah perbaikan.",
                "* Jika perlu data tambahan (screenshot/log), beri tahu saya."
            ].join("\n");

            const phone = "6282134027993";
            const url = "https://wa.me/" + phone + "?text=" + encodeURIComponent(message);
            window.open(url, "_blank");
        });
    </script>
    <script>
        document.querySelectorAll('.faq-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const parent = btn.closest('.faq-item');
                const expanded = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', !expanded);
                parent.classList.toggle('open', !expanded);
            });
        });
    </script>
</body>

</html>