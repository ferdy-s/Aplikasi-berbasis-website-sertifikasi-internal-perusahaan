<?php
// asesor/penilaian.php
session_start();
require_once __DIR__ . '/../config/koneksi.php';

if (!isset($_SESSION['id_pengguna']) || !in_array($_SESSION['peran'] ?? '', ['asesor', 'admin'], true)) {
    header('Location: ../login.php');
    exit;
}

$id_pendaftaran = (int)($_GET['id'] ?? 0);
if ($id_pendaftaran <= 0) {
    header('Location: dashboard_asesor.php');
    exit;
}

/* Ambil data peserta + penilaian */
$sql = "SELECT 
          p.id_pendaftaran, p.tanggal_daftar, p.status_verifikasi, p.status_penilaian, p.status_kelulusan,
          u.nama_lengkap, po.nama_posisi,
          pen.id_penilaian, pen.link_soal, pen.link_jawaban, pen.skor, pen.komentar, pen.rekomendasi, pen.tanggal_dinilai
        FROM pendaftaran p
        JOIN pengguna u   ON u.id_pengguna = p.id_pengguna
        JOIN posisi   po  ON po.id_posisi   = p.id_posisi
        LEFT JOIN penilaian pen ON pen.id_pendaftaran = p.id_pendaftaran
        WHERE p.id_pendaftaran = ?
        LIMIT 1";
$st = $conn->prepare($sql);
$st->bind_param('i', $id_pendaftaran);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
    header('Location: dashboard_asesor.php');
    exit;
}

