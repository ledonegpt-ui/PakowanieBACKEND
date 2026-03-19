(function () {
  function normalizeOrderCode(raw) {
    raw = (raw || '').trim();
    if (!raw || raw === '—') return null;

    // jeśli ktoś pokaże w UI "*1234*" itp.
    var m = raw.match(/\*(B\d+|E\d+|\d+)\*/i);
    if (m && m[1]) raw = m[1];

    raw = raw.trim().toUpperCase();
    if (!raw || raw.length > 32) return null;
    if (!/^[0-9A-Z]+$/.test(raw)) return null;
    return raw;
  }

  function getCurrentOrderCode() {
    var el = document.getElementById('hMain');
    if (!el) return null;
    return normalizeOrderCode(el.textContent);
  }

  async function postJson(url, payload) {
    var res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    var json = await res.json();
    return json;
  }

  function setMsg(text, isOk) {
    var msg = document.getElementById('orderMsg');
    if (!msg) return;
    msg.textContent = text;
    msg.classList.remove('ok', 'err');
    msg.classList.add(isOk ? 'ok' : 'err');
  }

  function setup() {
    var btn = document.getElementById('btnReprint');
    if (!btn) return;

    // enable/disable zależnie od tego czy jest aktualne order_code
    var last = null;
    setInterval(function () {
      var oc = getCurrentOrderCode();
      if (oc && oc !== last) {
        btn.disabled = false;
        last = oc;
      }
      if (!oc) {
        btn.disabled = true;
        last = null;
      }
    }, 400);

    btn.addEventListener('click', async function () {
      var oc = getCurrentOrderCode();
      if (!oc) return setMsg('Brak aktywnego zamówienia do druku.', false);

      var old = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Drukuję…';

      try {
        var json = await postJson('/api/reprint_label.php', { order_code: oc });

        if (!json || !json.ok) {
          var err = (json && json.error) ? json.error : 'Błąd druku';
          var hint = (json && json.hint) ? ('\n' + json.hint) : '';
          setMsg(err + hint, false);
          return;
        }

        setMsg('OK: wydrukowano ponownie etykietę dla ' + oc, true);
      } catch (e) {
        setMsg('Błąd sieci: ' + (e && e.message ? e.message : e), false);
      } finally {
        btn.textContent = old;
        // enable wróci w intervalu, jeśli nadal jest order
        btn.disabled = false;
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setup);
  } else {
    setup();
  }
})();
