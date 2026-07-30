// Server-simulation data layer for the back office.
// Every domain table lives here (seed), is persisted to localStorage (the DB),
// and is read by the pages via window.BOServer.table(name). No data is hardcoded in the UI.
(function(){
  var LS = 'ws_bo_store_v2';
  var SEED = {
    "kpis": [
      {label:'Obrót sieci (miesiąc)',value:'428 k€',valColor:'var(--color-text)',delta:'▲ +6,4 %',deltaColor:'#2d7a3e'},
      {label:'Obrót sklepów',value:'306 k€',valColor:'var(--color-primary)',delta:'▲ +4,8 %',deltaColor:'#2d7a3e'},
      {label:'Obrót dostaw biurowych',value:'122 k€',valColor:'#C87A3F',delta:'▲ +11 %',deltaColor:'#2d7a3e'},
      {label:'Aktywne sklepy',value:'14 / 15',valColor:'var(--color-text)',delta:'▲ +1 w tym kw.',deltaColor:'#2d7a3e'},
      {label:'Zamówienia dnia',value:'512',valColor:'var(--color-text)',delta:'▲ +38 vs wczoraj',deltaColor:'#2d7a3e'},
      {label:'Adopcja whitelisty',value:'82 %',valColor:'var(--color-text)',delta:'▼ −3 pkt',deltaColor:'var(--color-primary)'},
    ],
    "catchment": [
      {id:1,name:'Bruxelles Capitale (19 gmin)',postcodes:'1000 · 1020 · 1030 · 1040 · 1050',exclusive:true,active:true,shop_id:null,shop_name:''},
      {id:2,name:'Brabant flamand — peryferie',postcodes:'1600 · 1700 · 1800 · 3000',exclusive:true,active:true,shop_id:null,shop_name:''},
    ],
    "shops": [
      {id:'bxl',nom:'L\'Atelier — Bruxelles-Centre',ville:'Bruxelles 1000',web:true,contrat:'Oddział',act:true,caShop:29800,caOffice:8400,adoption:96,accent:'var(--color-primary)'},
      {id:'and',nom:'L\'Atelier — Anderlecht',ville:'Anderlecht 1070',web:true,contrat:'Franczyza',act:true,caShop:18600,caOffice:6200,adoption:88,accent:'#E8A15C'},
      {id:'ucc',nom:'L\'Atelier — Uccle',ville:'Uccle 1180',web:true,contrat:'Franczyza',act:true,caShop:22100,caOffice:9400,adoption:79,accent:'#8C4A2F'},
      {id:'sch',nom:'L\'Atelier — Schaerbeek',ville:'Schaerbeek 1030',web:false,contrat:'Franczyza',act:true,caShop:0,caOffice:0,adoption:0,accent:'#E8A15C'},
      {id:'lv',nom:'L\'Atelier — Louvain',ville:'Louvain 3000',web:true,contrat:'Master',act:false,caShop:14200,caOffice:5200,adoption:71,accent:'#8C4A2F'},
    ],
    "catalog": [
      {cat:'Pieczywo',prods:[
        {nom:'Bagietka tradycyjna',prix:1.35,statut:'Opublikowany',bw:true,bm:true,ad:96},
        {nom:'Czekoladowa drożdżówka',prix:1.60,statut:'Opublikowany',bw:true,bm:false,ad:74},
      ]},
      {cat:'Ciasta świeże',prods:[
        {nom:'Ekler czekoladowy',prix:3.50,statut:'Opublikowany',bw:true,bm:true,ad:88},
        {nom:'Tarta truskawkowa',prix:4.20,statut:'Sezonowy',saison:'Lato',bw:true,bm:false,ad:52},
        {nom:'Rolada firmowa',prix:24.00,statut:'Opublikowany',saison:'Boże Narodzenie',bw:true,bm:true,ad:100},
      ]},
      {cat:'Czekolada',prods:[
        {nom:'Makaroniki (pudełko 24)',prix:19.90,statut:'Opublikowany',bw:true,bm:false,ad:64},
      ]},
      {cat:'Katering',prods:[
        {nom:'Quiche lorraine',prix:5.80,statut:'Szkic',bw:false,bm:false,ad:22},
        {nom:'Foie gras mi-cuit',prix:28.00,statut:'Opublikowany',bw:true,bm:false,ad:41},
      ]},
      {cat:'Lody',prods:[
        {nom:'Lody rzemieślnicze',prix:6.50,statut:'Opublikowany',saison:'Lato',bw:false,bm:false,ad:30},
      ]},
    ],
    "vouchers": [
      {code:'MARQUE15',valeur:'−15 % na ciasta',type:'Koszyk',validite:'kampania letnia'},
      {code:'BIENVENUE',valeur:'Onboarding B2B',type:'add_office',validite:'bezterminowo'},
      {code:'RENTREE10',valeur:'−10 € od 50 €',type:'Kwota',validite:'wrz.'},
    ],
    "pricing_rules": [
      {nom:'Wiosenne menu marki',cible:'Menu',effet:'19,90 €'},
      {nom:'Cennik sieciowy ciast',cible:'Ciasta świeże',effet:'cena stała'},
      {nom:'Happy hour sieci',cible:'Pieczywo 18–19',effet:'−20 %'},
    ],
    "params": [
      {cle:'admin.schema_reports',type:'bool',def:true},
      {cle:'webshop.enabled',type:'bool',def:true},
      {cle:'nav.icon_back',type:'text',val:'arrow-left'},
      {cle:'delivery.enabled',type:'bool',def:true},
      {cle:'order.cutoff_default',type:'text',val:'17:00'},
      {cle:'brand.support_url',type:'text',val:'https://aide.latelierby.be'},
    ],
    "email_templates": [
      {cle:'order_confirm',langue:'PL',sujet:'Twoje zamówienie {{commande_ref}} jest potwierdzone'},
      {cle:'order_ready',langue:'PL',sujet:'Twoje zamówienie jest gotowe'},
      {cle:'invoice',langue:'PL',sujet:'Faktura {{commande_ref}}'},
      {cle:'office_onboarding',langue:'PL',sujet:'Witamy — Twoje konto {{bureau}}'},
      {cle:'office_reject',langue:'PL',sujet:'Twój wniosek o przyłączenie'},
    ],
    "users": [
      {nom:'Sophie Renard',email:'sophie.renard@latelierby.be',role:'Centrala',portee:'Cała sieć',act:true},
      {nom:'Thomas Legrand',email:'thomas.legrand@latelierby.be',role:'Franczyza',portee:'Bruxelles-Centre',act:true},
      {nom:'Marek Kowalski',email:'m.kowalski@latelierby.be',role:'Franczyza',portee:'Anderlecht, Uccle',act:true},
      {nom:'Julie Peeters',email:'j.peeters@latelierby.be',role:'Franczyza',portee:'Louvain',act:false},
    ],
    "audit": [
      {ts:'17/07 14:22',user:'Sophie Renard',verb:'Zmiana',entity:'ws_products #128 (brand_mandatory)',shop:'Sieć'},
      {ts:'17/07 13:05',user:'Thomas Legrand',verb:'Utworzenie',entity:'ws_vouchers BXL10',shop:'Bruxelles-Centre'},
      {ts:'17/07 11:40',user:'Sophie Renard',verb:'Zmiana',entity:'ws_param webshop.enabled',shop:'Sieć'},
      {ts:'16/07 18:12',user:'Marek Kowalski',verb:'Usunięcie',entity:'ws_office_delivery_sites #44',shop:'Anderlecht'},
      {ts:'16/07 09:30',user:'Sophie Renard',verb:'Utworzenie',entity:'bo_users j.peeters',shop:'Louvain'},
    ],
    "fr_alertes": [
      {color:'var(--color-primary)',titre:'Przekroczony limit — Belga SPRL',detail:'4 120 € / limit 4 000 € · zamówienie zablokowane'},
      {color:'var(--color-primary)',titre:'Incydent — Café Belga',detail:'Uszkodzona paczka · nota 24 € czeka na decyzję'},
      {color:'#c9a24b',titre:'Limit w 92 % — Delcourt',detail:'2 760 € / 3 000 € · do obserwacji'},
      {color:'#c9a24b',titre:'Odchylenie km — Trasa Uccle / Waterloo',detail:'+24 % vs plan · objazd Waterloo nieplanowany'},
    ],
    "fr_live_drivers": [
      {color:'#8D1D2C',nom:'Marek Kowalski',info:'BXL-Centre · Renault chłodnia',avancement:'3/4'},
      {color:'#3B3468',nom:'Julien Dubois',info:'Południe · Iveco Daily',avancement:'1/3'},
    ],
    "fr_clients": [
      {raison:'Le Cirio SA',code:'CL-0021',seg:'horeca',statut:'aktywny',tva:'BE 0421.111.222',paiement:'30 dni koniec mies.',plafond:6000,encours:3200,franco:'250 €',remise:'8 %',fact:'Miesięczna',points:[
        {libelle:'Brasserie — wejście od tyłu',adresse:'Rue de la Bourse 18, 1000 Bruxelles',fenetre:'08:00–11:00',jours:'Pn Wt Śr Cz Pt So',validation:'QR',marge:230},
      ]},
      {raison:'Rocco Forte',code:'CL-0044',seg:'horeca',statut:'aktywny',tva:'BE 0455.222.333',paiement:'30 dni',plafond:8000,encours:2600,franco:'300 €',remise:'10 %',fact:'Tygodniowa',points:[
        {libelle:'Kuchnia — rampa serwisowa',adresse:'Rue de l\'Amigo 1-3, 1000 Bruxelles',fenetre:'07:30–10:00',jours:'Pn Wt Śr Cz Pt',validation:'PIN',marge:205},
      ]},
      {raison:'Belga SPRL',code:'CL-0052',seg:'horeca',statut:'zawieszony',tva:'BE 0466.333.444',paiement:'7 dni',plafond:4000,encours:4120,franco:'—',remise:'5 %',fact:'Za dostawę',points:[
        {libelle:'Taras — dostęp Flagey',adresse:'Place Eugène Flagey 18, 1050 Ixelles',fenetre:'09:00–11:30',jours:'Wt Śr Cz Pt So',validation:'Podpis',marge:60},
      ]},
      {raison:'Dandoy',code:'CL-0060',seg:'retail',statut:'aktywny',tva:'BE 0401.444.555',paiement:'30 dni',plafond:5000,encours:1900,franco:'200 €',remise:'6 %',fact:'Miesięczna',points:[
        {libelle:'Sklep Sablon — tył',adresse:'Rue Charles Buls 14, 1000 Bruxelles',fenetre:'08:00–10:30',jours:'Pn Śr Pt',validation:'QR',marge:180},
      ]},
      {raison:'KBC Group',code:'CL-0071',seg:'corporate',statut:'aktywny',tva:'BE 0403.227.515',paiement:'30 dni koniec mies.',plafond:12000,encours:5400,franco:'400 €',remise:'12 %',fact:'Miesięczna',points:[
        {libelle:'Kafeteria HQ — hala dostaw',adresse:'Havenlaan 2, 3000 Leuven',fenetre:'07:00–09:00',jours:'Pn Wt Śr Cz Pt',validation:'PIN',marge:-15},
      ]},
      {raison:'Événements Sud',code:'CL-0088',seg:'event',statut:'prospekt',tva:'BE 0788.555.666',paiement:'Gotówka',plafond:2000,encours:0,franco:'—',remise:'0 %',fact:'Za dostawę',points:[
        {libelle:'Zamek — dostęp kateringu',adresse:'Chaussée de Bruxelles 100, 1410 Waterloo',fenetre:'11:00–13:00',jours:'So Nd',validation:'Zostawienie',marge:-78},
      ]},
    ],
    "fr_incidents": [
      {type:'Uszkodzona paczka',point:'Café Belga · Ixelles',heure:'dziś 09:12',statut:'Do obsłużenia',icon:'!',iconBg:'#fbe9eb',iconColor:'var(--color-primary)',ref:'INC-2026-0412',geo:'50.8275, 4.3705',horodatage:'17 lip 2026 09:12',chauffeur:'Marek Kowalski',impact:'24 €',impactRef:'szacowana nota',description:'Pojemnik izotermiczny uderzony przy rozładunku. 2 słoiki konfitur stłuczone. Zdjęcie na miejscu, odbiór odrzucił pozycję.',statutColor:'var(--color-primary)'},
      {type:'Brakująca paczka',point:'Hôtel Amigo · Sablon',heure:'dziś 08:40',statut:'Do obsłużenia',icon:'?',iconBg:'var(--color-background-secondary)',iconColor:'var(--color-text-muted)',ref:'INC-2026-0411',geo:'50.8451, 4.3520',horodatage:'17 lip 2026 08:40',chauffeur:'Marek Kowalski',impact:'46 €',impactRef:'ponowna dostawa',description:'1 oczekiwana paczka nieobecna przy skanie zdawczym. Rozbieżność na liście załadunku.',statutColor:'var(--color-text-muted)'},
      {type:'Dostawa odrzucona',point:'Event Château · Waterloo',heure:'wczoraj 12:58',statut:'W trakcie',icon:'✕',iconBg:'#fbe9eb',iconColor:'var(--color-primary)',ref:'INC-2026-0407',geo:'50.7147, 4.3990',horodatage:'16 lip 2026 12:58',chauffeur:'Julien Dubois',impact:'40 €',impactRef:'utracony towar',description:'Przyjazd poza oknem czasowym (13:12 vs 11:00–13:00). Klient nieobecny, zostawienie odrzucone.',statutColor:'var(--color-primary)'},
      {type:'Zwrot kaucji',point:'Maison Dandoy · Sablon',heure:'wczoraj 11:20',statut:'Rozwiązany',icon:'↩',iconBg:'#eaf5ec',iconColor:'#2d7a3e',ref:'INC-2026-0403',geo:'50.8410, 4.3560',horodatage:'16 lip 2026 11:20',chauffeur:'Sofie Peeters',impact:'0 €',impactRef:'bez wpływu',description:'3 pojemniki kaucyjne odebrane w punkcie. Uzgodnienie OK.',statutColor:'#2d7a3e'},
    ],
    "fr_rentabilite": [
      {nom:'Trasa Bruxelles-Centre',sites:[
        {nom:'Brasserie Le Cirio',offices:[{nom:'Kuchnia parter',ca:520,couts:210},{nom:'Bar piętro',ca:300,couts:150}]},
        {nom:'Hôtel Nord',offices:[{nom:'Recepcja',ca:580,couts:312}]},
      ]},
      {nom:'Trasa Południe',sites:[
        {nom:'Café des Arts',offices:[{nom:'Sala',ca:415,couts:413}]},
        {nom:'Résidence Les Tilleuls',offices:[{nom:'Recepcja',ca:260,couts:180}]},
      ]},
      {nom:'Trasa Wschód',sites:[
        {nom:'Traiteur Piotrowski',offices:[{nom:'Pracownia',ca:740,couts:360}]},
      ]},
    ],
    // --- Delivery module (mirror of the API shape: SRV('deliveries') etc.) ---
    // Dev fallback only — when the API is live these come from wsm_deliveries.
    "deliveries": [
      {id:1,ref:'LIV-2026-0001',client:'Le Cirio SA',point:'Brasserie — wejście od tyłu',driver:'Marek Kowalski',driver_color:'#8D1D2C',round:'Trasa Bruxelles-Centre',status:'livrée',window:'08:00–11:00',validation:'QR',confirm_code:'QR-8842',confirmed:1,ca:520,couts:210,marge:310},
      {id:2,ref:'LIV-2026-0002',client:'Rocco Forte',point:'Kuchnia — rampa serwisowa',driver:'Marek Kowalski',driver_color:'#8D1D2C',round:'Trasa Bruxelles-Centre',status:'en_cours',window:'07:30–10:00',validation:'PIN',confirm_code:'',confirmed:0,ca:300,couts:150,marge:150},
      {id:3,ref:'LIV-2026-0003',client:'Dandoy',point:'Sklep Sablon — tył',driver:'Julien Dubois',driver_color:'#3B3468',round:'Trasa Południe',status:'assignée',window:'08:00–10:30',validation:'QR',confirm_code:'',confirmed:0,ca:415,couts:260,marge:155},
      {id:4,ref:'LIV-2026-0004',client:'KBC Group',point:'Kafeteria HQ — hala dostaw',driver:'',driver_color:'#8D1D2C',round:'',status:'planifiée',window:'07:00–09:00',validation:'PIN',confirm_code:'',confirmed:0,ca:580,couts:312,marge:268},
    ],
    "drivers": [
      {id:1,nom:'Marek Kowalski',info:'BXL-Centre · Renault chłodnia',color:'#8D1D2C'},
      {id:2,nom:'Julien Dubois',info:'Południe · Iveco Daily',color:'#3B3468'},
      {id:3,nom:'Sofie Peeters',info:'Wschód · Renault Kangoo',color:'#2d7a3e'},
    ],
    "delivery_clients": [
      {id:1,code:'CL-0021',raison:'Le Cirio SA',seg:'horeca',statut:'aktywny',points:[{id:1,libelle:'Brasserie — wejście od tyłu',adresse:'Rue de la Bourse 18, 1000 Bruxelles',fenetre:'08:00–11:00',validation:'QR'}]},
      {id:2,code:'CL-0044',raison:'Rocco Forte',seg:'horeca',statut:'aktywny',points:[{id:2,libelle:'Kuchnia — rampa serwisowa',adresse:'Rue de l\'Amigo 1-3, 1000 Bruxelles',fenetre:'07:30–10:00',validation:'PIN'}]},
      {id:4,code:'CL-0060',raison:'Dandoy',seg:'retail',statut:'aktywny',points:[{id:4,libelle:'Sklep Sablon — tył',adresse:'Rue Charles Buls 14, 1000 Bruxelles',fenetre:'08:00–10:30',validation:'QR'}]},
    ],
    "incidents": [
      {id:1,ref:'INC-2026-0412',type:'Uszkodzona paczka',point:'Café Belga · Ixelles',statut:'Do obsłużenia',impact:'24 €',delivery_ref:'LIV-2026-0002'},
      {id:2,ref:'INC-2026-0411',type:'Brakująca paczka',point:'Hôtel Amigo · Sablon',statut:'Do obsłużenia',impact:'46 €',delivery_ref:null},
      {id:3,ref:'INC-2026-0407',type:'Dostawa odrzucona',point:'Event Château · Waterloo',statut:'W trakcie',impact:'40 €',delivery_ref:null},
      {id:4,ref:'INC-2026-0403',type:'Zwrot kaucji',point:'Maison Dandoy · Sablon',statut:'Rozwiązany',impact:'0 €',delivery_ref:'LIV-2026-0001'},
    ],
  };
  var DB = null;
  function read(){ try { var r = localStorage.getItem(LS); if (r) return JSON.parse(r); } catch(e){} return null; }
  function persist(){ try { localStorage.setItem(LS, JSON.stringify(DB)); } catch(e){} return DB; }
  function ensure(){ if (DB) return DB; DB = read(); if (!DB){ DB = JSON.parse(JSON.stringify(SEED)); } else { for (var k in SEED){ if (!(k in DB)) DB[k] = JSON.parse(JSON.stringify(SEED[k])); } } persist(); return DB; }
  window.BOServer = {
    table: function(n){ var db = ensure(); return db[n] ? JSON.parse(JSON.stringify(db[n])) : []; },
    all: function(){ return JSON.parse(JSON.stringify(ensure())); },
    getParam: function(key, dflt){ var db = ensure(); var rows = db.params || []; for (var i=0;i<rows.length;i++){ if (rows[i].cle===key){ var r=rows[i]; return (r.val!==undefined ? r.val : (r.def!==undefined ? r.def : dflt)); } } return dflt; },
    setParam: function(key, val){ ensure(); var rows = DB.params || (DB.params = []); var found=false; for (var i=0;i<rows.length;i++){ if (rows[i].cle===key){ rows[i].val=val; found=true; } } if (!found) rows.push({cle:key, type:'bool', val:val}); return persist(); },
    save: function(n, rows){ ensure(); DB[n] = JSON.parse(JSON.stringify(rows)); return persist(); },
    reset: function(){ DB = JSON.parse(JSON.stringify(SEED)); return persist(); },
    // Charge la vraie donnée depuis l'API PHP (/franchisor/*) EN MÉMOIRE, avec
    // repli seed par table : toute table absente/erreur/401 garde le seed, donc
    // le rendu ne casse jamais. Ne persiste pas l'API dans localStorage (pas de
    // cache périmé). À appeler AVANT le boot du runtime (données prêtes au 1er rendu).
    hydrate: function(){
      var fr = (typeof window !== 'undefined' && window.__FR) || {};
      if (!fr.base) return Promise.resolve(false);
      ensure();
      var MAP = { catchment:'catchment', kpis:'kpis', shops:'shops', catalog:'catalog', vouchers:'vouchers',
                  pricing_rules:'pricing-rules', params:'params',
                  email_templates:'email-templates', users:'users', audit:'audit',
                  // Delivery module (wsm_deliveries / wsm_drivers / wsm_clients / wsm_incidents)
                  deliveries:'deliveries', drivers:'drivers', delivery_clients:'delivery-clients', incidents:'incidents' };
      var headers = fr.token ? { 'X-Admin-Token': fr.token } : {};
      var jobs = Object.keys(MAP).map(function(key){
        return fetch(fr.base + '/franchisor/' + MAP[key], { headers: headers, credentials: 'omit' })
          .then(function(r){ return r.ok ? r.json() : null; })
          .then(function(data){ if (Array.isArray(data)) DB[key] = data; })
          .catch(function(){ /* garde le seed pour cette table */ });
      });
      return Promise.all(jobs).then(function(){ return true; });
    }
  };
})();
