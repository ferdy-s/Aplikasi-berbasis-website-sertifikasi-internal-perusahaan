<?php
include __DIR__ . '/../config/koneksi.php';

// Validasi parameter id_kategori
if (isset($_GET['id_kategori']) && is_numeric($_GET['id_kategori'])) {
    $id_kategori = intval($_GET['id_kategori']);

    // Siapkan array hasil
    $result = [];

    // Siapkan statement prepared
    $stmt = mysqli_prepare($conn, "SELECT id_posisi, nama_posisi FROM posisi WHERE id_kategori = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $id_kategori);
        mysqli_stmt_execute($stmt);
        $query = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($query)) {
            $result[] = $row;
        }

        mysqli_stmt_close($stmt);
    }

    // Output JSON
    header('Content-Type: application/json');
    echo json_encode($result);
} else {
    // Parameter tidak valid
    http_response_code(400);
    echo json_encode(['error' => 'Parameter id_kategori tidak valid']);
}
