<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login | RELIPROVE</title>
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
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: 'Segoe UI', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-light);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .matrix-bg {
            position: absolute;
            width: 100%;
            height: 100%;
            background-image:
                linear-gradient(to right, #9b5eff0c 1px, transparent 1px),
                linear-gradient(to bottom, #9b5eff0c 1px, transparent 1px);
            background-size: 40px 40px;
            animation: gridMove 60s linear infinite;
            z-index: 0;
            will-change: background-position;
            transform: translateZ(0);
        }

        @keyframes gridMove {
            from {
                background-position: 0 0;
            }

            to {
                background-position: 200px 200px;
            }
        }


        .login-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
            padding: 2rem;
            background-color: var(--bg-card);
            border: 1px solid var(--primary);
            border-radius: 16px;
            box-shadow: 0 0 12px #9b5eff33;
            content-visibility: auto;
            will-change: transform;
            transition: transform 0.3s ease;
        }

        .login-wrapper h3 {
            text-align: center;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 1.5rem;
            letter-spacing: 1px;
        }

        label {
            font-weight: 500;
            margin-bottom: 0.35rem;
        }

        .form-control {
            background-color: #1e1e2f;
            border: 1px solid #444;
            color: var(--text-light);
            font-size: 1rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .form-control::placeholder {
            color: var(--text-muted);
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 6px #9b5eff80;
        }

        .btn-primary-custom {
            background-color: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
            border-radius: 8px;
            font-weight: 500;
            width: 100%;
            padding: 0.8rem 1rem;
            transition: all 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary-custom:hover {
            background-color: var(--primary);
            color: var(--bg-dark);
            box-shadow: 0 0 10px #9b5eff88;
            transform: translateY(-2px);
        }

        .text-muted-custom {
            color: var(--text-muted);
        }

        a {
            color: var(--primary);
            transition: color 0.3s ease;
        }

        a:hover {
            text-decoration: underline;
            color: #b78fff;
        }

        .input-group-text {
            background-color: #1e1e2f;
            border: 1px solid #444;
            color: var(--text-light);
        }

        @media (max-width: 576px) {
            .login-wrapper {
                padding: 2rem 1.25rem;
                max-width: 100%;
                margin: 1rem;
                border-radius: 12px;
                box-shadow: 0 0 8px #9b5eff22;
            }

            .login-wrapper h3 {
                font-size: 1.3rem;
            }

            label,
            .form-control,
            .btn-primary-custom,
            .input-group-text {
                font-size: 0.95rem;
            }

            .input-group-text {
                padding: 0.45rem 0.75rem;
            }
        }
    </style>

</head>

<body>
    <div class="matrix-bg"></div>

    <div class="login-wrapper">
        <h3><i class="fas fa-fingerprint me-2"></i>LOGIN</h3>
        <form action="proses_login.php" method="post">
            <div class="mb-3">
                <label for="email" style="margin-bottom: 13px;">Email</label>
                <input type="email" name="email" id="email" class="form-control" placeholder="Masukan Email Terdaftar" required />
            </div>
            <div class="mb-3">
                <label for="sandi" style="margin-bottom: 13px;">Kata Sandi</label>
                <div class="input-group">
                    <input type="password" name="sandi" id="sandi" class="form-control" placeholder="Masukan Kata Sandi" required />
                    <span class="input-group-text bg-dark border-secondary text-light" onclick="togglePassword()" style="cursor: pointer;">
                        <i class="fas fa-eye" id="toggleEye"></i>
                    </span>
                </div>
            </div>
            <button type="submit" class="btn btn-primary-custom" style="margin-top: 25px;">
                <i class="fas fa-sign-in-alt"></i> Masuk
            </button>
            <!-- Tombol Hubungi Admin -->
            <button type="button" class="btn btn-primary-custom" onclick="openModal()" style="margin-top: 15px;">
                <i class="fa fa-address-book" aria-hidden="true"></i> Hubungi Admin
            </button>
            <!-- Modal Hubungi Admin -->
            <div id="adminModal" style="
    display: none;
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.85);
    border-radius: 16px;
    z-index: 10;
    backdrop-filter: blur(4px);
    justify-content: center;
    align-items: center;
    animation: none;
">
                <div id="modalContent" style="
        background: var(--bg-card);
        border: 1px solid #9b5eff;
        padding: 2.55rem;
        border-radius: 16px;
        box-shadow: 0 0 30px rgba(155, 94, 255, 0.25);
        width: 100%;
        max-width: 420px;
        font-family: 'Segoe UI', sans-serif;
        opacity: 0;
        transform: scale(0.95);
        transition: all 0.35s ease;
    ">
                    <h3 style="text-align:center; color:#9b5eff; font-size:1.4rem; font-weight:650; margin-bottom:1.8rem;">
                        FORM KENDALA PENGGUNA
                    </h3>

                    <div style="display: flex; flex-direction: column; gap: 1.7rem;">
                        <input type="text" id="namaPengirim" placeholder="Nama lengkap"
                            style="padding: 0.75rem 1rem; background: #1e1e2f; border: 1px solid #444; border-radius: 10px; color: #fff;" />
                        <input type="email" id="emailPengirim" placeholder="Email terdaftar"
                            style="padding: 0.75rem 1rem; background: #1e1e2f; border: 1px solid #444; border-radius: 10px; color: #fff;" />
                        <textarea id="pesanKendala" placeholder="Tuliskan kendala kamu di sini..." rows="4"
                            style="padding: 0.75rem 1rem; background: #1e1e2f; border: 1px solid #444; border-radius: 10px; color: #fff; resize: none;"></textarea>
                    </div>

                    <div style="margin-top:2rem; display:flex; justify-content:space-between; gap: 0.9rem;">
                        <button onclick="kirimWA()" style="
                flex: 1;
                background: #9b5eff;
                color: #0e0e0e;
                border: none;
                border-radius: 10px;
                padding: 0.6rem 1rem;
                font-weight: 600;
                font-size: 0.95rem;
                cursor: pointer;
            ">Kirim</button>
                        <button onclick="closeModal()" style="
                flex: 1;
                background: #333;
                color: #ccc;
                border: none;
                border-radius: 10px;
                padding: 0.6rem 1rem;
                font-weight: 500;
                cursor: pointer;
            ">Batal</button>
                    </div>
                </div>
            </div>



        </form>
        <!-- Notifikasi Overlay -->
        <div id="notifikasiOverlay" style="
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.65);
    backdrop-filter: blur(4px);
    z-index: 9998;
"></div>

        <!-- Kontainer Notifikasi -->
        <div id="notifikasiSukses" style="
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #1a1a2f;
    color: #f2f2f2;
    border-radius: 20px;
    padding: 2rem 2.5rem;
    width: 90%;
    max-width: 460px;
    z-index: 9999;
    font-family: 'Segoe UI', sans-serif;
    box-shadow: 0 0 30px rgba(155, 94, 255, 0.25);
    text-align: center;
    position: fixed;
">

            <!-- Tombol Tutup -->
            <button onclick="tutupNotifikasi()" style="
        position: absolute;
        top: 12px;
        right: 16px;
        background: none;
        border: none;
        font-size: 1.1rem;
        color: #ccc;
        cursor: pointer;
    " title="Tutup">
                ✖
            </button>

            <!-- Isi Notifikasi -->
            <div style="font-size: 1rem; line-height: 1.6;">
                <div style="font-size: 1.4rem; margin-bottom: 1rem;">Pesan Berhasil Dikirim</div>
                <div>Silakan cek email kamu dalam <strong>1x24 jam</strong> untuk informasi lebih lanjut dari admin.</div>
            </div>
        </div>
        <p class="text-center mt-4 mb-2 text-muted-custom">
            Belum punya akun? <br><a href="register.php">Daftar di sini</a>
        </p>
    </div>
    <script>
        function openModal() {
            const modal = document.getElementById("adminModal");
            const content = document.getElementById("modalContent");
            modal.style.display = "flex";
            setTimeout(() => {
                content.style.opacity = "1";
                content.style.transform = "scale(1)";
            }, 10);
        }

        function closeModal() {
            const modal = document.getElementById("adminModal");
            const content = document.getElementById("modalContent");
            content.style.opacity = "0";
            content.style.transform = "scale(0.95)";
            setTimeout(() => {
                modal.style.display = "none";
            }, 300);
        }

        function kirimWA() {
            const nama = document.getElementById("namaPengirim").value.trim();
            const email = document.getElementById("emailPengirim").value.trim();
            const pesan = document.getElementById("pesanKendala").value.trim();

            if (!nama || !email || !pesan) {
                alert("Semua kolom harus diisi terlebih dahulu.");
                return;
            }

            const nomorAdmin = "6282134027993";
            const waktu = new Date().toLocaleString("id-ID");

            const text =
                `LAPORAN KENDALA PENGGUNA — SISTEM RELIPROVE

IDENTITAS PENGGUNA

Nama Lengkap      : ${nama}
Email Terdaftar   : ${email}
Waktu Pelaporan   : ${waktu}

RINCIAN KENDALA PENGGUNA

${pesan}

ASAL PENGIRIMAN

Sistem Sertifikasi Digital — RELIPROVE
Silakan ditindaklanjuti oleh tim administrator yang bertugas.`;

            const url = `https://wa.me/${nomorAdmin}?text=${encodeURIComponent(text)}`;
            window.open(url, "_blank");
            closeModal();
            tampilkanNotifikasi();
        }

        function tampilkanNotifikasi() {
            const overlay = document.getElementById("notifikasiOverlay");
            const notif = document.getElementById("notifikasiSukses");

            overlay.style.display = "block";
            notif.style.display = "block";

            setTimeout(() => {
                tutupNotifikasi();
            }, 30000); // Auto-close after 30 seconds
        }

        function tutupNotifikasi() {
            document.getElementById("notifikasiOverlay").style.display = "none";
            document.getElementById("notifikasiSukses").style.display = "none";
        }
    </script>

    <style>
        #notifikasiBerhasil {
            transition: opacity 0.4s ease;
            opacity: 0;
        }
    </style>


    <script>
        function togglePassword() {
            const password = document.getElementById("sandi");
            const icon = document.getElementById("toggleEye");
            if (password.type === "password") {
                password.type = "text";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            } else {
                password.type = "password";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            }
        }
    </script>
</body>

</html>