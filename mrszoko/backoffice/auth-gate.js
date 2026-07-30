/* =====================================================================
   auth-gate.js — porte d'entrée du back-office.
   =====================================================================
   Chargé AVANT l'hydratation : interroge ../api/auth/me et ne laisse
   démarrer l'application que si une identité est établie. Sinon il
   affiche l'écran de connexion (e-mail + mot de passe → /auth/login),
   qui ouvre une session par cookie HttpOnly côté serveur.

   • Le jeton de service (?token=… mémorisé par api-config.js) reste
     accepté : /auth/me le valide et la porte s'ouvre sans mot de passe.
   • Sans API (démo GitHub Pages, mode seed), aucune porte : l'app
     démarre sur les données de démonstration.
   • Textes en polonais, comme le reste de la console.
   ===================================================================== */
(function () {
  'use strict';

  var T = {
    title: 'Konsola marki',
    subtitle: 'Zaloguj się, aby kontynuować',
    email: 'E-mail',
    password: 'Hasło',
    submit: 'Zaloguj się',
    working: 'Logowanie…',
    logout: 'Wyloguj',
    errBad: 'Nieprawidłowy e-mail lub hasło.',
    errLocked: 'Konto tymczasowo zablokowane po zbyt wielu próbach. Spróbuj ponownie za 15 minut.',
    errNet: 'Brak połączenia z serwerem.',
    errFields: 'Podaj e-mail i hasło.',
    errServer: 'Serwer nie odpowiada poprawnie. Odśwież stronę lub skontaktuj się z administratorem.'
  };

  function api() {
    var fr = window.__FR || {};
    return fr.base || '';
  }
  function headers() {
    var fr = window.__FR || {};
    var h = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (fr.token) h['X-Admin-Token'] = fr.token;   // jeton de service éventuel
    return h;
  }
  function call(path, opts) {
    opts = opts || {};
    return fetch(api() + path, {
      method: opts.method || 'GET',
      headers: headers(),
      body: opts.body ? JSON.stringify(opts.body) : undefined,
      credentials: 'same-origin'                   // indispensable : cookie de session
    });
  }

  function el(tag, css, text) {
    var n = document.createElement(tag);
    if (css) n.style.cssText = css;
    if (text != null) n.textContent = text;
    return n;
  }

  /* Ce script est chargé dans <head> : document.body n'existe pas encore quand
     la vérification d'identité revient. Toute insertion attend donc le DOM. */
  function domReady(cb) {
    if (document.body) { cb(); return; }
    document.addEventListener('DOMContentLoaded', cb, { once: true });
  }

  /* Écran d'erreur : on n'ouvre JAMAIS l'application par défaut. */
  function showBlocked(msg) {
    var wrap = el('div',
      'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;' +
      'background:var(--bg-page, #FBF6EF);font-family:system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px');
    var card = el('div',
      'max-width:420px;background:#fff;border-radius:14px;padding:26px 28px;border:1px solid var(--border-subtle, #DEC9AC);' +
      'box-shadow:0 18px 40px -12px rgba(46, 22, 12, .20)');
    card.appendChild(el('div', 'font:600 16px/1.3 inherit;color:var(--text-strong, #211712);margin-bottom:8px', T.title));
    card.appendChild(el('div', 'font:400 13.5px/1.55 inherit;color:var(--text-muted, #7C6A5D)', msg));
    wrap.appendChild(card);
    document.body.appendChild(wrap);
  }

  /* ---- Écran de connexion --------------------------------------------- */
  function showLogin(onDone) {
    var wrap = el('div',
      'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;' +
      'background:var(--bg-page, #FBF6EF);font-family:system-ui,-apple-system,"Segoe UI",sans-serif');

    var card = el('div',
      'width:min(400px,92vw);background:#fff;border-radius:14px;padding:32px 30px;' +
      'box-shadow:0 18px 40px -12px rgba(46, 22, 12, .20);border:1px solid var(--border-subtle, #DEC9AC)');

    var logo = document.createElement('img');
    logo.src = 'img/logo.png';
    logo.alt = 'Mister Szoko';
    logo.style.cssText = 'width:auto;display:block;margin-bottom:20px';
    logo.style.setProperty('height', '72px', 'important');  // prime sur la règle du DS
    logo.onerror = function () { logo.style.display = 'none'; };
    card.appendChild(logo);

    card.appendChild(el('div', 'font:600 19px/1.2 inherit;color:var(--text-strong, #211712);margin-bottom:4px', T.title));
    card.appendChild(el('div', 'font:400 13.5px/1.5 inherit;color:var(--text-muted, #7C6A5D);margin-bottom:22px', T.subtitle));

    var form = document.createElement('form');
    var lblCss = 'display:block;font:600 11px/1 inherit;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted, #7C6A5D);margin-bottom:6px';
    var inpCss = 'width:100%;box-sizing:border-box;padding:11px 13px;border:1px solid var(--border-subtle, #DEC9AC);border-radius:8px;' +
                 'font:400 14px/1.2 inherit;color:var(--text-strong, #211712);background:#fff;margin-bottom:16px;outline:none';

    form.appendChild(el('label', lblCss, T.email));
    var inEmail = document.createElement('input');
    inEmail.type = 'email'; inEmail.autocomplete = 'username'; inEmail.required = true;
    inEmail.style.cssText = inpCss;
    form.appendChild(inEmail);

    form.appendChild(el('label', lblCss, T.password));
    var inPass = document.createElement('input');
    inPass.type = 'password'; inPass.autocomplete = 'current-password'; inPass.required = true;
    inPass.style.cssText = inpCss;
    form.appendChild(inPass);

    var err = el('div', 'display:none;font:400 12.5px/1.45 inherit;color:var(--danger, #B0402E);background:var(--color-danger-bg, #FBEAE5);' +
                        'border-radius:8px;padding:9px 11px;margin-bottom:14px');
    form.appendChild(err);

    var btn = document.createElement('button');
    btn.type = 'submit';
    btn.textContent = T.submit;
    btn.style.cssText = 'width:100%;padding:12px 0;border:none;border-radius:999px;cursor:pointer;' +
                        'font:700 14px/1 inherit;color:#fff;background:var(--brand, #41281A);transition:background .2s';
    btn.onmouseenter = function () { btn.style.background = 'var(--brand-hover, #2E160C)'; };
    btn.onmouseleave = function () { btn.style.background = 'var(--brand, #41281A)'; };
    form.appendChild(btn);

    [inEmail, inPass].forEach(function (i) {
      i.onfocus = function () { i.style.borderColor = 'var(--accent, #C68A3C)'; i.style.boxShadow = '0 0 0 3px rgba(198, 138, 60, .25)'; };
      i.onblur = function () { i.style.borderColor = 'var(--border-subtle, #DEC9AC)'; i.style.boxShadow = 'none'; };
    });

    function fail(msg) {
      err.textContent = msg;
      err.style.display = 'block';
      btn.disabled = false;
      btn.textContent = T.submit;
    }

    form.onsubmit = function (e) {
      e.preventDefault();
      err.style.display = 'none';
      if (!inEmail.value || !inPass.value) { fail(T.errFields); return; }
      btn.disabled = true;
      btn.textContent = T.working;
      call('/auth/login', { method: 'POST', body: { email: inEmail.value, password: inPass.value } })
        .then(function (r) {
          if (r.ok) {
            // Après une connexion, on arrive sur le tableau de bord DU
            // WEBSHOP, pas sur celui du réseau de franchise hérité. Sauf si
            // l'adresse demandait explicitement un écran de la console.
            if (!wantsErpScreen()) { toWebshop(); return; }
            document.body.removeChild(wrap);
            onDone();
            return;
          }
          fail(r.status === 429 ? T.errLocked : T.errBad);
        })
        .catch(function () { fail(T.errNet); });
    };

    card.appendChild(form);
    wrap.appendChild(card);
    document.body.appendChild(wrap);
    inEmail.focus();
  }

  /* ---- Bouton de déconnexion (discret, une fois connecté) ------------- */
  function addLogout() {
    var b = document.createElement('button');
    b.textContent = T.logout;
    b.style.cssText = 'position:fixed;right:14px;bottom:14px;z-index:9998;padding:7px 14px;' +
      'border:1px solid rgba(46, 22, 12, .15);border-radius:999px;cursor:pointer;background:rgba(255, 255, 255, .92);' +
      'font:600 11.5px/1 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink-700, #4A3B31);backdrop-filter:blur(6px)';
    b.onclick = function () {
      call('/auth/logout', { method: 'POST' })
        .catch(function () {})
        .then(function () {
          try { localStorage.removeItem('adminToken'); } catch (e) {}
          location.reload();
        });
    };
    document.body.appendChild(b);
  }

  /* La console héritée s'ouvre sur son « Pulpit sieci » : chiffre d'affaires
     réseau, boutiques bruxelloises, zones de chalandise. Ici on tient une
     boutique en ligne, pas une franchise. Une identité établie mène donc au
     tableau de bord du webshop — sauf si l'adresse demande explicitement un
     écran de la console (#ekran=users), auquel cas on la laisse s'ouvrir. */
  function wantsErpScreen() {
    return /(?:^|[#&])ekran=/.test(location.hash || '');
  }
  function toWebshop() {
    location.replace('pulpit.php');
  }

  /* ---- Porte ---------------------------------------------------------- */
  window.WSMAuth = {
    gate: function (start) {
      if (!api()) { start(); return; }             // pas d'API configurée : démo/seed

      // .then(succès, échec-réseau) — et NON .catch global : une exception de
      // rendu ne doit jamais être confondue avec « API injoignable » et ouvrir
      // l'application. Le seul cas qui démarre sans identité est l'absence
      // totale de serveur (mode démonstration hors ligne).
      call('/auth/me').then(
        function (r) {
          if (r.ok) {
            if (!wantsErpScreen()) { toWebshop(); return; }
            domReady(addLogout); start(); return;
          }
          if (r.status === 401) {
            domReady(function () { showLogin(function () { domReady(addLogout); start(); }); });
            return;
          }
          domReady(function () { showBlocked(T.errServer + ' (' + r.status + ')'); });
        },
        function () { start(); }                   // échec réseau : repli seed hors ligne
      ).catch(function (e) {                       // exception de rendu : on bloque, on n'ouvre pas
        console.error('auth-gate', e);
        domReady(function () { showBlocked(T.errServer); });
      });
    }
  };
})();
