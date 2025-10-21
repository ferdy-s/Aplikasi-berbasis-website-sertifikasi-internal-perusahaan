<?php

/*************************************************
 * RELIPROVE — referensi_posisi.php
 * CRUD Referensi: Bidang, Kategori, Posisi
 * Styling: css/style.css & css/fitur_filter.css (no <style>)
 *************************************************/

declare(strict_types=1);
session_start();

/* ====== CONFIG ====== */
$dbHost = '127.0.0.1';
$dbName = 'db_relipove';
$dbUser = 'root';
$dbPass = '';
$dsn    = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

/* ====== AUTH MOCK ====== */
if (!isset($_SESSION['user'])) {
    $_SESSION['user'] = [
        'id_pengguna'  => 3,
        'nama_lengkap' => 'Admin RELIPROVE',
        'email'        => 'admin@reliprove.local',
        'peran'        => 'superadmin'
    ];
}
$me        = $_SESSION['user'];
$namaAdmin = $me['nama_lengkap'] ?? 'Admin';

/* ====== HELPER ====== */
function esc(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function is_admin(): bool
{
    return in_array($_SESSION['user']['peran'] ?? '', ['admin', 'superadmin'], true);
}
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check(string $t): bool
{
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
function flash(string $key, ?string $val = null): ?string
{
    if ($val !== null) {
        $_SESSION['flash'][$key] = $val;
        return null;
    }
    $m = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $m;
}

function log_aktivitas(PDO $pdo, ?int $id_pengguna, string $aktivitas): void
{
    $stmt = $pdo->prepare("INSERT INTO log_aktivitas (id_pengguna, aktivitas) VALUES (?, ?)");
    $stmt->execute([$id_pengguna, $aktivitas]);
}
function slugify(string $text): string
{
    $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    $text = preg_replace('~[^\\pL\\d]+~u', '-', $text);
    $text = trim($text, '-');
    $text = strtolower($text);
    $text = preg_replace('~[^-a-z0-9]+~', '', $text);
    return $text ?: 'n-a';
}
function unique_slug(PDO $pdo, string $table, string $col, string $base, ?int $excludeId = null, string $pk = 'id'): string
{
    $slug = $base;
    $i = 2;
    while (true) {
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$col}=?" . ($excludeId ? " AND {$pk}<>?" : '');
        $st  = $pdo->prepare($sql);
        $st->execute($excludeId ? [$slug, $excludeId] : [$slug]);
        if ((int)$st->fetchColumn() === 0) return $slug;
        $slug = $base . '-' . $i++;
    }
}

/* ====== DB CONNECT ====== */
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "DB error: " . esc($e->getMessage());
    exit;
}

/* ====== READ FILTER PARAMS ====== */
$tab = $_GET['tab'] ?? 'bidang'; // bidang|kategori|posisi

// Bidang
$q_b = trim((string)($_GET['q_b'] ?? ''));
$pb  = max(1, (int)($_GET['pb'] ?? 1));

// Kategori
$q_k       = trim((string)($_GET['q_k'] ?? ''));
$f_bidangK = (int)($_GET['bidang_k'] ?? 0);
$pk        = max(1, (int)($_GET['pk'] ?? 1));

// Posisi
$q_p       = trim((string)($_GET['q_p'] ?? ''));
$f_bidangP = (int)($_GET['bidang_p'] ?? 0);
$f_katP    = (int)($_GET['kategori_p'] ?? 0);
$pp        = max(1, (int)($_GET['pp'] ?? 1));

// paging
$perB = 12;
$perK = 12;
$perP = 12;

