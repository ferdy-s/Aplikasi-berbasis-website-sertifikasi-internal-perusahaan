<?php
// =========================
// sertifikat.php - RELIPROVE (No-Dompdf, QuickChart QR, Cards Only, Cancel Issue)
// =========================

session_start();
// if (!isset($_SESSION['peran']) || !in_array($_SESSION['peran'], ['asesor','admin','superadmin'])) { header('Location: ../login.php'); exit; }
date_default_timezone_set('Asia/Jakarta');

// ============== KONEKSI DB ==============
$cfg = __DIR__ . '/../config/koneksi.php';
if (!file_exists($cfg)) {
    die('Konfigurasi DB tidak ditemukan: ../config/koneksi.php');
}
require_once $cfg;
// Normalisasi var koneksi
if (!isset($koneksi) || !($koneksi instanceof mysqli)) {
    if (isset($conn) && $conn instanceof mysqli) {
        $koneksi = $conn;
    } else {
        die('Koneksi DB tidak tersedia. Pastikan config/koneksi.php membuat $koneksi (mysqli).');
    }
}

// ============== DIREKTORI FILE SERTIFIKAT ==============
$CERT_DIR = realpath(__DIR__ . '/../storage/sertifikat');
if ($CERT_DIR === false) {
    $CERT_DIR = __DIR__ . '/../storage/sertifikat';
    @mkdir($CERT_DIR, 0777, true);
}
$CERT_DIR = rtrim($CERT_DIR, '/\\') . DIRECTORY_SEPARATOR;

