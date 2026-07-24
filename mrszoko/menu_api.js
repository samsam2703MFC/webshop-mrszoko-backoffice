// Server simulation for the Menu Builder — stands in for the /admin/bundle* API.
// It OWNS the data: reads the seed once, persists every write to localStorage
// (the "database"), verifies parent ownership on every write (no orphans / no
// trusted ids), and recalculates price server-side. The component never mutates
// the tree directly — it calls these functions, exactly as it would call the API.
import { SEED } from './menu_seed.js';

const LS = 'ws_menu_store_v2';
const clone = (o) => JSON.parse(JSON.stringify(o));

let DB = null;

function read() { try { const r = localStorage.getItem(LS); if (r) return JSON.parse(r); } catch (e) {} return null; }
// Donnée serveur pré-chargée (window.__FR_MENUS, hydratée avant le boot) : elle
// fait foi quand elle existe ; sinon repli localStorage puis seed.
function serverDB() { try { const s = (typeof window !== 'undefined') && window.__FR_MENUS; return (s && typeof s === 'object' && !Array.isArray(s)) ? s : null; } catch (e) { return null; } }
function ensure() { if (DB) return DB; const srv = serverDB(); DB = srv ? clone(srv) : (read() || clone(SEED)); persist(); return DB; }
function persist() { try { localStorage.setItem(LS, JSON.stringify(DB)); } catch (e) {} return clone(DB); }

function nid(prefix) { return prefix + Date.now().toString(36) + Math.random().toString(36).slice(2, 5); }
function reindex(arr) { arr.forEach((x, i) => { x.sort_order = i; }); }
function move(arr, id, dir) { const i = arr.findIndex((x) => x.id === id), j = i + dir; if (i < 0 || j < 0 || j >= arr.length) return; const t = arr[i]; arr[i] = arr[j]; arr[j] = t; }

// --- scope / ownership resolution: never trust an id, verify the chain in "db" ---
function P(pid) { ensure(); return DB[pid] || null; }
function B(pid, bid) { const p = P(pid); return p ? (p.bundles.find((b) => b.id === bid) || null) : null; }
function S(pid, bid, sid) { const b = B(pid, bid); return b ? (b.slots.find((s) => s.id === sid) || null) : null; }
function C(pid, bid, sid, cid) { const s = S(pid, bid, sid); return s ? (s.choices.find((c) => c.id === cid) || null) : null; }

// --- reads ---
export function initDB() { ensure(); return persist(); }
export function resetDB() { DB = clone(SEED); return persist(); }

// GET /admin/bundles?productId= — full tree incl. inactive for editing
export function getBundles(pid) { const p = P(pid); return p ? clone(p) : null; }

// --- product ---
// Lazily create a store row for any catalogue product the admin opens.
export function ensureProduct(pid, name, basePrice, category) { ensure(); if (!DB[pid]) { DB[pid] = { productName: name || pid, category: category || '', menuOverride: null, basePrice: basePrice || 0, baseCost: 0, bundles: [] }; } return persist(); }
export function updateProduct(pid, patch) { const p = P(pid); if (p) Object.assign(p, patch); return persist(); }
export function deleteProduct(pid) { ensure(); if (DB[pid]) delete DB[pid]; return persist(); }
export function deleteBundle(pid, bid) { const p = P(pid); if (p) { p.bundles = p.bundles.filter(function(b){ return b.id !== bid; }); p.bundles.forEach(function(b,i){ b.sort_order = i; }); } return persist(); }

