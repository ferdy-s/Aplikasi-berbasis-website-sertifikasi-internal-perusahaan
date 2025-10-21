<?php

/*************************************************
 * RELIPROVE — pengguna.php (Cards High-Tech)
 * Manajemen Pengguna: list (cards), search, filter, tambah, ubah,
 * hapus, toggle aktif, reset password (hash).
 *************************************************/

declare(strict_types=1);
session_start();

/* ====== CONFIG (GANTI SESUAI SERVERMU) ====== */
$dbHost = '127.0.0.1';
$dbName = 'db_relipove';
$dbUser = 'root';
$dbPass = '';
$dsn    = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

/* ====== AUTH MOCK (sesuaikan integrasi loginmu) ====== */
if (!isset($_SESSION['user'])) {
    $_SESSION['user'] = [
        'id_pengguna' => 3,
        'nama_lengkap' => 'Admin RELIPROVE',
        'email' => 'admin@reliprove.local',
        'peran' => 'superadmin'
    ];
}
$me = $_SESSION['user'];
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

/* ====== ACTIONS (POST) ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    $act = $_POST['act'] ?? '';
    $csrf = $_POST['csrf'] ?? '';
    if (!csrf_check($csrf)) {
        flash('err', 'Sesi berakhir / CSRF tidak valid. Coba ulangi.');
        header('Location: pengguna.php');
        exit;
    }
    try {
        if ($act === 'create') {
            $nama  = trim($_POST['nama_lengkap'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $peran = $_POST['peran'] ?? 'peserta';
            $asal  = $_POST['asal_peserta'] ?? 'internal';
            $inst  = trim($_POST['nama_instansi'] ?? '');
            $noid  = trim($_POST['no_identitas'] ?? '');
            $pend  = trim($_POST['pendidikan'] ?? '');
            $pass1 = $_POST['password'] ?? '';
            if ($nama === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $pass1 === '') {
                throw new RuntimeException('Nama, email, dan password wajib diisi & email harus valid.');
            }
            if (!in_array($peran, ['peserta', 'asesor', 'admin', 'superadmin'], true)) $peran = 'peserta';
            if (!in_array($asal, ['internal', 'eksternal', 'magang'], true)) $asal = 'internal';
            $hash = password_hash($pass1, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO pengguna (nama_lengkap,email,sandi,peran,status_aktif,pendidikan,asal_peserta,nama_instansi,no_identitas)
                             VALUES (?,?,?,?,1,?,?,?,?)");
            $stmt->execute([$nama, $email, $hash, $peran, $pend, $asal, $inst, $noid]);
            flash('ok', 'Pengguna baru berhasil ditambahkan.');
        }

        if ($act === 'update') {
            $id    = (int)($_POST['id_pengguna'] ?? 0);
            $nama  = trim($_POST['nama_lengkap'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $peran = $_POST['peran'] ?? 'peserta';
            $asal  = $_POST['asal_peserta'] ?? 'internal';
            $inst  = trim($_POST['nama_instansi'] ?? '');
            $noid  = trim($_POST['no_identitas'] ?? '');
            $pend  = trim($_POST['pendidikan'] ?? '');
            if ($id <= 0) throw new RuntimeException('ID tidak valid.');
            if ($nama === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Nama & email wajib diisi & email harus valid.');
            }
            if (!in_array($peran, ['peserta', 'asesor', 'admin', 'superadmin'], true)) $peran = 'peserta';
            if (!in_array($asal, ['internal', 'eksternal', 'magang'], true)) $asal = 'internal';
            $stmt = $pdo->prepare("UPDATE pengguna SET nama_lengkap=?, email=?, peran=?, pendidikan=?, asal_peserta=?, nama_instansi=?, no_identitas=? WHERE id_pengguna=?");
            $stmt->execute([$nama, $email, $peran, $pend, $asal, $inst, $noid, $id]);
            flash('ok', 'Data pengguna diperbarui.');
        }

        if ($act === 'delete') {
            $id = (int)($_POST['id_pengguna'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ID tidak valid.');
            if ($id === (int)$me['id_pengguna']) throw new RuntimeException('Tidak dapat menghapus akun yang sedang login.');
            $pdo->prepare("DELETE FROM pengguna WHERE id_pengguna=?")->execute([$id]);
            flash('ok', 'Pengguna dihapus.');
        }

        if ($act === 'toggle') {
            $id = (int)($_POST['id_pengguna'] ?? 0);
            $to = (int)($_POST['to'] ?? 1);
            if ($id <= 0) throw new RuntimeException('ID tidak valid.');
            $pdo->prepare("UPDATE pengguna SET status_aktif=? WHERE id_pengguna=?")->execute([$to ? 1 : 0, $id]);
            flash('ok', 'Status pengguna diperbarui.');
        }

        if ($act === 'resetpass') {
            $id  = (int)($_POST['id_pengguna'] ?? 0);
            $new = $_POST['new_password'] ?? '';
            if ($id <= 0 || $new === '') throw new RuntimeException('ID/password baru tidak valid.');
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE pengguna SET sandi=? WHERE id_pengguna=?")->execute([$hash, $id]);
            flash('ok', 'Password pengguna telah direset.');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: pengguna.php');
    exit;
}

/* ====== FILTER + PAGINATION (GET) ====== */
$q       = trim($_GET['q']   ?? '');
$fRole   = $_GET['role']     ?? '';
$fStatus = $_GET['status']   ?? '';
$fAsal   = $_GET['asal']     ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(5, min(50, (int)($_GET['per'] ?? 10)));
$offset  = ($page - 1) * $perPage;

