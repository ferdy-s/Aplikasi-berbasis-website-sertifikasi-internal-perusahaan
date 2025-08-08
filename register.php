<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Registrasi | RELIPROVE</title>
    <link rel="icon" href="aset/img/logo.png" type="image/png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />

    <style>
        :root {
            --primary: #9b5eff;
            --bg-dark: #0b0f1a;
            --bg-card: #161a25;
            --text-light: #f2f2f2;
            --text-muted: #999;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
            width: 100%;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-light);
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow: hidden;
            padding: 1.5rem;
        }

        .matrix-bg {
            position: absolute;
            width: 100%;
            height: 100%;
            background: repeating-linear-gradient(0deg, #9b5eff11 0px, #9b5eff11 1px, transparent 1px, transparent 20px),
                repeating-linear-gradient(90deg, #9b5eff11 0px, #9b5eff11 1px, transparent 1px, transparent 20px);
            animation: moveGrid 30s linear infinite;
            z-index: 0;
        }

        @keyframes moveGrid {
            0% {
                background-position: 0 0, 0 0;
            }

            100% {
                background-position: 200px 200px, 200px 200px;
            }
        }

        .register-card {
            background-color: var(--bg-card);
            border: 1px solid var(--primary);
            border-radius: 16px;
            box-shadow: 0 0 20px #9b5eff55;
            padding: 2rem;
            width: 100%;
            max-width: 480px;
            z-index: 1;
            position: relative;
        }

        .register-card h3 {
            color: var(--primary);
            font-weight: 600;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        label {
            margin-bottom: 0.4rem;
            font-weight: 500;
        }

        .form-control,
        .form-select {
            background-color: #1e1e2f;
            color: var(--text-light);
            border: 1px solid #444;
        }

        .form-control::placeholder {
            color: var(--text-muted);
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 8px #9b5eff77;
            background-color: #1e1e2f;
            color: var(--text-light);
        }

        .btn-primary-custom {
            background-color: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
            padding: 0.6rem 1.4rem;
            border-radius: 8px;
            font-weight: 500;
            width: 100%;
            transition: all 0.3s ease;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary-custom:hover {
            background-color: var(--primary);
            color: var(--bg-dark);
            box-shadow: 0 0 12px #9b5eff88;
        }

        input[readonly] {
            background-color: #1e1e1e !important;
            color: var(--text-light) !important;
            opacity: 1 !important;
            cursor: not-allowed;
        }

        .text-muted-custom {
            color: var(--text-muted);
        }

        a {
            color: var(--primary);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        @media (max-width: 576px) {
            .register-card {
                padding: 1.5rem;
                border-radius: 12px;
            }

            .register-card h3 {
                font-size: 1.3rem;
            }

            .btn-primary-custom {
                font-size: 0.9rem;
                padding: 0.5rem 1rem;
            }

            .form-control {
                font-size: 0.9rem;
                padding: 0.45rem 0.75rem;
            }
        }
    </style>
</head>

<body>
    <div class="matrix-bg"></div>

    <div class="register-card">
        <h3><i class="fas fa-user-plus me-2"></i>Registrasi</h3>
        <form action="proses_register.php" method="post">
            <div class="mb-3">
                <label for="nama">Nama Lengkap</label>
                <input type="text" name="nama" id="nama" class="form-control" required placeholder="Masukkan nama lengkap" />
            </div>
            <div class="mb-3">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" class="form-control" required placeholder="Masukkan email aktif" />
            </div>
            <div class="mb-3">
                <label for="sandi">Kata Sandi</label>
                <input type="password" name="sandi" id="sandi" class="form-control" required placeholder="Buat kata sandi" />
            </div>
            <div class="mb-3">
                <label for="peran">Status</label>
                <input type="text" id="peran" class="form-control" value="Peserta" readonly>
                <input type="hidden" name="peran" value="peserta">
            </div>
            <button type="submit" class="btn btn-primary-custom mt-2">
                <i class="fas fa-user-check me-1"></i> Daftar
            </button>
        </form>

        <p class="text-center text-muted-custom mt-3">
            Sudah punya akun?<br>
            <a href="login.php" style="font-size: 1rem;">Login di sini</a>
        </p>
    </div>
</body>

</html>