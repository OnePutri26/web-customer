/* YesNet - Halaman Biodata: dropdown wilayah, lokasi saya, validasi form */
(function () {
  'use strict';

  var form = document.getElementById('bd-form');
  if (!form) return;

  var $ = function (id) { return document.getElementById(id); };

  /* ---------- Util ---------- */
  function normalizePhone(v) {
    var d = String(v || '').replace(/\D+/g, '');
    if (!d) return '';
    if (d.indexOf('62') === 0) return d;
    if (d.charAt(0) === '0') return '62' + d.slice(1);
    if (d.charAt(0) === '8') return '62' + d;
    return d;
  }

  function showErr(id, msg) {
    var span = $('err-' + id);
    var field = $(id);
    if (span) span.textContent = msg || '';
    if (field) {
      field.classList.toggle('is-invalid', !!msg);
      if (msg) field.setAttribute('aria-invalid', 'true'); else field.removeAttribute('aria-invalid');
    }
  }

  /* ---------- Dropdown wilayah (provinsi > kota > kecamatan > kelurahan) ---------- */
  var API = 'https://www.emsifa.com/api-wilayah-indonesia/api/';
  var cache = {};
  var order = ['provinsi', 'kota', 'kecamatan', 'kelurahan'];
  var paths = [
    function () { return 'provinces.json'; },
    function (c) { return 'regencies/' + c + '.json'; },
    function (c) { return 'districts/' + c + '.json'; },
    function (c) { return 'villages/' + c + '.json'; }
  ];
  var labels = {
    provinsi: 'Pilih provinsi',
    kota: 'Pilih kota/kabupaten',
    kecamatan: 'Pilih kecamatan',
    kelurahan: 'Pilih kelurahan'
  };

  function title(s) {
    return String(s).toLowerCase()
      .replace(/(^|\s)(\S)/g, function (m, a, b) { return a + b.toUpperCase(); })
      .replace(/\bDki\b/, 'DKI').replace(/\bDi\b/, 'DI');
  }

  function getJSON(path) {
    if (cache[path]) return Promise.resolve(cache[path]);
    var ctl = ('AbortController' in window) ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctl) ctl.abort(); }, 9000);
    return fetch(API + path, ctl ? { signal: ctl.signal } : undefined)
      .then(function (r) { clearTimeout(timer); if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) { cache[path] = d; return d; });
  }

  function currentSelect(i) {
    var el = $(order[i]);
    return el && el.tagName === 'SELECT' ? el : null;
  }

  function fillSelect(sel, items, label) {
    sel.innerHTML = '';
    var first = document.createElement('option');
    first.value = ''; first.textContent = label;
    sel.appendChild(first);
    items.forEach(function (it) {
      var o = document.createElement('option');
      o.value = title(it.name); o.textContent = title(it.name);
      o.setAttribute('data-code', it.id);
      sel.appendChild(o);
    });
  }

  function pickOld(sel) {
    var old = (sel.getAttribute('data-old') || '').toLowerCase();
    sel.removeAttribute('data-old');
    if (!old) return false;
    for (var i = 0; i < sel.options.length; i++) {
      if (sel.options[i].value.toLowerCase() === old) { sel.selectedIndex = i; return true; }
    }
    return false;
  }

  function resetFrom(i) {
    for (; i < order.length; i++) {
      var s = currentSelect(i);
      if (s) { fillSelect(s, [], labels[order[i]]); s.disabled = true; }
    }
  }

  /* Jika API tidak bisa diakses, ganti dropdown dengan kolom ketik manual */
  function toText(from) {
    for (var i = from; i < order.length; i++) {
      var s = currentSelect(i);
      if (!s) continue;
      var inp = document.createElement('input');
      inp.type = 'text';
      inp.id = s.id; inp.name = s.name; inp.className = s.className;
      inp.required = true; inp.maxLength = 100;
      inp.placeholder = 'Ketik ' + labels[order[i]].replace('Pilih ', '');
      inp.value = s.getAttribute('data-old') || '';
      s.parentNode.replaceChild(inp, s);
    }
  }

  function loadLevel(i) {
    var sel = currentSelect(i);
    if (!sel) return Promise.resolve();
    var code = '';
    if (i > 0) {
      var parent = currentSelect(i - 1);
      var opt = parent && parent.options[parent.selectedIndex];
      code = opt ? opt.getAttribute('data-code') : '';
      if (!code) { resetFrom(i); return Promise.resolve(); }
    }
    sel.disabled = true;
    sel.innerHTML = '<option value="">Memuat…</option>';
    return getJSON(paths[i](code)).then(function (items) {
      fillSelect(sel, items, labels[order[i]]);
      sel.disabled = false;
      if (pickOld(sel) && i < order.length - 1) return loadLevel(i + 1);
    }).catch(function () {
      toText(i);
    });
  }

  order.forEach(function (id, i) {
    var el = $(id);
    if (!el || el.tagName !== 'SELECT') return;
    el.addEventListener('change', function () {
      showErr(id, '');
      resetFrom(i + 1);
      if (el.value && i < order.length - 1) loadLevel(i + 1);
    });
  });

  resetFrom(1);
  loadLevel(0);

  /* ---------- Gunakan lokasi saya ---------- */
  var geoBtn = $('bd-geo');
  var geoMsg = $('bd-geo-msg');
  var coord = $('koordinat');

  function say(text, cls) {
    if (!geoMsg) return;
    geoMsg.textContent = text || '';
    geoMsg.className = 'bd-geo-msg' + (cls ? ' ' + cls : '');
  }

  if (geoBtn && coord) {
    geoBtn.addEventListener('click', function () {
      if (!navigator.geolocation) { say('Perangkat Anda tidak mendukung pencarian lokasi.', 'is-bad'); return; }
      if (window.isSecureContext === false) { say('Pencarian lokasi hanya bisa lewat koneksi HTTPS.', 'is-bad'); return; }
      geoBtn.disabled = true;
      say('Mencari lokasi Anda…');
      navigator.geolocation.getCurrentPosition(function (p) {
        coord.value = p.coords.latitude.toFixed(6) + ', ' + p.coords.longitude.toFixed(6);
        showErr('koordinat', '');
        say('Koordinat berhasil diisi.', 'is-ok');
        geoBtn.disabled = false;
      }, function (err) {
        var m = err.code === 1 ? 'Izin lokasi ditolak. Anda bisa mengisi koordinat secara manual atau melewatinya.'
              : err.code === 3 ? 'Waktu pencarian lokasi habis. Coba lagi.'
              : 'Lokasi tidak dapat ditemukan.';
        say(m, 'is-bad');
        geoBtn.disabled = false;
      }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
    });
  }

  /* ---------- Validasi (mengikuti aturan di server) ---------- */
  function val(id) {
    var el = $(id);
    return el ? String(el.value || '').replace(/\s+/g, ' ').trim() : '';
  }

  var rules = {
    nama: function () {
      var v = val('nama');
      if (!v) return 'Nama lengkap wajib diisi.';
      if (v.length < 3) return 'Nama minimal 3 karakter.';
      if (!/^[\p{L}\p{M}\s.'’-]+$/u.test(v)) return 'Nama hanya boleh berisi huruf.';
    },
    whatsapp: function () {
      var v = val('whatsapp');
      if (!v) return 'Nomor WhatsApp wajib diisi.';
      if (!/^628\d{7,12}$/.test(normalizePhone(v))) return 'Gunakan nomor WhatsApp yang valid, contoh 081234567890.';
    },
    email: function () {
      var v = val('email');
      if (v && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) return 'Format email tidak valid.';
    },
    provinsi: function () { if (!val('provinsi')) return 'Pilih provinsi.'; },
    kota: function () { if (!val('kota')) return 'Pilih kota/kabupaten.'; },
    kecamatan: function () { if (!val('kecamatan')) return 'Pilih kecamatan.'; },
    kelurahan: function () { if (!val('kelurahan')) return 'Pilih kelurahan.'; },
    alamat: function () {
      var v = val('alamat');
      if (!v) return 'Alamat lengkap wajib diisi.';
      if (v.length < 10) return 'Tulis alamat lebih lengkap (jalan, nomor, RT/RW).';
    },
    koordinat: function () {
      var v = val('koordinat');
      if (!v) return;
      var m = v.match(/^(-?\d{1,2}(?:\.\d+)?)\s*[,; ]\s*(-?\d{1,3}(?:\.\d+)?)$/);
      if (!m) return 'Format koordinat: latitude, longitude. Contoh: -7.257472, 112.752088';
      var lat = parseFloat(m[1]), lng = parseFloat(m[2]);
      if (lat < -11 || lat > 6 || lng < 95 || lng > 141) return 'Koordinat berada di luar wilayah Indonesia.';
    },
    setuju: function () {
      if (!$('setuju').checked) return 'Anda perlu menyetujui kebijakan privasi untuk melanjutkan.';
    }
  };

  var ids = Object.keys(rules);

  ids.forEach(function (id) {
    var el = $(id);
    if (!el) return;
    el.addEventListener('blur', function () { showErr(id, rules[id]() || ''); });
    el.addEventListener(id === 'setuju' ? 'change' : 'input', function () {
      if (el.classList.contains('is-invalid')) showErr(id, rules[id]() || '');
    });
  });

  var phone = $('whatsapp');
  if (phone) phone.addEventListener('input', function () {
    var clean = phone.value.replace(/[^\d+\s-]/g, '');
    if (clean !== phone.value) phone.value = clean;
  });

  var submitBtn = $('bd-submit');

  form.addEventListener('submit', function (ev) {
    var first = null;
    ids.forEach(function (id) {
      var msg = rules[id]() || '';
      showErr(id, msg);
      if (msg && !first) first = $(id);
    });
    if (first) {
      ev.preventDefault();
      first.focus({ preventScroll: true });
      first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    if (submitBtn) submitBtn.classList.add('is-loading');
  });

  /* tombol kembali dari browser: pulihkan tombol kirim */
  window.addEventListener('pageshow', function () {
    if (submitBtn) submitBtn.classList.remove('is-loading');
  });
})();