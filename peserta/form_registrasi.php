<?php
ob_start();
session_start();
include __DIR__ . '/../config/koneksi.php';

if (!isset($_SESSION['id_pengguna']) || $_SESSION['peran'] !== 'peserta') {
    header("Location: ../login.php");
    exit;
}

$id = $_SESSION['id_pengguna'];
$nama = $_SESSION['nama_lengkap'];

// Reset data hanya jika ?ulang=1
if (isset($_GET['ulang']) && $_GET['ulang'] == 1) {
    mysqli_query($conn, "DELETE FROM pendaftaran WHERE id_pengguna = '$id'");
    mysqli_query($conn, "ALTER TABLE pendaftaran AUTO_INCREMENT = 1");
    $_SESSION['pesan'] = "Silakan isi ulang data kamu.";
    header("Location: form_registrasi.php");
    exit;
}



if (isset($_POST['pendidikan'])) {
    $pendidikan = mysqli_real_escape_string($conn, $_POST['pendidikan']);
    mysqli_query($conn, "UPDATE pengguna SET pendidikan = '$pendidikan' WHERE id_pengguna = '$id'");
}

$cek_form = mysqli_query($conn, "SELECT * FROM pendaftaran WHERE id_pengguna = '$id'");
$sudah_mendaftar = mysqli_num_rows($cek_form) > 0;
$bidang = mysqli_query($conn, "SELECT id_bidang, nama_bidang FROM bidang");
?>


