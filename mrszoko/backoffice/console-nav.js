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
    ['pulpit.php',      'Pulpit'],
    ['zamowienia.php',  'Zamówienia'],
    ['faktury.php',     'Faktury'],
    ['poczta.php',      'Poczta'],
    ['produkty.php',    'Produkty'],
    ['magazyn.php',     'Magazyn'],
    ['kontrahenci.php', 'Kontrahenci'],
    ['kraje.php',       'Kraje i VAT'],
    ['rabaty.php',      'Rabaty'],
    ['uzytkownicy.php', 'Użytkownicy'],
    ['audyt.php',       'Audyt i wykresy'],
    ['ustawienia.php',  'Ustawienia integracji'],
    ['../shop/',        'Sklep ↗']
  ];

  /* clé d'URL → libellé exact du bouton dans la barre exportée. */
  var ERP_SCREENS = {
    comms: 'Komunikacja', users: 'Użytkownicy i role', audit: 'Dziennik audytu',
    catalogue: 'Katalog', dash: 'Pulpit sieci'
  };

  /* Ce qui décrit un RÉSEAU DE BOUTIQUES, pas une boutique en ligne. Ces
     écrans viennent de la démonstration franchise d'origine : ils affichent
     des magasins bruxellois, des zones de chalandise et une adoption de
     whitelist qui n'existent pas ici. On ne les efface pas — ce serait
     toucher à l'export — on cesse de les proposer. */
  var HIDDEN = ['Pulpit sieci', 'Sklepy', 'Promocje sieci', 'Strefy zasięgu',
                'Analiza geograficzna', 'Menu i zestawy', 'Dostawy',
                // Remplacés par nos écrans : celui-ci ne savait pas écrire,
                // celui-là ne montrait que le journal sans les chiffres.
                'Użytkownicy i role', 'Dziennik audytu'];

  var NAV_SELECTOR = 'nav.lz';
  var BLOCK_ID = 'wsm-screens';

  /* ---- 1. Le groupe « Sklep » dans la barre ------------------------- */

  function buildBlock() {
    var box = document.createElement('div');
    box.id = BLOCK_ID;

    var head = document.createElement('div');
    head.textContent = 'Webshop';
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

  /** Retire de la barre les entrées qui décrivent un réseau de boutiques. */
  function hideNetworkScreens() {
    var nav = document.querySelector(NAV_SELECTOR);
    if (!nav) return;
    var buttons = nav.querySelectorAll('button');
    for (var i = 0; i < buttons.length; i++) {
      if (HIDDEN.indexOf((buttons[i].textContent || '').trim()) !== -1) {
        buttons[i].style.display = 'none';
      }
    }
  }

  function mount() {
    var nav = document.querySelector(NAV_SELECTOR);
    if (!nav) return false;
    hideNetworkScreens();
    if (document.getElementById(BLOCK_ID)) return true;
    // En tête de barre : c'est ici qu'on travaille, pas dans l'ERC hérité.
    nav.insertBefore(buildBlock(), nav.firstChild ? nav.firstChild.nextSibling : null);
    return true;
  }

  /* React refait le rendu de la barre à chaque changement d'écran et
     emporte notre bloc avec lui. On le remet — c'est moins fragile que
     de patcher l'application exportée, qui serait perdue au prochain
     export. */
  function watch() {
    var obs = new MutationObserver(function () {
      mount();                                   // remet le bloc ET le masquage
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
        // On GARDE « #ekran=… » dans l'adresse : sans elle, un simple
        // rafraîchissement renverrait l'utilisateur au tableau de bord du
        // webshop, alors qu'il travaille dans cet écran-ci.
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
