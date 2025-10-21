<?php
// asesor/verifikasi_pendaftaran.php
ob_start();
session_start();
require_once __DIR__ . '/../config/koneksi.php';

/* 1) Wajib login & peran */
if (!isset($_SESSION['id_pengguna'])) {
    header('Location: ../login.php');
    exit;
}
$peran = $_SESSION['peran'] ?? '';
if (!in_array($peran, ['asesor', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}

/* 2) Hanya izinkan POST */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard_asesor.php');
    exit;
}

/* 3) Ambil & validasi input */
$id_pendaftaran = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$aksi           = isset($_POST['aksi']) ? strtolower(trim($_POST['aksi'])) : '';
$return_to      = $_POST['return_to'] ?? 'dashboard_asesor.php#tbl-verifikasi';

/* aksi yang diizinkan: terima, tolak, batal */
$allowed_actions = ['terima', 'tolak', 'batal'];

if ($id_pendaftaran <= 0 || !in_array($aksi, $allowed_actions, true)) {
    $_SESSION['flash_error'] = 'Data verifikasi tidak valid.';
    header('Location: dashboard_asesor.php');
    exit;
}

/* 4) Pastikan pendaftaran ada */
$cek = $conn->prepare("
    SELECT id_pendaftaran, status_verifikasi, status_penilaian, status_kelulusan
    FROM pendaftaran
    WHERE id_pendaftaran = ?
    LIMIT 1
");
$cek->bind_param('i', $id_pendaftaran);
$cek->execute();
$res = $cek->get_result();
if ($res->num_rows === 0) {
    $_SESSION['flash_error'] = 'Pendaftaran tidak ditemukan.';
    $cek->close();
    header('Location: dashboard_asesor.php');
    exit;
}
$cek->close();

/* 5) Proses aksi */
$ok = false;
$err = '';

if ($aksi === 'terima' || $aksi === 'tolak') {
    /* Map aksi -> status_verifikasi */
    $status_baru = ($aksi === 'terima') ? 'diterima' : 'ditolak';

    $upd = $conn->prepare("
        UPDATE pendaftaran
           SET status_verifikasi = ?
         WHERE id_pendaftaran   = ?
         LIMIT 1
    ");
    $upd->bind_param('si', $status_baru, $id_pendaftaran);
    $ok  = $upd->execute();
    $err = $upd->error ?? '';
    $upd->close();

    if ($ok) {
        $_SESSION['flash_success'] =
            'Pendaftaran #' . $id_pendaftaran . ' ' . strtoupper($status_baru) . '.';
    }
} elseif ($aksi === 'batal') {
    mysqli_begin_transaction($conn);
    try {
        /* 1) Reset pendaftaran ke tahap Dokumen */
        $upd = $conn->prepare("
            UPDATE pendaftaran
               SET status_verifikasi = 'pending',
                   status_penilaian  = 'belum',
                   status_kelulusan  = 'belum',
                   link_soal_asesmen = NULL,
                   link_hasil_ujian  = NULL
             WHERE id_pendaftaran   = ?
             LIMIT 1
        ");
        $upd->bind_param('i', $id_pendaftaran);
        if (!$upd->execute()) {
            throw new Exception($upd->error);
        }
        $upd->close();

        /* 2) Hapus penilaian agar ringkasan turun */
        $delPen = $conn->prepare("DELETE FROM penilaian WHERE id_pendaftaran = ?");
        $delPen->bind_param('i', $id_pendaftaran);
        if (!$delPen->execute()) {
            throw new Exception($delPen->error);
        }
        $delPen->close();

        /* 3) Hapus record pengiriman link soal jika tabelnya ada */
        $candidates = ['pengiriman_link_soal', 'pengiriman_soal', 'kirim_soal', 'log_kirim_soal'];
        foreach ($candidates as $tbl) {
            $cekTbl = $conn->prepare("
                SELECT 1
                  FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name   = ?
                 LIMIT 1
            ");
            $cekTbl->bind_param('s', $tbl);
            if (!$cekTbl->execute()) {
                throw new Exception($cekTbl->error);
            }
            $has = $cekTbl->get_result()->num_rows === 1;
            $cekTbl->close();

            if ($has) {
                $del = $conn->prepare("DELETE FROM `{$tbl}` WHERE id_pendaftaran = ?");
                $del->bind_param('i', $id_pendaftaran);
                if (!$del->execute()) {
                    throw new Exception($del->error);
                }
                $del->close();
            }
        }

        mysqli_commit($conn);
        $ok = true;
        $_SESSION['flash_success'] =
            'Pendaftaran #' . $id_pendaftaran . ' dibatalkan & record pengiriman link soal dihapus.';
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $ok  = false;
        $err = $e->getMessage();
    }
}

/* 6) Error handling */
if (!$ok) {
    $_SESSION['flash_error'] = 'Gagal memproses aksi. ' . $err;
    header('Location: dashboard_asesor.php');
    exit;
}

/* 7) Redirect kembali (sanitize path lokal saja) */
if (preg_match('~^(?:[a-zA-Z0-9_\-\/\.#]+)$~', $return_to) !== 1) {
    $return_to = 'dashboard_asesor.php#tbl-verifikasi';
}
header('Location: ' . $return_to);
exit;
