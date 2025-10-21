<?php
// asesor/partials/sidebar_asesor.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($conn)) {
    require_once __DIR__ . '/../../config/koneksi.php'; // pastikan $conn (MySQLi) tersedia
}
$id_pengguna = isset($_SESSION['id_pengguna']) ? (int)$_SESSION['id_pengguna'] : 0;

// Hitung unread safe default = 0
$jmlNotif = 0;
if ($id_pengguna > 0 && $stmtN = $conn->prepare("SELECT COUNT(*) FROM notifikasi WHERE id_pengguna = ? AND status_baca = 0")) {
    $stmtN->bind_param("i", $id_pengguna);
    $stmtN->execute();
    $stmtN->bind_result($cnt);
    if ($stmtN->fetch()) {
        $jmlNotif = (int)$cnt;
    }
    $stmtN->close();
}

// Tentukan menu aktif (optional): set $ACTIVE_PAGE di tiap file sebelum include
$ACTIVE_PAGE = $ACTIVE_PAGE ?? basename($_SERVER['PHP_SELF']);
function is_active($file, $ACTIVE_PAGE)
{
    return $ACTIVE_PAGE === $file ? 'active' : '';
}
?>
<style>
    /* badge untuk item Notifikasi di sidebar (sekali saja, aman duplikat) */
    .nav-notif {
        position: relative;
    }

    .nav-notif .nav-dot {
        position: absolute;
        top: 10px;
        right: 14px;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #e05252;
        box-shadow: 0 0 0 3px rgba(224, 82, 82, .25);
    }

    .nav-notif .nav-badge {
        margin-left: 8px;
        padding: 2px 6px;
        font-size: 10px;
        font-weight: 800;
        color: #fff;
        background: #e05252;
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, .16);
    }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-title">
        <h2 class="sidebar-brand d-none d-md-block text-center" style="margin-top: 37px; margin-bottom: 50px;">RELIPROVE</h2>
        <button class="sidebar-close d-md-none" onclick="document.getElementById('sidebar').classList.remove('active')">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <a href="dashboard_asesor.php" class="<?= is_active('dashboard_asesor.php', $ACTIVE_PAGE) ?>"><i class="fas fa-gauge"></i> Dashboard</a>
    <a href="bank_soal.php" class="<?= is_active('bank_soal.php', $ACTIVE_PAGE) ?>"><i class="fa-solid fa-book-open"></i> Bank Soal</a>
    <a href="daftar_penilaian.php" class="<?= is_active('daftar_penilaian.php', $ACTIVE_PAGE) ?>"><i class="fas fa-clipboard-list"></i> Daftar Penilaian</a>
    <a href="riwayat_penilaian.php" class="<?= is_active('riwayat_penilaian.php', $ACTIVE_PAGE) ?>"><i class="fas fa-history"></i> Riwayat Penilaian</a>


    <a href="notifikasi.php" class="nav-notif <?= is_active('notifikasi.php', $ACTIVE_PAGE) ?>">
        <i class="fas fa-bell"></i> Notifikasi
        <?php if ($jmlNotif > 0): ?>
            <span class="nav-dot" aria-hidden="true"></span>
            <span class="nav-badge">NEW</span>
        <?php endif; ?>
    </a>

    <a href="pengaturan.php" class="<?= is_active('pengaturan.php', $ACTIVE_PAGE) ?>"><i class="fas fa-gear"></i> Pengaturan</a>
    <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Keluar</a>
</div>