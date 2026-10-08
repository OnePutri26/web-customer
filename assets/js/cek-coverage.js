/* Cek coverage: geocode alamat (Nominatim/OSM) lalu kirim koordinat ke server */
(function () {
  var f = document.getElementById('covForm');
  if (!f) return;
  var btn = f.querySelector('button[type=submit]');
  var info = document.getElementById('covInfo');
  var busy = false;

  function say(t) { if (info) info.textContent = t; }

  async function geocode(q) {
    var c = new AbortController();
    var t = setTimeout(function () { c.abort(); }, 6000);
    try {
      var r = await fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=id&q=' + encodeURIComponent(q), { signal: c.signal });
      var j = await r.json();
      return j && j[0] ? j[0] : null;
    } catch (e) { return null; } finally { clearTimeout(t); }
  }

  f.addEventListener('submit', async function (ev) {
    if (f.dataset.ready) return;
    ev.preventDefault();
    if (busy) return;
    busy = true;
    btn.disabled = true; btn.classList.add('loading');
    say('Mencari lokasi alamat Anda...');
    if (!f.latitude.value) {
      var g = await geocode(f.alamat.value);
      if (g) { f.latitude.value = g.lat; f.longitude.value = g.lon; }
    }
    say('Mengecek jaringan di lokasi Anda...');
    f.dataset.ready = '1';
    setTimeout(function () { f.submit(); }, 500);
  });

  var gps = document.getElementById('covGps');
  if (gps && navigator.geolocation) {
    gps.hidden = false;
    gps.addEventListener('click', function () {
      say('Mengambil lokasi perangkat...');
      navigator.geolocation.getCurrentPosition(function (p) {
        f.latitude.value = p.coords.latitude; f.longitude.value = p.coords.longitude;
        say('Lokasi perangkat terdeteksi. Lengkapi alamat lalu klik Cek Ketersediaan.');
      }, function () { say('Lokasi tidak bisa diambil. Silakan isi alamat secara manual.'); });
    });
  }

  if (f.dataset.auto === '1') f.requestSubmit();
})();
