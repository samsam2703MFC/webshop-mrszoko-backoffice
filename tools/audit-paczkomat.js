// ============================================================================
//  audit-paczkomat.js — le choix du Paczkomat, dans un vrai navigateur.
//
//  POURQUOI UN OUTIL À PART. Les suites PHP lisent du HTML : elles savent
//  dire que le champ est là et que le composant est déclaré. Elles ne savent
//  PAS dire ce qui se passe quand on clique — et tout ce qui compte ici se
//  passe après le clic, dans un composant qui vient d'un autre domaine.
//
//  LES TROIS MONDES, et pourquoi chacun est réel :
//
//   A. LE SCRIPT D'INPOST ARRIVE. La carte s'ouvre, elle a une hauteur, on
//      choisit un point, le champ texte se remplit. C'est le cas nominal, et
//      c'est le seul qu'on pense à essayer.
//   B. LE SCRIPT N'ARRIVE PAS. Bloqueur de publicité, réseau d'entreprise,
//      panne chez eux. Le client clique et il ne se passe rien. Sans message,
//      il croit que la caisse est cassée et il s'en va. La panne doit être
//      ANNONCÉE et le curseur renvoyé sur le champ texte, qui marche toujours.
//   C. PAS DE JAVASCRIPT DU TOUT. Le bloc est rendu `hidden` par le serveur
//      et révélé par le script : sans script, aucun bouton mort ne s'affiche.
//
//  UNE ERREUR QUE CET OUTIL A ATTRAPÉE, et qu'aucune lecture n'aurait vue :
//  en donnant une hauteur au composant, le CSS la donnait AUSSI à la balise
//  inconnue du monde B. La carte ne chargeait pas, le cadre faisait quand
//  même 460 px, le repli mesurait la hauteur et se taisait. D'où `:defined`
//  dans la feuille et `customElements.get()` dans le script.
//
//  Usage :
//    (boutique servie AVEC un jeton geowidget, sinon le bloc n'existe pas)
//    WSM_INPOST_GEOWIDGET_TOKEN=... php -S localhost:8095 router.php
//    node tools/audit-paczkomat.js http://localhost:8095
// ============================================================================
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const SHOP = (process.argv[2] || 'http://localhost:8091').replace(/\/$/, '');
const soucis = [];
const ok = (l, c, got) => {
  console.log((c ? '  ✓ ' : '  ✗ ') + l + (c ? '' : '  (got: ' + JSON.stringify(got) + ')'));
  if (!c) soucis.push(l);
};

// Le bloc de choix ne vit que sur la caisse, et la caisse ne rend ses champs
// de livraison qu'avec un panier. On achète donc, comme un client.
async function panier(page) {
  await page.goto(SHOP + '/', { waitUntil: 'load' });
  await page.locator('form[data-add] button').first().click();
  await page.waitForLoadState('load');
  await page.goto(SHOP + '/kasa', { waitUntil: 'load' });
}

(async () => {
  const nav = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

  // ---- A. Le script d'InPost arrive ---------------------------------------
  console.log('\n-- skrypt InPost dociera --');
  {
    const ctx = await nav.newContext({ viewport: { width: 1400, height: 900 } });
    const page = await ctx.newPage();
    // Le domaine d'InPost est injoignable depuis l'atelier : on sert à sa
    // place ce que fait le vrai script, c'est-à-dire DÉFINIR le composant.
    await page.route('**/geowidget.inpost.pl/**', route => {
      if (route.request().url().endsWith('.css')) return route.fulfill({ contentType: 'text/css', body: '' });
      return route.fulfill({ contentType: 'application/javascript', body: `
        class G extends HTMLElement {
          connectedCallback() { this.innerHTML = '<div style="height:100%">mapa</div>'; }
        }
        customElements.define('inpost-geowidget', G);
      `});
    });
    await panier(page);

    const bloc = page.locator('[data-geo]');
    ok('le bloc « choisir sur la carte » est révélé par JS', await bloc.isVisible());
    await page.locator('[data-geo-open]').click();
    await page.waitForTimeout(2400);          // au-delà du délai du repli (1800 ms)

    const boite = page.locator('[data-geo-box]');
    ok('la carte reste ouverte quand le script est là', await boite.isVisible());
    const h = await boite.evaluate(el => el.getBoundingClientRect().height);
    ok('et elle a une VRAIE hauteur — un composant à 0 px se charge sans se voir',
       h > 200, Math.round(h));
    ok('aucun message de panne affiché', !(await page.locator('[data-geo-fail]').isVisible()));

    await page.evaluate(() => window.wsmGeoPoint({
      name: 'KRA010', address: { line1: 'ul. Testowa 1', line2: '30-001 Kraków' } }));
    ok('choisir un point remplit le champ texte',
       (await page.locator('#f-inpost_point').inputValue()) === 'KRA010',
       await page.locator('#f-inpost_point').inputValue());
    ok('et l\'adresse choisie est rappelée à l\'écran — on relit ce qu\'on a cliqué',
       (await page.locator('[data-geo-chosen]').textContent() || '').includes('Testowa'));
    ok('la carte se referme une fois le point choisi', !(await boite.isVisible()));
    await ctx.close();
  }

  // ---- B. Le script d'InPost n'arrive pas ---------------------------------
  console.log('\n-- skrypt InPost nie dociera (bloker, sieć firmowa, awaria) --');
  {
    const ctx = await nav.newContext({ viewport: { width: 390, height: 844 } });
    const page = await ctx.newPage();
    await page.route('**/geowidget.inpost.pl/**', route => route.abort());
    await panier(page);
    await page.locator('[data-geo-open]').click();
    await page.waitForTimeout(2400);
    ok('la panne est ANNONCÉE, pas silencieuse', await page.locator('[data-geo-fail]').isVisible());
    ok('la boîte vide est refermée — pas de grand cadre sans rien dedans',
       !(await page.locator('[data-geo-box]').isVisible()));
    ok('et le curseur est renvoyé sur le champ qui marche',
       await page.locator('#f-inpost_point').evaluate(el => el === document.activeElement));
    ok('la caisse reste utilisable : le champ texte accepte un code',
       await page.locator('#f-inpost_point').isEditable());
    await ctx.close();
  }

  // ---- C. Sans JavaScript du tout -----------------------------------------
  console.log('\n-- bez JavaScriptu --');
  {
    const ctx = await nav.newContext({ javaScriptEnabled: false, viewport: { width: 1400, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(SHOP + '/kasa', { waitUntil: 'load' });
    const vis = await page.locator('[data-geo]').isVisible().catch(() => false);
    ok('aucun bouton « carte » mort n\'est affiché sans JS', !vis);
    await ctx.close();
  }

  await nav.close();
  console.log('\n' + (soucis.length ? 'SOUCIS: ' + soucis.length : 'aucun souci'));
  process.exit(soucis.length ? 1 : 0);
})();
