<?php

/* ---------- KONFIGURASI ---------- */
const DB_HOST    = 'localhost';
const DB_USER    = 'root';
const DB_PASS    = '';
const DB_NAME    = 'pens_event_hub';
const ADMIN_USER = 'admin';
const ADMIN_PASS = 'admin123';   // GANTI sebelum dipakai sungguhan

/* Poster event (diunggah admin lewat form, tidak perlu edit CSS lagi) */
const POSTER_DIR = __DIR__ . '/uploads/posters/';
const POSTER_URL = 'uploads/posters/';
const POSTER_MAX = 2097152;      // 2 MB (aman untuk upload_max_filesize bawaan XAMPP)

session_start();
ob_start();
date_default_timezone_set('Asia/Jakarta');

/* ---------- KONEKSI & SETUP DATABASE ---------- */
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');
} catch (PDOException $e) {
    http_response_code(500);
    die('<h2>Koneksi database gagal</h2><p>Periksa konfigurasi DB di bagian atas index.php.</p><pre>' . htmlspecialchars($e->getMessage()) . '</pre>');
}

$pdo->exec("CREATE TABLE IF NOT EXISTS kategori (
    id_kategori INT AUTO_INCREMENT PRIMARY KEY,
    nama_kategori VARCHAR(60) NOT NULL UNIQUE,
    deskripsi VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS event (
    id_event INT AUTO_INCREMENT PRIMARY KEY,
    nama_event VARCHAR(150) NOT NULL,
    id_kategori INT NOT NULL,
    deskripsi TEXT,
    tanggal DATE NOT NULL,
    waktu VARCHAR(30) NOT NULL,
    lokasi VARCHAR(150) NOT NULL,
    kuota INT NOT NULL DEFAULT 50,
    poster VARCHAR(120) DEFAULT NULL,
    FOREIGN KEY (id_kategori) REFERENCES kategori(id_kategori) ON DELETE RESTRICT
) ENGINE=InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS mahasiswa (
    id_mahasiswa INT AUTO_INCREMENT PRIMARY KEY,
    nim VARCHAR(30) NOT NULL UNIQUE,
    nama VARCHAR(100) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS pendaftaran (
    id_pendaftaran INT AUTO_INCREMENT PRIMARY KEY,
    id_event INT NOT NULL,
    id_mahasiswa INT DEFAULT NULL,
    nama_peserta VARCHAR(100) NOT NULL,
    nim VARCHAR(30) NOT NULL,
    email VARCHAR(120) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'terdaftar',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event_nim (id_event, nim),
    FOREIGN KEY (id_event) REFERENCES event(id_event) ON DELETE CASCADE,
    FOREIGN KEY (id_mahasiswa) REFERENCES mahasiswa(id_mahasiswa) ON DELETE SET NULL
) ENGINE=InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS event_tersimpan (
    id_mahasiswa INT NOT NULL,
    id_event INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_mahasiswa, id_event),
    FOREIGN KEY (id_mahasiswa) REFERENCES mahasiswa(id_mahasiswa) ON DELETE CASCADE,
    FOREIGN KEY (id_event) REFERENCES event(id_event) ON DELETE CASCADE
) ENGINE=InnoDB");

/* Migrasi otomatis untuk database lama (dibuat sebelum fitur poster & login mahasiswa) */
if (!$pdo->query("SHOW COLUMNS FROM event LIKE 'poster'")->fetch()) {
    $pdo->exec('ALTER TABLE event ADD COLUMN poster VARCHAR(120) DEFAULT NULL');
}
if (!$pdo->query("SHOW COLUMNS FROM pendaftaran LIKE 'id_mahasiswa'")->fetch()) {
    $pdo->exec('ALTER TABLE pendaftaran ADD COLUMN id_mahasiswa INT DEFAULT NULL AFTER id_event');
    try {
        $pdo->exec('ALTER TABLE pendaftaran ADD CONSTRAINT fk_pendaftaran_mahasiswa FOREIGN KEY (id_mahasiswa) REFERENCES mahasiswa(id_mahasiswa) ON DELETE SET NULL');
    } catch (PDOException $e) { /* indeks tetap berfungsi tanpa FK */ }
}

/* Data awal (hanya jika kosong) */
if ((int)$pdo->query('SELECT COUNT(*) FROM kategori')->fetchColumn() === 0) {
    $kat = [
        ['Seminar', 'Pemaparan materi oleh narasumber'],
        ['Workshop', 'Pelatihan praktik langsung'],
        ['Lomba', 'Kompetisi tingkat kampus dan nasional'],
        ['Webinar', 'Seminar daring'],
        ['Kegiatan Organisasi', 'Acara himpunan dan UKM'],
        ['Kegiatan Mahasiswa', 'Kegiatan umum mahasiswa'],
    ];
    $st = $pdo->prepare('INSERT INTO kategori (nama_kategori, deskripsi) VALUES (?, ?)');
    foreach ($kat as $k) { $st->execute($k); }

    $ev = [
        ['Seminar Artificial Intelligence 2026', 1, 'Seminar tentang tren AI terkini, penerapan di industri, dan peluang karier bagi mahasiswa teknologi.', date('Y') . '-09-20', '09.00-12.00', 'Auditorium PENS', 100],
        ['Workshop Web Development dengan PHP & MySQL', 2, 'Praktik membangun website dinamis dari nol: PHP, MySQL, dan integrasi frontend.', date('Y') . '-10-05', '13.00-16.00', 'Lab Komputer Gedung D4', 40],
        ['PENS Programming Contest', 3, 'Kompetisi pemrograman kompetitif individu. Hadiah uang tunai dan sertifikat.', date('Y') . '-10-18', '08.00-15.00', 'Gedung Teknik Informatika', 80],
        ['Webinar Karier di Dunia Data', 4, 'Webinar bersama praktisi data tentang jalur karier data analyst dan data engineer.', date('Y') . '-11-02', '19.00-21.00', 'Zoom Meeting', 200],
        ['Pelantikan Pengurus Himpunan', 5, 'Acara pelantikan pengurus himpunan mahasiswa periode baru.', date('Y') . '-11-15', '10.00-13.00', 'Graha Sepuluh Nopember PENS', 150],
        ['Bakti Sosial Mahasiswa', 6, 'Kegiatan sosial bersama masyarakat sekitar kampus.', date('Y') . '-12-06', '07.00-12.00', 'Balai Warga Keputih', 60],
    ];
    $st = $pdo->prepare('INSERT INTO event (nama_event, id_kategori, deskripsi, tanggal, waktu, lokasi, kuota) VALUES (?,?,?,?,?,?,?)');
    foreach ($ev as $e) { $st->execute($e); }
}

/* ---------- HELPER ---------- */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf() {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_ok() {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}
function flash($type, $msg) { $_SESSION['flash'] = [$type, $msg]; }
function bersihkan_buffer() { while (ob_get_level() > 0) { ob_end_clean(); } }
function go($url) { bersihkan_buffer(); header('Location: ' . $url); exit; }
function json_out($data, $code = 200) {
    bersihkan_buffer();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function is_admin() { return !empty($_SESSION['admin']); }
function require_admin() { if (!is_admin()) { go('?page=login'); } }
function mhs() { return $_SESSION['mahasiswa'] ?? null; }

/* Hanya izinkan redirect ke halaman internal (?page=...) */
function safe_next($n, $default = '?page=akun') {
    $n = (string)$n;
    return preg_match('/^\?page=[a-z]+(&[a-z_]+=[A-Za-z0-9_%.+-]*)*$/', $n) ? $n : $default;
}
function masuk_url($next = '', $mode = '') {
    $u = '?page=masuk';
    if ($mode !== '') { $u .= '&mode=' . $mode; }
    if ($next !== '') { $u .= '&next=' . rawurlencode($next); }
    return $u;
}

function tgl($d) {
    $bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime($d);
    return date('j', $t) . ' ' . $bln[(int)date('n', $t)] . ' ' . date('Y', $t);
}
function terisi($pdo, $id) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM pendaftaran WHERE id_event = ? AND status <> 'batal'");
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}

/* --- Poster: validasi, simpan, hapus --- */
function poster_valid_name($file) {
    return is_string($file) && preg_match('/^poster_[a-f0-9]{16}\.(jpg|png|webp)$/', $file);
}
function poster_src($file) {
    if (poster_valid_name($file) && is_file(POSTER_DIR . $file)) { return POSTER_URL . $file; }
    return 'assets/poster.png';   // poster bawaan bila event belum punya poster
}
function hapus_poster_file($file) {
    if (poster_valid_name($file) && is_file(POSTER_DIR . $file)) { @unlink(POSTER_DIR . $file); }
}
/* Mengembalikan nama file baru, atau null (tidak ada upload / gagal -> $err terisi). */
function proses_poster(array $f, &$err) {
    $err = null;
    if (!isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) { return null; }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        $err = 'Ukuran poster terlalu besar (maksimal 2 MB).';
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $err = 'Upload poster gagal. Silakan coba lagi.';
        return null;
    }
    if ($f['size'] > POSTER_MAX) {
        $err = 'Ukuran poster terlalu besar (maksimal 2 MB).';
        return null;
    }
    $info = @getimagesize($f['tmp_name']);
    $tipe = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($tipe[$info[2]])) {
        $err = 'Format poster harus berupa gambar JPG, PNG, atau WEBP.';
        return null;
    }
    if (!is_dir(POSTER_DIR) && !@mkdir(POSTER_DIR, 0755, true)) {
        $err = 'Folder uploads/posters tidak dapat dibuat. Periksa izin folder.';
        return null;
    }
    $nama = 'poster_' . bin2hex(random_bytes(8)) . '.' . $tipe[$info[2]];
    if (!@move_uploaded_file($f['tmp_name'], POSTER_DIR . $nama)) {
        $err = 'Poster tidak dapat disimpan. Periksa izin folder uploads/posters.';
        return null;
    }
    return $nama;
}

