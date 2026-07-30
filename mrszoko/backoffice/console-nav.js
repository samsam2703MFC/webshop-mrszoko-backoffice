/* =====================================================================
   console-nav.js — le lanceur des écrans serveur de la console.

   La console principale est une application exportée par Claude Design ;
   les écrans qui touchent à l'argent et aux données (Zamówienia, Poczta,
   Produkty, Ustawienia…) sont des pages PHP rendues côté serveur, à côté.
   Jusqu'ici on n'y accédait qu'en tapant l'URL — ce bouton les rend
   simplement atteignables, sans toucher au fichier exporté.

   Rien d'autre : pas de mise en page, pas d'état, pas de dépendance.
   ===================================================================== */
(function () {
  'use strict';

  var SCREENS = [
    ['zamowienia.php',  'Zamówienia',   'Płatności, wysyłka, historia'],
    ['poczta.php',      'Poczta',       'Wiadomości i szablony'],
    ['produkty.php',    'Produkty',     'Ceny, stany, zdjęcia'],
    ['kontrahenci.php', 'Kontrahenci',  'NIP i VAT UE (VIES)'],
    ['kraje.php',       'Kraje',        'Gdzie sprzedajemy, jaki VAT'],
    ['rabaty.php',      'Rabaty',       'Procent według wagi'],
    ['ustawienia.php',  'Ustawienia',   'tpay, InPost, poczta'],
    ['../shop/',        'Sklep ↗',      'Strona publiczna']
  ];

  function build() {
    if (document.getElementById('wsm-launcher')) return;

    var css = document.createElement('style');
    css.textContent =
      '#wsm-launcher{position:fixed;right:16px;bottom:16px;z-index:9999;font-family:var(--font-sans,system-ui)}' +
      '#wsm-launcher>summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:8px;' +
        'min-height:48px;padding:0 18px;border-radius:999px;background:var(--choco-800,#3a2418);' +
        'color:var(--cream-50,#fff);font-size:14px;font-weight:600;box-shadow:0 6px 20px rgba(0,0,0,.25)}' +
      '#wsm-launcher>summary::-webkit-details-marker{display:none}' +
      '#wsm-launcher[open]>summary{border-radius:14px}' +
      '#wsm-launcher nav{position:absolute;right:0;bottom:56px;width:min(88vw,300px);padding:8px;' +
        'background:var(--surface-card,#fff);border:1px solid var(--border-subtle,#e6ded6);border-radius:16px;' +
        'box-shadow:0 18px 40px rgba(0,0,0,.22);max-height:70vh;overflow:auto}' +
      '#wsm-launcher nav a{display:block;padding:11px 12px;border-radius:11px;text-decoration:none;' +
        'color:var(--text-strong,#2d1c12)}' +
      '#wsm-launcher nav a:hover{background:var(--surface-raised,#f6f1ec)}' +
      '#wsm-launcher nav b{display:block;font-size:14.5px;font-weight:600}' +
      '#wsm-launcher nav span{display:block;font-size:12px;color:var(--text-muted,#8a7768);margin-top:2px}';
    document.head.appendChild(css);

    var d = document.createElement('details');
    d.id = 'wsm-launcher';
    var s = document.createElement('summary');
    s.textContent = 'Ekrany ▾';
    d.appendChild(s);

    var nav = document.createElement('nav');
    SCREENS.forEach(function (row) {
      var a = document.createElement('a');
      a.href = row[0];
      if (row[0].charAt(0) === '.') { a.target = '_blank'; a.rel = 'noopener'; }
      var b = document.createElement('b'); b.textContent = row[1];
      var sp = document.createElement('span'); sp.textContent = row[2];
      a.appendChild(b); a.appendChild(sp);
      nav.appendChild(a);
    });
    d.appendChild(nav);
    document.body.appendChild(d);

    // Un clic ailleurs referme le panneau : sur téléphone il couvre l'écran.
    document.addEventListener('click', function (e) {
      if (d.open && !d.contains(e.target)) d.open = false;
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', build);
  } else {
    build();
  }
})();
