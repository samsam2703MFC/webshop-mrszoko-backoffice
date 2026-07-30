// Seed catalog for the Menu Builder — the ONLY place default data lives.
// Menu trigger (logique b): the CATEGORY arms (menu_default), the PRODUCT can override
// (menu_override 'on'/'off'/null=inherit). Effective menu resolved server-side.
//   ws_categories : menu_default (0/1)
//   ws_products   : menu_override ('on'|'off'|null), price (basePrice), baseCost
//   ws_bundles / ws_bundle_slots / ws_bundle_slot_choices : formule tree
export const SEED = {
  _categories: {
    'Menu i zestawy': { menu_default: 1 },
    'Katering': { menu_default: 1 },
    'Ciasta świeże': { menu_default: 0 },
    'Pieczywo': { menu_default: 0 },
    'Czekolada': { menu_default: 0 },
    'Lody': { menu_default: 0 }
  },
  'p-midi': { productName: 'Menu lunchowe — Mister Szoko', category: 'Menu i zestawy', menuOverride: 'on', basePrice: 8.50, baseCost: 2.40, bundles: [
    { id:'b1', name:'Zestaw pełny', description:'Danie + napój + deser do wyboru', price_modifier:4.50, sort_order:0, active:true, slots:[
      { id:'s1', label:'Danie', required:true, kind:'single', min_select:1, max_select:1, sort_order:0, active:true, choices:[
        { id:'c1', label:'Quiche lorraine', img:'a', delta:0, cost:1.10, sort_order:0, active:true },
        { id:'c2', label:'Tost firmowy', img:'b', delta:1.50, cost:1.60, sort_order:1, active:true },
        { id:'c3', label:'Sałatka Cezar', img:'d', delta:0, cost:1.30, sort_order:2, active:true } ] },
      { id:'s2', label:'Napój', required:true, kind:'single', min_select:1, max_select:1, sort_order:1, active:true, choices:[
        { id:'c4', label:'Woda niegazowana 50cl', img:'', delta:0, cost:0.30, sort_order:0, active:true },
        { id:'c5', label:'Napój 33cl', img:'', delta:0.50, cost:0.45, sort_order:1, active:true },
        { id:'c6', label:'Świeżo wyciskany sok', img:'c', delta:1.20, cost:0.90, sort_order:2, active:true } ] },
      { id:'s3', label:'Dodatki dla łasuchów', required:false, kind:'multi', min_select:0, max_select:2, sort_order:2, active:true, choices:[
        { id:'c7', label:'Domowe ciastko', img:'a', delta:2.00, cost:0.70, sort_order:0, active:true },
        { id:'c8', label:'Kawałek tarty', img:'b', delta:2.80, cost:1.10, sort_order:1, active:true },
        { id:'c9', label:'Café gourmand', img:'', delta:3.20, cost:1.40, sort_order:2, active:false } ] } ] },
    { id:'b2', name:'Zestaw dziecięcy', description:'Małe danie + syrop + niespodzianka', price_modifier:-1.00, sort_order:1, active:true, slots:[
      { id:'s4', label:'Małe danie', required:true, kind:'single', min_select:1, max_select:1, sort_order:0, active:true, choices:[
        { id:'c10', label:'Mini tost', img:'b', delta:0, cost:0.90, sort_order:0, active:true },
        { id:'c11', label:'Domowe nuggetsy', img:'a', delta:0, cost:1.10, sort_order:1, active:true } ] },
      { id:'s5', label:'Napój', required:true, kind:'single', min_select:1, max_select:1, sort_order:1, active:true, choices:[
        { id:'c12', label:'Syrop z wodą', img:'', delta:0, cost:0.20, sort_order:0, active:true },
        { id:'c13', label:'Sok jabłkowy', img:'c', delta:0, cost:0.40, sort_order:1, active:true } ] } ] } ] },
  'p-gouter': { productName: 'Zestaw podwieczorkowy — Mister Szoko', category: 'Menu i zestawy', menuOverride: 'on', basePrice: 3.20, baseCost: 0.90, bundles: [
    { id:'gb1', name:'Duet podwieczorkowy', description:'Wypiek + gorący napój', price_modifier:1.20, sort_order:0, active:true, slots:[
      { id:'gs1', label:'Wypiek', required:true, kind:'single', min_select:1, max_select:1, sort_order:0, active:true, choices:[
        { id:'gc1', label:'Czekoladowa drożdżówka', img:'b', delta:0, cost:0.50, sort_order:0, active:true },
        { id:'gc2', label:'Rogalik migdałowy', img:'a', delta:0.60, cost:0.65, sort_order:1, active:true } ] },
      { id:'gs2', label:'Gorący napój', required:true, kind:'single', min_select:1, max_select:1, sort_order:1, active:true, choices:[
        { id:'gc3', label:'Kawa', img:'', delta:0, cost:0.35, sort_order:0, active:true },
        { id:'gc4', label:'Gorąca czekolada', img:'d', delta:0.50, cost:0.55, sort_order:1, active:true } ] } ] } ] },
  'p-cafe': { productName: 'Café Gourmand — Mister Szoko', category: 'Menu i zestawy', menuOverride: 'off', basePrice: 6.50, baseCost: 2.10, bundles: [] },
  'p-brunch': { productName: 'Brunch weekendowy — Mister Szoko', category: 'Menu i zestawy', menuOverride: null, basePrice: 18.00, baseCost: 5.50, bundles: [] }
};
