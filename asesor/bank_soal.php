<?php
// asesor/bank_soal.php
ob_start();
session_start();
require_once __DIR__ . '/../config/koneksi.php';

if (!isset($_SESSION['id_pengguna']) || $_SESSION['peran'] !== 'asesor') {
    header('Location: ../login.php');
    exit;
}

$nama = $_SESSION['nama_lengkap'] ?? 'Asesor';

// Flash
$flash_ok  = $_SESSION['flash_ok'] ?? null;
$flash_err = $_SESSION['flash_err'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

/* =================== HANDLE TAMBAH / HAPUS =================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---------- TAMBAH ----------
    if (($_POST['aksi'] ?? '') === 'tambah') {
        $id_bidang   = (int)($_POST['id_bidang'] ?? 0);
        $id_kategori = (int)($_POST['id_kategori'] ?? 0);
        $id_posisi   = (int)($_POST['id_posisi'] ?? 0);
        $link        = trim($_POST['link_drive'] ?? '');
        $set_default = !empty($_POST['set_default']);

        if ($id_bidang <= 0 || $id_kategori <= 0 || $id_posisi <= 0 || $link === '') {
            $_SESSION['flash_err'] = 'Semua field wajib diisi.';
            header('Location: bank_soal.php');
            exit;
        }
        if (!preg_match('~^https?://(drive\.google\.com|docs\.google\.com)/~i', $link)) {
            $_SESSION['flash_err'] = 'Link harus berupa tautan Google Drive/Docs yang valid.';
            header('Location: bank_soal.php');
            exit;
        }

        // Validasi konsistensi: posisi -> kategori -> bidang
        $cek = $conn->prepare("
            SELECT b.id_bidang, b.nama_bidang, k.id_kategori, k.nama_kategori, p.id_posisi
            FROM posisi p
            JOIN kategori k ON k.id_kategori = p.id_kategori
            JOIN bidang   b ON b.id_bidang   = k.id_bidang
            WHERE p.id_posisi = ? LIMIT 1
        ");
        $cek->bind_param('i', $id_posisi);
        $cek->execute();
        $rel = $cek->get_result()->fetch_assoc();
        $cek->close();

        if (!$rel) {
            $_SESSION['flash_err'] = 'Posisi tidak ditemukan.';
            header('Location: bank_soal.php');
            exit;
        }
        if ((int)$rel['id_kategori'] !== $id_kategori || (int)$rel['id_bidang'] !== $id_bidang) {
            $_SESSION['flash_err'] = 'Bidang/Kategori tidak konsisten dengan Posisi yang dipilih.';
            header('Location: bank_soal.php');
            exit;
        }

        mysqli_begin_transaction($conn);
        try {
            // INSERT lengkap: simpan juga id_kategori + nama kategori (denormalisasi opsional)
            $stmt = $conn->prepare("
                INSERT INTO bank_soal
                    (id_bidang, id_kategori, kategori, id_posisi, link_drive, link_soal_default, is_aktif, dibuat_pada)
                VALUES
                    (?,?,?,?,?,NULL,1,NOW())
            ");
            $kategori_nama = $rel['nama_kategori'];
            $stmt->bind_param('iisis', $id_bidang, $id_kategori, $kategori_nama, $id_posisi, $link);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $new_id = $stmt->insert_id;
            $stmt->close();

            // Jika dicentang default → update posisi
            if ($set_default) {
                $upd = $conn->prepare("UPDATE posisi SET link_soal_default = ? WHERE id_posisi = ? LIMIT 1");
                $upd->bind_param('si', $link, $id_posisi);
                if (!$upd->execute()) throw new Exception($upd->error);
                $upd->close();

                // optional: simpan juga ke kolom link_soal_default pada bank_soal baru
                $conn->query("UPDATE bank_soal SET link_soal_default = '" . $conn->real_escape_string($link) . "' WHERE id_bank_soal = " . $new_id);
            }

            mysqli_commit($conn);
            $_SESSION['flash_ok'] = 'Bank soal berhasil ditambahkan.' . ($set_default ? ' (Diset sebagai default posisi.)' : '');
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $_SESSION['flash_err'] = 'Gagal menambahkan: ' . $e->getMessage();
        }

        header('Location: bank_soal.php');
        exit;
    }

    // ---------- HAPUS ----------
    if (($_POST['aksi'] ?? '') === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['flash_err'] = "Parameter tidak valid.";
            header('Location: bank_soal.php');
            exit;
        }

        mysqli_begin_transaction($conn);
        try {
            // 1) Ambil data bank_soal yang akan dihapus (butuh id_posisi & link_drive)
            $sel = $conn->prepare("SELECT id_posisi, link_drive FROM bank_soal WHERE id_bank_soal = ? LIMIT 1");
            $sel->bind_param('i', $id);
            $sel->execute();
            $res = $sel->get_result();
            $row = $res->fetch_assoc();
            $sel->close();

            if (!$row) {
                throw new Exception("Data tidak ditemukan.");
            }

            $id_posisi = (int)$row['id_posisi'];
            $link_bs   = $row['link_drive'];

            // 2) Hapus baris bank_soal
            $del = $conn->prepare("DELETE FROM bank_soal WHERE id_bank_soal = ? LIMIT 1");
            $del->bind_param('i', $id);
            $del->execute();
            if ($del->affected_rows <= 0) {
                throw new Exception("Gagal menghapus data.");
            }
            $del->close();

            // 3) Jika link yang dihapus kebetulan adalah default posisi -> kosongkan default
            $cek = $conn->prepare("SELECT link_soal_default FROM posisi WHERE id_posisi = ? LIMIT 1");
            $cek->bind_param('i', $id_posisi);
            $cek->execute();
            $cekRes = $cek->get_result()->fetch_assoc();
            $cek->close();

            if ($cekRes && !empty($cekRes['link_soal_default']) && $cekRes['link_soal_default'] === $link_bs) {
                $upd = $conn->prepare("UPDATE posisi SET link_soal_default = NULL WHERE id_posisi = ? LIMIT 1");
                $upd->bind_param('i', $id_posisi);
                $upd->execute();
                $upd->close();
            }

            mysqli_commit($conn);
            $_SESSION['flash_ok'] = "Bank soal berhasil dihapus.";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $_SESSION['flash_err'] = "Gagal menghapus: " . $e->getMessage();
        }

        header('Location: bank_soal.php');
        exit;
    }
}

/* =================== DROPDOWN DATA (untuk filter) =================== */
$bidang_rs = mysqli_query($conn, "SELECT id_bidang, nama_bidang FROM bidang ORDER BY nama_bidang ASC");

/* =================== FILTER LIST =================== */
$f_id_bidang   = isset($_GET['f_id_bidang'])   ? (int)$_GET['f_id_bidang']   : 0;
$f_id_kategori = isset($_GET['f_id_kategori']) ? (int)$_GET['f_id_kategori'] : 0;
$f_posisi      = isset($_GET['f_posisi'])      ? (int)$_GET['f_posisi']      : 0;

/*
  Rantai relasi:
  bank_soal.id_posisi -> posisi.id_posisi -> kategori.id_kategori -> bidang.id_bidang
*/
$sql = "
  SELECT 
    bs.id_bank_soal, bs.link_drive, bs.dibuat_pada, bs.link_soal_default AS link_default_bs,
    b.id_bidang, b.nama_bidang,
    k.id_kategori, k.nama_kategori,
    p.id_posisi, p.nama_posisi, p.link_soal_default AS link_default_posisi
  FROM bank_soal bs
  JOIN posisi   p ON p.id_posisi   = bs.id_posisi
  JOIN kategori k ON k.id_kategori = p.id_kategori
  JOIN bidang   b ON b.id_bidang   = k.id_bidang
  WHERE 1
";

$params = [];
$types  = "";

// Terapkan filter hanya jika > 0
if ($f_id_bidang > 0) {
    $sql      .= " AND b.id_bidang = ? ";
    $params[]  = $f_id_bidang;
    $types    .= "i";
}
if ($f_id_kategori > 0) {
    $sql      .= " AND k.id_kategori = ? ";
    $params[]  = $f_id_kategori;
    $types    .= "i";
}
if ($f_posisi > 0) {
    $sql      .= " AND p.id_posisi = ? ";
    $params[]  = $f_posisi;
    $types    .= "i";
}

$sql .= " ORDER BY bs.dibuat_pada DESC";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die("Query error: " . mysqli_error($conn));
}
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$list_rs = mysqli_stmt_get_result($stmt);

