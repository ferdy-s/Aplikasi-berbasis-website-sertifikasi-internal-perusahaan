<?php
// riwayat_penilaian.php — RELIPROVE (Asesor)

session_start();
require_once __DIR__ . '/../config/koneksi.php';

/* --------- Guard: wajib login & peran asesor --------- */
if (!isset($_SESSION['id_pengguna']) || (($_SESSION['peran'] ?? '') !== 'asesor')) {
    header('Location: ../login.php');
    exit;
}

$nama     = $_SESSION['nama_lengkap'] ?? 'Asesor';
$idAsesor = (int)($_SESSION['id_pengguna'] ?? 0);

/* --------- Guard koneksi DB --------- */
if (!isset($koneksi) || !($koneksi instanceof mysqli)) {
    if (isset($conn) && ($conn instanceof mysqli)) {
        $koneksi = $conn;
    } elseif (isset($db) && ($db instanceof mysqli)) {
        $koneksi = $db;
    } else {
        die('Error: Koneksi database tidak tersedia. Pastikan koneksi.php mendefinisikan $koneksi = new mysqli(...);');
    }
}

/* --------- Ambil filter dari query string --------- */
$filter_kategori = isset($_GET['kategori']) ? trim($_GET['kategori']) : '';
$filter_posisi   = isset($_GET['posisi'])   ? trim($_GET['posisi'])   : '';
$filter_lulus    = isset($_GET['kelulusan']) ? trim($_GET['kelulusan']) : '';
$q_search        = isset($_GET['q'])        ? trim($_GET['q'])        : '';

/* --------- Data dropdown Kategori & Posisi --------- */
$ddKategori = [];
if ($resKat = $koneksi->query("SELECT id_kategori, nama_kategori FROM kategori ORDER BY nama_kategori")) {
    while ($row = $resKat->fetch_assoc()) {
        $ddKategori[] = $row;
    }
    $resKat->free();
}
$ddPosisi = [];
if ($resPos = $koneksi->query("SELECT id_posisi, nama_posisi FROM posisi ORDER BY nama_posisi")) {
    while ($row = $resPos->fetch_assoc()) {
        $ddPosisi[] = $row;
    }
    $resPos->free();
}

/* --------- Query utama Riwayat --------- */
// Sertifikat dicek via subquery EXISTS
$sql = "
SELECT
  pd.id_pendaftaran,
  pd.id_pengguna,
  g.nama_lengkap,
  g.email,
  ps.id_posisi,
  ps.nama_posisi,
  k.id_kategori,
  k.nama_kategori,
  pd.tanggal_daftar,
  pd.status_verifikasi,
  pd.status_penilaian,
  pd.status_kelulusan,
  pd.link_timeline,
  pd.link_jobdesk,
  pd.link_portofolio,
  pd.link_foto_formal,
  pd.link_foto_transparan,
  pd.link_cv,
  pd.link_biodata,
  pd.link_dok_perkuliahan,
  pd.link_soal_asesmen,
  pd.link_hasil_ujian,
  p.id_penilaian,
  p.id_asesor,
  p.link_soal    AS link_soal_penilaian,
  p.link_jawaban AS link_jawaban_penilaian,
  p.skor,
  p.komentar,
  p.rekomendasi,
  p.tanggal_dinilai,
  EXISTS(SELECT 1 FROM sertifikat s WHERE s.id_pendaftaran = pd.id_pendaftaran) AS ada_sertifikat
FROM pendaftaran pd
JOIN pengguna g ON g.id_pengguna = pd.id_pengguna
JOIN posisi   ps ON ps.id_posisi = pd.id_posisi
JOIN kategori k  ON k.id_kategori = ps.id_kategori
LEFT JOIN penilaian p ON p.id_pendaftaran = pd.id_pendaftaran
WHERE 1=1
";

/* --------- Filter dinamis --------- */
$params = [];
if ($filter_kategori !== '') {
    $sql .= " AND k.id_kategori = ? ";
    $params[] = ['i', (int)$filter_kategori];
}
if ($filter_posisi !== '') {
    $sql .= " AND ps.id_posisi = ? ";
    $params[] = ['i', (int)$filter_posisi];
}
if ($filter_lulus !== '') { // 'belum' | 'lulus' | 'tidak'
    $sql .= " AND pd.status_kelulusan = ? ";
    $params[] = ['s', $filter_lulus];
}
if ($q_search !== '') {
    $sql .= " AND (g.nama_lengkap LIKE ? OR g.email LIKE ? OR ps.nama_posisi LIKE ? OR k.nama_kategori LIKE ?) ";
    $like = "%{$q_search}%";
    $params[] = ['s', $like];
    $params[] = ['s', $like];
    $params[] = ['s', $like];
    $params[] = ['s', $like];
}