/* --- Kartu event (dipakai di beranda & halaman Event Saya) --- */
function ikon_simpan() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4-7 4V4a1 1 0 0 1 1-1Z"/></svg>';
}
function event_card(array $e, array $o = []) {
    $id     = (int)$e['id_event'];
    $terisi = (int)$e['terisi'];
    $sisa   = (int)$e['kuota'] - $terisi;
    $pct    = $e['kuota'] > 0 ? min(100, round($terisi / $e['kuota'] * 100)) : 100;
    $lewat  = $e['tanggal'] < date('Y-m-d');
    $logged = !empty($o['logged']);
    $saved  = !empty($o['saved']);
    $status = $o['status'] ?? null;
    $back   = $o['back'] ?? '?page=home';
    ?>
        <article class="card" data-k="<?= (int)$e['id_kategori'] ?>" data-text="<?= h(mb_strtolower($e['nama_event'] . ' ' . $e['lokasi'] . ' ' . $e['deskripsi'])) ?>">
          <div class="event-poster">
            <img src="<?= h(poster_src($e['poster'] ?? null)) ?>" alt="Poster <?= h($e['nama_event']) ?>" loading="lazy">
            <?php if ($status): ?><span class="reg-pill <?= h($status) ?>"><?= h(ucfirst($status)) ?></span><?php endif; ?>
            <?php if ($logged): ?>
              <form method="post" class="save-form" data-save>
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="act" value="simpan_toggle">
                <input type="hidden" name="id_event" value="<?= $id ?>">
                <input type="hidden" name="back" value="<?= h($back) ?>">
                <button type="submit" class="save-btn <?= $saved ? 'on' : '' ?>" aria-pressed="<?= $saved ? 'true' : 'false' ?>" aria-label="Simpan event" title="<?= $saved ? 'Hapus dari simpanan' : 'Simpan event' ?>"><?= ikon_simpan() ?></button>
              </form>
            <?php else: ?>
              <a class="save-btn" href="<?= h(masuk_url($back)) ?>" aria-label="Masuk untuk menyimpan event" title="Masuk untuk menyimpan event"><?= ikon_simpan() ?></a>
            <?php endif; ?>
          </div>
          <span class="badge"><?= h($e['nama_kategori']) ?></span>
          <h3><?= h($e['nama_event']) ?></h3>
          <div class="meta">
            <div><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><?= h(tgl($e['tanggal'])) ?> | <?= h($e['waktu']) ?></div>
            <div><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="9" r="2.5"/></svg><?= h($e['lokasi']) ?></div>
          </div>
          <div>
            <div class="bar"><i class="<?= $pct >= 100 ? 'full' : ($pct >= 80 ? 'hot' : '') ?>" style="width:<?= $pct ?>%"></i></div>
            <div class="quota"><span>Kuota: <?= (int)$e['kuota'] ?></span><span><?= $lewat ? 'Selesai' : ($sisa > 0 ? $sisa . ' kursi tersisa' : 'Penuh') ?></span></div>
          </div>
          <a class="btn" href="?page=detail&id=<?= $id ?>">Details</a>
        </article>
    <?php
}

