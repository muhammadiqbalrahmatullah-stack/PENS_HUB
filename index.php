/* ---------- KONFIGURASI ---------- */
const DB_HOST    = 'localhost';
const DB_USER    = 'root';
const DB_PASS    = '';
const DB_NAME    = 'pens_event_hub';
const ADMIN_USER = 'admin';
const ADMIN_PASS = 'admin123';   // GANTI sebelum dipakai sungguhan

session_start();
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
    FOREIGN KEY (id_kategori) REFERENCES kategori(id_kategori) ON DELETE RESTRICT
) ENGINE=InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS pendaftaran (
    id_pendaftaran INT AUTO_INCREMENT PRIMARY KEY,
    id_event INT NOT NULL,
    nama_peserta VARCHAR(100) NOT NULL,
    nim VARCHAR(30) NOT NULL,
    email VARCHAR(120) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'terdaftar',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event_nim (id_event, nim),
    FOREIGN KEY (id_event) REFERENCES event(id_event) ON DELETE CASCADE
) ENGINE=InnoDB");

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
function go($url) { header('Location: ' . $url); exit; }
function is_admin() { return !empty($_SESSION['admin']); }
function require_admin() { if (!is_admin()) { go('?page=login'); } }
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

$page = $_GET['page'] ?? 'home';

/* ---------- AKSI (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { http_response_code(400); die('Token tidak valid. Muat ulang halaman.'); }
    $act = $_POST['act'] ?? '';

    // Pendaftaran peserta
    if ($act === 'daftar') {
        $id    = (int)($_POST['id_event'] ?? 0);
        $nama  = trim($_POST['nama_peserta'] ?? '');
        $nim   = trim($_POST['nim'] ?? '');
        $email = trim($_POST['email'] ?? '');

        $st = $pdo->prepare('SELECT * FROM event WHERE id_event = ?');
        $st->execute([$id]);
        $event = $st->fetch();

        if (!$event) {
            flash('err', 'Event tidak ditemukan.');
            go('?page=home');
        }
        if ($nama === '' || !preg_match('/^[0-9A-Za-z]{5,20}$/', $nim) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('err', 'Data tidak valid. Periksa nama, NIM (5-20 huruf/angka), dan email.');
            go('?page=detail&id=' . $id);
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
            $ins = $pdo->prepare('INSERT INTO pendaftaran (id_event, nama_peserta, nim, email) VALUES (?,?,?,?)');
            $ins->execute([$id, $nama, $nim, $email]);
            flash('ok', 'Pendaftaran berhasil! Data Anda sudah tersimpan.');
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

    // Simpan event (tambah / ubah)
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
        if ($data[0] === '' || !$data[1] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[3]) || $data[4] === '' || $data[5] === '') {
            flash('err', 'Semua kolom bertanda * wajib diisi.');
            go($id ? '?page=form&id=' . $id : '?page=form');
        }
        if ($id) {
            $data[] = $id;
            $pdo->prepare('UPDATE event SET nama_event=?, id_kategori=?, deskripsi=?, tanggal=?, waktu=?, lokasi=?, kuota=? WHERE id_event=?')->execute($data);
            flash('ok', 'Event berhasil diperbarui.');
        } else {
            $pdo->prepare('INSERT INTO event (nama_event, id_kategori, deskripsi, tanggal, waktu, lokasi, kuota) VALUES (?,?,?,?,?,?,?)')->execute($data);
            flash('ok', 'Event baru berhasil ditambahkan.');
        }
        go('?page=admin');
    }

    // Hapus event
    if ($act === 'hapus_event') {
        require_admin();
        $pdo->prepare('DELETE FROM event WHERE id_event = ?')->execute([(int)($_POST['id_event'] ?? 0)]);
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

$titles = [
    'home' => 'PENS Event Hub', 'detail' => 'Detail Event', 'login' => 'Login Admin',
    'admin' => 'Dashboard Admin', 'form' => 'Form Event', 'peserta' => 'Data Peserta',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titles[$page] ?? 'PENS Event Hub') ?> | PENS Event Hub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>

<header class="nav">
  <div class="wrap">
    <a class="brand" href="?page=home">PENS EVENT <span>HUB</span></a>
    <nav class="nav-links">
      <a href="?page=home" class="<?= $page === 'home' || $page === 'detail' ? 'active' : '' ?>">Daftar Event</a>
      <?php if (is_admin()): ?>
        <a href="?page=admin" class="<?= $is_admin_page ? 'active' : '' ?>">Dashboard</a>
        <a href="?page=logout">Keluar</a>
      <?php else: ?>
        <a href="?page=login" class="<?= $page === 'login' ? 'active' : '' ?>">Admin</a>
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

    $total_event = (int)$pdo->query('SELECT COUNT(*) FROM event')->fetchColumn();
    $total_daftar = (int)$pdo->query("SELECT COUNT(*) FROM pendaftaran WHERE status <> 'batal'")->fetchColumn();
?>
  <section class="hero">
    <div class="wrap">
      <h1>PENS EVENT <span>HUB</span></h1>
      <p>Website informasi dan pendaftaran event kampus. Semua seminar, workshop, lomba, dan kegiatan mahasiswa terpusat di satu platform.</p>
      <div class="stats">
        <div class="stat"><b><?= $total_event ?></b><small>Event tersedia</small></div>
        <div class="stat"><b><?= count($kategori) ?></b><small>Kategori</small></div>
        <div class="stat"><b><?= $total_daftar ?></b><small>Pendaftar</small></div>
      </div>
    </div>
  </section>

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

    <section class="section">
      <div class="section-head">
        <h2 class="title">Daftar Event Kampus</h2>
        <span class="count" id="count"><?= count($events) ?> event ditemukan</span>
      </div>
      <div class="grid" id="grid">
        <?php foreach ($events as $e):
            $sisa = (int)$e['kuota'] - (int)$e['terisi'];
            $pct = $e['kuota'] > 0 ? min(100, round($e['terisi'] / $e['kuota'] * 100)) : 100;
            $lewat = $e['tanggal'] < date('Y-m-d');
        ?>
        <article class="card" data-k="<?= (int)$e['id_kategori'] ?>" data-text="<?= h(mb_strtolower($e['nama_event'] . ' ' . $e['lokasi'] . ' ' . $e['deskripsi'])) ?>">
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
          <a class="btn" href="?page=detail&id=<?= (int)$e['id_event'] ?>">Lihat Detail</a>
        </article>
        <?php endforeach; ?>
        <div class="empty <?= $events ? 'hidden' : '' ?>" id="empty">Tidak ada event yang cocok dengan pencarian Anda.</div>
      </div>
    </section>
  </div>

<?php
/* ======================================================================
   HALAMAN: DETAIL EVENT + FORM PENDAFTARAN
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
?>
  <div class="wrap">
    <div class="detail">
      <div class="panel">
        <span class="badge"><?= h($e['nama_kategori']) ?></span>
        <h1><?= h($e['nama_event']) ?></h1>
        <p><?= h($e['deskripsi'] ?: 'Belum ada deskripsi untuk event ini.') ?></p>
        <div class="info-list">
          <div><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><span><small>Tanggal</small><?= h(tgl($e['tanggal'])) ?></span></div>
          <div><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span><small>Waktu</small><?= h($e['waktu']) ?></span></div>
          <div><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="9" r="2.5"/></svg><span><small>Lokasi</small><?= h($e['lokasi']) ?></span></div>
        </div>
        <div class="bar"><i class="<?= $pct >= 100 ? 'full' : ($pct >= 80 ? 'hot' : '') ?>" style="width:<?= $pct ?>%"></i></div>
        <div class="quota"><span><?= $terisi ?> dari <?= (int)$e['kuota'] ?> kursi terisi</span><span><?= $sisa ?> tersisa</span></div>
        <a class="back" href="?page=home">&larr; Kembali ke daftar event</a>
      </div>

      <div class="panel">
        <h3>Pendaftaran Event</h3>
        <?php if (!$bisa): ?>
          <p><?= $lewat ? 'Event ini sudah selesai, pendaftaran ditutup.' : 'Mohon maaf, kuota event sudah penuh.' ?></p>
          <button class="btn" style="margin-top:16px;width:100%" disabled>Pendaftaran Ditutup</button>
        <?php else: ?>
          <form method="post" id="daftarForm" novalidate>
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="act" value="daftar">
            <input type="hidden" name="id_event" value="<?= $id ?>">
            <div class="field"><label for="nama_peserta">Nama Lengkap</label><input id="nama_peserta" name="nama_peserta" maxlength="100" required><span class="err-msg"></span></div>
            <div class="field"><label for="nim">NIM</label><input id="nim" name="nim" maxlength="20" inputmode="numeric" required><span class="err-msg"></span></div>
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="120" required><span class="err-msg"></span></div>
            <button class="btn" type="submit" style="width:100%">DAFTAR SEKARANG</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif;

/* ======================================================================
   HALAMAN: LOGIN ADMIN
   ====================================================================== */
