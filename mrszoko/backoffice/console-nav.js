/* =====================================================================
   console-nav.js — une seule navigation pour toute la console.

   La console est en deux moitiés : l'application exportée par Claude
   Design (Pulpit, Sklepy, Katalog, Dostawy…) et les écrans PHP rendus
   côté serveur (Zamówienia, Poczta, Produkty, Ustawienia…). Elles ne se
   connaissaient pas : on n'atteignait les seconds qu'en tapant l'URL.

   Ce fichier fait deux choses, sans toucher au fichier exporté :

    1. IL AJOUTE LES ÉCRANS PHP DANS LA BARRE DE NAVIGATION de la console,
       dans son propre style, comme un groupe de plus. Un MutationObserver
       les remet si React refait le rendu de la barre.

    2. IL OUVRE UN ÉCRAN DE LA CONSOLE DEPUIS UNE URL. L'application
       navigue par état interne, sans adresse : impossible d'y pointer
       depuis une page PHP. On lit donc « #ekran=users » et on clique le
       bouton correspondant — en dépliant d'abord le groupe s'il est
       replié. C'est ce qui permet aux écrans PHP de renvoyer vers la
       console, et pas seulement l'inverse.
   ===================================================================== */
(function () {
  'use strict';

  /* Les écrans PHP, dans l'ordre du travail réel. */
  var PHP_SCREENS = [
    ['zamowienia.php',  'Zamówienia'],
    ['poczta.php',      'Poczta'],
    ['produkty.php',    'Produkty'],
    ['kontrahenci.php', 'Kontrahenci'],
    ['kraje.php',       'Kraje i VAT'],
    ['rabaty.php',      'Rabaty'],
    ['ustawienia.php',  'Ustawienia integracji'],
    ['../shop/',        'Sklep ↗']
  ];

  /* clé d'URL → libellé exact du bouton dans la barre exportée. */
  var ERP_SCREENS = {
    dash: 'Pulpit sieci', boutiques: 'Sklepy', catalogue: 'Katalog',
    menus: 'Menu i zestawy', promos: 'Promocje sieci', livraisons: 'Dostawy',
    geo: 'Analiza geograficzna', comms: 'Komunikacja', users: 'Użytkownicy i role',
    zones: 'Strefy zasięgu', audit: 'Dziennik audytu'
  };

  var NAV_SELECTOR = 'nav.lz';
  var BLOCK_ID = 'wsm-screens';

  /* ---- 1. Le groupe « Sklep » dans la barre ------------------------- */

  function buildBlock() {
    var box = document.createElement('div');
    box.id = BLOCK_ID;

    var head = document.createElement('div');
    head.textContent = 'Sklep online';
    head.style.cssText = 'margin:14px 8px 6px;font:600 10px/1 var(--font-ui);' +
      'letter-spacing:.1em;text-transform:uppercase;color:var(--color-text-muted)';
    box.appendChild(head);

    PHP_SCREENS.forEach(function (row) {
      var a = document.createElement('a');
      a.href = row[0];
      if (row[0].charAt(0) === '.') { a.target = '_blank'; a.rel = 'noopener'; }
      a.style.cssText = 'display:flex;align-items:center;gap:10px;width:100%;' +
        'padding:9px 12px;border-radius:8px;text-decoration:none;cursor:pointer;' +
        'font:500 12.5px/1.2 var(--font-ui);color:var(--color-text)';
      var dot = document.createElement('span');
      dot.style.cssText = 'flex:none;width:7px;height:7px;border-radius:50%;background:var(--choco-500)';
      var label = document.createElement('span');
      label.style.cssText = 'flex:1;text-align:left';
      label.textContent = row[1];
      a.appendChild(dot);
      a.appendChild(label);
      a.addEventListener('mouseenter', function () { a.style.background = 'var(--color-background-secondary)'; });
      a.addEventListener('mouseleave', function () { a.style.background = 'transparent'; });
      box.appendChild(a);
    });
    return box;
  }

  function mount() {
    var nav = document.querySelector(NAV_SELECTOR);
    if (!nav || document.getElementById(BLOCK_ID)) return !!nav;
    nav.appendChild(buildBlock());
    return true;
  }

  /* React refait le rendu de la barre à chaque changement d'écran et
     emporte notre bloc avec lui. On le remet — c'est moins fragile que
     de patcher l'application exportée, qui serait perdue au prochain
     export. */
  function watch() {
    var obs = new MutationObserver(function () {
      if (!document.getElementById(BLOCK_ID)) mount();
    });
    obs.observe(document.body, { childList: true, subtree: true });
  }

  /* ---- 2. Ouvrir un écran de la console depuis l'URL ---------------- */

  function labelOf(node) {
    return (node.textContent || '').trim();
  }

  /** Le bouton de la barre portant ce libellé, s'il est rendu. */
  function findNavButton(label) {
    var nav = document.querySelector(NAV_SELECTOR);
    if (!nav) return null;
    var buttons = nav.querySelectorAll('button');
    for (var i = 0; i < buttons.length; i++) {
      if (labelOf(buttons[i]) === label) return buttons[i];
    }
    return null;
  }

  /** Déplie les groupes repliés : leurs entrées ne sont pas rendues. */
  function expandGroups() {
    var nav = document.querySelector(NAV_SELECTOR);
    if (!nav) return;
    var buttons = nav.querySelectorAll('button');
    for (var i = 0; i < buttons.length; i++) {
      var t = labelOf(buttons[i]);
      if (t === 'Ustawienia' || t === 'Parametry') buttons[i].click();
    }
  }

  function openFromHash() {
    var m = /(?:^|[#&])ekran=([a-z]+)/i.exec(location.hash || '');
    if (!m) return;
    var label = ERP_SCREENS[m[1].toLowerCase()];
    if (!label) return;

    var tries = 0;
    (function attempt() {
      var btn = findNavButton(label);
      if (btn) {
        btn.click();
        // L'adresse a joué son rôle ; on l'efface pour qu'un rafraîchissement
        // ne ramène pas l'utilisateur au même endroit malgré lui.
        history.replaceState(null, '', location.pathname + location.search);
        return;
      }
      if (tries === 3) expandGroups();      // l'entrée est peut-être dans un groupe replié
      if (++tries < 25) setTimeout(attempt, 120);
    })();
  }

  /* ---- Démarrage ---------------------------------------------------- */

  function start() {
    mount();
    watch();
    openFromHash();
    window.addEventListener('hashchange', openFromHash);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