$sql .= " ORDER BY COALESCE(p.tanggal_dinilai, pd.tanggal_daftar) DESC ";

/* --------- Helper binding dinamis --------- */
function _bindQuery($sql, $params)
{
    if (empty($params)) {
        return ['query' => $sql, 'bind' => function ($stmt) {}];
    }
    $types = '';
    $values = [];
    foreach ($params as $p) {
        $types  .= $p[0];   // 'i','s','d','b'
        $values[] = $p[1];
    }
    return [
        'query' => $sql,
        'bind'  => function ($stmt) use ($types, $values) {
            $stmt->bind_param($types, ...$values);
        }
    ];
}

/* --------- Eksekusi statement --------- */
$prep = _bindQuery($sql, $params);
$stmt = $koneksi->prepare($prep['query']);
if (!$stmt) {
    die('Prepare failed: ' . $koneksi->error);
}
$prep['bind']($stmt);
if (!$stmt->execute()) {
    die('Execute failed: ' . $stmt->error);
}
$result = $stmt->get_result();
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
$stmt->close();

/* --------- Util: mapping status → progress --------- */
function computeProgress($row)
{
    // 1 Dokumen Dikirim, 2 Verifikasi, 3 Soal&Jawaban, 4 Penilaian, 5 Rekomendasi, 6 Kelulusan, 7 Sertifikat
    $step = 1;

    // 2 Verifikasi
    if (in_array($row['status_verifikasi'], ['pending', 'diterima', 'ditolak'], true)) {
        $step = max($step, 2);
    }
    // 3 Soal & Jawaban
    if (
        !empty($row['link_soal_asesmen']) || !empty($row['link_hasil_ujian']) ||
        !empty($row['link_soal_penilaian']) || !empty($row['link_jawaban_penilaian'])
    ) {
        $step = max($step, 3);
    }
    // 4 Penilaian
    if ($row['status_penilaian'] === 'dinilai' || !empty($row['tanggal_dinilai'])) {
        $step = max($step, 4);
    }
    // 5 Rekomendasi
    if (!empty($row['rekomendasi'])) {
        $step = max($step, 5);
    }
    // 6 Kelulusan
    if (in_array($row['status_kelulusan'], ['lulus', 'tidak'], true)) {
        $step = max($step, 6);
    }
    // 7 Sertifikat
    if ((int)$row['ada_sertifikat'] === 1) {
        $step = 7;
    }
    $percent = (int) round(($step / 7) * 100);
    return [$step, $percent];
}