?>

<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Bank Soal • RELIPROVE</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../aset/img/logo.png" type="image/png">

    <!-- Library umum (biarkan, tanpa custom style) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

    <link rel="stylesheet" href="../asesor/css/dashboard_asesor.css">

<body>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-title">
            <h2 class="sidebar-brand d-none d-md-block text-center" style="margin-top:37px;margin-bottom:50px;">RELIPROVE</h2>
            <button class="sidebar-close d-md-none" onclick="document.getElementById('sidebar').classList.remove('active')">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <a href="dashboard_asesor.php"><i class="fas fa-gauge"></i> Dashboard</a>
        <a href="bank_soal.php" class="active"><i class="fa-solid fa-book-open"></i> Bank Soal</a>
        <a href="daftar_penilaian.php"><i class="fas fa-clipboard-list"></i> Daftar Penilaian</a>
        <a href="riwayat_penilaian.php"><i class="fas fa-history"></i> Riwayat Penilaian</a>
        <!-- <a href="sertifikat.php"><i class="fas fa-graduation-cap"></i> Sertifikat</a> -->
        <a href="notifikasi.php"><i class="fas fa-bell"></i> Notifikasi</a>
        <a href="pengaturan.php"><i class="fas fa-gear"></i> Pengaturan</a>
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Keluar</a>
    </div>
    <?php
    if (session_status() === PHP_SESSION_NONE) session_start();

    include __DIR__ . '/partials/sidebar_asesor.php';
    ?>

    <!-- CONTENT -->
    <div class="content">
        <!-- TOPBAR -->
        <div class="topbar">
            <div class="left-group" style="display:flex; align-items:center; gap:10px;">
                <div class="toggle-sidebar" onclick="document.getElementById('sidebar').classList.toggle('active')" style="cursor:pointer; display:flex; align-items:center;">
                    <i class="fas fa-bars"></i>
                </div>
                <h4 class="d-none d-md-block" style="margin:0; font-weight:600;">BANK SOAL</h4>
                <h4 class="d-md-none" style="margin:0; font-weight:600;">RELIPROVE</h4>
            </div>

            <div class="right-group">
                <div class="user-info">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($nama) ?>&background=9b5eff&color=fff&rounded=true&size=40" alt="avatar">
                    <div class="user-meta">
                        <strong><?= htmlspecialchars($nama) ?></strong><br>
                        <small>Asesor</small>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($flash_ok): ?>
            <div class="alert alert-success" role="alert" style="border-radius:12px;">
                <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($flash_ok) ?>
            </div>
        <?php elseif ($flash_err): ?>
            <div class="alert alert-danger" role="alert" style="border-radius:12px;">
                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($flash_err) ?>
            </div>
        <?php endif; ?>

        <!-- Form Tambah -->
        <div class="table-surface">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <h5 class="m-0">Tambah Bank Soal
                    <span class="trend"><i class="fa-solid fa-book-open"></i></span>
                </h5>
            </div>

            <form method="post" class="row g-3 needs-validation" id="formTambahBankSoal" novalidate>
                <input type="hidden" name="aksi" value="tambah">

                <!-- Bidang -->
                <div class="col-12 col-md-4">
                    <label for="id_bidang" style="color:#eaeaea;font-weight:600;" class="form-label">
                        Bidang <span style="color:#ff6b6b">*</span>
                    </label>
                    <select name="id_bidang" id="id_bidang" required
                        style="width:100%;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);color:#eaeaea;border-radius:12px;padding:.65rem;">
                        <option value="" selected disabled>··· Pilih Bidang ···</option>
                        <?php
                        $bidang_rs = mysqli_query($conn, "SELECT id_bidang, nama_bidang FROM bidang ORDER BY nama_bidang ASC");
                        while ($b = mysqli_fetch_assoc($bidang_rs)) {
                            echo '<option value="' . (int)$b['id_bidang'] . '">' . htmlspecialchars($b['nama_bidang']) . '</option>';
                        }
                        ?>
                    </select>
                    <div style="color:#9aa0a6;font-size:.85rem;padding-top:0.7rem">Pilih bidang terlebih dahulu untuk menampilkan kategori.</div>
                </div>

                <!-- Kategori (dependent) -->
                <div class="col-12 col-md-4">
                    <label for="id_kategori" style="color:#eaeaea;font-weight:600;" class="form-label">
                        Kategori <span style="color:#ff6b6b">*</span>
                    </label>
                    <select name="id_kategori" id="id_kategori" required disabled
                        style="width:100%;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);color:#eaeaea;border-radius:12px;padding:.65rem;">
                        <option value="" selected disabled>··· Pilih Kategori ···</option>
                    </select>
                    <div style="color:#9aa0a6;font-size:.85rem; padding-top:0.7rem">Kategori akan muncul berdasarkan bidang yang dipilih.</div>
                </div>
                <!-- Posisi (dependent) -->
                <div class="col-12 col-md-4">
                    <label for="id_posisi" style="color:#eaeaea;font-weight:600;" class="form-label">
                        Posisi <span style="color:#ff6b6b">*</span>
                    </label>
                    <select name="id_posisi" id="id_posisi" required disabled
                        style="width:100%;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);color:#eaeaea;border-radius:12px;padding:.65rem;">
                        <option value="" selected disabled>··· Pilih Posisi ···</option>
                    </select>
                    <div style="color:#9aa0a6;font-size:.85rem;padding-top:0.7rem">Posisi akan muncul berdasarkan kategori yang dipilih.</div>
                </div>

                <!-- Link Drive -->
                <div class="col-12">
                    <label for="link_drive" style="color:#eaeaea;font-weight:600;" class="form-label">
                        Link Google Drive <span style="color:#ff6b6b">*</span>
                    </label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);color:#9aa0a6;border-top-left-radius:12px;border-bottom-left-radius:12px;">
                            <i class="fa-brands fa-google-drive"></i>
                        </span>
                        <input type="url" name="link_drive" id="link_drive" required
                            placeholder="https://drive.google.com/..."
                            style="flex:1;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);color:#eaeaea;border-top-right-radius:12px;border-bottom-right-radius:12px;padding:.65rem;">
                    </div>
                    <div style="color:#9aa0a6;font-size:.85rem;padding-top:0.7rem">Tempel tautan file/folder Google Drive yang berisi soal.</div>
                </div>

                <!-- Default toggle -->
                <!-- Checkbox + Actions dalam satu baris -->
                <div class="col-12"
                    style="display:flex; align-items:center; justify-content:space-between; gap:.75rem; flex-wrap:wrap; margin-top:1.7rem;">

                    <!-- Kiri: checkbox -->
                    <label for="set_default" style="display:flex; align-items:center; gap:.5rem; margin:0;">
                        <input type="checkbox" id="set_default" name="set_default" value="1"
                            style="width:1.15rem;height:1.15rem;
                  background:rgba(255,255,255,.03);
                  border:1px solid rgba(255,255,255,.12);
                  border-radius:.25rem;">
                        <span style="color:#eaeaea;">Jadikan default untuk posisi ini</span>
                    </label>

                    <!-- Kanan: tombol -->
                    <div style="display:flex; align-items:center; gap:.5rem;">
                        <button type="reset"
                            style="color:#eaeaea;border:1px solid rgba(255,255,255,.12);
                   background:transparent;border-radius:12px;
                   padding:.55rem 1rem;">
                            <i class="fa-solid fa-rotate-left me-1"></i> Reset
                        </button>
                        <button type="submit"
                            style="background:#9b5eff;border:1px solid #9b5eff;color:#fff;
                   border-radius:12px;padding:.55rem 1rem;font-weight:600;
                   box-shadow:0 6px 18px rgba(155,94,255,.25);">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Simpan
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <br>
        <!-- Filter & List -->
        <?php if (!empty($flash_ok)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert" style="margin-bottom:12px;">
                <?= htmlspecialchars($flash_ok) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($flash_err)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert" style="margin-bottom:12px;">
                <?= htmlspecialchars($flash_err) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="table-surface">
            <h5 style="margin-bottom:19px;">Daftar Bank Soal <span class="trend"><i class="fa-solid fa-database"></i></span></h5>

            <form method="get" class="row g-2" style="margin-bottom:12px;">
                <div class="col-12 col-md-3 ">
                    <select name="f_id_bidang" id="f_id_bidang" class="form-control" style="width:100%;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);color:#eaeaea;border-radius:12px;padding:.65rem;text-align:center;">
                        <option value="0">Semua Bidang </option>
                        <?php
                        $bidang_f = mysqli_query($conn, "SELECT id_bidang, nama_bidang FROM bidang ORDER BY nama_bidang ASC");
                        while ($bf = mysqli_fetch_assoc($bidang_f)): ?>
                            <option value="<?= (int)$bf['id_bidang'] ?>" <?= $f_id_bidang === (int)$bf['id_bidang'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($bf['nama_bidang']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="col-12 col-md-3">
                    <select name="f_id_kategori" id="f_id_kategori" class="form-control" style="width:100%;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);color:#eaeaea;border-radius:12px;padding:.65rem;text-align:center;">
                        <option value="0">Semua Kategori</option>
                    </select>
                </div>

                <div class="col-12 col-md-3">
                    <select name="f_posisi" id="f_posisi" class="form-control" style="width:100%;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);color:#eaeaea;border-radius:12px;padding:.65rem; text-align:center;">
                        <option value="0">Semua Posisi</option>
                    </select>
                </div>

                <div class="col-12 col-md-3 d-flex" style="gap:.5rem;">
                    <button class="btn btn-primary flex-fill" style="background:var(--primary);border-color:var(--primary);">Terapkan</button>
                    <a href="bank_soal.php" class="btn btn-outline-light flex-fill">Reset</a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table dt-theme align-middle w-100">
                    <thead>
                        <tr>
                            <?php
                            $thStyle = 'background-color:#9b5eff !important;color:#fff !important;border:none;padding:12px;';
                            ?>
                            <th style="<?= $thStyle ?>">Bidang</th>
                            <th style="<?= $thStyle ?>">Kategori</th>
                            <th style="<?= $thStyle ?>">Posisi</th>
                            <th style="<?= $thStyle ?>">Link</th>
                            <th style="<?= $thStyle ?>">Dibuat</th>
                            <th style="<?= $thStyle ?>" class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($list_rs && mysqli_num_rows($list_rs) > 0): ?>
                            <?php while ($r = mysqli_fetch_assoc($list_rs)): ?>
                                <?php $tdStyle = 'background-color:rgba(255,255,255,.03) !important;color:#eaeaea !important;padding:10px;'; ?>
                                <tr>
                                    <td style="<?= $tdStyle ?>"><?= htmlspecialchars($r['nama_bidang']) ?></td>
                                    <td style="<?= $tdStyle ?>"><?= htmlspecialchars($r['nama_kategori']) ?></td>
                                    <td style="<?= $tdStyle ?>"><?= htmlspecialchars($r['nama_posisi']) ?></td>
                                    <td style="<?= $tdStyle ?>">
                                        <a href="<?= htmlspecialchars($r['link_drive']) ?>" target="_blank"
                                            style="display:inline-block;background:#9b5eff;color:#fff;text-decoration:none;
                        padding:6px 12px;border-radius:10px;font-size:.85rem;">
                                            <i class="fa-solid fa-link"></i> Buka
                                        </a>
                                        <?php
                                        $def = $r['link_default_posisi'] ?: ($r['link_default_bs'] ?? null);
                                        if (!empty($def) && $def === $r['link_drive']): ?>
                                            <span style="display:inline-block;margin-left:6px;background:#efe6ff;border:1px solid #e0ccff;
                             color:#7a44f0;padding:6px 10px;border-radius:999px;font-size:.8rem;">
                                                ⭐ Default
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="<?= $tdStyle ?>"><?= date('d M Y H:i', strtotime($r['dibuat_pada'])) ?></td>
                                    <td style="<?= $tdStyle ?>" class="text-center">
                                        <form method="post" action="bank_soal.php" onsubmit="return confirm('Hapus bank soal ini?');"
                                            class="d-inline" style="margin:0;">
                                            <input type="hidden" name="aksi" value="hapus">
                                            <input type="hidden" name="id" value="<?= (int)$r['id_bank_soal'] ?>">
                                            <button type="submit" title="Hapus"
                                                style="background:#e74c3c;border:none;border-radius:12px;color:#fff;padding:8px 12px;">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted"
                                    style="background-color:#0f1117 !important;color:#eaeaea !important;padding:12px;">
                                    Belum ada data.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <br>
        <div class="footer"
            style="margin-top:auto;">
            &copy; <?= date('Y') ?> Created by PT. Reliable Future Technology
        </div>
    </div>
</body>
<script>
    (function() {
        const form = document.getElementById('formTambahBankSoal');
        const selBidang = document.getElementById('id_bidang');
        const selKategori = document.getElementById('id_kategori');
        const selPosisi = document.getElementById('id_posisi');
        const urlKategori = 'get_kategori.php';
        const urlPosisi = 'get_posisi.php';

        // helpers
        function setLoading(select, isLoading) {
            select.classList.toggle('select-loading', isLoading);
        }

        function resetSelect(select, placeholder = '— Pilih —', disable = true) {
            select.innerHTML = `<option value="" selected disabled>${placeholder}</option>`;
            select.disabled = !!disable;
        }

        function appendOptions(select, items) {
            const frag = document.createDocumentFragment();
            items.forEach(({
                value,
                label
            }) => {
                const opt = document.createElement('option');
                opt.value = value;
                opt.textContent = label;
                frag.appendChild(opt);
            });
            select.appendChild(frag);
        }

        function normalizeOptions(data, keyId, keyLabel) {
            // data bisa [{value,label}] atau hasil mentah {id_kategori,nama_kategori} / {id_posisi,nama_posisi}
            const arr = Array.isArray(data) ? data : [];
            return arr.map(row => {
                const v = row.value ?? row[keyId];
                const l = row.label ?? row[keyLabel];
                return (v && l) ? {
                    value: String(v),
                    label: l
                } : null;
            }).filter(Boolean);
        }

        async function fetchJSON(url) {
            const res = await fetch(url, {
                cache: 'no-store'
            });
            if (!res.ok) throw new Error('Network error');
            return res.json();
        }

        // Load Kategori by Bidang (atau semua kalau idBidang kosong)
        async function loadKategori(idBidang) {
            resetSelect(selKategori, '— Pilih Kategori —');
            resetSelect(selPosisi, '— Pilih Posisi —');
            setLoading(selKategori, true);

            try {
                const qs = new URLSearchParams({
                    id_bidang: idBidang || 0
                });
                const data = await fetchJSON(`${urlKategori}?${qs.toString()}`);
                // endpoint mungkin sudah menyertakan option "Semua", tapi untuk form tambah kita rebuild
                const items = normalizeOptions(data, 'id_kategori', 'nama_kategori');
                appendOptions(selKategori, items);
                selKategori.disabled = false;
            } catch (e) {
                console.error(e);
                resetSelect(selKategori, 'Gagal memuat kategori', true);
            } finally {
                setLoading(selKategori, false);
            }
        }

        // Load Posisi by Kategori (prioritas), fallback by Bidang
        async function loadPosisi({
            idKategori,
            idBidang
        }) {
            resetSelect(selPosisi, '— Pilih Posisi —');
            setLoading(selPosisi, true);

            try {
                const qs = new URLSearchParams();
                if (idKategori) qs.set('id_kategori', idKategori);
                else if (idBidang) qs.set('id_bidang', idBidang);
                else {
                    resetSelect(selPosisi, '— Pilih Posisi —', true);
                    setLoading(selPosisi, false);
                    return;
                }

                const data = await fetchJSON(`${urlPosisi}?${qs.toString()}`);
                const items = normalizeOptions(data, 'id_posisi', 'nama_posisi');
                appendOptions(selPosisi, items);
                selPosisi.disabled = false;
            } catch (e) {
                console.error(e);
                resetSelect(selPosisi, 'Gagal memuat posisi', true);
            } finally {
                setLoading(selPosisi, false);
            }
        }

        // Events
        selBidang.addEventListener('change', () => {
            const idB = selBidang.value || 0;
            loadKategori(idB);
        });

        selKategori.addEventListener('change', () => {
            const idK = selKategori.value || '';
            const idB = selBidang.value || 0;
            loadPosisi({
                idKategori: idK,
                idBidang: idB
            });
        });

        // Validasi Bootstrap
        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });

        // Reset UX
        form.addEventListener('reset', function() {
            setTimeout(() => {
                resetSelect(selKategori, '— Pilih Kategori —', true);
                resetSelect(selPosisi, '— Pilih Posisi —', true);
                form.classList.remove('was-validated');
            }, 0);
        });
    })();
</script>


<script>
    (function() {
        const fBidang = document.getElementById('f_id_bidang');
        const fKategori = document.getElementById('f_id_kategori');
        const fPosisi = document.getElementById('f_posisi');

        const qsInt = (k, d = 0) => {
            const v = new URLSearchParams(location.search).get(k);
            const n = parseInt(v, 10);
            return isNaN(n) ? d : n;
        };

        const selB = qsInt('f_id_bidang', 0);
        const selK = qsInt('f_id_kategori', 0);
        const selP = qsInt('f_posisi', 0);

        function setOptions(selectEl, options, selected = 0, firstOptText) {
            const frag = document.createDocumentFragment();
            const first = document.createElement('option');
            first.value = 0;
            first.textContent = firstOptText;
            frag.appendChild(first);
            options.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.value;
                opt.textContent = o.label;
                if (+o.value === +selected) opt.selected = true;
                frag.appendChild(opt);
            });
            selectEl.innerHTML = "";
            selectEl.appendChild(frag);
        }

        async function fetchJSON(url) {
            const res = await fetch(url, {
                cache: 'no-store'
            });
            if (!res.ok) throw new Error('Network');
            return res.json();
        }

        async function loadKategori(idBidang, selected = 0) {
            const data = await fetchJSON(`get_kategori.php?id_bidang=${idBidang||0}`);
            // data sudah termasuk option "Semua Kategori" index 0; tapi kita rebuild biar konsisten
            setOptions(fKategori, data.slice(1), selected, 'Semua Kategori');
        }

        async function loadPosisi({
            idKategori = 0,
            idBidang = 0,
            selected = 0
        } = {}) {
            const params = new URLSearchParams();
            params.set('id_kategori', idKategori || 0);
            params.set('id_bidang', idBidang || 0);
            const data = await fetchJSON(`get_posisi.php?${params.toString()}`);
            setOptions(fPosisi, data.slice(1), selected, 'Semua Posisi');
        }

        // Event: Bidang berubah → muat kategori (all jika 0), lalu posisi (pakai bidang jika kategori=0)
        fBidang.addEventListener('change', async e => {
            const idB = parseInt(e.target.value, 10) || 0;
            await loadKategori(idB, 0);
            await loadPosisi({
                idKategori: 0,
                idBidang: idB,
                selected: 0
            });
        });

        // Event: Kategori berubah → muat posisi (prioritas kategori; jika 0 pakai bidang; kalau bidang juga 0 → semua)
        fKategori.addEventListener('change', async e => {
            const idK = parseInt(e.target.value, 10) || 0;
            const idB = parseInt(fBidang.value, 10) || 0;
            await loadPosisi({
                idKategori: idK,
                idBidang: idB,
                selected: 0
            });
        });

        // Preload sesuai query string saat halaman dibuka
        (async () => {
            // 1) Preload kategori sesuai bidang (jika 0 → ambil semua kategori)
            await loadKategori(selB, selK);

            // 2) Preload posisi: jika ada kategori → by kategori; else kalau bidang ada → by bidang; else → semua
            if (selK > 0) {
                await loadPosisi({
                    idKategori: selK,
                    idBidang: selB,
                    selected: selP
                });
            } else {
                await loadPosisi({
                    idKategori: 0,
                    idBidang: selB,
                    selected: selP
                });
            }
        })();
    })();
</script>


</html>