/* ====== ACTIONS ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    $csrf   = $_POST['csrf']   ?? '';
    $entity = $_POST['entity'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!csrf_check($csrf)) {
        flash('err', 'CSRF token tidak valid.');
        header('Location: bank_soal.php?tab=' . $tab);
        exit;
    }

    try {
        /* ---------- BIDANG ---------- */
        if ($entity === 'bidang') {
            if ($action === 'create') {
                $nama = trim((string)$_POST['nama_bidang']);
                if ($nama === '') throw new Exception('Nama bidang wajib diisi.');
                $st = $pdo->prepare("INSERT INTO bidang (nama_bidang) VALUES (?)");
                $st->execute([$nama]);
                log_aktivitas($pdo, (int)$me['id_pengguna'], "Tambah bidang: {$nama}");
                flash('ok', 'Bidang ditambahkan.');
            }
            if ($action === 'update') {
                $id   = (int)($_POST['id_bidang'] ?? 0);
                $nama = trim((string)$_POST['nama_bidang']);
                if ($id <= 0 || $nama === '') throw new Exception('Data tidak lengkap.');
                $pdo->prepare("UPDATE bidang SET nama_bidang=? WHERE id_bidang=?")->execute([$nama, $id]);
                log_aktivitas($pdo, (int)$me['id_pengguna'], "Ubah bidang #{$id} -> {$nama}");
                flash('ok', 'Bidang diperbarui.');
            }
            if ($action === 'delete') {
                $id = (int)($_POST['id_bidang'] ?? 0);
                if ($id <= 0) throw new Exception('ID tidak valid.');
                // Cek dependensi (kategori & posisi)
                $st = $pdo->prepare("SELECT COUNT(*) FROM kategori WHERE id_bidang=?");
                $st->execute([$id]);
                $ck = (int)$st->fetchColumn();
                if ($ck > 0) throw new Exception('Tidak bisa dihapus: masih ada kategori di bidang ini.');
                $pdo->prepare("DELETE FROM bidang WHERE id_bidang=?")->execute([$id]);
                log_aktivitas($pdo, (int)$me['id_pengguna'], "Hapus bidang #{$id}");
                flash('ok', 'Bidang dihapus.');
            }
            header('Location: bank_soal.php?tab=bidang');
            exit;
        }

        /* ---------- KATEGORI ---------- */
        if ($entity === 'kategori') {
            if ($action === 'create') {
                $nama      = trim((string)$_POST['nama_kategori']);
                $id_bidang = (int)($_POST['id_bidang'] ?? 0);
                $slug_in   = trim((string)($_POST['slug_kategori'] ?? ''));

                if ($nama === '' || $id_bidang <= 0) throw new Exception('Nama & bidang wajib diisi.');
                // ambil nama bidang sebagai string "bidang" (kolom redundan)
                $b = $pdo->prepare("SELECT nama_bidang FROM bidang WHERE id_bidang=?");
                $b->execute([$id_bidang]);
                $nama_b = $b->fetchColumn();
                if (!$nama_b) throw new Exception('Bidang tidak ditemukan.');

                $base = slugify($slug_in ?: $nama);
                $slug = unique_slug($pdo, 'kategori', 'slug_kategori', $base, null, 'id_kategori');

                $st = $pdo->prepare("INSERT INTO kategori (nama_kategori, bidang, slug_kategori, id_bidang) VALUES (?,?,?,?)");
                $st->execute([$nama, (string)$nama_b, $slug, $id_bidang]);

                log_aktivitas($pdo, (int)$me['id_pengguna'], "Tambah kategori: {$nama} ({$nama_b})");
                flash('ok', 'Kategori ditambahkan.');
            }
            if ($action === 'update') {
                $id        = (int)($_POST['id_kategori'] ?? 0);
                $nama      = trim((string)$_POST['nama_kategori']);
                $id_bidang = (int)($_POST['id_bidang'] ?? 0);
                $slug_in   = trim((string)($_POST['slug_kategori'] ?? ''));

                if ($id <= 0 || $nama === '' || $id_bidang <= 0) throw new Exception('Data tidak lengkap.');
                $b = $pdo->prepare("SELECT nama_bidang FROM bidang WHERE id_bidang=?");
                $b->execute([$id_bidang]);
                $nama_b = $b->fetchColumn();
                if (!$nama_b) throw new Exception('Bidang tidak ditemukan.');

                $base = slugify($slug_in ?: $nama);
                $slug = unique_slug($pdo, 'kategori', 'slug_kategori', $base, $id, 'id_kategori');

                $pdo->prepare("UPDATE kategori SET nama_kategori=?, bidang=?, slug_kategori=?, id_bidang=? WHERE id_kategori=?")
                    ->execute([$nama, (string)$nama_b, $slug, $id_bidang, $id]);

                log_aktivitas($pdo, (int)$me['id_pengguna'], "Ubah kategori #{$id} -> {$nama}");
                flash('ok', 'Kategori diperbarui.');
            }
            if ($action === 'delete') {
                $id = (int)($_POST['id_kategori'] ?? 0);
                if ($id <= 0) throw new Exception('ID tidak valid.');
                // cek posisi
                $st = $pdo->prepare("SELECT COUNT(*) FROM posisi WHERE id_kategori=?");
                $st->execute([$id]);
                $cp = (int)$st->fetchColumn();
                if ($cp > 0) throw new Exception('Tidak bisa dihapus: masih ada posisi di kategori ini.');
                $pdo->prepare("DELETE FROM kategori WHERE id_kategori=?")->execute([$id]);
                log_aktivitas($pdo, (int)$me['id_pengguna'], "Hapus kategori #{$id}");
                flash('ok', 'Kategori dihapus.');
            }
            header('Location: bank_soal.php?tab=kategori');
            exit;
        }

        /* ---------- POSISI ---------- */
        if ($entity === 'posisi') {
            if ($action === 'create') {
                $nama       = trim((string)$_POST['nama_posisi']);
                $id_kat     = (int)($_POST['id_kategori'] ?? 0);
                $slug_in    = trim((string)($_POST['slug_posisi'] ?? ''));
                $link_def   = trim((string)($_POST['link_soal_default'] ?? ''));

                if ($nama === '' || $id_kat <= 0) throw new Exception('Nama & kategori wajib diisi.');
                $k = $pdo->prepare("SELECT id_kategori FROM kategori WHERE id_kategori=?");
                $k->execute([$id_kat]);
                if (!$k->fetchColumn()) throw new Exception('Kategori tidak ditemukan.');

                $base = slugify($slug_in ?: $nama);
                $slug = unique_slug($pdo, 'posisi', 'slug_posisi', $base, null, 'id_posisi');

                $st = $pdo->prepare("INSERT INTO posisi (id_kategori, nama_posisi, slug_posisi, link_soal_default) VALUES (?,?,?,?)");
                $st->execute([$id_kat, $nama, $slug, $link_def ?: null]);

                log_aktivitas($pdo, (int)$me['id_pengguna'], "Tambah posisi: {$nama}");
                flash('ok', 'Posisi ditambahkan.');
            }
            if ($action === 'update') {
                $id         = (int)($_POST['id_posisi'] ?? 0);
                $nama       = trim((string)$_POST['nama_posisi']);
                $id_kat     = (int)($_POST['id_kategori'] ?? 0);
                $slug_in    = trim((string)($_POST['slug_posisi'] ?? ''));
                $link_def   = trim((string)($_POST['link_soal_default'] ?? ''));

                if ($id <= 0 || $nama === '' || $id_kat <= 0) throw new Exception('Data tidak lengkap.');
                $k = $pdo->prepare("SELECT id_kategori FROM kategori WHERE id_kategori=?");
                $k->execute([$id_kat]);
                if (!$k->fetchColumn()) throw new Exception('Kategori tidak ditemukan.');

                $base = slugify($slug_in ?: $nama);
                $slug = unique_slug($pdo, 'posisi', 'slug_posisi', $base, $id, 'id_posisi');

                $pdo->prepare("UPDATE posisi SET id_kategori=?, nama_posisi=?, slug_posisi=?, link_soal_default=? WHERE id_posisi=?")
                    ->execute([$id_kat, $nama, $slug, $link_def ?: null, $id]);

                log_aktivitas($pdo, (int)$me['id_pengguna'], "Ubah posisi #{$id} -> {$nama}");
                flash('ok', 'Posisi diperbarui.');
            }
            if ($action === 'delete') {
                $id = (int)($_POST['id_posisi'] ?? 0);
                if ($id <= 0) throw new Exception('ID tidak valid.');
                // Cek pendaftaran & bank_soal agar aman
                $st = $pdo->prepare("SELECT COUNT(*) FROM pendaftaran WHERE id_posisi=?");
                $st->execute([$id]);
                $cp = (int)$st->fetchColumn();
                if ($cp > 0) throw new Exception('Tidak bisa dihapus: sudah dipakai pendaftaran.');
                $st = $pdo->prepare("SELECT COUNT(*) FROM bank_soal WHERE id_posisi=?");
                $st->execute([$id]);
                $cb = (int)$st->fetchColumn();
                if ($cb > 0) throw new Exception('Tidak bisa dihapus: ada bank_soal terkait. Arsipkan/hapus bank_soal lebih dulu.');
                $pdo->prepare("DELETE FROM posisi WHERE id_posisi=?")->execute([$id]);
                log_aktivitas($pdo, (int)$me['id_pengguna'], "Hapus posisi #{$id}");
                flash('ok', 'Posisi dihapus.');
            }
            header('Location: bank_soal.php?tab=posisi');
            exit;
        }

        throw new Exception('Aksi tidak dikenal.');
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
        header('Location: bank_soal.php?tab=' . $tab);
        exit;
    }
}