$page = $_GET['page'] ?? 'home';

/* ---------- AKSI (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Berkas melebihi post_max_size -> PHP mengosongkan $_POST
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('err', 'Data yang dikirim terlalu besar. Gunakan poster dengan ukuran maksimal 2 MB.');
        go('?page=admin');
    }
    if (!csrf_ok()) {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') { json_out(['ok' => false, 'msg' => 'Token tidak valid'], 400); }
        http_response_code(400);
        die('Token tidak valid. Muat ulang halaman.');
    }
    $act = $_POST['act'] ?? '';

    // Registrasi akun mahasiswa
    if ($act === 'mhs_daftar') {
        $nama  = trim($_POST['nama'] ?? '');
        $nim   = trim($_POST['nim'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $pass2 = $_POST['password2'] ?? '';
        $next  = safe_next($_POST['next'] ?? '', '');

        if ($nama === '' || !preg_match('/^[0-9A-Za-z]{5,20}$/', $nim) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            flash('err', 'Lengkapi data dengan benar. NIM 5-20 huruf/angka dan password minimal 8 karakter.');
            go(masuk_url($next, 'daftar'));
        }
        if ($pass !== $pass2) {
            flash('err', 'Konfirmasi password tidak sama.');
            go(masuk_url($next, 'daftar'));
        }
        try {
            $pdo->prepare('INSERT INTO mahasiswa (nim, nama, email, password_hash) VALUES (?,?,?,?)')
                ->execute([$nim, $nama, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            flash('err', 'NIM atau email tersebut sudah terdaftar. Silakan masuk.');
            go(masuk_url($next, 'masuk'));
        }
        session_regenerate_id(true);
        $_SESSION['mahasiswa'] = ['id' => (int)$pdo->lastInsertId(), 'nim' => $nim, 'nama' => $nama, 'email' => $email];
        flash('ok', 'Akun berhasil dibuat. Selamat datang, ' . $nama . '!');
        go($next !== '' ? $next : '?page=akun');
    }

    // Login mahasiswa
    if ($act === 'mhs_login') {
        $nim  = trim($_POST['nim'] ?? '');
        $next = safe_next($_POST['next'] ?? '', '');
        $st = $pdo->prepare('SELECT * FROM mahasiswa WHERE nim = ? LIMIT 1');
        $st->execute([$nim]);
        $m = $st->fetch();
        if ($m && password_verify($_POST['password'] ?? '', $m['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['mahasiswa'] = ['id' => (int)$m['id_mahasiswa'], 'nim' => $m['nim'], 'nama' => $m['nama'], 'email' => $m['email']];
            go($next !== '' ? $next : '?page=akun');
        }
        flash('err', 'NIM atau password salah.');
        go(masuk_url($next, 'masuk'));
    }

    // Simpan / lepas simpan event (bookmark)
    if ($act === 'simpan_toggle') {
        $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
        $id   = (int)($_POST['id_event'] ?? 0);
        $back = safe_next($_POST['back'] ?? '', '?page=home');
        $m    = mhs();
        if (!$m) {
            if ($ajax) { json_out(['ok' => false, 'login' => true], 401); }
            flash('err', 'Masuk terlebih dahulu untuk menyimpan event.');
            go(masuk_url($back));
        }
        $cek = $pdo->prepare('SELECT 1 FROM event WHERE id_event = ?');
        $cek->execute([$id]);
        if (!$cek->fetchColumn()) {
            if ($ajax) { json_out(['ok' => false, 'msg' => 'Event tidak ditemukan'], 404); }
            flash('err', 'Event tidak ditemukan.');
            go($back);
        }
        $del = $pdo->prepare('DELETE FROM event_tersimpan WHERE id_mahasiswa = ? AND id_event = ?');
        $del->execute([$m['id'], $id]);
        $saved = false;
        if ($del->rowCount() === 0) {
            $pdo->prepare('INSERT INTO event_tersimpan (id_mahasiswa, id_event) VALUES (?, ?)')->execute([$m['id'], $id]);
            $saved = true;
        }
        if ($ajax) { json_out(['ok' => true, 'saved' => $saved]); }
        flash('ok', $saved ? 'Event disimpan ke daftar Anda.' : 'Event dihapus dari daftar simpanan.');
        go($back);
    }

    // Pendaftaran peserta (wajib login mahasiswa)
    if ($act === 'daftar') {
        $id = (int)($_POST['id_event'] ?? 0);
        $m  = mhs();
        if (!$m) {
            flash('err', 'Masuk dengan akun mahasiswa untuk mendaftar event.');
            go(masuk_url('?page=detail&id=' . $id));
        }

        $st = $pdo->prepare('SELECT * FROM event WHERE id_event = ?');
        $st->execute([$id]);
        $event = $st->fetch();

        if (!$event) {
            flash('err', 'Event tidak ditemukan.');
            go('?page=home');
        }
        if ($event['tanggal'] < date('Y-m-d')) {
            flash('err', 'Event ini sudah berlalu.');
            go('?page=detail&id=' . $id);
        }
        if (terisi($pdo, $id) >= (int)$event['kuota']) {
            flash('err', 'Maaf, kuota event sudah penuh.');
            go('?page=detail&id=' . $id);
        }
        try {
            $ins = $pdo->prepare('INSERT INTO pendaftaran (id_event, id_mahasiswa, nama_peserta, nim, email) VALUES (?,?,?,?,?)');
            $ins->execute([$id, $m['id'], $m['nama'], $m['nim'], $m['email']]);
            flash('ok', 'Pendaftaran berhasil! Event tersimpan di halaman Event Saya.');
        } catch (PDOException $e) {
            flash('err', 'NIM ini sudah terdaftar pada event tersebut.');
        }
        go('?page=detail&id=' . $id);
    }

    // Login admin
    if ($act === 'login') {
        if (hash_equals(ADMIN_USER, $_POST['username'] ?? '') && hash_equals(ADMIN_PASS, $_POST['password'] ?? '')) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            go('?page=admin');
        }
        flash('err', 'Username atau password salah.');
        go('?page=login');
    }

    // Simpan event (tambah / ubah) + upload poster
    if ($act === 'simpan_event') {
        require_admin();
        $id   = (int)($_POST['id_event'] ?? 0);
        $data = [
            trim($_POST['nama_event'] ?? ''),
            (int)($_POST['id_kategori'] ?? 0),
            trim($_POST['deskripsi'] ?? ''),
            $_POST['tanggal'] ?? '',
            trim($_POST['waktu'] ?? ''),
            trim($_POST['lokasi'] ?? ''),
            max(1, (int)($_POST['kuota'] ?? 1)),
        ];
        $kembali = $id ? '?page=form&id=' . $id : '?page=form';
        if ($data[0] === '' || !$data[1] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[3]) || $data[4] === '' || $data[5] === '') {
            flash('err', 'Semua kolom bertanda * wajib diisi.');
            go($kembali);
        }

        $poster_baru = proses_poster($_FILES['poster'] ?? [], $err_poster);
        if ($err_poster) {
            flash('err', $err_poster);
            go($kembali);
        }

        if ($id) {
            $st = $pdo->prepare('SELECT poster FROM event WHERE id_event = ?');
            $st->execute([$id]);
            $poster_lama = $st->fetchColumn() ?: null;

            $poster_akhir = $poster_lama;
            if ($poster_baru) { $poster_akhir = $poster_baru; }
            elseif (!empty($_POST['hapus_poster'])) { $poster_akhir = null; }

            $data[] = $poster_akhir;
            $data[] = $id;
            $pdo->prepare('UPDATE event SET nama_event=?, id_kategori=?, deskripsi=?, tanggal=?, waktu=?, lokasi=?, kuota=?, poster=? WHERE id_event=?')->execute($data);
            if ($poster_lama && $poster_lama !== $poster_akhir) { hapus_poster_file($poster_lama); }
            flash('ok', 'Event berhasil diperbarui.');
        } else {
            $data[] = $poster_baru;
            $pdo->prepare('INSERT INTO event (nama_event, id_kategori, deskripsi, tanggal, waktu, lokasi, kuota, poster) VALUES (?,?,?,?,?,?,?,?)')->execute($data);
            flash('ok', 'Event baru berhasil ditambahkan.');
        }
        go('?page=admin');
    }

    // Hapus event
    if ($act === 'hapus_event') {
        require_admin();
        $id = (int)($_POST['id_event'] ?? 0);
        $st = $pdo->prepare('SELECT poster FROM event WHERE id_event = ?');
        $st->execute([$id]);
        $poster_lama = $st->fetchColumn();
        $pdo->prepare('DELETE FROM event WHERE id_event = ?')->execute([$id]);
        if ($poster_lama) { hapus_poster_file($poster_lama); }
        flash('ok', 'Event beserta data pendaftarnya telah dihapus.');
        go('?page=admin');
    }

    // Ubah status pendaftar
    if ($act === 'status') {
        require_admin();
        $status = in_array($_POST['status'] ?? '', ['terdaftar', 'hadir', 'batal'], true) ? $_POST['status'] : 'terdaftar';
        $pdo->prepare('UPDATE pendaftaran SET status = ? WHERE id_pendaftaran = ?')->execute([$status, (int)($_POST['id_pendaftaran'] ?? 0)]);
        go('?page=peserta&id=' . (int)($_POST['id_event'] ?? 0));
    }
}

if ($page === 'logout') {
    $_SESSION = [];
    session_destroy();
    go('?page=home');
}

/* ---------- DATA UNTUK TAMPILAN ---------- */
$kategori = $pdo->query('SELECT * FROM kategori ORDER BY id_kategori')->fetchAll();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$admin_pages = ['admin', 'form', 'peserta'];
$is_admin_page = in_array($page, $admin_pages, true);
if ($is_admin_page) { require_admin(); }
if ($page === 'akun' && !mhs()) { go(masuk_url('?page=akun')); }
if ($page === 'masuk' && mhs()) { go('?page=akun'); }