// --- menu trigger (logique b): category arms, product overrides, server resolves ---
export function setCategoryDefault(cat, val) { ensure(); if (!DB._categories) DB._categories = {}; if (!DB._categories[cat]) DB._categories[cat] = { menu_default: 0 }; DB._categories[cat].menu_default = val ? 1 : 0; return persist(); }
export function getCategoryDefault(cat) { ensure(); return (DB._categories && DB._categories[cat] && DB._categories[cat].menu_default) ? 1 : 0; }
export function setProductOverride(pid, val) { const p = P(pid); if (p) p.menuOverride = (val === 'on' || val === 'off') ? val : null; return persist(); }
// menu_effectif = override 'on'->1 / 'off'->0 / else category.menu_default
export function resolveMenu(pid) {
  const p = P(pid);
  if (!p) return { effective: 0, origin: 'default', category: '', catDefault: 0, override: null };
  const cat = p.category || '';
  const catDefault = (DB._categories && DB._categories[cat] && DB._categories[cat].menu_default) ? 1 : 0;
  let effective, origin;
  if (p.menuOverride === 'on') { effective = 1; origin = 'override'; }
  else if (p.menuOverride === 'off') { effective = 0; origin = 'override'; }
  else { effective = catDefault; origin = 'category'; }
  return { effective, origin, category: cat, catDefault, override: p.menuOverride || null };
}

// --- bundles (ws_bundles) ---
export function addBundle(pid) { const p = P(pid); if (!p) return { db: persist(), id: null }; const id = nid('b'); p.bundles.push({ id, name:'Nouvelle formule', description:'', price_modifier:0, sort_order:p.bundles.length, active:true, slots:[] }); return { db: persist(), id }; }
export function duplicateBundle(pid, bid) { const p = P(pid), b = B(pid, bid); if (!p || !b) return { db: persist(), id: null }; const i = p.bundles.indexOf(b); const cl = clone(b); cl.id = nid('b'); cl.name = b.name + ' (copie)'; cl.slots.forEach((s) => { s.id = nid('s'); s.choices.forEach((c) => { c.id = nid('c'); }); }); p.bundles.splice(i + 1, 0, cl); reindex(p.bundles); return { db: persist(), id: cl.id }; }
export function updateBundle(pid, bid, patch) { const b = B(pid, bid); if (b) Object.assign(b, patch); return persist(); }
export function toggleBundle(pid, bid) { const b = B(pid, bid); if (b) b.active = !b.active; return persist(); }
export function moveBundle(pid, bid, dir) { const p = P(pid); if (p) { move(p.bundles, bid, dir); reindex(p.bundles); } return persist(); }

// --- slots (ws_bundle_slots) ---
export function addSlot(pid, bid) { const b = B(pid, bid); if (b) b.slots.push({ id: nid('s'), label:'Nouvelle étape', required:true, kind:'single', min_select:1, max_select:1, sort_order:b.slots.length, active:true, choices:[] }); return persist(); }
export function updateSlot(pid, bid, sid, patch) { const s = S(pid, bid, sid); if (!s) return persist(); Object.assign(s, patch); if (patch.kind === 'single') { s.min_select = 1; s.max_select = 1; } if (patch.kind === 'multi') { s.min_select = 0; if (!(s.max_select >= 2)) s.max_select = 2; } return persist(); }
export function setSlotMax(pid, bid, sid, dir) { const s = S(pid, bid, sid); if (!s) return persist(); const cap = s.choices.length || 9; s.max_select = Math.max(1, Math.min(cap, (s.max_select || 1) + dir)); return persist(); }
export function moveSlot(pid, bid, sid, dir) { const b = B(pid, bid); if (b) { move(b.slots, sid, dir); reindex(b.slots); } return persist(); }
export function removeSlot(pid, bid, sid) { const b = B(pid, bid); if (b) { b.slots = b.slots.filter((s) => s.id !== sid); reindex(b.slots); } return persist(); }

// --- choices (ws_bundle_slot_choices) ---
export function addChoice(pid, bid, sid) { const s = S(pid, bid, sid); if (s) s.choices.push({ id: nid('c'), label:'Nouveau choix', img:'', delta:0, sort_order:s.choices.length, active:true }); return persist(); }
export function updateChoice(pid, bid, sid, cid, patch) { const c = C(pid, bid, sid, cid); if (c) Object.assign(c, patch); return persist(); }
export function toggleChoice(pid, bid, sid, cid) { const c = C(pid, bid, sid, cid); if (c) c.active = !c.active; return persist(); }
export function moveChoice(pid, bid, sid, cid, dir) { const s = S(pid, bid, sid); if (s) { move(s.choices, cid, dir); reindex(s.choices); } return persist(); }
export function removeChoice(pid, bid, sid, cid) { const s = S(pid, bid, sid); if (s) { s.choices = s.choices.filter((c) => c.id !== cid); reindex(s.choices); } return persist(); }
export function cycleImage(pid, bid, sid, cid) { const c = C(pid, bid, sid, cid); if (!c) return persist(); const cyc = ['a', 'b', 'c', 'd', '']; c.img = cyc[(cyc.indexOf(c.img) + 1) % cyc.length]; return persist(); }