/* ====== MASTER LISTS ====== */
$bidangAll   = $pdo->query("SELECT id_bidang, nama_bidang FROM bidang ORDER BY nama_bidang")->fetchAll();
$kategoriAll = $pdo->query("SELECT id_kategori, nama_kategori, id_bidang FROM kategori ORDER BY nama_kategori")->fetchAll();

/* ====== LISTING: BIDANG ====== */
$whereB = [];
$argB = [];
if ($q_b !== '') {
    $whereB[] = "nama_bidang LIKE ?";
    $argB[] = "%$q_b%";
}
$sqlWB = $whereB ? (" WHERE " . implode(" AND ", $whereB)) : "";
$cntB = $pdo->prepare("SELECT COUNT(*) FROM bidang" . $sqlWB);
$cntB->execute($argB);
$totB = (int)$cntB->fetchColumn();
$offB = ($pb - 1) * $perB;
$listB = $pdo->prepare("SELECT 
    b.id_bidang, b.nama_bidang,
    (SELECT COUNT(*) FROM kategori k WHERE k.id_bidang=b.id_bidang) AS jml_kategori,
    (SELECT COUNT(*) FROM posisi  p JOIN kategori k ON k.id_kategori=p.id_kategori WHERE k.id_bidang=b.id_bidang) AS jml_posisi
  FROM bidang b {$sqlWB}
  ORDER BY b.nama_bidang
  LIMIT {$perB} OFFSET {$offB}");
$listB->execute($argB);
$rowsB = $listB->fetchAll();

/* ====== LISTING: KATEGORI ====== */
$whereK = [];
$argK = [];
if ($q_k !== '') {
    $whereK[] = "k.nama_kategori LIKE ?";
    $argK[] = "%$q_k%";
}
if ($f_bidangK > 0) {
    $whereK[] = "k.id_bidang=?";
    $argK[] = $f_bidangK;
}
$sqlWK = $whereK ? (" WHERE " . implode(" AND ", $whereK)) : "";
$cntK = $pdo->prepare("SELECT COUNT(*) FROM kategori k" . $sqlWK);
$cntK->execute($argK);
$totK = (int)$cntK->fetchColumn();
$offK = ($pk - 1) * $perK;
$listK = $pdo->prepare("SELECT 
    k.id_kategori, k.nama_kategori, k.slug_kategori, k.id_bidang, 
    (SELECT nama_bidang FROM bidang b WHERE b.id_bidang=k.id_bidang) AS nama_bidang,
    (SELECT COUNT(*) FROM posisi p WHERE p.id_kategori=k.id_kategori) AS jml_posisi
  FROM kategori k {$sqlWK}
  ORDER BY k.nama_kategori
  LIMIT {$perK} OFFSET {$offK}");
$listK->execute($argK);
$rowsK = $listK->fetchAll();

/* ====== LISTING: POSISI ====== */
$whereP = [];
$argP = [];
if ($q_p !== '') {
    $whereP[] = "p.nama_posisi LIKE ?";
    $argP[] = "%$q_p%";
}
if ($f_bidangP > 0) {
    $whereP[] = "k.id_bidang=?";
    $argP[] = $f_bidangP;
}
if ($f_katP > 0) {
    $whereP[] = "p.id_kategori=?";
    $argP[] = $f_katP;
}
$sqlWP = $whereP ? (" WHERE " . implode(" AND ", $whereP)) : "";
$cntP = $pdo->prepare("SELECT COUNT(*) 
  FROM posisi p JOIN kategori k ON k.id_kategori=p.id_kategori {$sqlWP}");
$cntP->execute($argP);
$totP = (int)$cntP->fetchColumn();
$offP = ($pp - 1) * $perP;
$listP = $pdo->prepare("SELECT 
    p.id_posisi, p.nama_posisi, p.slug_posisi, p.link_soal_default,
    k.id_kategori, k.nama_kategori, k.id_bidang,
    (SELECT nama_bidang FROM bidang b WHERE b.id_bidang=k.id_bidang) AS nama_bidang,
    (SELECT COUNT(*) FROM bank_soal bs WHERE bs.id_posisi=p.id_posisi) AS jml_bank_soal,
    (SELECT COUNT(*) FROM pendaftaran pd WHERE pd.id_posisi=p.id_posisi) AS jml_pendaftaran
  FROM posisi p 
  JOIN kategori k ON k.id_kategori=p.id_kategori
  {$sqlWP}
  ORDER BY p.nama_posisi
  LIMIT {$perP} OFFSET {$offP}");
$listP->execute($argP);
$rowsP = $listP->fetchAll();

/* Mapping untuk dependent dropdown */
$katByBidang = [];
foreach ($kategoriAll as $k) {
    $katByBidang[(int)$k['id_bidang']][] = $k;
}

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Bank Soal | RELIPROVE</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- HANYA CSS EKSTERNAL -->
    <link href="css/style.css" rel="stylesheet">
    <link href="css/fitur_filter.css" rel="stylesheet">
    <link rel="icon" href="../aset/img/logo.png" type="image/png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet" />
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
            <a class="active" href="bank_soal.php"><i class="fa-solid fa-book-open"></i>Bank Soal</a>
            <a href="sertifikat.php"><i class="fa-solid fa-graduation-cap"></i>Sertifikat</a>
            <!-- <a href="notifikasi.php"><i class="fa-solid fa-bell"></i>Notifikasi</a> -->
            <a href="log_aktivitas.php"><i class="fa-solid fa-list"></i>Log Aktivitas</a>
            <!-- <a href="template_sertifikat.php"><i class="fa-solid fa-file"></i>Template Sertifikat</a> -->
            <a href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i>Keluar</a>
        </nav>
    </aside>

    <!-- MAIN -->
    <main class="main">
        <!-- TOPBAR -->
        <header class="tb">
            <div class="tb__left">
                <button class="tb__burger" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fa-solid fa-bars"></i></button>
                <h1 class="tb__title">Referensi Posisi</h1>
                <form class="tb__search" method="get">
                    <input type="hidden" name="tab" value="<?= esc($tab) ?>">
                    <?php if ($tab === 'bidang'): ?>
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input name="q_b" value="<?= esc($q_b) ?>" placeholder="Cari Bidang…">
                    <?php elseif ($tab === 'kategori'): ?>
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input name="q_k" value="<?= esc($q_k) ?>" placeholder="Cari Kategori…">
                    <?php else: ?>
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input name="q_p" value="<?= esc($q_p) ?>" placeholder="Cari Posisi…">
                    <?php endif; ?>
                    <button class="btn btn--sm">Cari</button>
                </form>
            </div>
            <div class="tb__right">
                <?php if (is_admin()): ?>
                    <button class="btn" onclick="tabAdd()"><i class="fa-solid fa-plus"></i>Tambah</button>
                <?php endif; ?>
                <div class="tb__me">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($namaAdmin) ?>&background=9b5eff&color=fff&rounded=true&size=40" alt="ava">
                    <div>
                        <div class="me__name"><?= esc($namaAdmin) ?></div>
                        <div class="me__role"><?= esc($me['peran'] ?? '-') ?></div>
                    </div>
                </div>
            </div>
        </header>
        <br>
        <!-- TABS -->
        <section class="card">
            <div class="card__hdr card__hdr--xl">
                <h6><i class="fa-solid fa-database"></i>Master Data</h6>
                <div class="chips">
                    <a class="chip" href="?tab=bidang"><i>•</i> Bidang</a>
                    <a class="chip" href="?tab=kategori"><i>•</i> Kategori</a>
                    <a class="chip" href="?tab=posisi"><i>•</i> Posisi</a>
                </div>
            </div>
            <div class="muted">Kelola referensi hirarki: Bidang → Kategori → Posisi.</div>
        </section>
        <br>
        <!-- FLASH -->
        <?php if ($m = flash('ok')): ?>
            <div class="card">
                <div class="tool"><span class="badge ok"><i class="fa-solid fa-circle-check"></i> OK</span><span><?= esc($m) ?></span></div>
            </div>
        <?php endif; ?>
        <?php if ($m = flash('warn')): ?>
            <div class="card">
                <div class="tool"><span class="badge warn"><i class="fa-solid fa-triangle-exclamation"></i> Peringatan</span><span><?= esc($m) ?></span></div>
            </div>
        <?php endif; ?>
        <?php if ($m = flash('err')): ?>
            <div class="card">
                <div class="tool"><span class="badge bad"><i class="fa-solid fa-circle-xmark"></i> Gagal</span><span><?= esc($m) ?></span></div>
            </div>
        <?php endif; ?>
        <!-- ===== TAB: BIDANG ===== -->
        <?php if ($tab === 'bidang'): ?>
            <section class="card card--ht-filter">
                <div class="filter__hdr">
                    <div class="filter__title"><i class="fa-solid fa-filter"></i><span>Filter Bidang</span></div>
                    <span class="count-badge"><?= (int)$totB ?> data</span>
                </div>
                <form method="get">
                    <input type="hidden" name="tab" value="bidang">
                    <div class="frow">
                        <div class="fitem">
                            <label>Cari</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-solid fa-magnifying-glass"></i></div>
                                <input class="fsel" name="q_b" value="<?= esc($q_b) ?>" placeholder="Nama bidang…">
                                <div class="chev"><i class="fa-solid fa-text-width"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="factions">
                        <p class="hintline">Tip: pakai kata kunci singkat.</p>
                        <div class="actions">
                            <button class="btn btn--sm"><i class="fa-solid fa-filter"></i>Terapkan</button>
                            <a class="btn btn--sm btn--ghost" href="?tab=bidang"><i class="fa-solid fa-rotate-left"></i>Reset</a>
                        </div>
                    </div>
                </form>
            </section>
            <br>
            <section class="card card--loose">
                <div class="card__hdr card__hdr--xl">
                    <h6><i class="fa-solid fa-layer-group"></i>Daftar Bidang</h6>
                    <?php if (is_admin()): ?><button class="btn btn--sm" onclick="openCreate('bidang')"><i class="fa-solid fa-plus"></i>Bidang Baru</button><?php endif; ?>
                </div>
                <ul class="relaxed-list" id="listBidang">
                    <?php if (!$rowsB): ?><li class="row row--empty">Tidak ada data.</li><?php endif; ?>
                    <?php foreach ($rowsB as $b): ?>
                        <li class="row">
                            <div class="row__left">
                                <div class="avatar"><i class="fa-solid fa-layer-group"></i></div>
                                <div class="txt">
                                    <div class="ttl"><?= esc($b['nama_bidang']) ?></div>
                                    <div class="sub">
                                        <span class="tag">Kategori: <?= (int)$b['jml_kategori'] ?></span>
                                        <span class="tag">Posisi: <?= (int)$b['jml_posisi'] ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="row__right">
                                <?php if (is_admin()): ?>
                                    <button class="btn btn--sm" onclick='editBidang(<?= (int)$b["id_bidang"] ?>,<?= json_encode($b["nama_bidang"]) ?>)'><i class="fa-solid fa-pen"></i>Edit</button>
                                    <form method="post" onsubmit="return confirm('Hapus bidang ini? Pastikan tidak ada kategori di dalamnya.')">
                                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="entity" value="bidang">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id_bidang" value="<?= (int)$b['id_bidang'] ?>">
                                        <button class="btn btn--sm btn--ghost"><i class="fa-solid fa-trash"></i>Hapus</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($totB > $perB): ?>
                    <div class="tool" style="margin-top:10px">
                        <?php for ($i = 1; $i <= ceil($totB / $perB); $i++): $qs = $_GET;
                            $qs['pb'] = $i; ?>
                            <?php if ($i === $pb): ?><span class="pill"><b>Hal <?= $i ?></b></span>
                            <?php else: ?><a class="btn btn--sm" href="?<?= esc(http_build_query($qs)) ?>">Hal <?= $i ?></a><?php endif; ?>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <!-- ===== TAB: KATEGORI ===== -->
        <?php if ($tab === 'kategori'): ?>
            <section class="card card--ht-filter">
                <div class="filter__hdr">
                    <div class="filter__title"><i class="fa-solid fa-filter"></i><span>Filter Kategori</span></div>
                    <span class="count-badge"><?= (int)$totK ?> data</span>
                </div>
                <form method="get">
                    <input type="hidden" name="tab" value="kategori">
                    <div class="frow">
                        <div class="fitem">
                            <label>Bidang</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-solid fa-layer-group"></i></div>
                                <select class="fsel" name="bidang_k" id="fil_bidang_k">
                                    <option value="0">Semua Bidang</option>
                                    <?php foreach ($bidangAll as $b): ?>
                                        <option value="<?= (int)$b['id_bidang'] ?>" <?= $f_bidangK === (int)$b['id_bidang'] ? 'selected' : ''; ?>><?= esc($b['nama_bidang']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                            </div>
                        </div>
                        <div class="fitem">
                            <label>Cari</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-solid fa-magnifying-glass"></i></div>
                                <input class="fsel" name="q_k" value="<?= esc($q_k) ?>" placeholder="Nama kategori…">
                                <div class="chev"><i class="fa-solid fa-text-width"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="factions">
                        <p class="hintline">Gunakan filter bidang untuk mempersempit.</p>
                        <div class="actions">
                            <button class="btn btn--sm"><i class="fa-solid fa-filter"></i>Terapkan</button>
                            <a class="btn btn--sm btn--ghost" href="?tab=kategori"><i class="fa-solid fa-rotate-left"></i>Reset</a>
                        </div>
                    </div>
                </form>
            </section>
            <br>
            <section class="card card--loose">
                <div class="card__hdr card__hdr--xl">
                    <h6><i class="fa-solid fa-diagram-project"></i>Daftar Kategori</h6>
                    <?php if (is_admin()): ?><button class="btn btn--sm" onclick="openCreate('kategori')"><i class="fa-solid fa-plus"></i>Kategori Baru</button><?php endif; ?>
                </div>
                <ul class="relaxed-list" id="listKategori">
                    <?php if (!$rowsK): ?><li class="row row--empty">Tidak ada data.</li><?php endif; ?>
                    <?php foreach ($rowsK as $k): ?>
                        <li class="row">
                            <div class="row__left">
                                <div class="avatar"><i class="fa-solid fa-diagram-project"></i></div>
                                <div class="txt">
                                    <div class="ttl"><?= esc($k['nama_kategori']) ?></div>
                                    <div class="sub">
                                        <span class="tag"><?= esc($k['nama_bidang']) ?></span>
                                        <span class="tag">Slug: <?= esc($k['slug_kategori'] ?: '-') ?></span>
                                        <span class="tag">Posisi: <?= (int)$k['jml_posisi'] ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="row__right">
                                <?php if (is_admin()): ?>
                                    <button class="btn btn--sm" onclick='editKategori(<?= json_encode($k) ?>)'><i class="fa-solid fa-pen"></i>Edit</button>
                                    <form method="post" onsubmit="return confirm('Hapus kategori ini? Pastikan tidak ada posisi di dalamnya.')">
                                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="entity" value="kategori">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id_kategori" value="<?= (int)$k['id_kategori'] ?>">
                                        <button class="btn btn--sm btn--ghost"><i class="fa-solid fa-trash"></i>Hapus</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($totK > $perK): ?>
                    <div class="tool" style="margin-top:10px">
                        <?php for ($i = 1; $i <= ceil($totK / $perK); $i++): $qs = $_GET;
                            $qs['pk'] = $i; ?>
                            <?php if ($i === $pk): ?><span class="pill"><b>Hal <?= $i ?></b></span>
                            <?php else: ?><a class="btn btn--sm" href="?<?= esc(http_build_query($qs)) ?>">Hal <?= $i ?></a><?php endif; ?>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- ===== TAB: POSISI ===== -->
        <?php if ($tab === 'posisi'): ?>
            <section class="card card--ht-filter">
                <div class="filter__hdr">
                    <div class="filter__title"><i class="fa-solid fa-filter"></i><span>Filter Posisi</span></div>
                    <span class="count-badge"><?= (int)$totP ?> data</span>
                </div>
                <form method="get">
                    <input type="hidden" name="tab" value="posisi">
                    <div class="frow">
                        <div class="fitem">
                            <label>Bidang</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-solid fa-layer-group"></i></div>
                                <select class="fsel" name="bidang_p" id="fil_bidang_p">
                                    <option value="0">Semua Bidang</option>
                                    <?php foreach ($bidangAll as $b): ?>
                                        <option value="<?= (int)$b['id_bidang'] ?>" <?= $f_bidangP === (int)$b['id_bidang'] ? 'selected' : ''; ?>><?= esc($b['nama_bidang']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                            </div>
                        </div>
                        <div class="fitem">
                            <label>Kategori</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-solid fa-diagram-project"></i></div>
                                <select class="fsel" name="kategori_p" id="fil_kategori_p">
                                    <option value="0">Semua Kategori</option>
                                    <?php foreach ($kategoriAll as $k): ?>
                                        <option data-bidang="<?= (int)$k['id_bidang'] ?>" value="<?= (int)$k['id_kategori'] ?>" <?= $f_katP === (int)$k['id_kategori'] ? 'selected' : ''; ?>><?= esc($k['nama_kategori']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                            </div>
                        </div>
                        <div class="fitem">
                            <label>Cari</label>
                            <div class="field">
                                <div class="ficon"><i class="fa-solid fa-magnifying-glass"></i></div>
                                <input class="fsel" name="q_p" value="<?= esc($q_p) ?>" placeholder="Nama posisi…">
                                <div class="chev"><i class="fa-solid fa-text-width"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="factions">
                        <p class="hintline">Filter bertingkat: pilih bidang, lalu kategori.</p>
                        <div class="actions">
                            <button class="btn btn--sm"><i class="fa-solid fa-filter"></i>Terapkan</button>
                            <a class="btn btn--sm btn--ghost" href="?tab=posisi"><i class="fa-solid fa-rotate-left"></i>Reset</a>
                        </div>
                    </div>
                </form>
            </section>
            <br>
            <section class="card card--loose">
                <div class="card__hdr card__hdr--xl">
                    <h6><i class="fa-solid fa-briefcase"></i>Daftar Posisi</h6>
                    <?php if (is_admin()): ?><button class="btn btn--sm" onclick="openCreate('posisi')"><i class="fa-solid fa-plus"></i>Posisi Baru</button><?php endif; ?>
                </div>
                <ul class="relaxed-list" id="listPosisi">
                    <?php if (!$rowsP): ?><li class="row row--empty">Tidak ada data.</li><?php endif; ?>
                    <?php foreach ($rowsP as $p): ?>
                        <li class="row">
                            <div class="row__left">
                                <div class="avatar"><i class="fa-solid fa-briefcase"></i></div>
                                <div class="txt">
                                    <div class="ttl"><?= esc($p['nama_posisi']) ?></div>
                                    <div class="sub">
                                        <span class="tag"><?= esc($p['nama_bidang']) ?></span>
                                        <span class="tag"><?= esc($p['nama_kategori']) ?></span>
                                        <span class="tag">Slug: <?= esc($p['slug_posisi'] ?: '-') ?></span>
                                    </div>
                                    <?php if (!empty($p['link_soal_default'])): ?>
                                        <div class="sub">
                                            <a class="pill-link" href="<?= esc($p['link_soal_default']) ?>" target="_blank" rel="noopener">Link Soal Default</a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="row__right">
                                <span class="badge <?= ($p['jml_pendaftaran'] > 0 || $p['jml_bank_soal'] > 0) ? 'warn' : 'ok' ?>">Relasi:
                                    BS <?= (int)$p['jml_bank_soal'] ?> / DF <?= (int)$p['jml_pendaftaran'] ?></span>
                                <?php if (is_admin()): ?>
                                    <button class="btn btn--sm" onclick='editPosisi(<?= json_encode($p) ?>)'><i class="fa-solid fa-pen"></i>Edit</button>
                                    <form method="post" onsubmit="return confirm('Hapus posisi ini? Tidak boleh ada pendaftaran/bank_soal terkait.')">
                                        <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="entity" value="posisi">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id_posisi" value="<?= (int)$p['id_posisi'] ?>">
                                        <button class="btn btn--sm btn--ghost"><i class="fa-solid fa-trash"></i>Hapus</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($totP > $perP): ?>
                    <div class="tool" style="margin-top:10px">
                        <?php for ($i = 1; $i <= ceil($totP / $perP); $i++): $qs = $_GET;
                            $qs['pp'] = $i; ?>
                            <?php if ($i === $pp): ?><span class="pill"><b>Hal <?= $i ?></b></span>
                            <?php else: ?><a class="btn btn--sm" href="?<?= esc(http_build_query($qs)) ?>">Hal <?= $i ?></a><?php endif; ?>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <br>
        <!-- ====== PANELS (CREATE / EDIT) ====== -->

        <!-- CREATE: BIDANG -->
        <section class="card" id="createBidang" hidden>
            <div class="card__hdr">
                <h6><i class="fa-solid fa-plus"></i>Bidang Baru</h6>
                <button class="btn btn--sm btn--ghost" onclick="togglePanel('createBidang', false)"><i class="fa-solid fa-xmark"></i>Tutup</button>
            </div>
            <form method="post" class="grid grid-2">
                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="entity" value="bidang">
                <input type="hidden" name="action" value="create">
                <div>
                    <label class="muted">Nama Bidang</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-layer-group"></i></div>
                        <input class="fsel" name="nama_bidang" placeholder="mis. Teknologi" required>
                        <div class="chev"><i class="fa-solid fa-text-width"></i></div>
                    </div>
                </div>
                <div>
                    <button class="btn btn--primary"><i class="fa-solid fa-floppy-disk"></i>Simpan</button>
                </div>
            </form>
        </section>
        <br>
        <!-- EDIT: BIDANG -->
        <section class="card" id="editBidang" hidden>
            <div class="card__hdr">
                <h6><i class="fa-solid fa-pen"></i>Edit Bidang</h6>
                <button class="btn btn--sm btn--ghost" onclick="togglePanel('editBidang', false)"><i class="fa-solid fa-xmark"></i>Tutup</button>
            </div>
            <form method="post" class="grid grid-2">
                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="entity" value="bidang">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id_bidang" id="eb_id">
                <div>
                    <label class="muted">Nama Bidang</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-layer-group"></i></div>
                        <input class="fsel" name="nama_bidang" id="eb_nama" required>
                        <div class="chev"><i class="fa-solid fa-text-width"></i></div>
                    </div>
                </div>
                <div>
                    <button class="btn btn--primary"><i class="fa-solid fa-floppy-disk"></i>Simpan Perubahan</button>
                </div>
            </form>
        </section>
        <br>
        <!-- CREATE: KATEGORI -->
        <section class="card" id="createKategori" hidden>
            <div class="card__hdr">
                <h6><i class="fa-solid fa-plus"></i>Kategori Baru</h6>
                <button class="btn btn--sm btn--ghost" onclick="togglePanel('createKategori', false)"><i class="fa-solid fa-xmark"></i>Tutup</button>
            </div>
            <form method="post" class="grid grid-2">
                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="entity" value="kategori">
                <input type="hidden" name="action" value="create">
                <div>
                    <label class="muted">Bidang</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-layer-group"></i></div>
                        <select class="fsel" name="id_bidang" id="ck_bidang" required>
                            <option value="">Pilih Bidang</option>
                            <?php foreach ($bidangAll as $b): ?>
                                <option value="<?= (int)$b['id_bidang'] ?>"><?= esc($b['nama_bidang']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                    </div>
                </div>
                <div>
                    <label class="muted">Nama Kategori</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-diagram-project"></i></div>
                        <input class="fsel" name="nama_kategori" id="ck_nama" placeholder="mis. Data & AI" required>
                        <div class="chev"><i class="fa-solid fa-text-width"></i></div>
                    </div>
                </div>
                <div>
                    <label class="muted">Slug (opsional)</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-tag"></i></div>
                        <input class="fsel" name="slug_kategori" id="ck_slug" placeholder="otomatis jika kosong">
                        <div class="chev"><i class="fa-solid fa-hashtag"></i></div>
                    </div>
                </div>
                <div>
                    <button class="btn btn--primary"><i class="fa-solid fa-floppy-disk"></i>Simpan</button>
                </div>
            </form>
        </section>
        <br>
        <!-- EDIT: KATEGORI -->
        <section class="card" id="editKategori" hidden>
            <div class="card__hdr">
                <h6><i class="fa-solid fa-pen"></i>Edit Kategori</h6>
                <button class="btn btn--sm btn--ghost" onclick="togglePanel('editKategori', false)"><i class="fa-solid fa-xmark"></i>Tutup</button>
            </div>
            <form method="post" class="grid grid-2">
                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="entity" value="kategori">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id_kategori" id="ek_id">
                <div>
                    <label class="muted">Bidang</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-layer-group"></i></div>
                        <select class="fsel" name="id_bidang" id="ek_bidang" required>
                            <option value="">Pilih Bidang</option>
                            <?php foreach ($bidangAll as $b): ?>
                                <option value="<?= (int)$b['id_bidang'] ?>"><?= esc($b['nama_bidang']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                    </div>
                </div>
                <div>
                    <label class="muted">Nama Kategori</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-diagram-project"></i></div>
                        <input class="fsel" name="nama_kategori" id="ek_nama" required>
                        <div class="chev"><i class="fa-solid fa-text-width"></i></div>
                    </div>
                </div>
                <div>
                    <label class="muted">Slug</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-tag"></i></div>
                        <input class="fsel" name="slug_kategori" id="ek_slug">
                        <div class="chev"><i class="fa-solid fa-hashtag"></i></div>
                    </div>
                </div>
                <div>
                    <button class="btn btn--primary"><i class="fa-solid fa-floppy-disk"></i>Simpan Perubahan</button>
                </div>
            </form>
        </section>
        <br>
        <!-- CREATE: POSISI -->
        <section class="card" id="createPosisi" hidden>
            <div class="card__hdr">
                <h6><i class="fa-solid fa-plus"></i>Posisi Baru</h6>
                <button class="btn btn--sm btn--ghost" onclick="togglePanel('createPosisi', false)"><i class="fa-solid fa-xmark"></i>Tutup</button>
            </div>
            <form method="post" class="grid grid-2">
                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="entity" value="posisi">
                <input type="hidden" name="action" value="create">
                <div>
                    <label class="muted">Bidang</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-layer-group"></i></div>
                        <select class="fsel" id="cp_bidang" required>
                            <option value="">Pilih Bidang</option>
                            <?php foreach ($bidangAll as $b): ?>
                                <option value="<?= (int)$b['id_bidang'] ?>"><?= esc($b['nama_bidang']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                    </div>
                </div>
                <div>
                    <label class="muted">Kategori</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-diagram-project"></i></div>
                        <select class="fsel" name="id_kategori" id="cp_kategori" required>
                            <option value="">Pilih Kategori</option>
                            <?php foreach ($kategoriAll as $k): ?>
                                <option data-bidang="<?= (int)$k['id_bidang'] ?>" value="<?= (int)$k['id_kategori'] ?>"><?= esc($k['nama_kategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                    </div>
                </div>
                <div>
                    <label class="muted">Nama Posisi</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-briefcase"></i></div>
                        <input class="fsel" name="nama_posisi" id="cp_nama" placeholder="mis. React Developer" required>
                        <div class="chev"><i class="fa-solid fa-text-width"></i></div>
                    </div>
                </div>
                <div>
                    <label class="muted">Slug (opsional)</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-tag"></i></div>
                        <input class="fsel" name="slug_posisi" id="cp_slug" placeholder="otomatis jika kosong">
                        <div class="chev"><i class="fa-solid fa-hashtag"></i></div>
                    </div>
                </div>
                <div>
                    <label class="muted">Link Soal Default (opsional)</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-regular fa-file-lines"></i></div>
                        <input class="fsel" name="link_soal_default" placeholder="https://...">
                        <div class="chev"><i class="fa-solid fa-link"></i></div>
                    </div>
                </div>
                <div>
                    <button class="btn btn--primary"><i class="fa-solid fa-floppy-disk"></i>Simpan</button>
                </div>
            </form>
        </section>
        <br>
        <!-- EDIT: POSISI -->
        <section class="card" id="editPosisi" hidden>
            <div class="card__hdr">
                <h6><i class="fa-solid fa-pen"></i>Edit Posisi</h6>
                <button class="btn btn--sm btn--ghost" onclick="togglePanel('editPosisi', false)"><i class="fa-solid fa-xmark"></i>Tutup</button>
            </div>
            <form method="post" class="grid grid-2">
                <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="entity" value="posisi">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id_posisi" id="ep_id">
                <div>
                    <label class="muted">Bidang</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-layer-group"></i></div>
                        <select class="fsel" id="ep_bidang" required>
                            <option value="">Pilih Bidang</option>
                            <?php foreach ($bidangAll as $b): ?>
                                <option value="<?= (int)$b['id_bidang'] ?>"><?= esc($b['nama_bidang']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                    </div>
                </div>
                <div>
                    <label class="muted">Kategori</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-diagram-project"></i></div>
                        <select class="fsel" name="id_kategori" id="ep_kategori" required></select>
                        <div class="chev"><i class="fa-solid fa-chevron-down"></i></div>
                    </div>
                </div>
                <div>
                    <label class="muted">Nama Posisi</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-briefcase"></i></div>
                        <input class="fsel" name="nama_posisi" id="ep_nama" required>
                        <div class="chev"><i class="fa-solid fa-text-width"></i></div>
                    </div>
                </div>
                <div>
                    <label class="muted">Slug</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-solid fa-tag"></i></div>
                        <input class="fsel" name="slug_posisi" id="ep_slug">
                        <div class="chev"><i class="fa-solid fa-hashtag"></i></div>
                    </div>
                </div>
                <div>
                    <label class="muted">Link Soal Default (opsional)</label>
                    <div class="field">
                        <div class="ficon"><i class="fa-regular fa-file-lines"></i></div>
                        <input class="fsel" name="link_soal_default" id="ep_link">
                        <div class="chev"><i class="fa-solid fa-link"></i></div>
                    </div>
                </div>
                <div>
                    <button class="btn btn--primary"><i class="fa-solid fa-floppy-disk"></i>Simpan Perubahan</button>
                </div>
            </form>
        </section>
        <br>
        <footer class="ft">© RELIPROVE</footer>
    </main>

    <!-- SCRIPTS -->
    <script>
        const katByBidang = <?= json_encode($katByBidang, JSON_UNESCAPED_UNICODE) ?>;

        function togglePanel(id, show) {
            const el = document.getElementById(id);
            if (!el) return;
            if (show) el.removeAttribute('hidden');
            else el.setAttribute('hidden', '');
            if (show) el.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }

        function tabAdd() {
            const url = new URL(location.href);
            const t = url.searchParams.get('tab') || 'bidang';
            if (t === 'bidang') openCreate('bidang');
            if (t === 'kategori') openCreate('kategori');
            if (t === 'posisi') openCreate('posisi');
        }

        function openCreate(which) {
            togglePanel('createBidang', false);
            togglePanel('createKategori', false);
            togglePanel('createPosisi', false);
            if (which === 'bidang') togglePanel('createBidang', true);
            if (which === 'kategori') togglePanel('createKategori', true);
            if (which === 'posisi') togglePanel('createPosisi', true);
        }

        // ---- Bidang
        function editBidang(id, nama) {
            document.getElementById('eb_id').value = id;
            document.getElementById('eb_nama').value = nama;
            togglePanel('editBidang', true);
        }

        // ---- Kategori
        function editKategori(k) {
            document.getElementById('ek_id').value = k.id_kategori;
            document.getElementById('ek_nama').value = k.nama_kategori;
            document.getElementById('ek_slug').value = k.slug_kategori || '';
            document.getElementById('ek_bidang').value = k.id_bidang;
            togglePanel('editKategori', true);
        }

        // ---- Posisi
        function fillKategoriSelect(selectEl, idBidang, selectedId) {
            selectEl.innerHTML = '<option value="">Pilih Kategori</option>';
            (katByBidang[idBidang] || []).forEach(k => {
                const o = document.createElement('option');
                o.value = k.id_kategori;
                o.textContent = k.nama_kategori;
                if (selectedId && parseInt(selectedId, 10) === parseInt(k.id_kategori, 10)) o.selected = true;
                selectEl.appendChild(o);
            });
        }
        // create posisi: filter kategori by bidang
        const cp_bidang = document.getElementById('cp_bidang');
        const cp_kat = document.getElementById('cp_kategori');
        if (cp_bidang && cp_kat) {
            const sync = () => fillKategoriSelect(cp_kat, parseInt(cp_bidang.value || '0', 10));
            cp_bidang.addEventListener('change', sync);
            sync();
        }

        function editPosisi(p) {
            document.getElementById('ep_id').value = p.id_posisi;
            document.getElementById('ep_nama').value = p.nama_posisi;
            document.getElementById('ep_slug').value = p.slug_posisi || '';
            document.getElementById('ep_link').value = p.link_soal_default || '';
            // bidang -> kategori
            const bid = parseInt(p.id_bidang, 10);
            const ekb = document.getElementById('ep_bidang');
            const ekc = document.getElementById('ep_kategori');
            ekb.value = bid || '';
            fillKategoriSelect(ekc, bid, p.id_kategori);
            ekb.onchange = () => fillKategoriSelect(ekc, parseInt(ekb.value || '0', 10));
            togglePanel('editPosisi', true);
        }

        // Filter Posisi: hide kategori not in bidang
        (function() {
            const fb = document.getElementById('fil_bidang_p');
            const fk = document.getElementById('fil_kategori_p');
            if (!fb || !fk) return;

            function filterKat() {
                const b = parseInt(fb.value || '0', 10);
                [...fk.options].forEach(o => {
                    if (!o.value || !o.dataset.bidang) return;
                    const ob = parseInt(o.dataset.bidang, 10);
                    o.hidden = (b && ob !== b);
                    if (o.hidden && o.selected) fk.value = '0';
                });
            }
            fb.addEventListener('change', filterKat);
            filterKat();
        })();

        // Filter Kategori: (opsional, hanya 1 dropdown—skip)

        // Slug helper on typing (optional UX)
        function toSlug(s) {
            return (s || '').normalize('NFKD').replace(/[^\w\s-]/g, '').trim().replace(/\s+/g, '-').toLowerCase();
        }
        const ck_nama = document.getElementById('ck_nama'),
            ck_slug = document.getElementById('ck_slug');
        if (ck_nama && ck_slug) {
            ck_nama.addEventListener('input', () => {
                if (!ck_slug.value) ck_slug.value = toSlug(ck_nama.value);
            });
        }
        const cp_nama = document.getElementById('cp_nama'),
            cp_slug = document.getElementById('cp_slug');
        if (cp_nama && cp_slug) {
            cp_nama.addEventListener('input', () => {
                if (!cp_slug.value) cp_slug.value = toSlug(cp_nama.value);
            });
        }
    </script>
</body>

</html>