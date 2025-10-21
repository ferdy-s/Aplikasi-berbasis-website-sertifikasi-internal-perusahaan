<?php
// pengaturan.php — Dashboard Asesor (RELIPROVE)
if (session_status() === PHP_SESSION_NONE) session_start();

// ====== Proteksi Login & Role ======
if (!isset($_SESSION['id_pengguna'])) {
    header('Location: ../login.php');
    exit;
}
if (!isset($_SESSION['peran']) || $_SESSION['peran'] !== 'asesor') {
    http_response_code(403);
    echo "Akses ditolak.";
    exit;
}

// ====== Koneksi DB ======
require_once __DIR__ . '/../config/koneksi.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo "Koneksi database tidak tersedia.";
    exit;
}

// ====== CSRF Token ======
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function verify_csrf()
{
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        throw new Exception("CSRF token tidak valid.");
    }
}

// ====== Ambil Data Asesor Login ======
$id_pengguna = (int) $_SESSION['id_pengguna'];
$stmt = $conn->prepare("SELECT id_pengguna, nama_lengkap, email, sandi, pendidikan, asal_peserta, nama_instansi, no_identitas FROM pengguna WHERE id_pengguna = ? LIMIT 1");
$stmt->bind_param("i", $id_pengguna);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows < 1) {
    http_response_code(404);
    echo "Pengguna tidak ditemukan.";
    exit;
}
$user = $res->fetch_assoc();
$stmt->close();

$flash = ['type' => null, 'msg' => null];
function set_flash($type, $msg)
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    // redirect agar PRG pattern, cegah resubmit
    header("Location: pengaturan.php");
    exit;
}
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// ====== Helpers Validasi ======
function clean($str)
{
    return trim($str ?? '');
}
function validate_password_strength($pwd)
{
    // Minimal 8, ada huruf besar, huruf kecil, angka
    $len = strlen($pwd) >= 8;
    $upper = preg_match('/[A-Z]/', $pwd);
    $lower = preg_match('/[a-z]/', $pwd);
    $digit = preg_match('/[0-9]/', $pwd);
    return $len && $upper && $lower && $digit;
}

// ====== Handle POST: Update Profil ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    try {
        verify_csrf();

        $nama_lengkap = clean($_POST['nama_lengkap'] ?? '');
        $pendidikan   = clean($_POST['pendidikan'] ?? '');
        $asal_peserta = clean($_POST['asal_peserta'] ?? 'internal'); // enum: internal, eksternal, magang
        $nama_instansi = clean($_POST['nama_instansi'] ?? '');
        $no_identitas = clean($_POST['no_identitas'] ?? '');

        if ($nama_lengkap === '') {
            throw new Exception("Nama lengkap wajib diisi.");
        }
        if (!in_array($asal_peserta, ['internal', 'eksternal', 'magang'], true)) {
            throw new Exception("Asal peserta tidak valid.");
        }
        // Batasan panjang dasar untuk cegah input berlebihan
        if (mb_strlen($nama_lengkap) > 100 || mb_strlen($pendidikan) > 100 || mb_strlen($nama_instansi) > 100 || mb_strlen($no_identitas) > 50) {
            throw new Exception("Panjang data melebihi batas yang diizinkan.");
        }

        $stmt = $conn->prepare("UPDATE pengguna 
            SET nama_lengkap = ?, pendidikan = ?, asal_peserta = ?, nama_instansi = ?, no_identitas = ?
            WHERE id_pengguna = ? LIMIT 1");
        $stmt->bind_param("sssssi", $nama_lengkap, $pendidikan, $asal_peserta, $nama_instansi, $no_identitas, $id_pengguna);
        if (!$stmt->execute()) {
            throw new Exception("Gagal menyimpan perubahan profil.");
        }
        $stmt->close();

        // Refresh data user terkini
        $_SESSION['nama_lengkap'] = $nama_lengkap;
        set_flash('success', 'Profil berhasil diperbarui.');
    } catch (Exception $e) {
        set_flash('danger', $e->getMessage());
    }
}

// ====== Handle POST: Ganti Password ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    try {
        verify_csrf();

        $old = $_POST['password_lama'] ?? '';
        $new = $_POST['password_baru'] ?? '';
        $rep = $_POST['password_konfirmasi'] ?? '';

        if ($old === '' || $new === '' || $rep === '') {
            throw new Exception("Semua field password wajib diisi.");
        }
        if (!password_verify($old, $user['sandi'])) {
            throw new Exception("Password lama tidak sesuai.");
        }
        if ($new !== $rep) {
            throw new Exception("Konfirmasi password tidak cocok.");
        }
        if (!validate_password_strength($new)) {
            throw new Exception("Password baru minimal 8 karakter, mengandung huruf besar, huruf kecil, dan angka.");
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE pengguna SET sandi = ? WHERE id_pengguna = ? LIMIT 1");
        $stmt->bind_param("si", $hash, $id_pengguna);
        if (!$stmt->execute()) {
            throw new Exception("Gagal mengubah password.");
        }
        $stmt->close();

        set_flash('success', 'Password berhasil diubah.');
    } catch (Exception $e) {
        set_flash('danger', $e->getMessage());
    }
}

