/* =====================================================================
   api-config.js — résolution de l'API pour la Console marque (mrszoko)
   =====================================================================
   L'app est autonome (hors webshop) : servie sous  <origine>/mrszoko/  et
   l'API PHP est déployée en  ./api  (same-origin). On dérive donc la base
   API = <dossier de la page>/api, quel que soit le chemin de montage.

   • Sur *.github.io ou si l'API ne répond pas → mode démo (seed en mémoire).
   • Le jeton admin est mémorisé par origine (localStorage 'adminToken').
   • Overrides de test :  ?api=<baseUrl>  et  ?token=<adminToken>.
   ===================================================================== */
(function () {
  var onGitHubPages = /\.github\.io$/i.test(location.hostname);

  // Base de l'API = le dossier courant de la page + /api (same-origin).
  // Ex. servi sous  /mrszoko/  → API sous  /mrszoko/api.
  var dir = location.pathname.replace(/[^/]*$/, '').replace(/\/$/, '');
  var base = onGitHubPages ? null : (location.origin + dir + '/api');

  var token = '';
  try { token = localStorage.getItem('adminToken') || ''; } catch (e) {}

  // Overrides explicites par query (tests / première connexion).
  try {
    var q = new URLSearchParams(location.search);
    if (q.get('api')) base = q.get('api');
    if (q.get('token')) { token = q.get('token'); try { localStorage.setItem('adminToken', token); } catch (e) {}
      // Le jeton ne doit pas rester dans l'URL (historique navigateur, logs
      // serveur, copier-coller de lien) : on le retire une fois mémorisé.
      try { q.delete('token'); var qs = q.toString();
        history.replaceState({}, '', location.pathname + (qs ? '?' + qs : '') + location.hash); } catch (e) {}
    }
  } catch (e) {}

  window.__FR = { base: base, token: token };
})();
