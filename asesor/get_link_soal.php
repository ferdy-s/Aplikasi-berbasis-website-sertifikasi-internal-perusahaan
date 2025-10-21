<?php
// get_link_soal.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/koneksi.php'; // harus membuat $conn (mysqli)

function out(array $a, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    out(['ok' => false, 'error' => 'no_db_connection'], 500);
}

$id_pendaftaran = isset($_GET['id_pendaftaran']) ? (int)$_GET['id_pendaftaran'] : 0;
if ($id_pendaftaran <= 0) out(['ok' => false, 'error' => 'invalid_id'], 400);

// 1) id_posisi dari pendaftaran
$id_posisi = 0;
$st = $conn->prepare("SELECT id_posisi FROM pendaftaran WHERE id_pendaftaran = ? LIMIT 1");
$st->bind_param('i', $id_pendaftaran);
if (!$st->execute()) out(['ok' => false, 'error' => 'query_error_posisi'], 500);
$st->bind_result($id_posisi_res);
$st->fetch();
$st->close();
$id_posisi = (int)$id_posisi_res;

if ($id_posisi <= 0) out(['ok' => false, 'error' => 'posisi_not_found'], 404);

// 2) cari link soal aktif terbaru di bank_soal
$link = null;
$st2 = $conn->prepare("
    SELECT COALESCE(NULLIF(link_soal_default,''), link_drive) AS link_soal
    FROM bank_soal
    WHERE id_posisi = ? AND is_aktif = 1
    ORDER BY dibuat_pada DESC
    LIMIT 1
");
$st2->bind_param('i', $id_posisi);
if (!$st2->execute()) out(['ok' => false, 'error' => 'query_error_banksoal'], 500);
$st2->bind_result($link_res);
$st2->fetch();
$st2->close();
$link = $link_res ?? null;

if ($link && trim($link) !== '') {
    out(['ok' => true, 'link' => $link, 'sumber' => 'bank_soal']);
}

// 3) Tidak ada link → kirim meta Bidang/Kategori/Posisi untuk panduan isi Bank Soal
//    JOIN posisi -> kategori -> bidang untuk ambil nama & id
$sqlMeta = "
    SELECT
      b.id_bidang, b.nama_bidang,
      k.id_kategori, k.nama_kategori,
      p.id_posisi,  p.nama_posisi
    FROM posisi p
    JOIN kategori k ON k.id_kategori = p.id_kategori
    JOIN bidang   b ON b.id_bidang   = k.id_bidang
    WHERE p.id_posisi = ?
    LIMIT 1
";
$st3 = $conn->prepare($sqlMeta);
$st3->bind_param('i', $id_posisi);
if (!$st3->execute()) out(['ok' => false, 'error' => 'query_error_meta'], 500);
$st3->bind_result($bid_id, $bid_nama, $kat_id, $kat_nama, $pos_id, $pos_nama);
$st3->fetch();
$st3->close();

$meta = [
    'bidang'   => ['id' => (int)$bid_id, 'nama' => $bid_nama],
    'kategori' => ['id' => (int)$kat_id, 'nama' => $kat_nama],
    'posisi'   => ['id' => (int)$pos_id, 'nama' => $pos_nama],
];

// URL CTA (prefill bisa kamu baca di bank_soal.php untuk auto-select)
$qs = http_build_query([
    'mode'        => 'add',
    'id_bidang'   => $meta['bidang']['id']   ?? null,
    'id_kategori' => $meta['kategori']['id'] ?? null,
    'id_posisi'   => $meta['posisi']['id']   ?? null,
]);
$url_bank = 'bank_soal.php?' . $qs;

out([
    'ok'            => false,
    'must_create'   => true,
    'meta'          => $meta,
    'url_bank_soal' => $url_bank
], 200);