function badgeVerifikasi($s)
{
    if ($s === 'diterima') return '<span class="badge bg-success"><i class="fa-solid fa-check"></i> Diterima</span>';
    if ($s === 'ditolak')  return '<span class="badge bg-danger"><i class="fa-solid fa-xmark"></i> Ditolak</span>';
    return '<span class="badge bg-warning text-dark"><i class="fa-solid fa-hourglass-half"></i> Pending</span>';
}
function badgePenilaian($s)
{
    if ($s === 'dinilai') return '<span class="badge bg-info"><i class="fa-solid fa-clipboard-check"></i> Dinilai</span>';
    return '<span class="badge bg-secondary"><i class="fa-regular fa-clipboard"></i> Belum</span>';
}
function badgeKelulusan($s)
{
    if ($s === 'lulus') return '<span class="badge bg-success"><i class="fa-solid fa-award"></i> Lulus</span>';
    if ($s === 'tidak') return '<span class="badge bg-danger"><i class="fa-solid fa-ban"></i> Tidak Lulus</span>';
    return '<span class="badge bg-secondary"><i class="fa-regular fa-circle"></i> Belum</span>';
}
function badgeRekomendasi($s)
{
    if ($s === 'layak')        return '<span class="badge bg-success"><i class="fa-solid fa-thumbs-up"></i> Layak</span>';
    if ($s === 'belum layak')  return '<span class="badge bg-danger"><i class="fa-solid fa-thumbs-down"></i> Belum Layak</span>';
    return '<span class="badge bg-warning text-dark"><i class="fa-solid fa-magnifying-glass"></i> Di Review</span>';
}
function e($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Riwayat Penilaian | RELIPROVE</title>
    <link rel="icon" href="../aset/img/logo.png" type="image/png">

    <!-- Library -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../asesor/css/dashboard_asesor.css">

    <style>
        :root {
            --bg: #0c0f16;
            --card: #121725;
            --muted: #9aa3b2;
            --line: #242a36;

            /* Warna gradient */
            --primary: linear-gradient(135deg, #8b5dff, #6a3dff);
            /* ungu */
            --blue: linear-gradient(135deg, #3b82f6, #2563eb);
            --green: linear-gradient(135deg, #22c55e, #16a34a);
            --amber: linear-gradient(135deg, #f59e0b, #d97706);
            --red: linear-gradient(135deg, #ef4444, #dc2626);

            --gray-700: #374151;
            --gray-800: #1f2937;
        }

        /* === BODY & CONTENT === */
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

        .content {
            background: #0b0f1a;
            color: #e6e6f0;
            min-height: 100vh;
        }

        .card-surface {
            background: var(--bg-surface);
            border-radius: 20px;
            padding: 3px;
        }

        /* === CARD === */
        .card-riwayat {
            background: #111119;
            border: 1px solid #202033;
            border-radius: 20px;
            overflow: hidden;
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s;
        }

        .card-riwayat:hover {
            transform: translateY(-2px);
            border-color: #2c2c44;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .35);
        }

        .card-header {
            background: linear-gradient(180deg, #151525, #111119);
            border-bottom: 1px solid #1f1f2f;
        }

        /* === PROGRESS BAR === */
        .progress {
            height: 10px;
            background: #1b1b28;
            border-radius: 999px;
        }

        .progress-bar {
            background: linear-gradient(90deg, #9b5eff, #6b8afd);
        }

        /* === BUTTON === */
        .btn {
            border: none;
            border-radius: 12px;
            padding: 8px 16px;
            font-weight: 600;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        /* Ungu */
        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-primary:hover {
            filter: brightness(1.1);
        }

        /* Biru */
        .btn-info {
            background: var(--blue);
            color: #fff;
        }

        .btn-info:hover {
            filter: brightness(1.1);
        }

        /* Hijau */
        .btn-success {
            background: var(--green);
            color: #fff;
        }

        .btn-success:hover {
            filter: brightness(1.1);
        }

        /* Abu / Netral */
        .btn-secondary {
            background: var(--gray-700);
            color: #e5e7eb;
        }

        .btn-secondary:hover {
            background: var(--gray-800);
        }

        /* === BADGE === */
        .badge {
            border-radius: 999px;
            font-size: .8rem;
            font-weight: 600;
            padding: .45rem .8rem;
            border: none !important;
        }

        /* Override Bootstrap badge/bg-* */
        /* === BASE STYLE UNTUK SEMUA BADGE === */
        .badge {
            display: inline-flex;
            /* biar konten (ikon + teks) sejajar tengah */
            align-items: center;
            justify-content: center;
            border-radius: 100px;
            /* full pill */
            font-size: 0.80rem;
            /* sedikit lebih kecil biar pas */
            font-weight: 400;
            line-height: 1;
            padding: 0.45rem 0.85rem;
            /* tinggi & lebar seimbang */
            border: none !important;
            /* hilangkan border default bootstrap */
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
            /* sedikit depth */
            white-space: nowrap;
            /* jangan terpotong ke bawah */
            gap: .4rem;
            /* jarak ikon dengan teks */
            transition: filter .15s ease;
        }

        .badge:hover {
            filter: brightness(1.08);
            /* efek hover lembut */
        }

        /* === VARIAN WARNA BADGE SESUAI SISTEM KAMU === */
        .badge.bg-primary,
        .text-bg-primary {
            background: var(--primary) !important;
            /* gradient ungu */
            color: #fff !important;
        }

        .badge.bg-success,
        .text-bg-success {
            background: var(--green) !important;
            /* gradient hijau */
            color: #fff !important;
        }

        .badge.bg-info,
        .text-bg-info {
            background: var(--blue) !important;
            /* gradient biru */
            color: #fff !important;
        }

        .badge.bg-warning,
        .text-bg-warning {
            background: var(--amber) !important;
            /* gradient kuning/amber */
            color: #111 !important;
        }

        .badge.bg-danger,
        .text-bg-danger {
            background: var(--red) !important;
            /* gradient merah */
            color: #fff !important;
        }

        .badge.bg-secondary,
        .text-bg-secondary {
            background: var(--gray-700) !important;
            /* abu gelap */
            color: #e5e7eb !important;
        }


        /* === STEP === */
        .step {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid #24243a;
            background: #0f0f18;
            font-size: .85rem;
        }

        .step.done {
            border-color: #22c55e;
            background: #111a13;
            color: #b9f6ca;
        }

        .step.fail {
            border-color: #ef4444;
            background: #1b0b0b;
            color: #ffb3b3;
        }

        /* === DOT STATUS === */
        .icon-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }

        .dot-green {
            background: #22c55e;
        }

        .dot-yellow {
            background: #eab308;
        }

        .dot-red {
            background: #ef4444;
        }

        .dot-gray {
            background: #9ca3af;
        }

        .sidebar-brand {
            color: #9b5eff !important;
            font-weight: 600 !important;
            padding: 0 1rem;
            margin-bottom: 1.2rem;
        }
    </style>
</head>

<body>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-title">
            <h2 class="sidebar-brand d-none d-md-block text-center" style="margin-top: 37px; margin-bottom: 50px; color: #9b5eff;">RELIPROVE</h2>
            <button class="sidebar-close d-md-none" onclick="document.getElementById('sidebar').classList.remove('active')">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <a href="dashboard_asesor.php"><i class="fas fa-gauge"></i> Dashboard</a>
        <a href="bank_soal.php"><i class="fa-solid fa-book-open"></i> Bank Soal</a>
        <a href="daftar_penilaian.php"><i class="fas fa-clipboard-list"></i> Daftar Penilaian</a>
        <a href="riwayat_penilaian.php" class="active"><i class="fas fa-history"></i> Riwayat Penilaian</a>
        <!-- <a href="sertifikat.php"><i class="fas fa-graduation-cap"></i> Sertifikat</a> -->
        <a href="notifikasi.php"><i class="fas fa-bell"></i> Notifikasi</a>
        <a href="pengaturan.php"><i class="fas fa-gear"></i> Pengaturan</a>
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Keluar</a>
    </div>
    <?php
    include __DIR__ . '/partials/sidebar_asesor.php';
    ?>

    <!-- CONTENT -->
    <div class="content">
        <!-- TOPBAR -->
        <div class="topbar d-flex align-items-center justify-content-between px-3 px-md-4">
            <div class="left-group d-flex align-items-center gap-2">
                <div class="toggle-sidebar" onclick="document.getElementById('sidebar').classList.toggle('active')" style="cursor:pointer;">
                    <i class="fas fa-bars"></i>
                </div>
                <h4 class="m-0 fw-bold text-uppercase">Riwayat Penilaian</h4>
            </div>
            <div class="right-group">
                <div class="user-info d-flex align-items-center gap-2">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($nama) ?>&background=9b5eff&color=fff&rounded=true&size=40" alt="avatar">
                    <div class="user-meta">
                        <strong><?= e($nama) ?></strong><br>
                        <small>Asesor</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="card-surface">
            <div class="container py-4"
                style="
      --primary:#9b5eff;--bg-dark:#0b0f1a;--bg-card:#161a25;--bg-surface:#151923;
      --bg-row:#10141d;--bg-row-alt:#0d1119;--text:#e9e9ee;--muted:#a6abb7;
      --line:rgba(255,255,255,.06);--text-light:#f2f2f2;--text-muted:#999;
      --border-light:rgba(255,255,255,.08);--shadow-card:0 0 20px rgba(155,94,255,.15);
     ">
                <form class="filter-bar shadow-soft" method="get"
                    style="background:var(--bg-surface);border:2px solid var(--border-light);border-radius:14px;padding:19px;color:var(--text);">
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-md-3">
                            <label class="form-label small-label" style="color:var(--muted);">Kategori</label>
                            <select name="kategori" class="form-select"
                                style="background:var(--bg-row-alt);color:var(--text);border:1px solid var(--border-light);">
                                <option value="">Semua</option>
                                <?php foreach ($ddKategori as $opt): ?>
                                    <option value="<?= (int)$opt['id_kategori'] ?>" <?= $filter_kategori == $opt['id_kategori'] ? 'selected' : '' ?>>
                                        <?= e($opt['nama_kategori']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small-label" style="color:var(--muted);">Posisi</label>
                            <select name="posisi" class="form-select"
                                style="background:var(--bg-row-alt);color:var(--text);border:1px solid var(--border-light);">
                                <option value="">Semua</option>
                                <?php foreach ($ddPosisi as $opt): ?>
                                    <option value="<?= (int)$opt['id_posisi'] ?>" <?= $filter_posisi == $opt['id_posisi'] ? 'selected' : '' ?>>
                                        <?= e($opt['nama_posisi']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label small-label" style="color:var(--muted);">Kelulusan</label>
                            <select name="kelulusan" class="form-select"
                                style="background:var(--bg-row-alt);color:var(--text);border:1px solid var(--border-light);">
                                <option value="">Semua</option>
                                <option value="belum" <?= $filter_lulus === 'belum' ? 'selected' : '' ?>>Belum</option>
                                <option value="lulus" <?= $filter_lulus === 'lulus' ? 'selected' : '' ?>>Lulus</option>
                                <option value="tidak" <?= $filter_lulus === 'tidak' ? 'selected' : '' ?>>Tidak Lulus</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small-label" style="color:var(--muted);">Cari</label>
                            <input type="text" class="form-control" name="q" placeholder="Nama, email, posisi, kategori..."
                                value="<?= e($q_search) ?>"
                                style="background:var(--bg-row-alt);color:var(--text);border:1px solid var(--border-light);">
                        </div>
                        <div class="col-12 col-md-1 d-grid">
                            <button class="btn btn-soft"
                                style="background:var(--bg-row);border:1px solid var(--border-light);color:var(--text);">
                                <i class="fa-solid fa-filter"></i> Terapkan
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- GRID CARDS -->
            <div class="card-surface" id="blok-siap-dinilai">
                <div class="container pb-5">
                    <?php if (empty($data)): ?>
                        <div class="empty"></div> <i class="fa-regular fa-folder-open fa-2xl" style="display:block;margin-bottom:12px;"></i>
                        <div>Belum ada riwayat sesuai filter.</div>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($data as $i => $row):
                            [$step, $percent] = computeProgress($row);
                            $cardId   = 'card-' . $row['id_pendaftaran'];
                            $detailId = 'detail-' . $row['id_pendaftaran'];
                    ?>
                        <div class="col-12 col-md-6 col-xl-4">
                            <!-- CARD -->
                            <div id="<?= e($cardId) ?>" class="card-riwayat h-100"
                                style="display:flex;flex-direction:column;background:var(--bg-card);border:2px solid var(--border-light);border-radius:20px;overflow:hidden;box-shadow:none;color:var(--text);">

                                <!-- HEADER -->
                                <div class="p-3" style="background:var(--bg-surface);border-bottom:1px solid var(--border-light);">
                                    <div style="display:flex;gap:16px;align-items:flex-start;justify-content:space-between;">
                                        <div
                                            style="
    /* atur seberapa kiri: ganti -10px jadi -4..-16px sesuai selera */
    --shift: -15px;

    min-width:0;
    display:flex;
    gap:8px;
    align-items:flex-start;

    /* geser seluruh cluster (ikon + teks) ke kiri */
    margin-left: var(--shift);
">
                                            <span class="icon-dot <?= $dotClass ?>"></span>

                                            <div style="min-width:0;">
                                                <div
                                                    style="
            font-weight:700;
            font-size:clamp(1rem,0.95rem + 0.25vw,1.2rem);
            color:var(--text-light);
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
            line-height:1.2;
            /* kalau mau geser HANYA teks (tanpa ikon), aktifkan ini lalu hapus margin-left di parent):
               transform: translateX(var(--shift)); */
          ">
                                                    <?= e($row['nama_lengkap']) ?>
                                                </div>

                                                <div style="color:var(--muted);font-size:.9rem;margin-top:8px;line-height:1.35;">
                                                    · Daftar : <?= date('d M Y', strtotime($row['tanggal_daftar'])) ?><br>
                                                    <?php if (!empty($row['tanggal_dinilai'])): ?>
                                                        · Dinilai : <?= date('d M Y', strtotime($row['tanggal_dinilai'])) ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>


                                        <div style="text-align:right;min-width:50%;max-width:50%;">
                                            <div style="color:var(--text);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                <i class="fa-solid fa-briefcase"></i> <?= e($row['nama_posisi']) ?>
                                            </div>
                                            <div style="color:var(--muted);font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                <?= e($row['nama_kategori']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- BODY -->
                                <div class="card-body p-3" style="font-size:.94rem;">
                                    <div class="d-flex flex-wrap gap-2 mb-4 justify-content-center" style="font-size:18px;">
                                        <?= badgeVerifikasi($row['status_verifikasi']) ?>
                                        <?= badgePenilaian($row['status_penilaian']) ?>
                                        <?= badgeRekomendasi($row['rekomendasi'] ?? '') ?>
                                        <?= badgeKelulusan($row['status_kelulusan']) ?>
                                    </div>



                                    <div style="display:grid;grid-template-columns:3fr 90px 1fr;gap:16px;align-items:center;">
                                        <div>
                                            <div style="color:var(--muted);font-size:.88rem;margin-bottom:8px;">Progres</div>
                                            <div class="progress"
                                                style="height:10px;background:var(--bg-row-alt);border:1px solid var(--line);border-radius:999px;overflow:hidden;margin:0;">
                                                <div class="progress-bar" role="progressbar"
                                                    style="width: <?= $percent ?>%;background:var(--primary);"
                                                    aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                            <div style="color:var(--muted);font-size:.82rem;margin-top:8px;"><?= $percent ?>%</div>
                                        </div>

                                        <div>
                                            <div style="color:var(--muted);font-size:.88rem;margin-bottom:6px;text-align:center;">Skor</div>
                                            <div style="font-weight:800;color:var(--text-light);font-size:1.15rem;text-align:center;">
                                                <?= is_null($row['skor']) ? '-' : (int)$row['skor'] ?>
                                            </div>
                                        </div>

                                        <div>
                                            <div style="color:var(--muted);font-size:.88rem;margin-bottom:6px;text-align:center;">Status</div>
                                            <div class="truncate" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text-muted);text-align:center;">-</div>
                                        </div>
                                    </div>

                                    <div style="height:1px;background:var(--line);margin:16px 0;"></div>

                                    <div class="stepper" style="display:flex;flex-direction:column;gap:10px;margin-top:25px;">
                                        <?php
                                        $steps = [
                                            ['label' => 'Dokumen Dikirim', 'state' => $step >= 1 ? 'done' : ''],
                                            ['label' => 'Verifikasi', 'state' => ($row['status_verifikasi'] === 'ditolak' ? 'fail' : ($step >= 2 ? 'done' : ''))],
                                            ['label' => 'Soal & Jawaban', 'state' => $step >= 3 ? 'done' : ''],
                                            ['label' => 'Penilaian', 'state' => $step >= 4 ? 'done' : ''],
                                            ['label' => 'Rekomendasi', 'state' => $step >= 5 ? 'done' : ''],
                                            ['label' => 'Kelulusan', 'state' => ($row['status_kelulusan'] === 'tidak' ? 'fail' : ($step >= 6 ? 'done' : ''))],
                                            ['label' => 'Sertifikat', 'state' => $step >= 7 ? 'done' : ''],
                                        ];
                                        foreach ($steps as $s) {
                                            $dot = 'var(--bg-row)';
                                            if ($s['state'] === 'done') $dot = 'var(--primary)';
                                            if ($s['state'] === 'fail') $dot = '#ef4444';
                                            echo '<div style="display:flex;align-items:center;gap:10px;color:var(--text);">
                            <span style="width:10px;height:10px;border-radius:50%;background:' . $dot . ';' .
                                                ($s['state'] === 'done' ? 'box-shadow:0 0 0 4px rgba(155,94,255,.15);' : '') .
                                                ($s['state'] === 'fail' ? 'box-shadow:0 0 0 4px rgba(239,68,68,.18);' : '') .
                                                'display:inline-block;"></span>
                            <span>' . $s['label'] . '</span>
                          </div>';
                                        }
                                        ?>
                                    </div>
                                </div>

                                <!-- FOOTER -->
                                <div class="p-3 d-flex flex-wrap gap-2 justify-content-between"
                                    style="background:var(--bg-surface);border-top:1px solid var(--border-light);">
                                    <div class="ms-auto d-flex gap-2">
                                        <button class="btn btn-soft btn-sm"
                                            style="background:var(--bg-row);border:1px solid var(--border-light);color:var(--text);border-radius:12px;padding:8px 12px;"
                                            onclick="exportJPG('#<?= e($cardId) ?>','riwayat-<?= (int)$row['id_pendaftaran'] ?>.jpg')">
                                            <i class="fa-regular fa-image"></i> Cetak JPG
                                        </button>
                                        <button class="btn btn-soft btn-sm"
                                            style="background:var(--bg-row);border:1px solid var(--border-light);color:var(--text);border-radius:12px;padding:8px 12px;"
                                            data-bs-toggle="modal" data-bs-target="#detailModal<?= (int)$row['id_pendaftaran'] ?>">
                                            <i class="fa-regular fa-eye"></i> Detail
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <!-- /CARD -->
                        </div>


                        <!-- MODAL DETAIL -->
                        <div class="modal fade" id="detailModal<?= (int)$row['id_pendaftaran'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content" id="<?= e($detailId) ?>"
                                    style="background:var(--bg-card);color:var(--text);border:1px solid var(--border-light);border-radius:16px;overflow:hidden;">
                                    <div class="modal-header" style="background:var(--bg-surface);border-bottom:1px solid var(--border-light);">
                                        <div style="display:flex;flex-direction:column;gap:2px;">
                                            <div style="font-weight:700;"><i class="fa-regular fa-id-card"></i> Detail Penilaian</div>
                                            <div style="color:var(--muted);font-size:.9rem;"><?= e($row['nama_lengkap']) ?></div>
                                        </div>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                                            <div style="background:var(--bg-surface);border:1px solid var(--border-light);border-radius:12px;padding:14px;">
                                                <div style="font-weight:600;color:var(--muted);margin-bottom:10px;">Identitas</div>
                                                <div style="display:grid;grid-template-columns:38% 62%;row-gap:8px;">
                                                    <div style="color:var(--muted);">Nama</div>
                                                    <div><?= e($row['nama_lengkap']) ?></div>
                                                    <div style="color:var(--muted);">Email</div>
                                                    <div><?= e($row['email']) ?></div>
                                                    <div style="color:var(--muted);">Kategori</div>
                                                    <div><?= e($row['nama_kategori']) ?></div>
                                                    <div style="color:var(--muted);">Posisi</div>
                                                    <div><?= e($row['nama_posisi']) ?></div>
                                                    <div style="color:var(--muted);">Tanggal Daftar</div>
                                                    <div><?= date('d M Y H:i', strtotime($row['tanggal_daftar'])) ?></div>
                                                </div>
                                            </div>
                                            <div style="background:var(--bg-surface);border:1px solid var(--border-light);border-radius:12px;padding:14px;">
                                                <div style="font-weight:600;color:var(--muted);margin-bottom:10px;">Status</div>
                                                <div style="display:grid;grid-template-columns:38% 62%;row-gap:8px;align-items:center;">
                                                    <div style="color:var(--muted);">Verifikasi</div>
                                                    <div><?= badgeVerifikasi($row['status_verifikasi']) ?></div>
                                                    <div style="color:var(--muted);">Penilaian</div>
                                                    <div><?= badgePenilaian($row['status_penilaian']) ?></div>
                                                    <div style="color:var(--muted);">Rekomendasi</div>
                                                    <div><?= badgeRekomendasi($row['rekomendasi'] ?? '') ?></div>
                                                    <div style="color:var(--muted);">Kelulusan</div>
                                                    <div><?= badgeKelulusan($row['status_kelulusan']) ?></div>
                                                    <div style="color:var(--muted);">Sertifikat</div>
                                                    <div><?= ((int)$row['ada_sertifikat'] === 1 ? '<span class="badge" style="background:var(--primary);border:none;border-radius:999px;"><i class="fa-solid fa-certificate"></i> Ada</span>' : '-') ?></div>
                                                </div>
                                            </div>
                                        </div>

                                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">
                                            <div style="background:var(--bg-surface);border:1px solid var(--border-light);border-radius:12px;padding:14px;">
                                                <div style="font-weight:600;color:var(--muted);margin-bottom:10px;">Skor & Komentar</div>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <div style="color:var(--muted);">Skor</div>
                                                        <div style="font-size:1.2rem;font-weight:800;"><?= is_null($row['skor']) ? '-' : (int)$row['skor'] ?>/10</div>
                                                    </div>
                                                    <?php if (!empty($row['tanggal_dinilai'])): ?>
                                                        <div class="text-end" style="color:var(--muted);">Dinilai: <?= date('d M Y H:i', strtotime($row['tanggal_dinilai'])) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div style="margin-top:10px;">
                                                    <div style="color:var(--muted);">Komentar</div>
                                                    <div><?= nl2br(e($row['komentar'] ?? '-')) ?></div>
                                                </div>
                                            </div>

                                            <div style="background:var(--bg-surface);border:1px solid var(--border-light);border-radius:12px;padding:14px;">
                                                <div style="font-weight:600;color:var(--muted);margin-bottom:10px;">Tautan Dokumen</div>
                                                <?php foreach (
                                                    [
                                                        'Timeline'               => $row['link_timeline'] ?? '',
                                                        'Jobdesk'                => $row['link_jobdesk'] ?? '',
                                                        'Portofolio'             => $row['link_portofolio'] ?? '',
                                                        'Foto Formal'            => $row['link_foto_formal'] ?? '',
                                                        'Foto Transparan'        => $row['link_foto_transparan'] ?? '',
                                                        'CV'                     => $row['link_cv'] ?? '',
                                                        'Biodata'                => $row['link_biodata'] ?? '',
                                                        'Dokumen Pendidikan'     => $row['link_dok_perkuliahan'] ?? '',
                                                        'Soal (pendaftaran)'     => $row['link_soal_asesmen'] ?? '',
                                                        'Hasil (pendaftaran)'    => $row['link_hasil_ujian'] ?? '',
                                                        'Soal (penilaian)'       => $row['link_soal_penilaian'] ?? '',
                                                        'Jawaban (penilaian)'    => $row['link_jawaban_penilaian'] ?? '',
                                                    ] as $label => $url
                                                ): if (empty($url)) continue; ?>
                                                    <div class="d-flex align-items-center justify-content-between"
                                                        style="padding:8px 0;border-bottom:1px solid var(--line);">
                                                        <div class="truncate" style="color:var(--text);"><i class="fa-regular fa-file-lines me-2"></i><?= e($label) ?></div>
                                                        <div class="btn-group btn-group-sm">
                                                            <button class="btn btn-soft"
                                                                style="background:var(--bg-row);border:1px solid var(--border-light);color:var(--text);"
                                                                onclick="copyToClipboard('<?= e($url) ?>')" title="Salin Link">
                                                                <i class="fa-regular fa-copy"></i>
                                                            </button>
                                                            <a class="btn btn-soft"
                                                                style="background:var(--bg-row);border:1px solid var(--border-light);color:var(--text);"
                                                                href="<?= e($url) ?>" target="_blank" rel="noopener" title="Buka">
                                                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                                            </a>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php
                                                $hasAny = !empty($row['link_timeline']) || !empty($row['link_jobdesk']) || !empty($row['link_portofolio']) || !empty($row['link_foto_formal']) ||
                                                    !empty($row['link_foto_transparan']) || !empty($row['link_cv']) || !empty($row['link_biodata']) || !empty($row['link_dok_perkuliahan']) ||
                                                    !empty($row['link_soal_asesmen']) || !empty($row['link_hasil_ujian']) || !empty($row['link_soal_penilaian']) || !empty($row['link_jawaban_penilaian']);
                                                if (!$hasAny) echo '<div class="text-muted" style="color:var(--text-muted)!important;">Tidak ada tautan.</div>';
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer" style="background:var(--bg-surface);border-top:1px solid var(--border-light);">
                                        <button class="btn btn-soft"
                                            style="background:var(--bg-row);border:1px solid var(--border-light);color:var(--text);border-radius:10px;padding:8px 14px;"
                                            onclick="exportJPG('#<?= e($detailId) ?>','detail-<?= (int)$row['id_pendaftaran'] ?>.jpg')">
                                            <i class="fa-regular fa-image"></i> Cetak JPG
                                        </button>
                                        <button class="btn btn-soft"
                                            style="background:var(--bg-row);border:1px solid var(--border-light);color:var(--text);border-radius:10px;padding:8px 14px;"
                                            data-bs-dismiss="modal">
                                            <i class="fa-regular fa-circle-xmark"></i> Tutup
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- EXPORT JPG (client-side) -->
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script>
        function exportJPG(targetSelector, filename) {
            const node = document.querySelector(targetSelector);
            if (!node) return;
            html2canvas(node, {
                backgroundColor: '#0E111A',
                scale: window.devicePixelRatio < 2 ? 2 : window.devicePixelRatio, // tajam
                useCORS: true,
                logging: false
            }).then(canvas => {
                const link = document.createElement('a');
                link.download = filename || 'export.jpg';
                link.href = canvas.toDataURL('image/jpeg', 0.95);
                link.click();
            });
        }
        // fallback kalau fungsi copyToClipboard belum ada di halamanmu
        if (typeof copyToClipboard === 'undefined') {
            window.copyToClipboard = function(text) {
                navigator.clipboard?.writeText(text).catch(() => {});
            }
        }
    </script>


    </div><!-- /content -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                const toast = document.createElement('div');
                toast.textContent = 'Link disalin';
                toast.style.position = 'fixed';
                toast.style.right = '16px';
                toast.style.bottom = '16px';
                toast.style.padding = '10px 14px';
                toast.style.background = '#111a13';
                toast.style.border = '1px solid #27402f';
                toast.style.borderRadius = '10px';
                toast.style.color = '#b9f6ca';
                toast.style.zIndex = '9999';
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 1200);
            });
        }
    </script>
</body>

</html>