elseif ($page === 'login'):
    if (is_admin()) { go('?page=admin'); }
?>
  <div class="login panel">
    <h3>Login Pengelola</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="act" value="login">
      <div class="field"><label for="username">Username</label><input id="username" name="username" required autofocus></div>
      <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" required></div>
      <button class="btn dark" type="submit" style="width:100%">Masuk</button>
    </form>
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
        <thead><tr><th>Nama Event</th><th>Kategori</th><th>Tanggal</th><th>Lokasi</th><th>Peserta</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
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
        <?php if (!$rows): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:34px">Belum ada event.</td></tr><?php endif; ?>
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
   HALAMAN: FORM TAMBAH / EDIT EVENT
   ====================================================================== */
elseif ($page === 'form'):
    $id = (int)($_GET['id'] ?? 0);
    $e = ['id_event' => 0, 'nama_event' => '', 'id_kategori' => 0, 'deskripsi' => '', 'tanggal' => '', 'waktu' => '', 'lokasi' => '', 'kuota' => 50];
    if ($id) {
        $st = $pdo->prepare('SELECT * FROM event WHERE id_event = ?');
        $st->execute([$id]);
        $found = $st->fetch();
        if ($found) { $e = $found; }
    }
?>
  <div class="wrap section">
    <div class="panel" style="max-width:720px;margin-inline:auto">
      <h3><?= $e['id_event'] ? 'Edit Event' : 'Tambah Event Baru' ?></h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="act" value="simpan_event">
        <input type="hidden" name="id_event" value="<?= (int)$e['id_event'] ?>">
        <div class="field"><label>Nama Event *</label><input name="nama_event" value="<?= h($e['nama_event']) ?>" maxlength="150" required></div>
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

<footer>
  <div class="wrap"><b>PENS Event Hub</b> &middot; Informasi dan pendaftaran event kampus dalam satu platform.</div>
</footer>

<script src="script.js"></script>
</body>
</html>