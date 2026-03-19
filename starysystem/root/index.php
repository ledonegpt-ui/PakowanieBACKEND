<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Pakowanie</title>
  <link rel="stylesheet" href="/assets/css/app.css?v=31" />
</head>
<body>
  <div class="wrap" id="app">
    <header class="top">
      <button id="btnLogoutTop" class="btnSm hidden">Wyloguj</button>
      <div class="brand">PAKOWANIE</div>
      <div class="statusbar" id="statusbar"></div>
    </header>

    <section class="card" id="loginCard">
      <h1>Login</h1>

      <div class="row">
        <label>Stanowisko</label>
        <select id="station_no">
          <?php for ($i=1; $i<=11; $i++): ?>
            <option value="<?php echo $i; ?>">stanowisko<?php echo $i; ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div class="row">
        <label>Kod pakowacza (scan)</label>
        <input id="packer_code" autocomplete="off" placeholder="Zeskanuj kod pakowacza i ENTER" />
      </div>

      <button id="btnLogin" class="btn primary">Zaloguj</button>
      <div class="hint">Wybierz stanowisko i zeskanuj kod pakowacza.</div>
      <div class="msg" id="loginMsg"></div>
    </section>

    <section class="card hidden" id="scanCard">
      <div class="scanRow">
        <div class="scanLabel">Skan</div>
        <input id="scanInput" class="scanInput" autocomplete="off" placeholder="Zamówienie + ENTER (FINISH/CANCEL/CLEAR/LOGOUT)" />
      </div>

      <div class="orderHeader">
        <div class="hLine">
          <div class="hMain" id="hMain">—</div>
          <div class="hSub" id="hSub">—</div>
        </div>
        <div class="timer">
          <div class="timerLabel">Czas</div>
          <div class="timerValue" id="timerValue">00:00:00</div>
        </div>
      </div>

      <div class="msg big" id="orderMsg"></div>
      <div class="items" id="items"></div>

      <div class="actions">
        <button id="btnFinish" class="btn danger" disabled>Zakończ (awaryjnie)</button>

        <!-- UWAGA: bez disabled, żeby nie znikał (CSS często ukrywa :disabled) -->
        <button id="btnReprint" class="btn">Drukuj ponownie etykietę</button>

        <button id="btnClear" class="btn">Wyczyść</button>
      </div>
    </section>
  </div>

  <!-- podbijamy wersję, żeby przeglądarka nie trzymała starego JS -->
  <script src="/assets/js/app.js?v=202"></script>
</body>
</html>
