<?php
const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'pens_event_hub';

session_start();
date_default_timezone_set('Asia/Jakarta');

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS mahasiswa (
        id_mahasiswa INT AUTO_INCREMENT PRIMARY KEY,
        nim VARCHAR(30) NOT NULL UNIQUE,
        nama VARCHAR(100) NOT NULL,
        email VARCHAR(120) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
} catch (PDOException $e) {
    http_response_code(500);
    exit('Koneksi database gagal. Periksa konfigurasi database pada file login-mahasiswa.php.');
}

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
$mode = $_GET['mode'] ?? 'login';
if (!in_array($mode, ['login', 'daftar'], true)) $mode = 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(400);
        exit('Sesi formulir tidak valid. Muat ulang halaman.');
    }

    $action = $_POST['action'] ?? '';
    $nim = trim($_POST['nim'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($action === 'register') {
        $nama = trim($_POST['nama'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if ($nama === '' || !preg_match('/^[0-9A-Za-z]{5,30}$/', $nim) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $_SESSION['notice'] = ['error', 'Lengkapi data dengan benar. NIM harus 5–30 karakter dan password minimal 8 karakter.'];
            header('Location: ?mode=daftar');
            exit;
        }

        try {
            $statement = $pdo->prepare('INSERT INTO mahasiswa (nim, nama, email, password_hash) VALUES (?, ?, ?, ?)');
            $statement->execute([$nim, $nama, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $_SESSION['notice'] = ['success', 'Akun berhasil dibuat. Silakan masuk dengan NIM dan password Anda.'];
            header('Location: ?mode=login');
            exit;
        } catch (PDOException $e) {
            $_SESSION['notice'] = ['error', 'NIM atau email tersebut sudah terdaftar.'];
            header('Location: ?mode=daftar');
            exit;
        }
    }

    if ($action === 'login') {
        $statement = $pdo->prepare('SELECT id_mahasiswa, nim, nama, email, password_hash FROM mahasiswa WHERE nim = ? LIMIT 1');
        $statement->execute([$nim]);
        $mahasiswa = $statement->fetch();
        if ($mahasiswa && password_verify($password, $mahasiswa['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['mahasiswa'] = [
                'id' => (int)$mahasiswa['id_mahasiswa'],
                'nim' => $mahasiswa['nim'],
                'nama' => $mahasiswa['nama'],
                'email' => $mahasiswa['email'],
            ];
            header('Location: ?page=mahasiswa');
            exit;
        }
        $_SESSION['notice'] = ['error', 'NIM atau password salah.'];
        header('Location: ?mode=login');
        exit;
    }
}

if (($_GET['page'] ?? '') === 'logout') {
    unset($_SESSION['mahasiswa']);
    session_regenerate_id(true);
    header('Location: ?mode=login');
    exit;
}

$mahasiswa = $_SESSION['mahasiswa'] ?? null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $mahasiswa ? 'Akun Mahasiswa' : ($mode === 'daftar' ? 'Daftar Mahasiswa' : 'Login Mahasiswa') ?> | PENS Event Hub</title>
</head>
<body>
<header>
    <a href="?">PENS EVENT HUB</a>
    <a href="index.php">Kembali ke event</a>
</header>
<main>
    <section>
        <?php if ($mahasiswa): ?>
            <p>Area Mahasiswa</p>
            <h1>Berhasil masuk</h1>
            <p>Selamat datang di PENS Event Hub. Akun mahasiswa Anda sudah aktif.</p>
            <p><strong><?= h($mahasiswa['nama']) ?></strong><br>NIM <?= h($mahasiswa['nim']) ?> · <?= h($mahasiswa['email']) ?></p>
            <a href="index.php">Jelajahi event</a>
            <p><a href="?page=logout">Keluar dari akun</a></p>
        <?php else: ?>
            <p>PENS Event Hub</p>
            <h1><?= $mode === 'daftar' ? 'Buat akun mahasiswa' : 'Login mahasiswa' ?></h1>
            <p><?= $mode === 'daftar' ? 'Daftar untuk membuat akun dan mengakses kegiatan kampus.' : 'Masuk untuk melanjutkan ke akun dan kegiatan kampus Anda.' ?></p>
            <?php if ($notice): ?><p role="status"><?= h($notice[1]) ?></p><?php endif; ?>
            <form method="post" autocomplete="on">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $mode === 'daftar' ? 'register' : 'login' ?>">
                <?php if ($mode === 'daftar'): ?>
                    <p><label for="nama">Nama lengkap</label><br><input id="nama" name="nama" maxlength="100" placeholder="Nama sesuai identitas" autocomplete="name" required></p>
                <?php endif; ?>
                <p><label for="nim">NIM</label><br><input id="nim" name="nim" maxlength="30" placeholder="Masukkan NIM" autocomplete="username" required></p>
                <?php if ($mode === 'daftar'): ?>
                    <p><label for="email">Email</label><br><input id="email" name="email" type="email" maxlength="120" placeholder="nama@contoh.ac.id" autocomplete="email" required></p>
                <?php endif; ?>
                <p><label for="password">Password</label><br><input id="password" name="password" type="password" placeholder="<?= $mode === 'daftar' ? 'Minimal 8 karakter' : 'Masukkan password' ?>" minlength="<?= $mode === 'daftar' ? '8' : '1' ?>" autocomplete="<?= $mode === 'daftar' ? 'new-password' : 'current-password' ?>" required></p>
                <?php if ($mode === 'daftar'): ?><p>Gunakan password minimal 8 karakter.</p><?php endif; ?>
                <p><button type="submit"><?= $mode === 'daftar' ? 'Buat akun' : 'Masuk' ?></button></p>
            </form>
            <p><?= $mode === 'daftar' ? 'Sudah punya akun?' : 'Belum punya akun?' ?> <a href="?mode=<?= $mode === 'daftar' ? 'login' : 'daftar' ?>"><?= $mode === 'daftar' ? 'Login di sini' : 'Daftar sekarang' ?></a></p>
            <p>Akun Anda dilindungi dengan enkripsi password.</p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
