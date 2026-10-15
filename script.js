(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* ---------- 1. Notifikasi (flash) hilang otomatis ---------- */
  var flash = $('#flash');
  if (flash) {
    setTimeout(function () {
      flash.style.transition = 'opacity .4s';
      flash.style.opacity = '0';
      setTimeout(function () { if (flash.parentNode) { flash.parentNode.removeChild(flash); } }, 450);
    }, 6000);
  }

  /* ---------- 2. Beranda: filter kategori + pencarian langsung ---------- */
  var grid = $('#grid');
  var chips = $('#chips');
  if (grid && chips) {
    var cards = $$('.card', grid);
    var emptyBox = $('#empty');
    var count = $('#count');
    var kategoriInputs = $$('input[name="kategori"]');
    var searchInputs = $$('input[name="q"]');

    var state = {
      k: parseInt(($('#kategoriInput') || {}).value, 10) || 0,
      q: ((searchInputs[0] || {}).value || '').trim().toLowerCase()
    };

    var apply = function () {
      var shown = 0;
      cards.forEach(function (card) {
        var okK = state.k === 0 || parseInt(card.dataset.k, 10) === state.k;
        var okQ = state.q === '' || (card.dataset.text || '').indexOf(state.q) !== -1;
        var visible = okK && okQ;
        card.hidden = !visible;
        if (visible) { shown++; }
      });
      if (count) { count.textContent = shown + ' event ditemukan'; }
      if (emptyBox) { emptyBox.classList.toggle('hidden', shown > 0); }

      // Sinkronkan URL agar bisa di-bookmark / dimuat ulang
      if (window.history && history.replaceState) {
        var u = new URL(window.location.href);
        u.searchParams.set('page', 'home');
        if (state.k) { u.searchParams.set('kategori', state.k); } else { u.searchParams.delete('kategori'); }
        if (state.q) { u.searchParams.set('q', state.q); } else { u.searchParams.delete('q'); }
        history.replaceState(null, '', u.pathname + u.search);
      }
    };

    chips.addEventListener('click', function (ev) {
      var chip = ev.target.closest('.chip');
      if (!chip) { return; }
      state.k = parseInt(chip.dataset.k, 10) || 0;
      $$('.chip', chips).forEach(function (c) { c.classList.toggle('on', c === chip); });
      kategoriInputs.forEach(function (i) { i.value = state.k || ''; });
      apply();
    });

    searchInputs.forEach(function (input) {
      input.addEventListener('input', function () {
        state.q = input.value.trim().toLowerCase();
        searchInputs.forEach(function (o) { if (o !== input) { o.value = input.value; } });
        apply();
      });
    });

    apply();
  }

  /* ---------- 3. Simpan / lepas simpan event (tanpa reload) ---------- */
  var gridAkun = $('#grid');
  var removeOnUnsave = gridAkun && gridAkun.dataset.removeOnUnsave === '1';

  $$('form[data-save]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.fetch || !window.FormData) { return; } // fallback: kirim form biasa
      ev.preventDefault();
      var btn = $('button[type="submit"]', form);
      if (btn) { btn.disabled = true; }

      fetch(window.location.pathname, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' }
      })
        .then(function (res) {
          return res.json().then(function (data) { return { status: res.status, data: data }; });
        })
        .then(function (r) {
          if (r.status === 401 || (r.data && r.data.login)) {
            window.location.href = '?page=masuk';
            return;
          }
          if (!r.data || !r.data.ok) { throw new Error('gagal'); }

          var saved = !!r.data.saved;
          if (btn) {
            btn.classList.toggle('on', saved);
            btn.setAttribute('aria-pressed', saved ? 'true' : 'false');
            if (btn.dataset.on) {
              var label = $('.save-label', btn);
              if (label) { label.textContent = saved ? btn.dataset.on : btn.dataset.off; }
            } else {
              btn.title = saved ? 'Hapus dari simpanan' : 'Simpan event';
            }
          }

          // Di tab "Event Tersimpan": kartu langsung dihapus saat tidak lagi disimpan
          if (removeOnUnsave && !saved) {
            var card = form.closest('.card');
            if (card && card.parentNode) {
              card.style.transition = 'opacity .25s, transform .25s';
              card.style.opacity = '0';
              card.style.transform = 'scale(.96)';
              setTimeout(function () {
                card.parentNode.removeChild(card);
                var sisa = $$('.card', gridAkun).length;
                var kosong = $('#empty');
                if (kosong) { kosong.classList.toggle('hidden', sisa > 0); }
                var tab = $('.tab.on');
                if (tab) { tab.textContent = tab.textContent.replace(/\(\d+\)/, '(' + sisa + ')'); }
              }, 260);
            }
          }
        })
        .catch(function () {
          window.location.reload(); // biarkan server menampilkan status terbaru
        })
        .then(function () { if (btn) { btn.disabled = false; } });
    });
  });

  /* ---------- 4. Cegah klik ganda pada form penting ---------- */
  $$('form[data-once]').forEach(function (form) {
    form.addEventListener('submit', function () {
      if (form.dataset.sending === '1') { return; }
      form.dataset.sending = '1';
      setTimeout(function () {
        $$('button[type="submit"]', form).forEach(function (b) {
          b.disabled = true;
          b.style.opacity = '.7';
        });
      }, 0);
    });
  });

  /* ---------- 5. Form buat akun: cek konfirmasi password ---------- */
  var authForm = $('form[data-auth="daftar"]');
  if (authForm) {
    var pw = $('#password', authForm);
    var pw2 = $('#password2', authForm);
    var cek = function () {
      pw2.setCustomValidity(pw.value !== pw2.value ? 'Konfirmasi password tidak sama.' : '');
    };
    pw.addEventListener('input', cek);
    pw2.addEventListener('input', cek);
  }

  /* ---------- 6. Admin: modal hapus event ---------- */
  var modal = $('#modalHapus');
  if (modal) {
    var closeModal = function () { modal.classList.remove('open'); };
    $$('[data-hapus]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        $('#hapusId').value = btn.dataset.hapus;
        $('#modalText').textContent = 'Event "' + btn.dataset.nama + '" beserta seluruh data pendaftarnya akan dihapus permanen.';
        modal.classList.add('open');
      });
    });
    var batal = $('#batalHapus');
    if (batal) { batal.addEventListener('click', closeModal); }
    modal.addEventListener('click', function (ev) { if (ev.target === modal) { closeModal(); } });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { closeModal(); } });
  }

  /* ---------- 7. Admin: pratinjau & validasi poster ---------- */
  var posterInput = $('#posterInput');
  if (posterInput) {
    var MAX = 2 * 1024 * 1024;
    var TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    var dropzone = $('#dropzone');
    var preview = $('#posterPreview');
    var previewImg = $('#posterPreviewImg');
    var previewLabel = $('#posterPreviewLabel');
    var errBox = $('#posterError');
    var hapusPoster = $('#hapusPoster');
    var objectUrl = null;

    var setError = function (msg) { if (errBox) { errBox.textContent = msg || ''; } };

    posterInput.addEventListener('change', function () {
      setError('');
      var file = posterInput.files && posterInput.files[0];
      if (!file) { return; }

      if (TYPES.indexOf(file.type) === -1) {
        setError('Format poster harus JPG, PNG, atau WEBP.');
        posterInput.value = '';
        return;
      }
      if (file.size > MAX) {
        setError('Ukuran poster ' + (file.size / 1048576).toFixed(1) + ' MB. Maksimal 2 MB.');
        posterInput.value = '';
        return;
      }

      if (objectUrl) { URL.revokeObjectURL(objectUrl); }
      objectUrl = URL.createObjectURL(file);
      previewImg.src = objectUrl;
      previewImg.style.opacity = '1';
      preview.classList.add('show');
      if (previewLabel) { previewLabel.textContent = 'Poster baru: ' + file.name; }
      if (hapusPoster) { hapusPoster.checked = false; }
    });

    ['dragenter', 'dragover'].forEach(function (name) {
      dropzone.addEventListener(name, function () { dropzone.classList.add('drag'); });
    });
    ['dragleave', 'drop'].forEach(function (name) {
      dropzone.addEventListener(name, function () { dropzone.classList.remove('drag'); });
    });

    if (hapusPoster) {
      hapusPoster.addEventListener('change', function () {
        previewImg.style.opacity = hapusPoster.checked ? '.3' : '1';
      });
    }
  }

  /* ---------- 8. Detail event: poster dapat diperbesar ---------- */
  var zoomImg = $('img[data-zoom]');
  if (zoomImg) {
    var box = null;
    var tutup = function () {
      if (box && box.parentNode) { box.parentNode.removeChild(box); }
      box = null;
    };
    zoomImg.addEventListener('click', function () {
      box = document.createElement('div');
      box.className = 'modal open lightbox';
      var big = document.createElement('img');
      big.src = zoomImg.src;
      big.alt = zoomImg.alt;
      box.appendChild(big);
      box.addEventListener('click', tutup);
      document.body.appendChild(box);
    });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { tutup(); } });
  }
})();