// ============== HELPER ==============
function buildDataUriImage(string $absPath): ?string
{
    if (!is_file($absPath)) return null;
    $data = @file_get_contents($absPath);
    if ($data === false) return null;
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $mime = $ext === 'svg' ? 'image/svg+xml' : "image/{$ext}";
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

function esc($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function ensureDirsWritable($path): bool
{
    return is_dir($path) && is_writable($path);
}
function slugify($t): string
{
    $t = strtolower(trim($t));
    $t = preg_replace('~[^\pL\d]+~u', '-', $t);
    $t = preg_replace('~^-+|-+$~', '', $t);
    $t = preg_replace('~[^-\w]+~', '', $t);
    return $t ?: 'sertifikat';
}
function generateCertificateNumber(mysqli $db): string
{
    $ym = date('Ym'); // contoh: 202508
    $prefix = "RELI-$ym-";

    $st = $db->prepare("
        SELECT MAX(nomor_sertifikat) AS max_nomor
        FROM sertifikat
        WHERE nomor_sertifikat LIKE CONCAT(?, '%')
    ");
    $st->bind_param('s', $prefix);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    if (!empty($row['max_nomor'])) {
        // ambil 4 digit paling kanan sebagai sequence
        $last = (int)substr($row['max_nomor'], -4);
        $seq = $last + 1;
    } else {
        $seq = 1;
    }

    return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

function generateSlug(mysqli $db, string $nama, string $nomor): string
{
    $base = slugify($nama . '-' . $nomor);
    $slug = $base;
    $i = 1;
    $st = $db->prepare("SELECT COUNT(*) c FROM sertifikat WHERE slug_sertifikat=?");
    do {
        $st->bind_param('s', $slug);
        $st->execute();
        $exists = ((int)$st->get_result()->fetch_assoc()['c']) > 0;
        if ($exists) $slug = $base . '-' . $i++;
    } while ($exists);
    return $slug;
}
function mapLevelByScore(?int $skor): ?string
{
    if ($skor === null) return null;
    if ($skor >= 9) return 'ahli';
    if ($skor >= 7) return 'menengah';
    if ($skor >= 1) return 'dasar';
    return null;
}
function getJenisKategori(mysqli $db, int $idPosisi): ?string
{
    $sql = "SELECT b.nama_bidang FROM posisi po JOIN kategori k ON k.id_kategori=po.id_kategori JOIN bidang b ON b.id_bidang=k.id_bidang WHERE po.id_posisi=?";
    $st = $db->prepare($sql);
    $st->bind_param('i', $idPosisi);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) return null;
    return (strtolower($row['nama_bidang']) === 'teknologi') ? 'IT' : 'Creative';
}
function buildQuickChartQrUrl(string $data, int $size = 300, int $margin = 1, string $ec = 'M'): string
{
    return 'https://quickchart.io/qr?' . http_build_query(['text' => $data, 'size' => $size, 'margin' => $margin, 'ecLevel' => $ec]);
}

// ============== TEMPLATE SERTIFIKAT (A4 LANDSCAPE, HI‑TECH, PRINT-FRIENDLY) ==============
function buildCertificateHTML(array $ctx): string
{
    $nama     = esc($ctx['nama']);
    $posisi   = esc($ctx['posisi']);
    $kategori = esc($ctx['kategori'] ?? '');
    $bidang   = esc($ctx['bidang'] ?? '');
    $nomor    = esc($ctx['nomor']);
    $tgl      = esc(date('d M Y', strtotime($ctx['tanggal_terbit'])));
    $level    = esc($ctx['level'] ?? '-');
    $tipe     = esc($ctx['tipe'] ?? 'kompetensi');
    $qrUrl    = esc($ctx['qr_url']);
    $urlv     = esc($ctx['url_verifikasi']);
    $logoAbs  = realpath(__DIR__ . '/../aset/img/logo.png');   // path file di server
    $logoData = $logoAbs ? buildDataUriImage($logoAbs) : null;
    $ctx['logo_url'] = $logoData ?: ''; // kosongkan jika gagal
    $logo = esc($ctx['logo_url'] ?? '');
    $isIT     = (($ctx['jenis_kategori'] ?? 'IT') === 'IT');
    $primary  = $isIT ? '#7c3cff' : '#ff4fb0';
    $accent   = $isIT ? '#22d3ee' : '#22c55e';
    $finger   = esc(substr(sha1(($ctx['nomor'] ?? '') . ($ctx['tanggal_terbit'] ?? '')), 0, 12));

    return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sertifikat {$nama}</title>
<style>
/* ====== CETAK: paksa warna background tampil ====== */
*{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
@page { size: A4 landscape; margin: 0; }

/* --- BASE PREVIEW --- */
html,body{height:100%}
body{
  margin:0; background:#0b0f1a; font-family: Inter, system-ui, Segoe UI, Arial, sans-serif;
}
.sheet{
  width:297mm; height:210mm; margin:0 auto; position:relative; overflow:hidden;
  background: radial-gradient(1200px 600px at 110% 110%, #0f0a26 10%, transparent 60%),
              radial-gradient(1200px 600px at  -10%  -10%, #031629 10%, transparent 60%),
              linear-gradient(180deg,#0a0f20 0%, #070b17 100%);
  box-shadow: 0 18px 40px rgba(0,0,0,.55);
}
.safe{
  position:absolute; inset:10mm; border-radius:6mm; padding:8mm;
  background:
    radial-gradient(600px 200px at 20% 0%, {$primary}22, transparent 60%),
    radial-gradient(600px 200px at 80% 100%, {$accent}22, transparent 60%);
  outline:1px solid #ffffff10;
}
.safe:before{
  content:""; position:absolute; inset:0; border-radius:6mm; border:1.6mm solid transparent;
  background: linear-gradient(135deg, {$accent}, {$primary}) border-box;
  -webkit-mask: linear-gradient(#000 0 0) padding-box, linear-gradient(#000 0 0);
  -webkit-mask-composite: xor; mask-composite: exclude; opacity:.18; pointer-events:none;
}
/* ❌ HAPUS overlay grid yang menimpa teks/gambar */
/* .safe:after{ ... }  -- dihapus */

/* --- PRINT BUTTON: selalu terlihat, tidak ikut tercetak --- */
.printbar{
  position:fixed; right:18px; top:16px; z-index:9999;
}
.printbar button{
  padding:10px 14px; border:1px solid #2b3147; border-radius:10px;
  background:#101629; color:#e9eeff; font-weight:800; cursor:pointer;
  box-shadow:0 8px 20px rgba(0,0,0,.35);
}
.printbar button:hover{ filter:brightness(1.06); }
@media print { .printbar{ display:none !important; } }

/* --- HEADER --- */
.header{display:flex;justify-content:space-between;align-items:center;
  padding: 4mm 6mm 6mm; border-bottom:1px solid #ffffff14; color:#e9eeff;}
.brand{ display:flex; gap:8mm; align-items:center }
.logo{
  width:18mm; height:18mm; border-radius:8mm; 
  object-fit:cover; display:block;
  box-shadow:0 0 18px rgba(0,0,0,.35);
}
.title-wrap .title{ font-weight:900; letter-spacing:1.2px; font-size:18pt; margin:0 }
.badge{
  display:inline-block; margin-top:2mm; padding:2.5mm 4mm; border-radius:999px; font-weight:800;
  font-size:9pt; text-transform:uppercase; color:#001016;
  background: linear-gradient(90deg, {$accent}, {$primary});
}
.meta{ text-align:right; color:#c7cbe3; font-size:10pt }

/* --- 2 COLUMNS (versi ringan & proporsional) --- */
.main{
  display:grid;
  grid-template-columns: 1.25fr 0.95fr;
  gap:10mm;
  padding:8mm 6mm 0;
  color:#f0f4ff;
  /* typographic defaults */
  font-kerning: normal;
  font-variant-numeric: proportional-nums; /* angka proporsional */
  font-feature-settings: "kern","liga","clig","calt"; /* fitur tipografi dasar */
}

.h1{
  font-size:26pt;          /* sedikit lebih kecil agar tak terlalu berat */
  font-weight:500;         /* REGULAR–MEDIUM, tidak tebal */
  letter-spacing:.2px;     /* kerning ringan */
  margin:0 0 3mm;
}

.h2{
  font-size:20pt;
  font-weight:600;         /* MEDIUM */
  text-transform:uppercase;/* NAMA FULL KAPITAL */
  letter-spacing:1px;      /* tampilan tegas tapi tidak ‘bold’ */
  color:{$accent};
  text-shadow:0 0 8px {$accent}55;
  margin:2mm 0 0;          /* posisi lebih turun sedikit */
  line-height:1.15;        /* lebih rapat agar kompak */
}

.p1{
  font-size:11.5pt;
  line-height:1.6;
  color:#c6cbe3;
  margin-top:5mm;
  font-weight:400;         /* regular */
}

.chips{
  display:flex; gap:4mm; flex-wrap:wrap; margin-top:6mm;
  font-weight:500;         /* badge tidak terlalu tebal */
}

.chip{
  border:1px solid {$accent}66;
  background:linear-gradient(180deg,#0c1826,#0a1421);
  color:#eafafe;
  border-radius:10mm;
  padding:2.2mm 4mm;
  font-size:9.5pt;
  font-weight:600;         /* medium, bukan bold */
  letter-spacing:.2px;
}

/* Opsional: kalau ingin nama (h2) sedikit bergeser naik/turun relatif kartu di kanan,
   tinggal ubah margin-top di .h2, misalnya: margin-top: 6mm; */

/* info cards */
.kgrid{ margin-top:8mm; display:grid; grid-template-columns: repeat(3,1fr); gap:5mm }
.card{
  border:1px solid #ffffff18; border-radius:4mm; padding:4mm 5mm;
  background: linear-gradient(180deg,#0e1423,#0b101b);
  box-shadow: 0 6px 18px rgba(0,0,0,.35), inset 0 0 28px #ffffff0a;
}
.k{ font-size:8.5pt; letter-spacing:.6px; text-transform:uppercase; color:#9fb0d9; margin-bottom:1mm }
.v{ font-size:13.5pt; font-weight:900; color:#f6f8ff }

/* QR panel */
.qrpanel{
  border:1px solid #ffffff18; border-radius:6mm; padding:8mm;
  background: linear-gradient(180deg,#0f1424,#0a0f1b);
  box-shadow: 0 10px 26px rgba(0,0,0,.35), inset 0 0 30px #ffffff0a;
  display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6mm
}
.qrpanel img{ width:52mm; height:52mm; border:3mm solid #050812; border-radius:4mm; box-shadow:0 10px 22px #000c }
.url{ font-size:9.5pt; text-align:center; color:#cfe9ff; word-break:break-all }

/* footer */
.footer{ display:flex; justify-content:space-between; align-items:flex-end;
  padding:8mm 6mm 2mm; position:absolute; left:10mm; right:10mm; bottom:10mm; }
.sign{text-align:center; color:#dfe6ff}
.sign .line{ width:65mm; height:2px; background:#29334e; margin:15mm auto 3mm }
.sign .nm{ font-weight:800 }
.fingerprint{
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  font-size:9pt; color:#9fb0d9; border:1px dashed #2b3754; border-radius:3mm;
  padding:3mm 4mm; background:#0b1222; opacity:.95
}
.fingerprint b{ color: {$accent}; }

/* --- PRINT MODE: jaga warna & hilangkan shadow layar --- */
@media print {
  body{ background:#0b0f1a !important; }
  .sheet{
    box-shadow:none !important;
    background: radial-gradient(1200px 600px at 110% 110%, #0f0a26 10%, transparent 60%),
                radial-gradient(1200px 600px at  -10%  -10%, #031629 10%, transparent 60%),
                linear-gradient(180deg,#0a0f20 0%, #070b17 100%) !important;
  }
  .safe{
    background:
      radial-gradient(600px 200px at 20% 0%, {$primary}22, transparent 60%),
      radial-gradient(600px 200px at 80% 100%, {$accent}22, transparent 60%) !important;
  }
}

/* --- OPSIONAL: kalau suatu saat butuh grid lagi, aktifkan dengan .safe.gridfx --- */
.safe.gridfx:after{
  content:""; position:absolute; inset:0; border-radius:6mm; pointer-events:none; opacity:.16;
  background-image:
    linear-gradient(transparent 23px,#12182a 24px),
    linear-gradient(90deg,transparent 23px,#12182a 24px);
  background-size:24px 24px;
}
@media print { .safe.gridfx:after{ display:none !important; } }

</style>
</head>
<body>
  <!-- tombol cetak selalu tampil di layar -->
  <div class="printbar"><button onclick="window.print()">🖨️ Cetak / Simpan PDF</button></div>

  <div class="sheet">
    <div class="safe">
      <div class="header">
        <div class="brand">
          <img class="logo" src="{$logo}" alt="RELIPROVE" />
          <div class="title-wrap">
            <h1 class="title">RELIPROVE</h1>
            <span class="badge">{$tipe}</span>
          </div>
        </div>
        <div class="meta">Nomor Sertifikat<br><b>{$nomor}</b></div>
      </div>

      <div class="main">
        <div>
          <div class="h1">SERTIFIKAT KOMPETENSI</div>
          <div style="opacity:.9">Diberikan kepada</div>
          <div class="h2">{$nama}</div>
          <p class="p1">
            Atas pencapaian kompetensi pada posisi <b>{$posisi}</b> pada kategori
            <b>{$kategori}</b> — bidang <b>{$bidang}</b>.
          </p>
          <div class="chips">
            <span class="chip">Digital Verified</span>
            <span class="chip">QR Secured</span>
            <span class="chip">Holographic Track</span>
            <span class="chip">RELIPROVE</span>
          </div>

          <div class="kgrid">
            <div class="card"><div class="k">Level</div><div class="v">{$level}</div></div>
            <div class="card"><div class="k">Tanggal Terbit</div><div class="v">{$tgl}</div></div>
            <div class="card"><div class="k">Jenis</div><div class="v">{$tipe}</div></div>
          </div>
        </div>

        <div class="qrpanel">
          <img src="{$qrUrl}" alt="QR Verifikasi">
          <div class="url">Verifikasi:<br><a href="{$urlv}" style="color:#cfe9ff; text-decoration:none">{$urlv}</a></div>
        </div>
      </div>

      <div class="footer">
        <div class="sign">
          <div class="line"></div>
          <div class="nm">PT. Reliable Future Technology</div>
          <div style="font-size:10pt; color:#9aa3c7">Pejabat Berwenang (TTE)</div>
        </div>
        <div class="fingerprint">Document Fingerprint: <b>{$finger}</b></div>
      </div>
    </div>
  </div>
</body>
</html>
HTML;
}



// ============== FETCH DETAIL ==============
function getPendaftaranDetail(mysqli $db, int $id): ?array
{
    $sql = "SELECT p.id_pendaftaran,p.id_pengguna,p.id_posisi,pg.nama_lengkap,pg.asal_peserta,pg.nama_instansi,
               po.nama_posisi,k.nama_kategori,b.nama_bidang,pe.skor,pe.rekomendasi,
               p.status_kelulusan,p.status_penilaian,p.status_verifikasi
        FROM pendaftaran p
        JOIN pengguna pg ON pg.id_pengguna=p.id_pengguna
        JOIN posisi po   ON po.id_posisi  =p.id_posisi
        JOIN kategori k  ON k.id_kategori =po.id_kategori
        JOIN bidang b    ON b.id_bidang   =k.id_bidang
        LEFT JOIN penilaian pe ON pe.id_pendaftaran=p.id_pendaftaran
        WHERE p.id_pendaftaran=? LIMIT 1";
    $st = $db->prepare($sql);
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return $row ?: null;
}

// ============== VERIFIKASI QR (?cek=) ==============
// ============== HANDLER VERIFIKASI QR (?cek=KODE) ==============
if (isset($_GET['cek'])) {
    $kode = substr($_GET['cek'], 0, 100);
    $ip   = $_SERVER['REMOTE_ADDR'] ?? null;

    // catat scan
    if ($stmt = $koneksi->prepare("INSERT INTO verifikasi_qr(kode_qr, ip_address) VALUES (?, ?)")) {
        $stmt->bind_param('ss', $kode, $ip);
        $stmt->execute();
    }

    // ambil info sertifikat
    $q = $koneksi->prepare("SELECT s.*, pg.nama_lengkap, po.nama_posisi
                            FROM sertifikat s
                            JOIN pendaftaran p ON p.id_pendaftaran = s.id_pendaftaran
                            JOIN pengguna pg    ON pg.id_pengguna   = p.id_pengguna
                            JOIN posisi po      ON po.id_posisi     = p.id_posisi
                            WHERE s.kode_qr = ?
                            LIMIT 1");
    $q->bind_param('s', $kode);
    $q->execute();
    $cek = $q->get_result()->fetch_assoc();

    header('Content-Type: text/html; charset=UTF-8');

    // tema mini
    $css = '<style>
        :root{ --bg:#0b0f1a; --card:#121826; --ok:#10b981; --text:#eaf1ff; --muted:#a8b3cf; --line:#1e2537; --accent:#7c3cff; }
        *{box-sizing:border-box; -webkit-print-color-adjust:exact; print-color-adjust:exact;}
        body{margin:0; background:var(--bg); font-family:Inter,system-ui,Segoe UI,Arial,sans-serif; color:var(--text);}
        .wrap{max-width:1100px; margin:28px auto; padding:0 16px;}
        .card{background:var(--card); border:1px solid var(--line); border-radius:14px; padding:18px; box-shadow:0 12px 30px #0005;}
        .head{display:flex; justify-content:space-between; gap:12px; align-items:center; border-bottom:1px solid var(--line); padding-bottom:12px;}
        .badge{display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; font-weight:800; font-size:14px; color:#06291b; background:linear-gradient(90deg,#34d399,#10b981);}
        .muted{color:var(--muted);}
        .grid{display:grid; gap:16px; grid-template-columns: 1.2fr 1fr; margin-top:16px;}
        .info b{font-weight:900}
        .kv{display:grid; grid-template-columns:140px 1fr; gap:6px 10px; margin-top:8px; font-size:15px;}
        .kv .k{color:var(--muted); text-transform:uppercase; letter-spacing:.6px; font-size:12px;}
        .panel{background:#0f1424; border:1px solid var(--line); border-radius:12px; padding:14px;}
        iframe, embed{width:100%; height:640px; border:none; border-radius:12px; background:#fff;}
        .actions{display:flex; gap:10px; align-items:center; margin-top:14px;}
        .btn{padding:10px 14px; border-radius:10px; border:1px solid #2b3754; background:#101629; color:#e9eeff; font-weight:800; text-decoration:none; display:inline-block}
        .finger{font-family:ui-monospace,Menlo,Consolas,monospace; font-size:13px; color:#b5c0de; padding:8px 10px; border:1px dashed #2b3754; border-radius:10px; background:#0b1222;}
        .ok{color:#052b1c}
        .brand{display:flex; align-items:center; gap:12px;}
        .logo{width:40px; height:40px; border-radius:12px; background:conic-gradient(from 0deg, #7c3cff, #22d3ee, #7c3cff); box-shadow:0 0 18px #7c3cff77, inset 0 0 12px #fff2;}
        .title{font-weight:900; letter-spacing:1px; font-size:20px;}
        .qrurl{font-size:12px; color:#cfe9ff; word-break:break-all}
        @media (max-width:900px){ .grid{grid-template-columns:1fr} iframe,embed{height:480px} }
    </style>';

    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . $css . '</head><body><div class="wrap">';

    if (!$cek) {
        echo '<div class="card"><div class="head">
                <div class="brand"><div class="logo"></div><div class="title">RELIPROVE</div></div>
                <div class="badge" style="background:linear-gradient(90deg,#f43f5e,#ef4444);color:#2b0b0b">Kode tidak valid</div>
              </div>
              <p class="muted" style="margin:12px 2px 0">Kode QR tidak ditemukan atau sudah tidak berlaku.</p>
            </div></div></body></html>';
        exit;
    }

    // tentukan file sertifikat & fingerprint
    $publicLink = $cek['link_file_sertifikat'] ?? '';
    $storageDir = realpath(__DIR__ . '/../storage/sertifikat') ?: (__DIR__ . '/../storage/sertifikat');
    $absPath    = null;
    $embedType  = 'iframe';

    if ($publicLink) {
        // hanya izinkan file di folder storage/sertifikat
        $basename = basename(parse_url($publicLink, PHP_URL_PATH));
        $guessAbs = realpath($storageDir . DIRECTORY_SEPARATOR . $basename);
        if ($guessAbs && str_starts_with($guessAbs, realpath($storageDir))) {
            $absPath = $guessAbs;
        }
        $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        if ($ext === 'pdf') $embedType = 'pdf';
    }

    $finger = substr(sha1(($cek['nomor_sertifikat'] ?? '') . ($cek['tanggal_terbit'] ?? '')), 0, 12);

    // tampilan sukses + sematkan sertifikat
    echo '<div class="card">
            <div class="head">
              <div class="brand"><div class="logo"></div><div>
                <div class="title">RELIPROVE</div>
                <div class="muted" style="font-size:12px">Verifikasi digital</div>
              </div></div>
              <div class="badge">Terverifikasi</div>
            </div>

            <div class="grid">
              <div class="info">
                <h2 style="margin:.2rem 0 0; font-size:28px; font-weight:900">Sertifikat Kompetensi</h2>
                <div class="kv" style="margin-top:10px">
                  <div class="k">Nama</div><div><b>' . esc($cek['nama_lengkap']) . '</b></div>
                  <div class="k">Posisi</div><div><b>' . esc($cek['nama_posisi']) . '</b></div>
                  <div class="k">Nomor</div><div><b>' . esc($cek['nomor_sertifikat']) . '</b></div>
                  <div class="k">Tanggal Terbit</div><div><b>' . esc($cek['tanggal_terbit']) . '</b></div>
                </div>

                <div class="actions">
                  <a class="btn" href="' . esc($publicLink) . '" target="_blank" rel="noopener">Buka Sertifikat</a>
                  <span class="finger">Document Fingerprint: <b>' . esc($finger) . '</b></span>
                </div>

                <p class="muted" style="margin-top:10px">Pemindaian QR berhasil dan cocok dengan catatan di sistem.</p>
              </div>';

    echo '<div class="panel">';
    if ($publicLink && $absPath && is_readable($absPath)) {
        if ($embedType === 'pdf') {
            echo '<embed type="application/pdf" src="' . esc($publicLink) . '#view=fitH" />';
        } else {
            echo '<iframe src="' . esc($publicLink) . '" loading="lazy"></iframe>';
        }
        echo '<div class="qrurl" style="margin-top:8px">Sumber: <a href="' . esc($publicLink) . '" target="_blank" rel="noopener">' . esc($publicLink) . '</a></div>';
    } else {
        echo '<div class="muted">File sertifikat tidak ditemukan di server. Tautan: ' . ($publicLink ? '<a class="qrurl" href="' . esc($publicLink) . '" target="_blank" rel="noopener">' . esc($publicLink) . '</a>' : '-') . '</div>';
    }
    echo '</div>'; // panel

    echo '  </div> <!-- grid -->
          </div> <!-- card -->
        </div></body></html>';
    exit;
}


// ============== ACTIONS ==============
$flash = null;

// TERBITKAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_id'])) {
    $idPendaftaran = (int)$_POST['generate_id'];
    $det = getPendaftaranDetail($koneksi, $idPendaftaran);
    if (!$det) {
        $flash = ['type' => 'danger', 'msg' => 'Pendaftaran tidak ditemukan.'];
    } elseif ($det['status_kelulusan'] !== 'lulus') {
        $flash = ['type' => 'warning', 'msg' => 'Status belum lulus. Tidak bisa terbit sertifikat.'];
    } elseif (!ensureDirsWritable($CERT_DIR)) {
        $flash = ['type' => 'danger', 'msg' => 'Folder penyimpanan tidak writable: ' . esc($CERT_DIR)];
    } else {
        $cek = $koneksi->prepare("SELECT id_sertifikat FROM sertifikat WHERE id_pendaftaran=?");
        $cek->bind_param('i', $idPendaftaran);
        $cek->execute();
        if ($cek->get_result()->fetch_assoc()) {
            $flash = ['type' => 'info', 'msg' => 'Sertifikat untuk pendaftaran ini sudah terbit.'];
        } else {
            $tipe = 'kompetensi';
            $level = mapLevelByScore($det['skor']);
            $nomor = generateCertificateNumber($koneksi);
            $slug = generateSlug($koneksi, $det['nama_lengkap'], $nomor);
            $kodeQR = substr(sha1($slug . 'RELIPROVE'), 0, 16);
            $verifyURL = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?cek=' . $kodeQR;
            $qrUrl = buildQuickChartQrUrl($verifyURL, 300, 1, 'M');
            $jenisKat = getJenisKategori($koneksi, (int)$det['id_posisi']) ?? 'IT';
            $tanggalTerbit = date('Y-m-d');
            $ctx = [
                'nama' => $det['nama_lengkap'],
                'posisi' => $det['nama_posisi'],
                'kategori' => $det['nama_kategori'],
                'bidang' => $det['nama_bidang'],
                'nomor' => $nomor,
                'tanggal_terbit' => $tanggalTerbit,
                'level' => $level,
                'tipe' => $tipe,
                'jenis_kategori' => $jenisKat,
                'qr_url' => $qrUrl,
                'url_verifikasi' => $verifyURL
            ];
            $html = buildCertificateHTML($ctx);
            $fname = $slug . '.html';
            $abs = $CERT_DIR . $fname;
            if (file_put_contents($abs, $html) === false) {
                $flash = ['type' => 'danger', 'msg' => 'Gagal menyimpan file sertifikat HTML.'];
            } else {
                $public = '../storage/sertifikat/' . $fname;
                $ins = $koneksi->prepare("INSERT INTO sertifikat
          (id_pendaftaran, tipe_sertifikat, level_kompetensi, nomor_sertifikat, slug_sertifikat, link_file_sertifikat, kode_qr, tanggal_terbit)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->bind_param('isssssss', $idPendaftaran, $tipe, $level, $nomor, $slug, $public, $kodeQR, $tanggalTerbit);
                if ($ins->execute()) {
                    $flash = ['type' => 'success', 'msg' => 'Sertifikat (HTML) berhasil diterbitkan.', 'link' => $public];
                } else {
                    $flash = ['type' => 'danger', 'msg' => 'Gagal menyimpan ke database: ' . esc($koneksi->error)];
                }
            }
        }
    }
}

// BATALKAN TERBIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batalkan_id'])) {
    $idSert = (int)$_POST['batalkan_id'];
    $q = $koneksi->prepare("SELECT link_file_sertifikat FROM sertifikat WHERE id_sertifikat=? LIMIT 1");
    $q->bind_param('i', $idSert);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    if (!$row) {
        $flash = ['type' => 'warning', 'msg' => 'Data sertifikat tidak ditemukan.'];
    } else {
        $del = $koneksi->prepare("DELETE FROM sertifikat WHERE id_sertifikat=?");
        $del->bind_param('i', $idSert);
        if ($del->execute()) {
            if (!empty($row['link_file_sertifikat'])) {
                $rel = $row['link_file_sertifikat']; // ../storage/sertifikat/xxx.html
                $absPath = realpath(dirname(__DIR__) . '/' . trim(dirname($rel), '/\\')) . DIRECTORY_SEPARATOR . basename($rel);
                if (!$absPath) {
                    $absPath = $CERT_DIR . basename($rel);
                }
                @unlink($absPath);
            }
            $flash = ['type' => 'success', 'msg' => 'Penerbitan sertifikat dibatalkan & file dihapus.'];
        } else {
            $flash = ['type' => 'danger', 'msg' => 'Gagal membatalkan: ' . esc($koneksi->error)];
        }
    }
}

// ============== DATA (untuk kartu) ==============
$sqlKandidat = "
SELECT p.id_pendaftaran, pg.nama_lengkap, po.nama_posisi, k.nama_kategori, b.nama_bidang,
       COALESCE(pe.skor,0) AS skor, COALESCE(pe.rekomendasi,'-') AS rekomendasi, p.tanggal_daftar
FROM pendaftaran p
JOIN pengguna pg ON pg.id_pengguna = p.id_pengguna
JOIN posisi po   ON po.id_posisi   = p.id_posisi
JOIN kategori k  ON k.id_kategori  = po.id_kategori
JOIN bidang b    ON b.id_bidang    = k.id_bidang
LEFT JOIN penilaian pe ON pe.id_pendaftaran = p.id_pendaftaran
WHERE p.status_kelulusan = 'lulus'
  AND NOT EXISTS (SELECT 1 FROM sertifikat s WHERE s.id_pendaftaran = p.id_pendaftaran)
ORDER BY p.tanggal_daftar DESC";
$kandidat = $koneksi->query($sqlKandidat);

$sqlTerbit = "
SELECT s.*, pg.nama_lengkap, po.nama_posisi
FROM sertifikat s
JOIN pendaftaran p ON p.id_pendaftaran = s.id_pendaftaran
JOIN pengguna pg ON pg.id_pengguna = p.id_pengguna
JOIN posisi po ON po.id_posisi = p.id_posisi
ORDER BY s.tanggal_terbit DESC, s.id_sertifikat DESC";
$terbit = $koneksi->query($sqlTerbit);

$nama = $_SESSION['nama'] ?? 'Asesor';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1.0" />
    <title>Sertifikat | RELIPROVE</title>
    <link rel="icon" href="../aset/img/logo.png" type="image/png">
    <link rel="stylesheet" href="css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet" />
    <style>
        :root {
            --primary: #9b5eff;
            --bg-dark: #0b0f1a;
            --bg-card: #161a25;
            --bg-surface: #151923;
            --text: #e9e9ee;
            --text-muted: #9aa3b2;
            --line: rgba(255, 255, 255, .08);
        }


        body {
            background: var(--bg-dark);
            color: var(--text);
            font-family: Inter, system-ui, Segoe UI, Arial, sans-serif;
        }

        /* SIDEBAR ala dashboard_asesor */

        .content {
            margin-left: 280px;
            padding: 1.2rem
        }

        .topbar {
            background: #161a25;
            padding: .8rem 1.2rem;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 0 20px rgba(155, 94, 255, .15);
            margin-bottom: 1rem
        }

        .cardx {
            background: #161a25;
            border-radius: 16px;
            box-shadow: 0 0 20px rgba(155, 94, 255, .15);
            padding: 18px;
            margin-bottom: 16px
        }

        .section-title {
            font-weight: 800
        }

        /* KARTU peserta/sertifikat */
        .grid-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 14px
        }

        .card-item {
            background: #121826;
            border: 1px solid rgba(255, 255, 255, .05);
            border-radius: 16px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px
        }

        .card-head {
            display: flex;
            justify-content: space-between;
            align-items: center
        }

        .card-title {
            font-weight: 800;
            font-size: 1.08rem
        }

        .card-sub {
            color: var(--text-muted);
            font-size: .92rem
        }

        .badge-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap
        }

        .badge-green {
            background: #1e9e6b;
            border: 1px solid #1e9e6b;
            color: #fff;
            border-radius: 999px;
            padding: .25rem .6rem;
            font-weight: 700
        }

        .badge-blue {
            background: #415bff;
            border: 1px solid #415bff;
            color: #fff;
            border-radius: 999px;
            padding: .25rem .6rem;
            font-weight: 700
        }

        .badge-purple {
            background: #7f56ff;
            border: 1px solid #7f56ff;
            color: #fff;
            border-radius: 999px;
            padding: .25rem .6rem;
            font-weight: 700
        }

        .progress-wrap {
            display: flex;
            gap: 16px;
            align-items: center
        }

        .progress {
            height: 8px;
            background: #232b3e;
            border-radius: 8px;
            overflow: hidden;
            width: 200px
        }

        .progress>span {
            display: block;
            height: 100%;
            background: #9b5eff;
            width: 100%
        }

        .kv-mini {
            display: grid;
            grid-template-columns: repeat(3, minmax(80px, 1fr));
            gap: 10px;
            margin-top: 6px
        }

        .kv-mini .k {
            color: #9aa3b2;
            font-size: .8rem
        }

        .kv-mini .v {
            font-weight: 800
        }

        .card-actions {
            display: flex;
            gap: 10px;
            margin-top: 8px
        }

        .btn-ghost {
            background: #1a2030;
            border: 1px solid #2a3348;
            color: #dfe6ff;
            border-radius: 12px;
            padding: .45rem .7rem;
            font-weight: 700
        }

        .btn-ghost:hover {
            filter: brightness(1.07)
        }

        .btn-danger {
            background: #e05252;
            border-color: #e05252;
            color: #fff
        }

        .link {
            color: #b597ff;
            text-decoration: none
        }

        .link:hover {
            text-decoration: underline
        }

        .alert {
            background: #121826;
            border: 1px solid var(--line);
            color: #e9e9ee
        }

        /* ===== Sertifikat Terbit – adaptasi visual ke style existing ===== */
        .cert-publish .grid-cards {
            grid-template-columns: repeat(auto-fill, minmax(520px, 1fr));
            gap: 18px;
        }

        .cert-publish .card-item {
            background: var(--bg-card);
            /* konsisten dengan sistem */
            border: 1.5px solid var(--line);
            border-radius: 18px;
            padding: 20px;
        }

        .cert-publish .card-head {
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 8px;
        }

        .cert-publish .card-title {
            font-size: 1.12rem;
            letter-spacing: .2px;
        }

        .cert-publish .badge-row {
            align-items: center;
        }

        .cert-publish .badge-purple {
            /* jadikan “pill” seperti preview, tapi tetap pakai warna brand kamu */
            background: linear-gradient(90deg, var(--primary), #6f58ff);
            border: none;
            color: #fff;
            padding: .55rem 1rem;
            border-radius: 999px;
            font-weight: 800;
            font-size: .98rem;
            box-shadow: 0 10px 22px rgba(155, 94, 255, .28);
            white-space: nowrap;
        }

        .cert-publish .kv-mini {
            display: grid;
            grid-template-columns: repeat(3, minmax(120px, 1fr));
            gap: 14px;
            padding: 14px;
            border-radius: 14px;
            border: 1px dashed var(--line);
            /* aksen seperti JPG #2 */
            background: var(--bg-surface);
            margin-top: 10px;
        }

        .cert-publish .kv-mini .k {
            color: var(--text-muted);
            font-size: .78rem;
            margin-bottom: 4px;
        }

        .cert-publish .kv-mini .v {
            color: var(--text);
            font-weight: 800;
            font-size: 1rem;
        }

        .cert-publish .link {
            color: #bca9ff;
            /* sedikit lebih kontras */
            text-decoration: none;
            font-weight: 700;
        }

        .cert-publish .link:hover {
            text-decoration: underline;
        }

        /* Actions: “Buka” outline, “Batalkan” filled merah */
        .cert-publish .card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 16px;
        }

        .cert-publish .btn-ghost {
            background: #1a2030;
            border: 1px solid #2a3348;
            color: #dfe6ff;
            padding: .55rem .9rem;
            border-radius: 12px;
            font-weight: 800;
            transition: transform .08s ease, filter .15s ease;
        }

        .cert-publish .btn-ghost:hover {
            transform: translateY(-1px);
            filter: brightness(1.06);
        }

        .cert-publish .btn-danger {
            background: #e05252;
            border-color: #e05252;
            color: #fff;
        }

        /* Responsif */
    </style>
</head>

<body>

    <!-- SIDEBAR -->
    <aside class="sb" id="sidebar">
        <div class="sb__brand" style="font-size: 35px; margin-top: 15px; margin-bottom: 10px;">RELIPROVE</div>
        <nav class="sb__nav">
            <a href="dashboard_admin.php"><i class="fa-solid fa-gauge"></i>Dashboard</a>
            <a href="pengguna.php"><i class="fa-solid fa-users"></i>Manajemen Pengguna</a>
            <a href="pendaftaran.php"><i class="fa-solid fa-id-card"></i>Pendaftaran</a>
            <a href="penilaian.php"><i class="fa-solid fa-clipboard-check"></i>Penilaian</a>
            <a href="bank_soal.php"><i class="fa-solid fa-book-open"></i>Bank Soal</a>
            <a class="active" href="sertifikat.php"><i class="fa-solid fa-graduation-cap"></i>Sertifikat</a>
            <!-- <a href="notifikasi.php"><i class="fa-solid fa-bell"></i>Notifikasi</a> -->
            <a href="log_aktivitas.php"><i class="fa-solid fa-list"></i>Log Aktivitas</a>
            <!-- <a href="template_sertifikat.php"><i class="fa-solid fa-file"></i>Template Sertifikat</a> -->
            <a href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i>Keluar</a>
        </nav>
    </aside>

    <!-- CONTENT -->
    <div class="content">
        <div class="topbar">
            <div style="display:flex;align-items:center;gap:10px;">
                <i class="fas fa-bars"></i>
                <h4 class="m-0 fw-bold text-uppercase">SERTIFIKAT</h4>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <?php $namaUser = $_SESSION['nama'] ?? 'admin'; ?>
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($namaUser) ?>&background=9b5eff&color=fff&rounded=true&size=36" style="border-radius:999px" alt="avatar">
                <span style="font-weight:700;"><?= esc($namaUser) ?></span>
            </div>
        </div>

        <?php if (!empty($flash)): ?>
            <div class="alert alert-<?= esc($flash['type']) ?>"><?= esc($flash['msg']) ?><?= !empty($flash['link']) ? ' — <a class="link" target="_blank" href="' . esc($flash['link']) . '">Buka Sertifikat</a>' : '' ?></div>
        <?php endif; ?>

        <!-- GRID KARTU: KANDIDAT -->
        <!-- ====== STYLE: Card Kandidat (proposional) ====== -->
        <style>
            :root {
                --primary: #9b5eff;
                --bg-card: #161a25;
                --bg-surface: #151923;
                --text: #e9e9ee;
                --muted: #9aa0ad;
                --line: rgba(255, 255, 255, .10);
            }

            .kc-wrap {
                background: var(--bg-surface);
                border: 1px solid var(--line);
                border-radius: 16px;
                padding: 16px
            }

            .kc-head {
                display: flex;
                align-items: flex-end;
                justify-content: space-between;
                gap: .75rem;
                margin-bottom: 8px
            }

            .kc-title {
                margin: 0;
                font-weight: 800;
                display: flex;
                align-items: center;
                gap: .5rem
            }

            .kc-sub {
                color: var(--muted);
                font-size: .86rem
            }

            .kc-grid {
                display: grid;
                gap: 14px;
                grid-template-columns: repeat(auto-fit, minmax(360px, 1fr))
            }

            .kc-card {
                background: var(--bg-card);
                border: 1px solid var(--line);
                border-radius: 18px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, .35);
                overflow: hidden;
                display: flex;
                flex-direction: column
            }

            /* HEADER: kiri (nama, meta), kanan (skor) */
            .kc-card__head {
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 10px;
                padding: 14px 16px;
                border-bottom: 1px solid var(--line)
            }

            .kc-name {
                font-weight: 900;
                letter-spacing: .2px
            }

            .kc-meta {
                color: var(--muted);
                font-size: .86rem
            }

            .kc-score {
                display: flex;
                flex-direction: column;
                align-items: flex-end;
                gap: 4px;
                min-width: 84px
            }

            .kc-score .lbl {
                color: var(--muted);
                font-size: .78rem
            }

            .kc-score .val {
                font-weight: 900;
                font-size: 1.25rem
            }

            /* BADGES: kompak */
            .kc-badges {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                padding: 10px 16px;
                border-bottom: 1px dashed var(--line);
                background: #131723
            }

            .kc-chip {
                display: inline-flex;
                align-items: center;
                gap: .4rem;
                padding: .32rem .62rem;
                border-radius: 999px;
                font-weight: 700;
                font-size: .78rem;
                color: #e9e9ee;
                border: 1px solid rgba(255, 255, 255, .14)
            }

            .kc-green {
                background: rgba(30, 158, 107, .18);
                border-color: rgba(30, 158, 107, .45);
                color: #c9ffea
            }

            .kc-blue {
                background: rgba(78, 160, 255, .18);
                border-color: rgba(78, 160, 255, .45);
                color: #d6e8ff
            }

            .kc-purple {
                background: rgba(127, 86, 255, .18);
                border-color: rgba(127, 86, 255, .45);
                color: #e6ddff
            }

            .kc-yellow {
                background: rgba(255, 198, 93, .18);
                border-color: rgba(255, 198, 93, .45);
                color: #ffe9b5
            }

            .kc-red {
                background: rgba(224, 82, 82, .18);
                border-color: rgba(224, 82, 82, .45);
                color: #ffd1d1
            }

            /* PROGRESS: full width, persen di kanan */
            .kc-progress {
                display: grid;
                grid-template-columns: 1fr auto;
                align-items: center;
                gap: 10px;
                padding: 12px 16px
            }

            .kc-bar {
                height: 10px;
                background: rgba(255, 255, 255, .14);
                border-radius: 999px;
                overflow: hidden
            }

            .kc-fill {
                height: 100%;
                background: var(--primary);
                border-radius: 999px
            }

            .kc-pct {
                color: var(--muted);
                font-size: .86rem;
                min-width: 42px;
                text-align: right
            }

            /* INFO GRID: 3 → 2 → 1 kolom */
            .kc-info {
                display: grid;
                gap: 12px;
                padding: 6px 16px 14px;
                grid-template-columns: repeat(3, 1fr)
            }

            @media (max-width:992px) {
                .kc-info {
                    grid-template-columns: repeat(2, 1fr)
                }
            }

            @media (max-width:576px) {
                .kc-info {
                    grid-template-columns: 1fr
                }
            }

            .kc-k {
                color: var(--muted);
                font-size: .78rem;
                margin-bottom: 4px
            }

            .kc-v {
                font-weight: 800
            }

            /* FOOTER: aksi rata kanan */
            .kc-foot {
                margin-top: auto;
                padding: 12px 16px;
                border-top: 1px solid var(--line);
                display: flex;
                gap: 8px;
                justify-content: flex-end;
                flex-wrap: wrap
            }

            .btn-ghost {
                display: inline-flex;
                align-items: center;
                gap: .45rem;
                padding: .48rem .8rem;
                border-radius: 10px;
                font-weight: 700;
                border: 1.5px solid rgba(255, 255, 255, .16);
                color: #e9e9ee;
                background: transparent;
                text-decoration: none
            }

            .btn-ghost:hover {
                border-color: var(--primary);
                color: #fff;
                box-shadow: 0 0 0 .12rem rgba(155, 94, 255, .2) inset
            }

            .btn-primary-soft {
                background: linear-gradient(0deg, rgba(127, 86, 255, .16), rgba(127, 86, 255, .10));
                border-color: rgba(127, 86, 255, .65)
            }
        </style>

        <div class="kc-wrap">
            <div class="kc-head">
                <div>
                    <h5 class="kc-title" style="margin-left:10px; margin-top:5px;">KANDIDAT SIAP DI TERBITKAN SERTIFIKAT <i class="fa-solid fa-wand-magic-sparkles" style="color:var(--primary)"></i></h5>
                    <div class="kc-sub" style="margin-left:10px; margin-bottom:15px;">Ringkasan progres, status, dan aksi cepat untuk setiap kandidat.</div>
                </div>
            </div>

            <div class="kc-grid">
                <?php if ($kandidat && $kandidat->num_rows): while ($r = $kandidat->fetch_assoc()):
                        $nama = $r['nama_lengkap'];
                        $pos  = $r['nama_posisi'];
                        $kat  = $r['nama_kategori'];
                        $bid  = $r['nama_bidang'];
                        $tgl  = date('d M Y', strtotime($r['tanggal_daftar']));
                        $skor = (int)($r['skor'] ?? 0);
                        $rekom = strtolower((string)($r['rekomendasi'] ?? ''));
                        $pct  = 100; // siap terbit
                ?>
                        <div class="kc-card">
                            <!-- HEAD -->
                            <div class="kc-card__head">
                                <div>
                                    <div class="kc-name" title="<?= esc($nama) ?>"><?= esc($nama) ?></div>
                                    <div class="kc-meta" style="margin-top: 10px;"><i class="fa-solid fa-briefcase"></i> <?= esc($pos) ?> · <?= esc($kat) ?> • Daftar: <?= esc($tgl) ?></div>
                                </div>
                                <div class="kc-score">
                                    <div class="lbl">Skor</div>
                                    <div class="val"><?= $skor ?></div>
                                </div>
                            </div>

                            <!-- BADGES -->
                            <div class="kc-badges">
                                <span class="kc-chip kc-green"><i class="fa-solid fa-circle-check"></i>Diterima</span>
                                <span class="kc-chip kc-blue"><i class="fa-solid fa-clipboard-check"></i>Dinilai</span>
                                <?php if ($rekom === 'layak'): ?>
                                    <span class="kc-chip kc-green"><i class="fa-solid fa-badge-check"></i>Layak</span>
                                    <span class="kc-chip kc-green"><i class="fa-solid fa-award"></i>Lulus</span>
                                <?php elseif ($rekom === 'belum layak'): ?>
                                    <span class="kc-chip kc-red"><i class="fa-solid fa-triangle-exclamation"></i>Belum Layak</span>
                                <?php else: ?>
                                    <span class="kc-chip kc-yellow"><i class="fa-solid fa-hourglass-half"></i>Review</span>
                                <?php endif; ?>
                                <span class="kc-chip kc-purple"><i class="fa-solid fa-certificate"></i>Sertifikat</span>
                            </div>

                            <!-- PROGRESS -->
                            <div class="kc-progress">
                                <div class="kc-bar">
                                    <div class="kc-fill" style="width:<?= $pct ?>%"></div>
                                </div>
                                <div class="kc-pct"><?= $pct ?>%</div>
                            </div>

                            <!-- INFO -->
                            <div class="kc-info">
                                <div>
                                    <div class="kc-k">Kategori</div>
                                    <div class="kc-v"><?= esc($kat) ?></div>
                                </div>
                                <div>
                                    <div class="kc-k">Bidang</div>
                                    <div class="kc-v"><?= esc($bid) ?></div>
                                </div>
                                <div>
                                    <div class="kc-k">Rekomendasi</div>
                                    <div class="kc-v"><?= esc(ucwords($rekom ?: '—')) ?></div>
                                </div>
                            </div>

                            <!-- FOOT -->
                            <div class="kc-foot">
                                <a class="btn-ghost" href="penilaian.php?detail=<?= (int)$r['id_pendaftaran'] ?>"><i class="fa-regular fa-eye"></i> Detail</a>
                                <form method="post" onsubmit="return confirm('Terbitkan sertifikat untuk <?= esc($nama) ?> ?')">
                                    <input type="hidden" name="generate_id" value="<?= (int)$r['id_pendaftaran'] ?>">
                                    <button class="btn-ghost btn-primary-soft"><i class="fa-solid fa-wand-magic-sparkles"></i> Terbitkan</button>
                                </form>
                            </div>
                        </div>
                    <?php endwhile;
                else: ?>
                    <div class="text-muted" style="grid-column:1/-1;text-align:center;padding:24px">Tidak ada kandidat siap terbit.</div>
                <?php endif; ?>
            </div>
        </div>
        <br>

        <!-- GRID KARTU: SERTIFIKAT TERBIT -->
        <div class="cardx cert-publish">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="badge-purple">SERTIFIKAT TERBIT</div>
                <small style="color:#9aa3b2">Cek QR &amp; batalkan terbit</small>
            </div>

            <div class="grid-cards" style="margin-top: 30px;">
                <?php if ($terbit && $terbit->num_rows): while ($s = $terbit->fetch_assoc()): ?>
                        <div class="card-item">
                            <div class="card-head">
                                <div>
                                    <div class="card-title"><?= esc($s['nama_lengkap']) ?></div>
                                    <div class="card-sub"><i class="fa-solid fa-briefcase"></i> <?= esc($s['nama_posisi']) ?></div>
                                </div>

                                <span class="badge-row">
                                    <span class="badge-purple">No. <?= esc($s['nomor_sertifikat']) ?></span>
                                </span>
                            </div>

                            <div class="kv-mini">
                                <div>
                                    <div class="k">Level</div>
                                    <div class="v"><?= esc($s['level_kompetensi'] ?? '-') ?></div>
                                </div>
                                <div>
                                    <div class="k">Terbit</div>
                                    <div class="v"><?= esc(date('d M Y', strtotime($s['tanggal_terbit']))) ?></div>
                                </div>
                                <div>
                                    <div class="k">QR</div>
                                    <div class="v"><a class="link" href="?cek=<?= esc($s['kode_qr']) ?>" target="_blank">Cek</a></div>
                                </div>
                            </div>

                            <div class="card-actions">
                                <?php if ($s['link_file_sertifikat']): ?>
                                    <a class="btn-ghost" target="_blank" href="<?= esc($s['link_file_sertifikat']) ?>">
                                        <i class="fa-regular fa-file"></i> Buka
                                    </a>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Batalkan penerbitan sertifikat ini? File HTML akan dihapus dan data dihapus dari database.')">
                                    <input type="hidden" name="batalkan_id" value="<?= (int)$s['id_sertifikat'] ?>">
                                    <button class="btn-ghost btn-danger" type="submit">
                                        <i class="fa-solid fa-trash"></i> Batalkan
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endwhile;
                else: ?>
                    <div class="text-muted">Belum ada sertifikat terbit.</div>
                <?php endif; ?>
            </div>
        </div>


        <div class="text-center" style="color:#9aa3b2;padding:10px 0;">&copy; <?= date('Y') ?> Created by PT. Reliable Future Technology</div>
    </div>

</body>

</html>