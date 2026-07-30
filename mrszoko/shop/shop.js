/* ============================================================================
   Mister Szoko — boutique : confort, pas fondation.
   La page est déjà complète et cliquable quand ce fichier arrive (il est en
   `defer`). Tout ce qu'il fait, un formulaire POST le fait déjà sans lui :
     · ajouter au panier sans recharger la page ;
     · afficher les bons champs selon le mode de livraison et la facture ;
     · appliquer une quantité modifiée sans cliquer sur « actualiser ».
   Si le script ne charge pas, la boutique reste utilisable de bout en bout.
   ============================================================================ */
(function () {
  'use strict';

  var CART = 'ms_cart';

  function cookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  }

  function cartCount() {
    try {
      var c = JSON.parse(cookie(CART) || '{}');
      return Object.keys(c).reduce(function (n, k) { return n + (parseInt(c[k], 10) || 0); }, 0);
    } catch (e) { return null; }
  }

  function paintCount() {
    var n = cartCount();
    if (n === null) return;
    document.querySelectorAll('[data-cart-count]').forEach(function (el) {
      el.textContent = String(n);
      el.classList.toggle('is-zero', n === 0);
    });
  }

  // ---- Ajout au panier sans quitter la page --------------------------------
  // On poste le MÊME formulaire, en arrière-plan. Le serveur reste seul juge
  // du contenu du panier ; on ne fait que lui éviter un rechargement.
  document.querySelectorAll('form[data-add]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.fetch) return;                       // navigateur ancien : POST classique
      ev.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      var label = btn ? btn.textContent : '';
      if (btn) { btn.disabled = true; }
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' },
        redirect: 'follow'
      }).then(function () {
        paintCount();
        if (btn) {
          var done = document.body.getAttribute('data-added') || '✓';
          btn.textContent = done;
          setTimeout(function () { btn.textContent = label; btn.disabled = false; }, 1400);
        }
      }).catch(function () {
        // Réseau indisponible : on laisse le formulaire partir normalement.
        if (btn) btn.disabled = false;
        form.submit();
      });
    });
  });

  // ---- Caisse : n'afficher que les champs réellement exigés ----------------
  var locker = document.querySelector('[data-ship-locker]');
  var courier = document.querySelector('[data-ship-courier]');
  document.querySelectorAll('input[data-ship]').forEach(function (r) {
    r.addEventListener('change', function () {
      if (!locker || !courier) return;
      var isCourier = r.value === 'inpost_courier';
      locker.hidden = isCourier;
      courier.hidden = !isCourier;
    });
  });

  var inv = document.querySelector('input[data-invoice]');
  var invFields = document.querySelector('[data-invoice-fields]');
  if (inv && invFields) {
    inv.addEventListener('change', function () { invFields.hidden = !inv.checked; });
  }

  // ---- Caisse : changer de pays change les transporteurs ET la TVA ---------
  // On recharge la page avec le pays choisi plutôt que de recalculer côté
  // client : le prix ne se décide pas dans le navigateur.
  var country = document.querySelector('select[data-country]');
  if (country) {
    country.addEventListener('change', function () {
      var q = new URLSearchParams(location.search);
      q.set('kraj', country.value);
      location.search = q.toString();
    });
  }

  // ---- Panier : appliquer une quantité dès qu'elle change ------------------
  document.querySelectorAll('.cart-line input[type="number"]').forEach(function (input) {
    var timer = null;
    input.addEventListener('change', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { input.form && input.form.submit(); }, 250);
    });
  });

  paintCount();
})();