$where = [];
$bind  = [];
if ($q !== '') {
    $where[] = "(nama_lengkap LIKE :q OR email LIKE :q OR no_identitas LIKE :q)";
    $bind[':q'] = "%{$q}%";
}
if (in_array($fRole, ['peserta', 'asesor', 'admin', 'superadmin'], true)) {
    $where[] = "peran=:r";
    $bind[':r'] = $fRole;
}
if ($fStatus === '1' || $fStatus === '0') {
    $where[] = "status_aktif=:s";
    $bind[':s'] = (int)$fStatus;
}
if (in_array($fAsal, ['internal', 'eksternal', 'magang'], true)) {
    $where[] = "asal_peserta=:a";
    $bind[':a'] = $fAsal;
}
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* Total */
$stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM pengguna {$sqlWhere}");
foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$total = (int)$stmt->fetch()['c'];
$pages = (int)ceil($total / $perPage);

/* Data */
$sql = "SELECT id_pengguna,nama_lengkap,email,peran,status_aktif,tanggal_daftar,pendidikan,asal_peserta,nama_instansi,no_identitas
        FROM pengguna
        {$sqlWhere}
        ORDER BY tanggal_daftar DESC, id_pengguna DESC
        LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Manajemen Pengguna | RELIPROVE</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/fitur_filter.css" rel="stylesheet">
    <link rel="icon" href="../aset/img/logo.png" type="image/png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet" />
    <style>
        .form-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: 1fr 1fr
        }

        .form-grid .full {
            grid-column: 1/-1
        }

        .inp,
        .sel {
            width: 100%;
            padding: .56rem .7rem;
            border-radius: 10px;
            border: 1px solid var(--border-light);
            background: rgba(255, 255, 255, .03);
            color: var(--text)
        }

        /* ===== Modal: high-tech wide sheet ===== */
        .modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 90;
            background: radial-gradient(1200px 600px at 20% -10%,
                    rgba(155, 94, 255, 0.08),
                    transparent 60%),
                rgba(0, 0, 0, 0.55);
            backdrop-filter: blur(3px);
        }

        .modal.open {
            display: flex;
        }

        .modal .sheet {
            width: min(1100px, 96vw);
            /* lebih lega */
            background: linear-gradient(180deg,
                    rgba(21, 25, 35, 0.96),
                    rgba(21, 25, 35, 0.94));
            border: 1px solid var(--border-light);
            border-radius: 24px;
            /* lebih bulat */
            box-shadow:
                0 20px 60px rgba(0, 0, 0, 0.55),
                0 0 0 1px rgba(255, 255, 255, 0.03) inset;
            padding: 18px;
            position: relative;
        }

        .modal .sheet::after {
            /* aura lembut */
            content: "";
            position: absolute;
            inset: -1px;
            border-radius: inherit;
            pointer-events: none;
            box-shadow: 0 0 38px rgba(155, 94, 255, 0.12);
        }

        /* header besar & tombol tutup */
        .sheet .card__hdr {
            border: none;
            padding: 4px 4px 12px;
            margin-bottom: 6px;
        }

        .sheet .card__hdr h6 {
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sheet .card__hdr h6 i {
            width: 28px;
            height: 28px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: rgba(155, 94, 255, .14);
            color: #e9ddff;
            border: 1px solid rgba(155, 94, 255, .35);
        }

        /* grid dua kolom yang lega */
        .sheet__body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 880px) {
            .sheet__body {
                grid-template-columns: 1fr;
            }
        }

        /* ===== Modal Edit: high-tech wide form ===== */
        .modal .sheet {
            width: min(950px, 96vw);
            border-radius: 22px;
            padding: 20px 22px;
            background: linear-gradient(180deg, rgba(21, 25, 35, .96), rgba(21, 25, 35, .94));
            border: 1px solid var(--border-light);
            box-shadow:
                0 20px 60px rgba(0, 0, 0, .55),
                0 0 0 1px rgba(255, 255, 255, .03) inset;
        }

        /* header */
        .sheet .card__hdr {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: none;
            margin-bottom: 14px;
        }

        .sheet .card__hdr h6 {
            font-weight: 900;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sheet .card__hdr h6 i {
            width: 28px;
            height: 28px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: rgba(155, 94, 255, .14);
            border: 1px solid rgba(155, 94, 255, .35);
            color: #e9ddff;
        }

        /* form grid lega */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px 24px;
        }

        .form-grid .full {
            grid-column: 1/-1;
        }

        /* input & select gelap elegan */
        .inp,
        .sel {
            width: 100%;
            padding: .75rem 1rem;
            border-radius: 14px;
            border: 1px solid var(--border-light);
            background: rgba(255, 255, 255, .05);
            color: var(--text);
            font-size: .95rem;
            transition: .2s border, .2s background;
        }

        .inp:focus,
        .sel:focus {
            outline: none;
            border-color: rgba(155, 94, 255, .65);
            background: rgba(155, 94, 255, .08);
        }

        /* label */
        label.muted {
            display: block;
            margin-bottom: 6px;
            font-size: .88rem;
            font-weight: 600;
            color: var(--text-light);
        }

        /* dropdown high-tech (hilangkan putih default) */
        .sel {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg fill='white' height='16' viewBox='0 0 24 24' width='16' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/></svg>");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px 16px;
            padding-right: 2.5rem;
        }

        .sel option {
            background: #151923;
            color: #e9e9f6;
        }

        /* button primary */
        .btn.btn--primary {
            background: linear-gradient(135deg, #9b5eff, #6936c9);
            border: none;
            border-radius: 14px;
            font-weight: 700;
            padding: .75rem 1.2rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn.btn--primary:hover {
            background: linear-gradient(135deg, #a873ff, #7a48db);
        }

        /* panel kaca untuk tiap kolom */
        .panel {
            background: var(--bg-row);
            border: 1px solid var(--border-light);
            border-radius: 18px;
            box-shadow: 0 12px 28px rgba(0, 0, 0, .35);
            padding: 16px;
            position: relative;
        }

        .panel::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            border-radius: inherit;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .04);
        }

        /* daftar key-value yang lapang */
        .kv {
            display: grid;
            grid-template-columns: 170px 1fr;
            gap: 10px;
            align-items: center;
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(255, 255, 255, .03);
            border: 1px solid var(--border-light);
            margin-bottom: 10px;
        }

        .kv__key {
            font-weight: 900;
            color: var(--text-light);
        }

        .kv__val {
            color: #dfe1f6;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .kv--ghost {
            /* untuk password block */
            grid-template-columns: 1fr auto;
        }

        /* status chip yang tebal */
        .chip-status {
            border: 1px solid rgba(255, 255, 255, .14);
            background: rgba(255, 255, 255, .06);
            padding: .26rem .6rem;
            border-radius: 999px;
            font-size: .9rem;
            font-weight: 700;
        }

        .chip-status.ok {
            background: rgba(23, 177, 117, .18);
            border-color: rgba(23, 177, 117, .35);
        }

        .chip-status.warn {
            background: rgba(255, 187, 60, .18);
            border-color: rgba(255, 187, 60, .35);
        }

        /* tombol reset menyatu block */
        .kv__actions {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            padding: 0.5rem 1rem;
        }

        /* form reset section */
        .sheet .reset-card {
            margin-top: 14px;
            background: var(--bg-row);
            border: 1px dashed var(--border-light);
            border-radius: 16px;
            padding: 14px;
        }

        .pagination {
            display: flex;
            gap: 6px;
            flex-wrap: wrap
        }

        .pagination a,
        .pagination span {
            padding: .32rem .6rem;
            border-radius: 10px;
            border: 1px solid var(--border-light);
            color: var(--text);
            text-decoration: none;
            background: rgba(255, 255, 255, .03)
        }

        .pagination .active {
            border-color: rgba(155, 94, 255, .35);
            background: rgba(155, 94, 255, .12);
            color: #efeaff;
            font-weight: 800
        }

        .hintline {
            color: var(--muted);
            font-size: .92rem
        }

        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace
        }

        /* Tambahan kecil untuk meta */
        .meta i {
            font-style: normal;
            opacity: .8
        }

        .assess__foot .btn {
            padding: .38rem .6rem
        }

        .user-id-chip {
            padding: .22rem .5rem;
            border: 1px solid var(--border-light);
            border-radius: 999px;
            background: rgba(255, 255, 255, .05);
            font-size: .82rem
        }
    </style>