$titles = [
    'home' => 'PENS Event Hub', 'detail' => 'Detail Event', 'login' => 'Login Admin',
    'admin' => 'Dashboard Admin', 'form' => 'Form Event', 'peserta' => 'Data Peserta',
    'masuk' => 'Masuk Mahasiswa', 'akun' => 'Event Saya',
];
$me = mhs();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($titles[$page] ?? 'PENS Event Hub') ?> | PENS Event Hub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800;900;1000&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header class="nav">
  <div class="wrap">
    <a class="brand" href="?page=home" aria-label="PENS Event Hub beranda"><img src="assets/logo.png" alt="PENS Event Hub"></a>
    <nav class="nav-links">
      <a href="?page=home" class="<?= $page === 'home' || $page === 'detail' ? 'active' : '' ?>">Daftar Event</a>
      <?php if ($me): ?>
        <a href="?page=akun" class="<?= $page === 'akun' ? 'active' : '' ?>">Event Saya</a>
      <?php endif; ?>
      <?php if (is_admin()): ?>
        <a href="?page=admin" class="<?= $is_admin_page ? 'active' : '' ?>">Dashboard</a>
      <?php endif; ?>
      <?php if ($me || is_admin()): ?>
        <a href="?page=logout" title="Keluar" aria-label="Keluar">Keluar</a>
      <?php else: ?>
        <a href="?page=masuk" title="Masuk mahasiswa" aria-label="Masuk mahasiswa">Masuk</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main>
<?php if ($flash): ?>
  <div class="wrap">
    <div class="alert <?= h($flash[0]) ?>" id="flash">
      <span><?= h($flash[1]) ?></span>
      <button type="button" onclick="this.parentElement.remove()" aria-label="Tutup">&times;</button>
    </div>
  </div>
<?php endif; ?>

<?php
/* ======================================================================
   HALAMAN: HOME (daftar event + cari + filter kategori)
   ====================================================================== */