// ====== Variabel untuk Header Avatar/Identitas ======
$nama = $user['nama_lengkap'] ?: 'Asesor';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Pengaturan | Dashboard Asesor | RELIPROVE</title>
    <link rel="icon" href="../aset/img/logo.png" type="image/png">

    <!-- Library umum -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

    <link rel="stylesheet" href="../asesor/css/dashboard_asesor.css">
    <style>
        /* Sedikit penyesuaian lokal untuk halaman Pengaturan */
        .settings-card {
            background: var(--bg-card);
            border: 1px solid rgba(139, 93, 255, 0.25);
            border-radius: 16px;
            box-shadow: var(--shadow-card);
        }

        .form-control,
        .form-select {
            background: #0f131b;
            color: var(--text);
            border: 1px solid #272c36;
            border-radius: 10px;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(155, 94, 255, .15);
            color: #fff;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            border-radius: 10px;
            font-weight: 600;
        }

        .btn-outline-light {
            border-radius: 10px;
        }

        .section-title {
            color: var(--text-light);
            font-weight: 700;
        }

        .muted {
            color: var(--text-muted);
        }

        .help-text {
            font-size: .85rem;
            color: var(--muted);
        }

        .divider {
            height: 1px;
            background: var(--border-light);
            margin: 1rem 0 1.25rem;
        }

        .badge-role {
            background: rgba(155, 94, 255, 0.15);
            color: #eae6ff;
            border: 1px solid rgba(139, 93, 255, 0.45);
            border-radius: 999px;
            padding: .35rem .6rem;
            font-weight: 700;
            letter-spacing: .3px;
        }

        .form-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        @media (max-width: 768px) {
            .form-2col {
                grid-template-columns: 1fr;
            }
        }

        .password-visibility {
            cursor: pointer;
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            opacity: .8;
        }

        .input-with-icon {
            position: relative;
        }
    </style>
</head>