</head>

<body>
    <!-- SIDEBAR -->
    <aside class="sb" id="sidebar">
        <div class="sb__brand" style="font-size: 35px; margin-top: 15px; margin-bottom: 10px;">RELIPROVE</div>
        <nav class="sb__nav">
            <a href="dashboard_admin.php"><i class="fa-solid fa-gauge"></i>Dashboard</a>
            <a class="active" href="pengguna.php"><i class="fa-solid fa-users"></i>Manajemen Pengguna</a>
            <a href="pendaftaran.php"><i class="fa-solid fa-id-card"></i>Pendaftaran</a>
            <a href="penilaian.php"><i class="fa-solid fa-clipboard-check"></i>Penilaian</a>
            <a href="bank_soal.php"><i class="fa-solid fa-book-open"></i>Bank Soal</a>
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
                <h1 class="tb__title">Manajemen Pengguna</h1>
                <form class="tb__search" method="get">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input name="q" value="<?= esc($q) ?>" placeholder="Cari… (nama, email, no identitas)">
                </form>
            </div>
            <div class="tb__right">
                <?php if (is_admin()): ?>
                    <button class="btn" onclick="openCreate()"><i class="fa-solid fa-user-plus"></i>Pengguna Baru</button>
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

        <!-- FLASH -->
        <?php if ($m = flash('ok')): ?>
            <div class="card" style="border-color:rgba(23,177,117,.35);background:rgba(23,177,117,.12);margin:12px 0"><?= esc($m) ?></div>
        <?php endif; ?>
        <?php if ($m = flash('err')): ?>
            <div class="card" style="border-color:rgba(255,107,107,.35);background:rgba(255,107,107,.12);margin:12px 0"><?= esc($m) ?></div>
        <?php endif; ?>
        <br>
        <!-- FILTERS -->
        <section class="card card--ht-filter">
            <div class="filter__hdr">
                <div class="filter__title">
                    <i class="fa-solid fa-filter"></i>
                    <span>Filter</span>
                </div>
                <span class="count-badge"><?= number_format($total) ?> pengguna</span>
            </div>

            <form method="get">
                <input type="hidden" name="q" value="<?= esc($q) ?>">

                <div class="frow">
                    <!-- Peran -->
                    <div class="fitem">
                        <label>Peran</label>
                        <div class="field">
                            <div class="ficon"><i class="fa-solid fa-user-gear"></i></div>
                            <select name="role" class="fsel">
                                <option value="">Semua</option>
                                <?php foreach (['peserta', 'asesor', 'admin', 'superadmin'] as $r): ?>
                                    <option value="<?= $r ?>" <?= $fRole === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="chev"><i class="fa-solid fa-angle-down"></i></div>
                        </div>
                    </div>

                    <!-- Status -->
                    <div class="fitem">
                        <label>Status</label>
                        <div class="field">
                            <div class="ficon"><i class="fa-solid fa-circle-check"></i></div>
                            <select name="status" class="fsel">
                                <option value="">Semua</option>
                                <option value="1" <?= $fStatus === '1' ? 'selected' : '' ?>>Aktif</option>
                                <option value="0" <?= $fStatus === '0' ? 'selected' : '' ?>>Nonaktif</option>
                            </select>
                            <div class="chev"><i class="fa-solid fa-angle-down"></i></div>
                        </div>
                    </div>

                    <!-- Asal -->
                    <div class="fitem">
                        <label>Asal Peserta</label>
                        <div class="field">
                            <div class="ficon"><i class="fa-solid fa-compass"></i></div>
                            <select name="asal" class="fsel">
                                <option value="">Semua</option>
                                <?php foreach (['internal', 'eksternal', 'magang'] as $a): ?>
                                    <option value="<?= $a ?>" <?= $fAsal === $a ? 'selected' : '' ?>><?= ucfirst($a) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="chev"><i class="fa-solid fa-angle-down"></i></div>
                        </div>
                    </div>
                </div>

                <div class="factions">
                    <div class="hintline">
                        Gunakan kolom cari cepat di atas untuk mencari nama, email, atau nomor identitas.
                    </div>
                    <div class="actions">
                        <button class="btn btn--sm"><i class="fa-solid fa-rotate"></i>Terapkan</button>
                        <a class="btn btn--sm btn--ghost" href="pengguna.php">
                            <i class="fa-solid fa-xmark"></i>Reset
                        </a>
                    </div>
                </div>


            </form>
        </section>

        <br>
        <!-- LIST (CARDS HIGH-TECH) -->
        <section class="card card--loose" style="margin-top:12px">
            <div class="card__hdr card__hdr--xl">
                <h6><i class="fa-solid fa-users"></i> Daftar Pengguna</h6>
                <div class="tool">
                    <span class="pill">Per halaman: <?= $perPage ?></span>
                </div>
            </div>

            <?php if (!$rows): ?>
                <div class="assess-empty">Belum ada data yang cocok.</div>
            <?php else: ?>
                <div class="assess-grid">
                    <?php foreach ($rows as $r):
                        $initials = function (string $name) {
                            $t = trim($name);
                            $parts = preg_split('/\s+/', $t);
                            $ini = '';
                            foreach ($parts as $i => $p) {
                                if ($i > 1) break;
                                $ini .= mb_strtoupper(mb_substr($p, 0, 1));
                            }
                            return $ini ?: 'U';
                        };
                    ?>
                        <div class="assess">
                            <!-- HEAD -->
                            <div class="assess__head">
                                <div class="avatar"><?= esc($initials($r['nama_lengkap'])) ?></div>
                                <div>
                                    <div class="assess__name"><?= esc($r['nama_lengkap']) ?></div>
                                    <div class="assess__pos mono" style="margin-top: 5px;"><?= esc($r['email']) ?></div>
                                </div>
                                <div class="head__tags">
                                    <span class="pill small"><?= esc($r['peran']) ?></span>
                                    <?php if ((int)$r['status_aktif'] === 1): ?>
                                        <span class="pill small good">aktif</span>
                                    <?php else: ?>
                                        <span class="pill small warn">nonaktif</span>
                                    <?php endif; ?>
                                    <span class="user-id-chip mono">No.<?= (int)$r['id_pengguna'] ?></span>
                                </div>
                            </div>

                            <!-- META -->
                            <div class="assess__meta" style="margin-top: 10px;">
                                <div class="meta"><i class="fa-solid fa-building"></i><?= esc($r['nama_instansi'] ?: '—') ?></div>
                                <div class="meta"><i class="fa-solid fa-graduation-cap"></i><?= esc($r['pendidikan'] ?: '—') ?></div>
                                <div class="meta"><i class="fa-solid fa-location-dot"></i><?= esc(ucfirst($r['asal_peserta'])) ?></div>
                                <div class="meta"><i class="fa-regular fa-calendar"></i><time class="muted"><?= esc(date('Y-m-d', strtotime($r['tanggal_daftar']))) ?></time></div>
                            </div>

                            <!-- METER (aksen hi-tech kecil: umur akun) -->
                            <div class="assess__meter" style="margin-top: 5px;">
                                <?php
                                $days = max(0, (int)floor((time() - strtotime($r['tanggal_daftar'])) / 86400));
                                $pct  = min(100, ($days / 365) * 100); // proporsi usia akun thn pertama
                                ?>
                                <div class="meter" style="margin-top: 5px;">
                                    <span class="meter__fill <?= $days < 90 ? 'ok' : ($days < 180 ? '' : 'warn') ?>" style="width: <?= number_format($pct, 0) ?>%"></span>
                                </div>
                                <div class="meter__info" style="margin-top: 9px;">
                                    <span class="hint">Usia akun ~ <?= $days ?> hari</span>
                                    <span class="age <?= $days < 90 ? 'ok' : ($days < 180 ? '' : 'warn') ?>"><?= $days < 90 ? 'baru' : ($days < 180 ? 'menengah' : 'lama') ?></span>
                                </div>
                            </div>

                            <!-- FOOT: ACTIONS -->
                            <div class="assess__foot">
                                <div style="display:flex;gap:8px;flex-wrap:wrap; margin-top: 10px;">
                                    <button class="btn btn--sm" onclick='openView(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fa-regular fa-eye"></i>Lihat</button>
                                    <?php if (is_admin()): ?>
                                        <button class="btn btn--sm" onclick='openEdit(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fa-regular fa-pen-to-square"></i>Edit</button>
                                    <?php endif; ?>
                                </div>
                                <?php if (is_admin()): ?>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap; margin-top: 10px;">
                                        <form method="post" onsubmit="return confirm('Hapus pengguna ini?');">
                                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                            <input type="hidden" name="act" value="delete">
                                            <input type="hidden" name="id_pengguna" value="<?= (int)$r['id_pengguna'] ?>">
                                            <button class="btn btn--sm btn--outline"><i class="fa-regular fa-trash-can"></i>Hapus</button>
                                        </form>
                                        <form method="post" onsubmit="return confirm('Ubah status aktif pengguna ini?');">
                                            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                                            <input type="hidden" name="act" value="toggle">
                                            <input type="hidden" name="id_pengguna" value="<?= (int)$r['id_pengguna'] ?>">
                                            <input type="hidden" name="to" value="<?= (int)$r['status_aktif'] ? 0 : 1 ?>">
                                            <?php if ((int)$r['status_aktif']): ?>
                                                <button class="btn btn--primary"><i class="fa-solid fa-user-slash"></i>Nonaktifkan</button>
                                            <?php else: ?>
                                                <button class="btn btn--sm btn--primary"><i class="fa-solid fa-user-check"></i>Aktifkan</button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- PAGINATION -->
            <?php if ($pages > 1): ?>
                <div style="margin-top:12px;display:flex;justify-content:flex-end">
                    <div class="pagination">
                        <?php
                        $qs = $_GET;
                        unset($qs['page']);
                        $base = 'pengguna.php?' . http_build_query($qs);
                        for ($p = 1; $p <= $pages; $p++):
                            $href = $base . ($qs ? '&' : '') . "page={$p}";
                        ?>
                            <?= $p === $page ? '<span class="active">' . $p . '</span>' : '<a href="' . esc($href) . '">' . $p . '</a>' ?>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
        <br>
        <footer class="ft">© <?= date('Y') ?> RELIPROVE — Manajemen Pengguna</footer>
    </main>

    <!-- MODALS -->
    <?php if (is_admin()): ?>
        <div class="modal" id="modalCreate">
            <div class="sheet">
                <div class="card__hdr">
                    <h6><i class="fa-solid fa-user-plus"></i> Tambah Pengguna</h6><button class="btn btn--sm" onclick="closeModal('modalCreate')"><i class="fa-solid fa-xmark"></i>Tutup</button>
                </div>
                <form method="post" class="form-grid">
                    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="act" value="create">
                    <div><label class="muted">Nama Lengkap</label><input class="inp" name="nama_lengkap" required></div>
                    <div><label class="muted">Email</label><input class="inp" type="email" name="email" required></div>
                    <div><label class="muted">Peran</label>
                        <select name="peran" class="sel">
                            <option value="peserta">peserta</option>
                            <option value="asesor">asesor</option>
                            <option value="admin">admin</option>
                            <option value="superadmin">superadmin</option>
                        </select>
                    </div>
                    <div><label class="muted">Asal Peserta</label>
                        <select name="asal_peserta" class="sel">
                            <option value="internal">internal</option>
                            <option value="eksternal">eksternal</option>
                            <option value="magang">magang</option>
                        </select>
                    </div>
                    <div><label class="muted">Nama Instansi</label><input class="inp" name="nama_instansi"></div>
                    <div><label class="muted">No. Identitas</label><input class="inp" name="no_identitas"></div>
                    <div class="full"><label class="muted">Pendidikan</label><input class="inp" name="pendidikan" placeholder="misal: S1 - Sistem Informasi"></div>
                    <div><label class="muted">Password</label><input class="inp" type="password" name="password" required></div>
                    <div class="full"><button class="btn btn--primary"><i class="fa-solid fa-check"></i>Simpan</button></div>
                </form>
            </div>
        </div>

        <div class="modal" id="modalEdit">
            <div class="sheet">
                <div class="card__hdr">
                    <h6><i class="fa-regular fa-pen-to-square"></i> Ubah Pengguna</h6>
                    <button class="btn btn--sm" onclick="closeModal('modalEdit')">
                        <i class="fa-solid fa-xmark"></i> Tutup
                    </button>
                </div>

                <form method="post" class="form-grid" id="formEdit">
                    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="act" value="update">
                    <input type="hidden" name="id_pengguna" id="e_id">

                    <div>
                        <label class="muted">Nama Lengkap</label>
                        <input class="inp" name="nama_lengkap" id="e_nama" required>
                    </div>
                    <div>
                        <label class="muted">Email</label>
                        <input class="inp" type="email" name="email" id="e_email" required>
                    </div>

                    <div>
                        <label class="muted">Peran</label>
                        <select name="peran" class="sel" id="e_peran">
                            <option value="peserta">peserta</option>
                            <option value="asesor">asesor</option>
                            <option value="admin">admin</option>
                            <option value="superadmin">superadmin</option>
                        </select>
                    </div>
                    <div>
                        <label class="muted">Asal Peserta</label>
                        <select name="asal_peserta" class="sel" id="e_asal">
                            <option value="internal">internal</option>
                            <option value="eksternal">eksternal</option>
                            <option value="magang">magang</option>
                        </select>
                    </div>

                    <div>
                        <label class="muted">Nama Instansi</label>
                        <input class="inp" name="nama_instansi" id="e_instansi">
                    </div>
                    <div>
                        <label class="muted">No. Identitas</label>
                        <input class="inp" name="no_identitas" id="e_noid">
                    </div>

                    <div class="full">
                        <label class="muted">Pendidikan</label>
                        <input class="inp" name="pendidikan" id="e_pend">
                    </div>

                    <div class="full" style="margin-top:8px">
                        <button class="btn btn--primary" type="submit">
                            <i class="fa-solid fa-check"></i> Perbarui
                        </button>
                    </div>
                </form>
            </div>
        </div>


        <div class="modal" id="modalView">
            <div class="sheet">
                <div class="card__hdr">
                    <h6><i class="fa-regular fa-eye"></i> Detail Pengguna</h6>
                    <button class="btn btn--sm" onclick="closeModal('modalView')">
                        <i class="fa-solid fa-xmark"></i>Tutup
                    </button>
                </div>

                <div class="sheet__body">
                    <!-- Kolom kiri -->
                    <div class="panel">
                        <div class="kv">
                            <div class="kv__key">Nama</div>
                            <div class="kv__val" id="v_nama"></div>
                        </div>
                        <div class="kv">
                            <div class="kv__key">Email</div>
                            <div class="kv__val mono" id="v_email"></div>
                        </div>
                        <div class="kv">
                            <div class="kv__key">Peran</div>
                            <div class="kv__val" id="v_peran"></div>
                        </div>
                        <div class="kv">
                            <div class="kv__key">Status</div>
                            <div class="kv__val" id="v_status"></div> <!-- diisi badge oleh JS -->
                        </div>
                        <div class="kv">
                            <div class="kv__key">Tgl Daftar</div>
                            <div class="kv__val"><time class="muted" id="v_tgl"></time></div>
                        </div>
                    </div>

                    <!-- Kolom kanan -->
                    <div class="panel">
                        <div class="kv">
                            <div class="kv__key">Asal</div>
                            <div class="kv__val" id="v_asal"></div>
                        </div>
                        <div class="kv">
                            <div class="kv__key">Instansi</div>
                            <div class="kv__val" id="v_instansi"></div>
                        </div>
                        <div class="kv">
                            <div class="kv__key">No Identitas</div>
                            <div class="kv__val mono" id="v_noid"></div>
                        </div>
                        <div class="kv">
                            <div class="kv__key">Pendidikan</div>
                            <div class="kv__val" id="v_pend"></div>
                        </div>

                        <div class="kv kv--ghost">
                            <div>
                                <div class="kv__key" style="margin-bottom:4px">Password</div>
                                <div class="muted">Disimpan dalam bentuk hash : <span class="mono">•••• (hashed)</span></div>
                            </div>
                            <div class="kv__actions">
                                <button class="btn btn--sm" onclick="openReset()">
                                    <i class="fa-solid fa-key"></i>Reset
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RESET PASSWORD -->
                <form method="post" id="formReset" class="reset-card" style="display:none">
                    <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="act" value="resetpass">
                    <input type="hidden" name="id_pengguna" id="rp_id">
                    <div class="form-grid">
                        <div class="full">
                            <label class="muted">Password Baru</label>
                            <input class="inp" type="password" name="new_password" required>
                        </div>
                        <div class="full">
                            <button class="btn btn--primary"><i class="fa-solid fa-check"></i>Setel Ulang Password</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    <?php endif; ?>

    <script>
        function closeModal(id) {
            document.getElementById(id).classList.remove('open');
        }

        function openCreate() {
            document.getElementById('modalCreate').classList.add('open');
        }

        function openEdit(row) {
            const m = document.getElementById('modalEdit');
            m.classList.add('open');
            document.getElementById('e_id').value = row.id_pengguna;
            document.getElementById('e_nama').value = row.nama_lengkap;
            document.getElementById('e_email').value = row.email;
            document.getElementById('e_peran').value = row.peran;
            document.getElementById('e_asal').value = row.asal_peserta ?? 'internal';
            document.getElementById('e_instansi').value = row.nama_instansi ?? '';
            document.getElementById('e_noid').value = row.no_identitas ?? '';
            document.getElementById('e_pend').value = row.pendidikan ?? '';
        }

        function openView(row) {
            const m = document.getElementById('modalView');
            m.classList.add('open');
            document.getElementById('v_nama').textContent = row.nama_lengkap || '-';
            document.getElementById('v_email').textContent = row.email || '-';
            document.getElementById('v_peran').textContent = row.peran || '-';
            document.getElementById('v_status').innerHTML =
                (parseInt(row.status_aktif) == 1) ?
                '<span class="chip-status ok">aktif</span>' :
                '<span class="chip-status warn">nonaktif</span>';
            document.getElementById('v_tgl').textContent = (row.tanggal_daftar || '').slice(0, 10);
            document.getElementById('v_asal').textContent = (row.asal_peserta || '-');
            document.getElementById('v_instansi').textContent = row.nama_instansi || '-';
            document.getElementById('v_noid').textContent = row.no_identitas || '-';
            document.getElementById('v_pend').textContent = row.pendidikan || '-';
            document.getElementById('rp_id').value = row.id_pengguna;
            document.getElementById('formReset').style.display = 'none';
        }

        function openReset() {
            document.getElementById('formReset').style.display = 'block';
        }
    </script>
</body>

</html>