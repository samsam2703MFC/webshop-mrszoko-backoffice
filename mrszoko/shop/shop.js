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
  /* Ce que le compteur de l'en-tête ne dit pas assez fort.
     Un chiffre qui passe de 0 à 1 en haut à droite est invisible sur un
     téléphone : le pouce est en bas, le regard sur le bouton. On confirme
     donc à l'endroit où l'on a cliqué, et on offre le chemin vers le panier
     — sans quoi il faut aller le chercher dans l'en-tête. */
  function confirmAdded(form) {
    var host = form.parentNode;
    if (!host) return;
    var note = host.querySelector('.added-note');
    if (!note) {
      var cart = document.querySelector('a.cart-btn');
      note = document.createElement('p');
      note.className = 'added-note';
      note.setAttribute('role', 'status');
      note.appendChild(document.createTextNode(document.body.getAttribute('data-added') || '✓'));
      if (cart) {
        var a = document.createElement('a');
        a.href = cart.getAttribute('href');
        var lbl = cart.querySelector('.cart-label');
        a.textContent = (lbl ? lbl.textContent : '').trim() || 'Koszyk';
        a.textContent += ' →';
        note.appendChild(document.createTextNode(' '));
        note.appendChild(a);
      }
      host.insertBefore(note, form.nextSibling);
    }
    note.hidden = false;
  }

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
        confirmAdded(form);
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

  // ---- Paczkomat : choisir sur la carte plutôt que taper un code ------------
  //
  // Le bloc est rendu `hidden` par le serveur et RÉVÉLÉ ici : sans JavaScript,
  // un bouton « choisir sur la carte » qui n'ouvre rien serait pire que pas de
  // bouton du tout. Le champ texte reste la source de vérité — la carte ne
  // fait que le remplir, et on peut toujours corriger à la main.
  var geo = document.querySelector('[data-geo]');
  if (geo) {
    var champ = document.querySelector('#f-inpost_point');
    var boite = geo.querySelector('[data-geo-box]');
    var choisi = geo.querySelector('[data-geo-chosen]');
    geo.hidden = false;

    var souci = geo.querySelector('[data-geo-fail]');

    geo.querySelector('[data-geo-open]').addEventListener('click', function () {
      boite.hidden = !boite.hidden;
      if (boite.hidden) return;

      // LE SCRIPT D'INPOST VIENT D'UN AUTRE DOMAINE, ET IL ARRIVE QU'IL NE
      // VIENNE PAS : bloqueur de publicité, réseau d'entreprise, panne chez
      // eux. On clique « choisir sur la carte » et il ne se passe RIEN.
      // On le dit, et on renvoie au champ texte qui, lui, marche toujours.
      //
      // ON DEMANDE AU NAVIGATEUR, ON NE MESURE PAS. Le premier jet regardait
      // la hauteur de la boîte : c'était juste tant que rien ne dimensionnait
      // le composant, et faux dès qu'on lui a donné une hauteur en CSS — la
      // balise inconnue prenait la hauteur, le repli mesurait 460 px et se
      // taisait. `customElements.get()` répond à la vraie question : le script
      // a-t-il défini le composant, oui ou non.
      setTimeout(function () {
        if (boite.hidden) return;
        var pret = window.customElements && window.customElements.get('inpost-geowidget');
        // Défini mais n'ayant rien dessiné compte aussi pour une panne.
        var vide = !pret || boite.getBoundingClientRect().height < 20;
        if (souci) souci.hidden = !vide;
        if (vide) {
          boite.hidden = true;
          if (champ) champ.focus();
        }
      }, 1800);
    });

    // Le composant InPost appelle cette fonction par son NOM (attribut
    // onpoint), pas par un écouteur : elle doit donc être globale.
    window.wsmGeoPoint = function (point) {
      var d = (point && point.detail) ? point.detail : point;
      var code = d && (d.name || d.id);
      if (!code) return;
      if (champ) {
        champ.value = code;
        // `input` et pas seulement l'affectation : la validation du navigateur
        // et tout écouteur en aval doivent voir la valeur changer.
        champ.dispatchEvent(new Event('input', { bubbles: true }));
      }
      if (choisi) {
        var adr = [d.address && d.address.line1, d.address && d.address.line2]
          .filter(Boolean).join(', ');
        choisi.textContent = code + (adr ? ' — ' + adr : '');
        choisi.hidden = false;
      }
      boite.hidden = true;
    };
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