<body>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-title">
            <h2 class="sidebar-brand d-none d-md-block text-center" style="margin-top: 37px; margin-bottom: 50px;">RELIPROVE</h2>
            <button class="sidebar-close d-md-none" onclick="document.getElementById('sidebar').classList.remove('active')">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <a href="dashboard_asesor.php"><i class="fas fa-gauge"></i> Dashboard</a>
        <a href="bank_soal.php"><i class="fa-solid fa-book-open"></i> Bank Soal</a>
        <a href="daftar_penilaian.php"><i class="fas fa-clipboard-list"></i> Daftar Penilaian</a>
        <a href="riwayat_penilaian.php"><i class="fas fa-history"></i> Riwayat Penilaian</a>
        <!-- <a href="sertifikat.php"><i class="fas fa-graduation-cap"></i> Sertifikat</a> -->
        <a href="notifikasi.php"><i class="fas fa-bell"></i> Notifikasi</a>
        <a href="pengaturan.php" class="active"><i class="fas fa-gear"></i> Pengaturan</a>
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Keluar</a>
    </div>

    <?php
    // Jika kamu pakai partial sidebar_asesor.php, bisa tetap di-include (opsional, jangan dobel output link)
    // include __DIR__ . '/partials/sidebar_asesor.php';
    ?>

    <!-- CONTENT -->
    <div class="content">
        <!-- TOPBAR -->
        <div class="topbar">
            <div class="left-group" style="display:flex; align-items:center; gap:10px;">
                <div class="toggle-sidebar" onclick="document.getElementById('sidebar').classList.toggle('active')" style="cursor:pointer; display:flex; align-items:center;">
                    <i class="fas fa-bars"></i>
                </div>
                <h4 class="d-none d-md-block" style="margin:0; font-weight:600;">PENGATURAN</h4>
                <h4 class="d-md-none" style="margin:0; font-weight:600;">RELIPROVE</h4>
            </div>

            <div class="right-group">
                <div class="user-info">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($nama) ?>&background=9b5eff&color=fff&rounded=true&size=40" alt="avatar" width="40" height="40">
                    <div class="user-meta">
                        <strong><?= htmlspecialchars($nama) ?></strong><br>
                        <small class="text-capitalize">Asesor</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- FLASH -->
        <?php if ($flash['type'] && $flash['msg']): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert" style="border-radius:12px;">
                <?= htmlspecialchars($flash['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
            </div>
        <?php endif; ?>

        <!-- PROFILE CARD -->
        <div class="settings-card p-4 mb-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                    <h5 class="section-title mb-1"><i class="fa-solid fa-user-gear me-2"></i> Pengaturan Profil</h5>
                    <div class="help-text">Perbarui detail profil yang akan tampil di sistem penilaian.</div>
                </div>
                <span class="badge-role"><i class="fa-solid fa-shield-halved me-1"></i> ASESOR</span>
            </div>
            <div class="divider"></div>

            <form method="post" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="update_profile">

                <div class="mb-3">
                    <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                    <input type="text" name="nama_lengkap" class="form-control" maxlength="100" required
                        value="<?= htmlspecialchars($user['nama_lengkap'] ?? '') ?>">
                </div>

                <div class="form-2col">
                    <div class="mb-3">
                        <label class="form-label">Pendidikan</label>
                        <input type="text" name="pendidikan" class="form-control" maxlength="100"
                            placeholder="Mis. S1 - Informatika"
                            value="<?= htmlspecialchars($user['pendidikan'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Asal Peserta</label>
                        <select name="asal_peserta" class="form-select">
                            <?php
                            $asal_list = ['internal' => 'Internal', 'eksternal' => 'Eksternal', 'magang' => 'Magang'];
                            $asal_val = $user['asal_peserta'] ?? 'internal';
                            foreach ($asal_list as $val => $label):
                            ?>
                                <option value="<?= $val ?>" <?= $asal_val === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text mt-1">Informasi ini berguna untuk pelaporan internal.</div>
                    </div>
                </div>

                <div class="form-2col">
                    <div class="mb-3">
                        <label class="form-label">Nama Instansi</label>
                        <input type="text" name="nama_instansi" class="form-control" maxlength="100"
                            placeholder="Mis. PT. Reliable Future Technology"
                            value="<?= htmlspecialchars($user['nama_instansi'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">No. Identitas</label>
                        <input type="text" name="no_identitas" class="form-control" maxlength="50"
                            placeholder="NIK/NIP/NIM/NIS"
                            value="<?= htmlspecialchars($user['no_identitas'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Email (hanya-baca)</label>
                    <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                    <div class="help-text mt-1">Hubungi admin jika perlu mengubah email.</div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-2"></i>Simpan Perubahan</button>
                    <a href="dashboard_asesor.php" class="btn btn-outline-light"><i class="fa-solid fa-arrow-left-long me-2"></i>Kembali</a>
                </div>
            </form>
        </div>

        <!-- PASSWORD CARD -->
        <div class="settings-card p-4 mb-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                    <h5 class="section-title mb-1"><i class="fa-solid fa-lock me-2"></i> Keamanan & Password</h5>
                    <div class="help-text">Gunakan password kuat: minimal 8 karakter, kombinasi huruf besar, kecil, dan angka.</div>
                </div>
            </div>
            <div class="divider"></div>

            <form method="post" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="change_password">

                <div class="form-2col">
                    <div class="mb-3 input-with-icon">
                        <label class="form-label">Password Lama <span class="text-danger">*</span></label>
                        <input type="password" name="password_lama" class="form-control" minlength="8" required id="pwd_old">
                        <span class="password-visibility" onclick="togglePwd('pwd_old')"><i class="fa-regular fa-eye"></i></span>
                    </div>
                    <div class="mb-3 input-with-icon">
                        <label class="form-label">Password Baru <span class="text-danger">*</span></label>
                        <input type="password" name="password_baru" class="form-control" minlength="8" required id="pwd_new">
                        <span class="password-visibility" onclick="togglePwd('pwd_new')"><i class="fa-regular fa-eye"></i></span>
                    </div>
                </div>

                <div class="mb-3 input-with-icon">
                    <label class="form-label">Konfirmasi Password Baru <span class="text-danger">*</span></label>
                    <input type="password" name="password_konfirmasi" class="form-control" minlength="8" required id="pwd_rep">
                    <span class="password-visibility" onclick="togglePwd('pwd_rep')"><i class="fa-regular fa-eye"></i></span>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-key me-2"></i>Ubah Password</button>
                    <button type="button" class="btn btn-outline-light" onclick="clearPwd()"><i class="fa-solid fa-broom me-2"></i>Bersihkan</button>
                </div>
            </form>
        </div>

        <!-- FOOTER -->
        <div class="footer">
            © <?= date('Y') ?> RELIPROVE — Panel Asesor
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePwd(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.type = el.type === 'password' ? 'text' : 'password';
        }

        function clearPwd() {
            ['pwd_old', 'pwd_new', 'pwd_rep'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
        }
        // Validasi simple di sisi klien (tambahan)
        (function() {
            const formPwd = document.querySelector('form [name="action"][value="change_password"]')?.closest('form');
            if (formPwd) {
                formPwd.addEventListener('submit', function(e) {
                    const newPwd = document.getElementById('pwd_new')?.value || '';
                    const repPwd = document.getElementById('pwd_rep')?.value || '';
                    if (newPwd !== repPwd) {
                        e.preventDefault();
                        alert('Konfirmasi password tidak cocok.');
                        return false;
                    }
                    // Pola: min 8, mengandung huruf besar, kecil, angka
                    const ok = /[A-Z]/.test(newPwd) && /[a-z]/.test(newPwd) && /[0-9]/.test(newPwd) && newPwd.length >= 8;
                    if (!ok) {
                        e.preventDefault();
                        alert('Password baru minimal 8 karakter dan mengandung huruf besar, huruf kecil, serta angka.');
                        return false;
                    }
                });
            }
        })();
    </script>
</body>

</html>