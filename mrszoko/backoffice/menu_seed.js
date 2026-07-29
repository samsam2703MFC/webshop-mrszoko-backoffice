// Seed catalog for the Menu Builder — the ONLY place default data lives.
// Menu trigger (logique b): the CATEGORY arms (menu_default), the PRODUCT can override
// (menu_override 'on'/'off'/null=inherit). Effective menu resolved server-side.
//   ws_categories : menu_default (0/1)
//   ws_products   : menu_override ('on'|'off'|null), price (basePrice), baseCost
//   ws_bundles / ws_bundle_slots / ws_bundle_slot_choices : formule tree
export const SEED = {
  _categories: {
    'Menus & formules': { menu_default: 1 },
    'Traiteur': { menu_default: 1 },
    'Pâtisserie fraîche': { menu_default: 0 },
    'Boulangerie': { menu_default: 0 },
    'Chocolaterie': { menu_default: 0 },
    'Glaces': { menu_default: 0 }
  },
  'p-midi': { productName: 'Menu du Midi — L\'Atelier', category: 'Menus & formules', menuOverride: 'on', basePrice: 8.50, baseCost: 2.40, bundles: [
    { id:'b1', name:'Formule Complète', description:'Plat + boisson + dessert au choix', price_modifier:4.50, sort_order:0, active:true, slots:[
      { id:'s1', label:'Le plat', required:true, kind:'single', min_select:1, max_select:1, sort_order:0, active:true, choices:[
        { id:'c1', label:'Quiche lorraine', img:'a', delta:0, cost:1.10, sort_order:0, active:true },
        { id:'c2', label:'Croque signature', img:'b', delta:1.50, cost:1.60, sort_order:1, active:true },
        { id:'c3', label:'Salade César', img:'d', delta:0, cost:1.30, sort_order:2, active:true } ] },
      { id:'s2', label:'La boisson', required:true, kind:'single', min_select:1, max_select:1, sort_order:1, active:true, choices:[
        { id:'c4', label:'Eau plate 50cl', img:'', delta:0, cost:0.30, sort_order:0, active:true },
        { id:'c5', label:'Soft 33cl', img:'', delta:0.50, cost:0.45, sort_order:1, active:true },
        { id:'c6', label:'Jus pressé maison', img:'c', delta:1.20, cost:0.90, sort_order:2, active:true } ] },
      { id:'s3', label:'Suppléments gourmands', required:false, kind:'multi', min_select:0, max_select:2, sort_order:2, active:true, choices:[
        { id:'c7', label:'Cookie maison', img:'a', delta:2.00, cost:0.70, sort_order:0, active:true },
        { id:'c8', label:'Part de tarte', img:'b', delta:2.80, cost:1.10, sort_order:1, active:true },
        { id:'c9', label:'Café gourmand', img:'', delta:3.20, cost:1.40, sort_order:2, active:false } ] } ] },
    { id:'b2', name:'Formule Enfant', description:'Petit plat + sirop + surprise', price_modifier:-1.00, sort_order:1, active:true, slots:[
      { id:'s4', label:'Le petit plat', required:true, kind:'single', min_select:1, max_select:1, sort_order:0, active:true, choices:[
        { id:'c10', label:'Mini croque', img:'b', delta:0, cost:0.90, sort_order:0, active:true },
        { id:'c11', label:'Nuggets maison', img:'a', delta:0, cost:1.10, sort_order:1, active:true } ] },
      { id:'s5', label:'La boisson', required:true, kind:'single', min_select:1, max_select:1, sort_order:1, active:true, choices:[
        { id:'c12', label:'Sirop à l\'eau', img:'', delta:0, cost:0.20, sort_order:0, active:true },
        { id:'c13', label:'Jus de pomme', img:'c', delta:0, cost:0.40, sort_order:1, active:true } ] } ] } ] },
  'p-gouter': { productName: 'Formule Goûter — L\'Atelier', category: 'Menus & formules', menuOverride: 'on', basePrice: 3.20, baseCost: 0.90, bundles: [
    { id:'gb1', name:'Duo Goûter', description:'Une viennoiserie + une boisson chaude', price_modifier:1.20, sort_order:0, active:true, slots:[
      { id:'gs1', label:'La viennoiserie', required:true, kind:'single', min_select:1, max_select:1, sort_order:0, active:true, choices:[
        { id:'gc1', label:'Pain au chocolat', img:'b', delta:0, cost:0.50, sort_order:0, active:true },
        { id:'gc2', label:'Croissant amandes', img:'a', delta:0.60, cost:0.65, sort_order:1, active:true } ] },
      { id:'gs2', label:'La boisson chaude', required:true, kind:'single', min_select:1, max_select:1, sort_order:1, active:true, choices:[
        { id:'gc3', label:'Café', img:'', delta:0, cost:0.35, sort_order:0, active:true },
        { id:'gc4', label:'Chocolat chaud', img:'d', delta:0.50, cost:0.55, sort_order:1, active:true } ] } ] } ] },
  'p-cafe': { productName: 'Café Gourmand — L\'Atelier', category: 'Menus & formules', menuOverride: 'off', basePrice: 6.50, baseCost: 2.10, bundles: [] },
  'p-brunch': { productName: 'Brunch du Week-end — L\'Atelier', category: 'Menus & formules', menuOverride: null, basePrice: 18.00, baseCost: 5.50, bundles: [] }
};