// --- server-authoritative price (mirrors POST /orders recompute) ---
// base(product) + price_modifier(bundle) + Σ delta(active selected choices), floored at 0.
export function resolvePrice(pid, bid, selection) {
  const p = P(pid), b = B(pid, bid);
  if (!p || !b) return 0;
  let total = (p.basePrice || 0) + (b.price_modifier || 0);
  b.slots.forEach((s) => {
    if (!s.active) return;
    const active = s.choices.filter((c) => c.active);
    const sel = selection ? selection[s.id] : undefined;
    if (s.kind === 'single') { const c = active.find((x) => x.id === sel); if (c) total += c.delta; }
    else { (Array.isArray(sel) ? sel : []).forEach((id) => { const c = active.find((x) => x.id === id); if (c) total += c.delta; }); }
  });
  return Math.max(0, Math.round(total * 100) / 100);
}

// server-authoritative food cost: baseCost(product) + Σ cost(active selected choices).
export function resolveCost(pid, bid, selection) {
  const p = P(pid), b = B(pid, bid);
  if (!p || !b) return 0;
  let total = (p.baseCost || 0);
  b.slots.forEach((s) => {
    if (!s.active) return;
    const active = s.choices.filter((c) => c.active);
    const sel = selection ? selection[s.id] : undefined;
    if (s.kind === 'single') { const c = active.find((x) => x.id === sel); if (c) total += (c.cost || 0); }
    else { (Array.isArray(sel) ? sel : []).forEach((id) => { const c = active.find((x) => x.id === id); if (c) total += (c.cost || 0); }); }
  });
  return Math.round(total * 100) / 100;
}

// margin snapshot for the current build (price - food cost)
export function resolveMargin(pid, bid, selection) {
  const price = resolvePrice(pid, bid, selection);
  const cost = resolveCost(pid, bid, selection);
  const margin = Math.round((price - cost) * 100) / 100;
  const marginPct = price > 0 ? Math.round((margin / price) * 1000) / 10 : 0;
  const foodCostPct = price > 0 ? Math.round((cost / price) * 1000) / 10 : 0;
  return { price, cost, margin, marginPct, foodCostPct };
}

// full margin envelope across every client selection (min → max € margin)
export function marginRange(pid, bid) {
  const p = P(pid), b = B(pid, bid);
  if (!p || !b) return { min: 0, max: 0 };
  const base = ((p.basePrice || 0) + (b.price_modifier || 0)) - (p.baseCost || 0);
  let min = base, max = base;
  b.slots.forEach((s) => {
    if (!s.active) return;
    const contrib = s.choices.filter((c) => c.active).map((c) => (c.delta || 0) - (c.cost || 0));
    if (!contrib.length) return;
    if (s.kind === 'single') {
      let lo = Math.min(...contrib), hi = Math.max(...contrib);
      if (!s.required) { lo = Math.min(0, lo); hi = Math.max(0, hi); }
      min += lo; max += hi;
    } else {
      const cap = s.max_select || contrib.length;
      const pos = contrib.filter((x) => x > 0).sort((a, b2) => b2 - a).slice(0, cap);
      const neg = contrib.filter((x) => x < 0).sort((a, b2) => a - b2).slice(0, cap);
      max += pos.reduce((a, b2) => a + b2, 0);
      min += neg.reduce((a, b2) => a + b2, 0);
    }
  });
  return { min: Math.round(min * 100) / 100, max: Math.round(max * 100) / 100 };
}

// --- integrity check surfaced in the editor: required slot with no active choice ---
export function validate(pid, bid) {
  const b = B(pid, bid); if (!b) return [];
  return b.slots.filter((s) => s.active && s.required && s.choices.filter((c) => c.active).length === 0).map((s) => s.id);
}