if ($page === 'home'):
    $q   = trim($_GET['q'] ?? '');
    $kid = (int)($_GET['kategori'] ?? 0);

    $sql = "SELECT e.*, k.nama_kategori,
              (SELECT COUNT(*) FROM pendaftaran p WHERE p.id_event = e.id_event AND p.status <> 'batal') AS terisi
            FROM event e JOIN kategori k ON k.id_kategori = e.id_kategori WHERE 1=1";
    $params = [];
    if ($q !== '')  { $sql .= ' AND (e.nama_event LIKE ? OR e.lokasi LIKE ? OR e.deskripsi LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like, $like); }
    if ($kid)       { $sql .= ' AND e.id_kategori = ?'; $params[] = $kid; }
    $sql .= ' ORDER BY e.tanggal ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $events = $st->fetchAll();

    // Status mahasiswa yang sedang login: event tersimpan & event yang diikuti
    $saved_ids = [];
    $my_status = [];
    if ($me) {
        $st = $pdo->prepare('SELECT id_event FROM event_tersimpan WHERE id_mahasiswa = ?');
        $st->execute([$me['id']]);
        $saved_ids = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        $st = $pdo->prepare('SELECT id_event, status FROM pendaftaran WHERE id_mahasiswa = ?');
        $st->execute([$me['id']]);
        foreach ($st->fetchAll() as $r) { $my_status[(int)$r['id_event']] = $r['status']; }
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $back_home = $qs !== '' ? '?' . $qs : '?page=home';
?>
  <section class="hero">
    <div class="hero-card">
      <img class="zoom zoom-left" src="assets/zoom.png" alt="" aria-hidden="true">
      <img class="zoom zoom-right" src="assets/zoom.png" alt="" aria-hidden="true">
      <h1>Temukan Event<br>Kampus <span>Idealmu!</span></h1>
      <p>Platform terpadu untuk mahasiswa PENS. Jelajahi berbagai kompetisi,<br class="desktop-only"> seminar industri, hingga kegiatan himpunan yang sesuai dengan minat dan bakatmu.</p>
      <form method="get" class="hero-search">
        <input type="hidden" name="page" value="home">
        <input type="hidden" name="kategori" value="<?= $kid ?: '' ?>">
        <input type="search" name="q" placeholder="Search Event" value="<?= h($q) ?>" aria-label="Cari event">
        <button type="submit" aria-label="Cari"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg></button>
      </form>
    </div>
  </section>

  <section class="section">
    <div class="section-head">
      <h2 class="title">Daftar Event Kampus</h2>
      <span class="count" id="count"><?= count($events) ?> event ditemukan</span>
    </div>

    <div class="wrap">
      <div class="filter">
        <form method="get" id="filterForm">
          <input type="hidden" name="page" value="home">
          <input type="hidden" name="kategori" id="kategoriInput" value="<?= $kid ?: '' ?>">
          <div class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" id="q" placeholder="Cari nama event, lokasi, atau deskripsi..." value="<?= h($q) ?>" autocomplete="off">
          </div>
          <button class="btn" type="submit">Cari</button>
        </form>
        <div class="chips" id="chips">
          <button type="button" class="chip <?= !$kid ? 'on' : '' ?>" data-k="0">Semua</button>
          <?php foreach ($kategori as $k): ?>
            <button type="button" class="chip <?= $kid === (int)$k['id_kategori'] ? 'on' : '' ?>" data-k="<?= (int)$k['id_kategori'] ?>"><?= h($k['nama_kategori']) ?></button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="grid" id="grid">
        <?php foreach ($events as $e) {
            event_card($e, [
                'logged' => (bool)$me,
                'saved'  => isset($saved_ids[$e['id_event']]),
                'status' => $my_status[(int)$e['id_event']] ?? null,
                'back'   => $back_home,
            ]);
        } ?>
        <div class="empty <?= $events ? 'hidden' : '' ?>" id="empty">Tidak ada event yang cocok dengan pencarian Anda.</div>
      </div>
    </div>
  </section>

<?php
/* ======================================================================
   HALAMAN: DETAIL EVENT + PENDAFTARAN
   ====================================================================== */
elseif ($page === 'detail'):
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT e.*, k.nama_kategori FROM event e JOIN kategori k ON k.id_kategori = e.id_kategori WHERE e.id_event = ?');
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e):
?>
  <div class="wrap"><div class="empty" style="margin-top:40px">Event tidak ditemukan. <a href="?page=home">Kembali</a></div></div>
<?php
    else:
        $terisi = terisi($pdo, $id);
        $sisa = max(0, (int)$e['kuota'] - $terisi);
        $pct = $e['kuota'] > 0 ? min(100, round($terisi / $e['kuota'] * 100)) : 100;
        $lewat = $e['tanggal'] < date('Y-m-d');
        $bisa = !$lewat && $sisa > 0;

        $reg = null;
        $tersimpan = false;
        if ($me) {
            $st = $pdo->prepare('SELECT status, created_at FROM pendaftaran WHERE id_event = ? AND id_mahasiswa = ?');
            $st->execute([$id, $me['id']]);
            $reg = $st->fetch() ?: null;
            $st = $pdo->prepare('SELECT 1 FROM event_tersimpan WHERE id_event = ? AND id_mahasiswa = ?');
            $st->execute([$id, $me['id']]);
            $tersimpan = (bool)$st->fetchColumn();
        }
        $next_detail = '?page=detail&id=' . $id;
?>
  <div class="wrap">
    <div class="detail">
      <div class="panel">
        <img class="detail-poster" src="<?= h(poster_src($e['poster'] ?? null)) ?>" alt="Poster <?= h($e['nama_event']) ?>" data-zoom>
        <span class="badge"><?= h($e['nama_kategori']) ?></span>
        <h1><?= h($e['nama_event']) ?></h1>
        <p><?= nl2br(h($e['deskripsi'] ?: 'Belum ada deskripsi untuk event ini.')) ?></p>
        <div class="info-list">
          <div><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><span><small>Tanggal</small><?= h(tgl($e['tanggal'])) ?></span></div>
          <div><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span><small>Waktu</small><?= h($e['waktu']) ?></span></div>
          <div><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="9" r="2.5"/></svg><span><small>Lokasi</small><?= h($e['lokasi']) ?></span></div>
        </div>
        <div class="bar"><i class="<?= $pct >= 100 ? 'full' : ($pct >= 80 ? 'hot' : '') ?>" style="width:<?= $pct ?>%"></i></div>
        <div class="quota"><span><?= $terisi ?> dari <?= (int)$e['kuota'] ?> kursi terisi</span><span><?= $sisa ?> tersisa</span></div>

        <?php if ($me): ?>
          <form method="post" class="save-inline" data-save>
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="act" value="simpan_toggle">
            <input type="hidden" name="id_event" value="<?= $id ?>">
            <input type="hidden" name="back" value="<?= h($next_detail) ?>">
            <button type="submit" class="btn ghost save-toggle <?= $tersimpan ? 'on' : '' ?>" aria-pressed="<?= $tersimpan ? 'true' : 'false' ?>" data-on="Tersimpan" data-off="Simpan Event">
              <?= ikon_simpan() ?><span class="save-label"><?= $tersimpan ? 'Tersimpan' : 'Simpan Event' ?></span>
            </button>
          </form>
        <?php endif; ?>

        <a class="back" href="?page=home">&larr; Kembali ke daftar event</a>
      </div>

      <div class="panel">
        <h3>Pendaftaran Event</h3>
        <?php if ($reg): ?>
          <div class="mhs-box">
            <b>Anda sudah terdaftar &#10003;</b><br>
            Status: <?= h(ucfirst($reg['status'])) ?><br>
            Terdaftar pada <?= h(date('d/m/Y H:i', strtotime($reg['created_at']))) ?>
          </div>
          <a class="btn" style="width:100%" href="?page=akun">Lihat Event Saya</a>
        <?php elseif (!$bisa): ?>
          <p><?= $lewat ? 'Event ini sudah selesai, pendaftaran ditutup.' : 'Mohon maaf, kuota event sudah penuh.' ?></p>
          <button class="btn" style="margin-top:16px;width:100%" disabled>Pendaftaran Ditutup</button>
        <?php elseif (!$me): ?>
          <p>Masuk dengan akun mahasiswa untuk mendaftar. Event yang Anda ikuti akan tersimpan di halaman <b>Event Saya</b>.</p>
          <a class="btn" style="width:100%;margin-top:14px" href="<?= h(masuk_url($next_detail)) ?>">Masuk untuk Mendaftar</a>
          <p class="hint">Belum punya akun? <a href="<?= h(masuk_url($next_detail, 'daftar')) ?>">Buat akun mahasiswa</a></p>
        <?php else: ?>
          <form method="post" id="daftarForm" data-once>
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="act" value="daftar">
            <input type="hidden" name="id_event" value="<?= $id ?>">
            <div class="mhs-box">
              <small>Mendaftar sebagai</small><br>
              <b><?= h($me['nama']) ?></b><br>
              NIM <?= h($me['nim']) ?><br>
              <?= h($me['email']) ?>
            </div>
            <button class="btn" type="submit" style="width:100%">DAFTAR SEKARANG</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif;

/* ======================================================================
   HALAMAN: MASUK / BUAT AKUN MAHASISWA
   ====================================================================== */
elseif ($page === 'masuk'):
    $mode = ($_GET['mode'] ?? 'masuk') === 'daftar' ? 'daftar' : 'masuk';
    $next = safe_next($_GET['next'] ?? '', '');
?>
  <div class="login panel">
    <h3><?= $mode === 'daftar' ? 'Buat Akun Mahasiswa' : 'Masuk Mahasiswa' ?></h3>
    <p class="hint" style="margin-top:-6px"><?= $mode === 'daftar' ? 'Daftar sekali, lalu semua event yang Anda ikuti dan simpan tercatat di akun Anda.' : 'Masuk untuk mendaftar event dan melihat event yang sudah Anda ikuti atau simpan.' ?></p>
    <div class="auth-tabs">
      <a href="<?= h(masuk_url($next, 'masuk')) ?>" class="<?= $mode === 'masuk' ? 'on' : '' ?>">Masuk</a>
      <a href="<?= h(masuk_url($next, 'daftar')) ?>" class="<?= $mode === 'daftar' ? 'on' : '' ?>">Buat Akun</a>
    </div>
    <form method="post" data-once data-auth="<?= $mode ?>">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="act" value="<?= $mode === 'daftar' ? 'mhs_daftar' : 'mhs_login' ?>">
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <?php if ($mode === 'daftar'): ?>
        <div class="field"><label for="nama">Nama Lengkap</label><input id="nama" name="nama" maxlength="100" autocomplete="name" required></div>
      <?php endif; ?>
      <div class="field"><label for="nim">NIM</label><input id="nim" name="nim" maxlength="20" autocomplete="username" required <?= $mode === 'masuk' ? 'autofocus' : '' ?>></div>
      <?php if ($mode === 'daftar'): ?>
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="120" autocomplete="email" placeholder="nama@contoh.ac.id" required></div>
      <?php endif; ?>
      <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" minlength="<?= $mode === 'daftar' ? 8 : 1 ?>" autocomplete="<?= $mode === 'daftar' ? 'new-password' : 'current-password' ?>" placeholder="<?= $mode === 'daftar' ? 'Minimal 8 karakter' : '' ?>" required></div>
      <?php if ($mode === 'daftar'): ?>
        <div class="field"><label for="password2">Ulangi Password</label><input id="password2" name="password2" type="password" minlength="8" autocomplete="new-password" required></div>
      <?php endif; ?>
      <button class="btn" type="submit" style="width:100%"><?= $mode === 'daftar' ? 'Buat Akun' : 'Masuk' ?></button>
    </form>
    <p class="hint" style="text-align:center;margin-top:18px">Pengelola event? <a href="?page=login">Login Admin</a></p>
  </div>

<?php
/* ======================================================================
   HALAMAN: EVENT SAYA (diikuti & tersimpan)
   ====================================================================== */
elseif ($page === 'akun'):
    $tab = ($_GET['tab'] ?? 'diikuti') === 'tersimpan' ? 'tersimpan' : 'diikuti';

    $base = "SELECT e.*, k.nama_kategori,
               (SELECT COUNT(*) FROM pendaftaran p2 WHERE p2.id_event = e.id_event AND p2.status <> 'batal') AS terisi";

    $st = $pdo->prepare($base . ", p.status AS status_saya
        FROM pendaftaran p JOIN event e ON e.id_event = p.id_event JOIN kategori k ON k.id_kategori = e.id_kategori
        WHERE p.id_mahasiswa = ? ORDER BY e.tanggal DESC");
    $st->execute([$me['id']]);
    $diikuti = $st->fetchAll();

    $st = $pdo->prepare($base . "
        FROM event_tersimpan s JOIN event e ON e.id_event = s.id_event JOIN kategori k ON k.id_kategori = e.id_kategori
        WHERE s.id_mahasiswa = ? ORDER BY s.created_at DESC");
    $st->execute([$me['id']]);
    $tersimpan_list = $st->fetchAll();

    $saved_ids = array_flip(array_map('intval', array_column($tersimpan_list, 'id_event')));
    $my_status = [];
    foreach ($diikuti as $r) { $my_status[(int)$r['id_event']] = $r['status_saya']; }

    $list = $tab === 'diikuti' ? $diikuti : $tersimpan_list;
    $hadir = 0;
    foreach ($diikuti as $r) { if ($r['status_saya'] === 'hadir') { $hadir++; } }
    $back_akun = '?page=akun&tab=' . $tab;
?>
  <div class="wrap section">
    <div class="panel account">
      <div class="avatar"><?= h(mb_strtoupper(mb_substr($me['nama'], 0, 1))) ?></div>
      <div class="account-info">
        <b><?= h($me['nama']) ?></b>
        <span>NIM <?= h($me['nim']) ?> &middot; <?= h($me['email']) ?></span>
      </div>
      <div class="account-stats">
        <div><b><?= count($diikuti) ?></b><span>Diikuti</span></div>
        <div><b><?= $hadir ?></b><span>Hadir</span></div>
        <div><b><?= count($tersimpan_list) ?></b><span>Tersimpan</span></div>
      </div>
    </div>

    <div class="section-head"><h2 class="title">Event Saya</h2></div>
    <div class="tabs">
      <a class="tab <?= $tab === 'diikuti' ? 'on' : '' ?>" href="?page=akun&tab=diikuti">Event Diikuti (<?= count($diikuti) ?>)</a>
      <a class="tab <?= $tab === 'tersimpan' ? 'on' : '' ?>" href="?page=akun&tab=tersimpan">Event Tersimpan (<?= count($tersimpan_list) ?>)</a>
    </div>

    <div class="grid" id="grid" data-remove-on-unsave="<?= $tab === 'tersimpan' ? '1' : '0' ?>">
      <?php foreach ($list as $e) {
          event_card($e, [
              'logged' => true,
              'saved'  => isset($saved_ids[(int)$e['id_event']]),
              'status' => $my_status[(int)$e['id_event']] ?? null,
              'back'   => $back_akun,
          ]);
      } ?>
      <div class="empty <?= $list ? 'hidden' : '' ?>" id="empty">
        <?php if ($tab === 'diikuti'): ?>
          Anda belum mengikuti event apa pun. <a href="?page=home">Jelajahi event</a> dan daftar sekarang.
        <?php else: ?>
          Belum ada event tersimpan. Tekan ikon penanda pada poster event untuk menyimpannya. <a href="?page=home">Lihat event</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php
/* ======================================================================
   HALAMAN: LOGIN ADMIN
   ====================================================================== */
elseif ($page === 'login'):
    if (is_admin()) { go('?page=admin'); }
?>
  <div class="login panel">
    <h3>Login Pengelola</h3>
    <form method="post" data-once>
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="act" value="login">
      <div class="field"><label for="username">Username</label><input id="username" name="username" required autofocus></div>
      <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" required></div>
      <button class="btn dark" type="submit" style="width:100%">Masuk</button>
    </form>
    <p class="hint" style="text-align:center;margin-top:18px">Mahasiswa? <a href="?page=masuk">Masuk di sini</a></p>
  </div>

<?php
/* ======================================================================
   HALAMAN: DASHBOARD ADMIN (daftar event + aksi)
   ====================================================================== */
elseif ($page === 'admin'):
    $rows = $pdo->query("SELECT e.*, k.nama_kategori,
        (SELECT COUNT(*) FROM pendaftaran p WHERE p.id_event = e.id_event AND p.status <> 'batal') AS terisi
        FROM event e JOIN kategori k ON k.id_kategori = e.id_kategori ORDER BY e.tanggal DESC")->fetchAll();
?>
  <div class="wrap section">
    <div class="section-head">
      <h2 class="title">Kelola Event</h2>
      <a class="btn" href="?page=form">+ Tambah Event</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Poster</th><th>Nama Event</th><th>Kategori</th><th>Tanggal</th><th>Lokasi</th><th>Peserta</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><img class="thumb" src="<?= h(poster_src($r['poster'] ?? null)) ?>" alt="Poster <?= h($r['nama_event']) ?>" loading="lazy"></td>
            <td><b><?= h($r['nama_event']) ?></b></td>
            <td><span class="badge"><?= h($r['nama_kategori']) ?></span></td>
            <td><?= h(tgl($r['tanggal'])) ?><br><small style="color:var(--muted)"><?= h($r['waktu']) ?></small></td>
            <td><?= h($r['lokasi']) ?></td>
            <td><?= (int)$r['terisi'] ?> / <?= (int)$r['kuota'] ?></td>
            <td><div class="actions">
              <a class="btn sm ghost" href="?page=peserta&id=<?= (int)$r['id_event'] ?>">Peserta</a>
              <a class="btn sm dark" href="?page=form&id=<?= (int)$r['id_event'] ?>">Edit</a>
              <button class="btn sm danger" type="button" data-hapus="<?= (int)$r['id_event'] ?>" data-nama="<?= h($r['nama_event']) ?>">Hapus</button>
            </div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;color:var(--muted);padding:34px">Belum ada event.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="modal" id="modalHapus">
    <div class="modal-box">
      <h3>Hapus event?</h3>
      <p id="modalText"></p>
      <form method="post" class="actions">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="act" value="hapus_event">
        <input type="hidden" name="id_event" id="hapusId">
        <button type="button" class="btn ghost sm" id="batalHapus">Batal</button>
        <button type="submit" class="btn danger sm">Ya, hapus</button>
      </form>
    </div>
  </div>

<?php
/* ======================================================================
   HALAMAN: FORM TAMBAH / EDIT EVENT (+ upload poster)
   ====================================================================== */
elseif ($page === 'form'):
    $id = (int)($_GET['id'] ?? 0);
    $e = ['id_event' => 0, 'nama_event' => '', 'id_kategori' => 0, 'deskripsi' => '', 'tanggal' => '', 'waktu' => '', 'lokasi' => '', 'kuota' => 50, 'poster' => null];
    if ($id) {
        $st = $pdo->prepare('SELECT * FROM event WHERE id_event = ?');
        $st->execute([$id]);
        $found = $st->fetch();
        if ($found) { $e = $found; }
    }
    $poster_sekarang = poster_src($e['poster'] ?? null);
    $punya_poster = !empty($e['poster']) && $poster_sekarang !== 'assets/poster.png';
?>
  <div class="wrap section">
    <div class="panel" style="max-width:720px;margin-inline:auto">
      <h3><?= $e['id_event'] ? 'Edit Event' : 'Tambah Event Baru' ?></h3>
      <form method="post" enctype="multipart/form-data" data-once>
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="act" value="simpan_event">
        <input type="hidden" name="id_event" value="<?= (int)$e['id_event'] ?>">
        <div class="field"><label>Nama Event *</label><input name="nama_event" value="<?= h($e['nama_event']) ?>" maxlength="150" required></div>

        <div class="poster-field">
          <span class="lbl">Poster Event</span>
          <div class="dropzone" id="dropzone">
            <input type="file" name="poster" id="posterInput" accept="image/jpeg,image/png,image/webp" aria-label="Unggah poster event">
            <strong>Klik atau seret poster ke sini</strong>
            <span>JPG, PNG, atau WEBP &middot; maksimal 2 MB</span>
          </div>
          <p class="hint err" id="posterError" role="alert"></p>
          <div class="poster-preview <?= $punya_poster ? 'show' : '' ?>" id="posterPreview">
            <img id="posterPreviewImg" src="<?= $punya_poster ? h($poster_sekarang) : '' ?>" alt="Pratinjau poster">
            <span class="hint" id="posterPreviewLabel"><?= $punya_poster ? 'Poster saat ini' : '' ?></span>
          </div>
          <?php if ($punya_poster): ?>
            <label class="check"><input type="checkbox" name="hapus_poster" value="1" id="hapusPoster"> Hapus poster ini (pakai poster bawaan)</label>
          <?php endif; ?>
          <p class="hint">Poster akan langsung tampil di daftar event dan halaman detail tanpa perlu mengubah CSS.</p>
        </div>

        <div class="field"><label>Kategori *</label>
          <select name="id_kategori" required>
            <option value="">-- Pilih kategori --</option>
            <?php foreach ($kategori as $k): ?>
              <option value="<?= (int)$k['id_kategori'] ?>" <?= (int)$e['id_kategori'] === (int)$k['id_kategori'] ? 'selected' : '' ?>><?= h($k['nama_kategori']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row2">
          <div class="field"><label>Tanggal *</label><input type="date" name="tanggal" value="<?= h($e['tanggal']) ?>" required></div>
          <div class="field"><label>Waktu *</label><input name="waktu" value="<?= h($e['waktu']) ?>" placeholder="09.00-12.00" maxlength="30" required></div>
        </div>
        <div class="row2">
          <div class="field"><label>Lokasi *</label><input name="lokasi" value="<?= h($e['lokasi']) ?>" maxlength="150" required></div>
          <div class="field"><label>Kuota *</label><input type="number" min="1" name="kuota" value="<?= (int)$e['kuota'] ?>" required></div>
        </div>
        <div class="field"><label>Deskripsi</label><textarea name="deskripsi"><?= h($e['deskripsi']) ?></textarea></div>
        <div class="actions" style="justify-content:flex-end">
          <a class="btn ghost" href="?page=admin">Batal</a>
          <button class="btn" type="submit">Simpan</button>
        </div>
      </form>
    </div>
  </div>

<?php
/* ======================================================================
   HALAMAN: DATA PESERTA PER EVENT
   ====================================================================== */
elseif ($page === 'peserta'):
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM event WHERE id_event = ?');
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e) { go('?page=admin'); }
    $st = $pdo->prepare('SELECT * FROM pendaftaran WHERE id_event = ? ORDER BY created_at DESC');
    $st->execute([$id]);
    $ps = $st->fetchAll();
?>
  <div class="wrap section">
    <div class="section-head">
      <div>
        <h2 class="title">Peserta: <?= h($e['nama_event']) ?></h2>
        <span class="count"><?= count($ps) ?> pendaftar | Kuota <?= (int)$e['kuota'] ?></span>
      </div>
      <a class="btn ghost" href="?page=admin">&larr; Kembali</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Nama</th><th>NIM</th><th>Email</th><th>Waktu Daftar</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($ps as $i => $p): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><b><?= h($p['nama_peserta']) ?></b></td>
            <td><?= h($p['nim']) ?></td>
            <td><?= h($p['email']) ?></td>
            <td><?= h(date('d/m/Y H:i', strtotime($p['created_at']))) ?></td>
            <td>
              <form method="post" class="status-form">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="act" value="status">
                <input type="hidden" name="id_pendaftaran" value="<?= (int)$p['id_pendaftaran'] ?>">
                <input type="hidden" name="id_event" value="<?= $id ?>">
                <select name="status" onchange="this.form.submit()">
                  <?php foreach (['terdaftar', 'hadir', 'batal'] as $s): ?>
                    <option value="<?= $s ?>" <?= $p['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$ps): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:34px">Belum ada peserta yang mendaftar.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php else: ?>
  <div class="wrap"><div class="empty" style="margin-top:40px">Halaman tidak ditemukan. <a href="?page=home">Ke beranda</a></div></div>
<?php endif; ?>
</main>

<footer class="site-footer">
  <div class="footer-brand"><img src="assets/logo.png" alt="PENS Event Hub"></div>
  <div class="footer-about"><b>Pens Event Hub</b><p>Platform terpadu untuk mahasiswa PENS dalam menemukan berbagai peluang pengembangan diri, mulai dari kompetisi, seminar industri, workshop, hingga kegiatan himpunan.</p><strong>Sponsorship</strong><div class="sponsors"><span>alobanks</span><span>BSI</span><span>BRI</span><span>PLN</span></div></div>
  <div class="footer-contact"><b>Contact Person</b><span>Ghifar<br>0812-8912-2321</span><span>Iqbal<br>0821-9922-1923</span><span>Jonzaven<br>0813-3529-7180</span></div>
</footer>

<script src="script.js"></script>
</body>
</html>