/* Simpan penilaian (POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $skor     = max(0, min(10, (int)($_POST['skor'] ?? 0))); // clamp 0..10
    $komentar = trim($_POST['komentar'] ?? '');

    // rekomendasi otomatis + kelulusan
    if ($skor >= 7) {
        $rekom = 'layak';
        $kelulusan = 'lulus';
    } elseif ($skor >= 1) {
        $rekom = 'belum layak';
        $kelulusan = 'tidak';
    } else {
        $rekom = 'di review';
        $kelulusan = 'belum';
    }

    if (empty($row['id_penilaian'])) {
        // buat baris penilaian jika belum ada
        $id_asesor = (int)$_SESSION['id_pengguna'];
        $ins = $conn->prepare("INSERT INTO penilaian
              (id_pendaftaran, id_asesor, link_soal, link_jawaban, skor, komentar, rekomendasi, tanggal_dinilai)
              VALUES (?, ?, NULL, NULL, ?, ?, ?, NOW())");
        $ins->bind_param('iiiss', $id_pendaftaran, $id_asesor, $skor, $komentar, $rekom);
        $ok = $ins->execute();
        $err = $ins->error ?? '';
        $ins->close();
    } else {
        $upd = $conn->prepare("UPDATE penilaian
              SET skor=?, komentar=?, rekomendasi=?, tanggal_dinilai=NOW()
              WHERE id_penilaian=?");
        $upd->bind_param('issi', $skor, $komentar, $rekom, $row['id_penilaian']);
        $ok = $upd->execute();
        $err = $upd->error ?? '';
        $upd->close();
    }

    // update status di pendaftaran
    if ($ok) {
        $up = $conn->prepare("UPDATE pendaftaran
                              SET status_penilaian='dinilai', status_kelulusan=?
                              WHERE id_pendaftaran=?");
        $up->bind_param('si', $kelulusan, $id_pendaftaran);
        $up->execute();
        $up->close();
    }

    $_SESSION['flash_' . ($ok ? 'success' : 'error')] = $ok ? 'Penilaian disimpan & status kelulusan diperbarui.' : ('Gagal menyimpan: ' . $err);
    header('Location: dashboard_asesor.php');
    exit;
}

/* Variabel tampilan */
$nama           = $row['nama_lengkap'];
$posisi         = $row['nama_posisi'];
$tanggal        = date('d M Y', strtotime($row['tanggal_daftar']));
$link_soal      = trim($row['link_soal']    ?? '');
$link_jawaban   = trim($row['link_jawaban'] ?? '');
$skor_now       = (int)($row['skor'] ?? 0);
$komentar_now   = $row['komentar'] ?? '';
$rekom_now      = $row['rekomendasi'] ?? 'di review';
$kelulusan_now  = $row['status_kelulusan'] ?? 'belum';
$sudahDinilai = (($row['status_penilaian'] ?? '') === 'dinilai');
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Penilaian Peserta • RELIPROVE</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bg: #0c0f16;
            --card: #121725;
            --muted: #9aa3b2;
            --line: #242a36;
            --primary: linear-gradient(135deg, #8b5dff, #6a3dff);
        }

        /* Body jadi flex container */
        body {
            background: var(--bg);
            color: #e6e9ef;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            font-family: 'Segoe UI', sans-serif;
        }

        .container-narrow {
            max-width: 980px;
            width: 100%;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            animation: fadeIn 0.6s ease-in-out;
        }

        /* Card modern */
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 0 30px rgba(139, 93, 255, 0.12), 0 10px 30px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(8px);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 0 40px rgba(139, 93, 255, 0.18), 0 12px 35px rgba(0, 0, 0, 0.4);
        }

        .card h5 {
            margin: 0;
            font-size: 1.25rem;
            color: #e6e9ef;
        }

        .badge-soft {
            background: rgba(139, 93, 255, .18);
            border: 1px solid rgba(139, 93, 255, .35);
            color: #dcd3ff;
            border-radius: 999px;
            padding: .35rem .7rem;
            font-weight: 600;
        }

        .btn-primary {
            background: var(--primary);
            border: none;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.25s ease;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-outline-light {
            border: 1px solid #3a4150;
            color: #e6e9ef;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: background 0.25s ease, transform 0.25s ease;
        }

        .btn-outline-light:hover {
            background: #1b2230;
            transform: translateY(-1px);
        }

        .field {
            background: #0f1421;
            border: 1px solid #242a36;
            color: #e6e9ef;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
        }

        .link-row .btn {
            min-width: 110px
        }

        .subtle {
            color: var(--muted);
            font-size: .9rem;
        }

        .divider {
            height: 1px;
            background: var(--line);
            margin: 1rem 0;
        }

        /* Animasi masuk */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>

</head>

<body>
    <div class="container container-narrow py-4">
        <div class="mb-3 d-flex align-items-center justify-content-between">
            <h3 class="m-0">Penilaian Peserta</h3>
            <a href="dashboard_asesor.php" class="btn btn-outline-light btn-sm">Kembali</a>
        </div>

        <!-- Ringkasan Peserta -->
        <div class="card p-3 mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 style="margin-bottom: 10px;"><?= htmlspecialchars($nama) ?></h5>
                    <div class="subtle">Posisi: <span class="text-light"><?= htmlspecialchars($posisi) ?></span> •
                        Daftar: <span class="text-light"><?= $tanggal ?></span></div>
                </div>
                <span class="pill badge-soft"><?= htmlspecialchars(ucfirst($rekom_now)) ?></span>
            </div>
            <div class="divider my-3"></div>
            <div class="d-flex flex-wrap gap-2 link-row">
                <a href="<?= $link_soal ? htmlspecialchars($link_soal) : '#' ?>"
                    target="_blank"
                    class="btn <?= $link_soal ? 'btn-primary' : 'btn-secondary disabled' ?>">
                    Soal
                </a>
                <a href="<?= $link_jawaban ? htmlspecialchars($link_jawaban) : '#' ?>"
                    target="_blank"
                    class="btn <?= $link_jawaban ? 'btn-success' : 'btn-secondary disabled' ?>">
                    Jawaban
                </a>
            </div>
            <?php if (!$link_soal): ?>
                <div class="mt-2 subtle">Belum ada link soal dari asesor.</div>
            <?php endif; ?>
        </div>

        <!-- Form Penilaian -->
        <?php if (!$sudahDinilai): ?>
            <!-- Form Penilaian (belum dinilai) -->
            <form method="post" class="card p-3">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label">Skor (0–10)</label>
                        <input type="number" name="skor" class="form-control field" min="0" max="10" value="<?= $skor_now ?>" required>
                        <div class="form-text subtle">0 = di review, 1–6 = belum layak, 7–10 = layak</div>
                    </div>
                    <div class="col-12 col-md-8">
                        <label class="form-label">Komentar</label>
                        <textarea name="komentar" rows="3" class="form-control field" placeholder="Catatan/observasi..."><?= htmlspecialchars($komentar_now) ?></textarea>
                    </div>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <button class="btn btn-primary">Simpan Penilaian</button>
                    <a href="dashboard_asesor.php" class="btn btn-outline-light">Batal</a>
                </div>
            </form>

        <?php else: ?>
            <!-- Kartu Hasil (sudah dinilai) -->
            <div class="card p-4 text-center">
                <?php
                $isLulus = ($kelulusan_now === 'lulus');
                $label   = strtoupper($kelulusan_now); // LULUS / TIDAK / BELUM
                $kelas   = $isLulus ? 'bg-success' : ($kelulusan_now === 'tidak' ? 'bg-danger' : 'bg-secondary');
                ?>
                <h4 class="mb-2">Hasil Penilaian</h4>
                <span class="badge <?= $kelas ?> rounded-pill px-3 py-2" style="font-size:1rem;"><?= $label ?></span>

                <div class="mt-3 subtle">
                    <?php if (isset($skor_now)): ?>
                        Skor: <span class="text-light fw-semibold"><?= (int)$skor_now ?></span>
                    <?php endif; ?>
                    <?php if (!empty($rekom_now)): ?>
                        • Rekomendasi: <span class="text-light fw-semibold"><?= htmlspecialchars(ucfirst($rekom_now)) ?></span>
                    <?php endif; ?>
                </div>

                <div class="mt-3 d-flex gap-2 justify-content-center flex-wrap">
                    <a href="status_penilaian.php?id=<?= (int)$id_pendaftaran ?>" class="btn btn-outline-light">
                        Lihat Detail Status
                    </a>
                    <a href="dashboard_asesor.php" class="btn btn-secondary">Kembali ke Dashboard</a>
                </div>
            </div>
        <?php endif; ?>

    </div>
</body>

</html>