<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Form Registrasi | RELIPROVE</title>
    <link rel="icon" href="../aset/img/logo.png" type="image/png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../peserta/css/form_regis.css">

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
        <a href="#" class="active"><i class="fas fa-file-lines"></i> Form Registrasi</a>
        <a href="unggah_dokumen.php"><i class="fas fa-upload"></i> Upload Dokumen</a>
        <a href="status_asesmen.php"><i class="fas fa-clipboard-check"></i> Status Asesmen</a>
        <a href="sertifikat.php"><i class="fas fa-graduation-cap"></i> Sertifikat</a>
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Keluar</a>
    </div>

    <div class="content">
        <div class="topbar">
            <div class="left-group">
                <div class="toggle-sidebar" onclick="document.getElementById('sidebar').classList.toggle('active')">
                    <i class="fas fa-bars"></i>
                </div>
                <h4 class="d-none d-md-block" style="color: white">REGISTRASI</h4>
                <h4 class="d-md-none">REGISTRASI</h4>
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
        <div class="main-content">
            <?php if ($sudah_mendaftar):
                $data = mysqli_fetch_assoc($cek_form);

                $get_posisi = mysqli_query($conn, "SELECT p.nama_posisi, k.nama_kategori, b.nama_bidang 
        FROM posisi p
        JOIN kategori k ON p.id_kategori = k.id_kategori
        JOIN bidang b ON k.id_bidang = b.id_bidang
        WHERE p.id_posisi = '{$data['id_posisi']}'");
                $posisi = mysqli_fetch_assoc($get_posisi);

                // Ambil data asal peserta dari tabel pengguna
                $ambil_pengguna = mysqli_query($conn, "SELECT asal_peserta, nama_instansi, no_identitas FROM pengguna WHERE id_pengguna = '$id'");
                $akun = mysqli_fetch_assoc($ambil_pengguna);
            ?>
                <div class="card-pendaftaran">
                    <h3>DATA REGISTRASI KAMU</h3>
                    <div class="info-group"><span class="label">Nama :</span> <?= htmlspecialchars($nama) ?></div>
                    <div class="info-group"><span class="label">Asal Peserta :</span> <?= ucfirst($akun['asal_peserta']) ?></div>

                    <?php if ($akun['asal_peserta'] !== 'internal'): ?>
                        <div class="info-group"><span class="label"><?= $akun['asal_peserta'] === 'magang' ? 'Nama Kampus / Sekolah' : 'Nama Perusahaan' ?> :</span> <?= htmlspecialchars($akun['nama_instansi']) ?></div>
                    <?php else: ?>
                        <div class="info-group"><span class="label">Nama Perusahaan :</span> PT. Reliable Future Technology</div>
                    <?php endif; ?>

                    <div class="info-group"><span class="label"><?= $akun['asal_peserta'] === 'magang' ? 'NIM / NIS' : 'NIK / No. Karyawan' ?>:</span> <?= htmlspecialchars($akun['no_identitas']) ?></div>

                    <div class="info-group"><span class="label">Departemen :</span> <?= $posisi['nama_bidang'] ?></div>
                    <div class="info-group"><span class="label">Divisi :</span> <?= $posisi['nama_kategori'] ?></div>
                    <div class="info-group"><span class="label">Posisi :</span> <?= $posisi['nama_posisi'] ?></div>
                    <div class="info-group"><span class="label">Timeline Proyek :</span> <a href="<?= $data['link_timeline'] ?>" target="_blank"><?= $data['link_timeline'] ?></a></div>
                    <div class="info-group"><span class="label">Jobdesk :</span> <a href="<?= $data['link_jobdesk'] ?>" target="_blank"><?= $data['link_jobdesk'] ?></a></div>
                    <?php if (!empty($data['link_portofolio'])): ?>
                        <div class="info-group"><span class="label">Portofolio :</span> <a href="<?= $data['link_portofolio'] ?>" target="_blank"><?= $data['link_portofolio'] ?></a></div>
                    <?php endif; ?>
                    <div class="info-group"><span class="label">Tanggal Daftar :</span> <?= date('d M Y H:i', strtotime($data['tanggal_daftar'])) ?></div>
                    <div class="status">
                        <span>Status Verifikasi : <strong><?= ucfirst($data['status_verifikasi']) ?></strong></span><br>
                        <span>Status Asesmen : <strong><?= ucfirst($data['status_penilaian']) ?></strong></span>
                    </div>

                    <div style="text-align:center; margin-top: 2rem;">
                        <a href="form_registrasi.php?ulang=1" onclick="return confirm('Apakah kamu yakin ingin mengisi ulang data? Data sebelumnya akan dihapus.')"
                            class="btn btn-sm" style="padding: 0.65rem 1.6rem; border-radius: 10px; background-color: var(--primary); color: white; font-weight: 600; border: none;">
                            Isi Ulang Data
                        </a>
                    </div>
                </div>
            <?php else: ?>



                <div class="form-wrapper">
                    <h3>FORM REGISTRASI SERTIFIKASI</h3>
                    <form action="simpan_registrasi.php" method="POST" id="form-registrasi">

                        <div class="mb-3">
                            <div class="mb-3">
                                <label class="form-label">Nama</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($nama) ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Asal Peserta</label>
                                <div class="d-flex gap-3 flex-wrap">
                                    <label><input type="radio" name="asal_peserta" value="internal" checked> Internal</label>
                                    <label><input type="radio" name="asal_peserta" value="eksternal"> Eksternal</label>
                                    <label><input type="radio" name="asal_peserta" value="magang"> Magang</label>
                                </div>
                            </div>

                            <div id="instansi-wrapper" class="mb-3">
                                <label class="form-label" id="label-instansi">Nama Perusahaan</label>
                                <input type="text" class="form-control" name="nama_instansi" id="nama_instansi" placeholder="PT ABC / Universitas XYZ">
                            </div>

                            <div class="mb-3">
                                <label class="form-label" id="label-identitas">NIK / No. Karyawan</label>
                                <input type="text" class="form-control" name="no_identitas" id="no_identitas" placeholder="Masukkan nomor identitas">
                            </div>

                            <script>
                                document.querySelectorAll('input[name="asal_peserta"]').forEach(function(el) {
                                    el.addEventListener('change', function() {
                                        const value = this.value;
                                        const instansiWrapper = document.getElementById('instansi-wrapper');
                                        const labelInstansi = document.getElementById('label-instansi');
                                        const labelIdentitas = document.getElementById('label-identitas');
                                        const namaInstansi = document.getElementById('nama_instansi');
                                        const noIdentitas = document.getElementById('no_identitas');

                                        if (value === 'internal') {
                                            instansiWrapper.style.display = 'none';
                                            namaInstansi.value = 'PT. Reliable Future Technology';
                                            labelIdentitas.innerText = 'NIK / No. Karyawan';
                                        } else if (value === 'eksternal') {
                                            instansiWrapper.style.display = 'block';
                                            labelInstansi.innerText = 'Nama Perusahaan';
                                            labelIdentitas.innerText = 'NIK / No. Karyawan';
                                            namaInstansi.value = '';
                                        } else {
                                            instansiWrapper.style.display = 'block';
                                            labelInstansi.innerText = 'Nama Kampus / Sekolah';
                                            labelIdentitas.innerText = 'NIM / NIS';
                                            namaInstansi.value = '';
                                        }
                                    });
                                });

                                // Trigger default
                                document.querySelector('input[name="asal_peserta"]:checked').dispatchEvent(new Event('change'));
                            </script>


                            <div class="mb-3">
                                <label class="form-label">Pendidikan Terakhir</label>
                                <input type="text" class="form-control" name="pendidikan" placeholder="Contoh: S1 Teknik Informatika" required>
                            </div>

                            <label class="form-label">Pilih Bidang</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <?php while ($b = mysqli_fetch_assoc($bidang)): ?>
                                    <button type="button" class="btn btn-outline-light bidang-btn" data-bidang="<?= $b['id_bidang'] ?>">
                                        <?= $b['nama_bidang'] ?>
                                    </button>
                                <?php endwhile; ?>

                            </div>
                        </div>
                        <div id="kategori-wrapper" class="mb-3" style="display:none;">
                            <label for="kategori" class="form-label">Pilih Kategori</label>
                            <select name="id_kategori" id="kategori" class="form-control" required disabled>
                                <option value="">-- Pilih Kategori --</option>
                            </select>
                        </div>

                        <div id="posisi-wrapper" class="mb-3" style="display:none;">
                            <label for="posisi" class="form-label">Pilih Posisi</label>
                            <select name="id_posisi" id="posisi" class="form-control" required disabled>
                                <option value="">-- Pilih Posisi --</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="timeline" class="form-label">Link Timeline Proyek</label>
                            <input type="url" class="form-control" name="link_timeline" id="timeline" required>
                        </div>

                        <div class="mb-3">
                            <label for="jobdesk" class="form-label">Link Jobdesk</label>
                            <input type="url" class="form-control" name="link_jobdesk" id="jobdesk" required>
                        </div>

                        <div class="mb-4">
                            <label for="portofolio" class="form-label">Link Portofolio (opsional)</label>
                            <input type="url" class="form-control" name="link_portofolio" id="portofolio">
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Kirim</button>

                    </form>
                </div>
            <?php endif; ?>
        </div>
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
        document.getElementById('kategori').addEventListener('change', function() {
            const id_kategori = this.value;
            const posisiSelect = document.getElementById('posisi');
            posisiSelect.innerHTML = '<option value="">Memuat posisi...</option>';

            fetch('get_posisi.php?id_kategori=' + id_kategori)
                .then(res => res.json())
                .then(data => {
                    posisiSelect.innerHTML = '<option value="">-- Pilih Posisi --</option>';
                    data.forEach(posisi => {
                        const opt = document.createElement('option');
                        opt.value = posisi.id_posisi;
                        opt.textContent = posisi.nama_posisi;
                        posisiSelect.appendChild(opt);
                    });
                })
                .catch(err => {
                    posisiSelect.innerHTML = '<option value="">Gagal memuat posisi</option>';
                });
        });

        let bidangAktif = '';

        document.querySelectorAll('.bidang-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                bidangAktif = btn.dataset.bidang;

                // Reset semua tombol
                document.querySelectorAll('.bidang-btn').forEach(b => {
                    b.classList.remove('btn-primary');
                    b.classList.add('btn-outline-light');
                });

                // Aktifkan tombol yang diklik
                btn.classList.remove('btn-outline-light');
                btn.classList.add('btn-primary');

                // Ambil kategori dari bidang
                fetch('get_kategori.php?bidang=' + bidangAktif)
                    .then(res => res.json())
                    .then(data => {
                        const kategori = document.getElementById('kategori');
                        kategori.innerHTML = '<option value="">-- Pilih Kategori --</option>';
                        data.forEach(k => {
                            const opt = document.createElement('option');
                            opt.value = k.id_kategori;
                            opt.textContent = k.nama_kategori;
                            kategori.appendChild(opt);
                        });

                        kategori.disabled = false;
                        document.getElementById('kategori-wrapper').style.display = 'block';
                        document.getElementById('posisi-wrapper').style.display = 'none';
                    });
            });
        });

        document.getElementById('kategori').addEventListener('change', function() {
            const id_kategori = this.value;
            const posisiSelect = document.getElementById('posisi');

            fetch('get_posisi.php?id_kategori=' + id_kategori)
                .then(res => res.json())
                .then(data => {
                    posisiSelect.innerHTML = '<option value="">-- Pilih Posisi --</option>';
                    data.forEach(posisi => {
                        const opt = document.createElement('option');
                        opt.value = posisi.id_posisi;
                        opt.textContent = posisi.nama_posisi;
                        posisiSelect.appendChild(opt);
                    });

                    posisiSelect.disabled = false;
                    document.getElementById('posisi-wrapper').style.display = 'block';
                });
        });
    </script>
    <script>
        document.querySelectorAll('.bidang-btn').forEach(button => {
            button.addEventListener('click', function() {
                // Hapus semua kelas active dulu
                document.querySelectorAll('.bidang-btn').forEach(btn => btn.classList.remove('active'));

                // Tambahkan active ke tombol yang diklik
                this.classList.add('active');

                // Optional: isi input hidden untuk backend
                const bidangInput = document.querySelector('#input_bidang');
                if (bidangInput) {
                    bidangInput.value = this.dataset.bidang;
                }
            });
        });
    </script>

</body>

</html>