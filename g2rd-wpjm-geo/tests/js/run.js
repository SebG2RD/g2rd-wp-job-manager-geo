const { JSDOM } = require('jsdom');
const fs = require('fs');
// Usage : npm i jsdom (hors du plugin) puis node tests/js/run.js
const P = require('path').join(__dirname, '..', '..', 'assets') + '/';
const src = f => fs.readFileSync(P + f, 'utf8');
let pass = 0, fail = 0;
const check = (l, c) => { c ? pass++ : fail++; console.log((c ? '  [OK]   ' : '  [FAIL] ') + l); };
const wait = ms => new Promise(r => setTimeout(r, ms));
const key = (w, el, k) => { const e = new w.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }); el.dispatchEvent(e); return e; };
const type = (t, v) => { t.input.value = v; t.input.dispatchEvent(new t.w.Event('input', { bubbles: true })); return wait(400); };

async function autocomplete({ rejectCities, failLoad }) {
  const dom = new JSDOM('<form id="f"><input type="text" id="job_location" name="job_location"><input id="other"></form>', { runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window, calls = [];
  w.g2rdWpjmGeoConfig = { apiKey: 'k', language: 'fr' };
  w.g2rdWpjmGeoAutocomplete = { selectors: ['#job_location', 'input[name="job_location"]'], minLength: 2, i18n: { poweredBy: 'Powered by Google', results: 'Suggestions' } };
  const places = {
    AutocompleteSessionToken: function () {},
    AutocompleteSuggestion: { fetchAutocompleteSuggestions: req => {
      calls.push(req);
      if (rejectCities && req.includedPrimaryTypes[0] === '(cities)') return Promise.reject(new Error('INVALID_ARGUMENT'));
      const mk = (m, s) => ({ placePrediction: { text: { text: m + ', ' + s }, mainText: { text: m }, secondaryText: { text: s } } });
      return Promise.resolve({ suggestions: [mk('London', 'England, United Kingdom'), mk('London', 'Ontario, Canada'), mk('<img src=x onerror=alert(1)>', 'XSS')] });
    } }
  };
  w.g2rdWpjmGeoLoader = { load: () => failLoad ? Promise.reject(new Error('down')) : Promise.resolve({ importLibrary: () => Promise.resolve(places) }) };
  w.eval(src('js/location-autocomplete.js'));
  const d = w.document, input = d.getElementById('job_location');
  let formEvents = 0; input.addEventListener('change', () => formEvents++);
  input.focus(); await wait(10);
  const t = { w, d, input, calls, panel: d.querySelector('.g2rd-wpjm-geo-ac'), formEvents: () => formEvents };
  await type(t, 'Lond');
  return t;
}

const jobs = [
  { id: 1, title: 'Dev WordPress', permalink: 'https://x.test/job/1', company: 'XYZ', city: 'Rouen', country: 'France', latitude: 49.443232, longitude: 1.099971, job_type: 'CDI' },
  { id: 2, title: 'Paris 1', permalink: 'https://x.test/job/2', company: 'A', city: 'Paris', country: 'France', latitude: 48.856614, longitude: 2.352222, job_type: 'CDI' },
  { id: 3, title: '<img src=x onerror=alert(1)>', permalink: 'javascript:alert(1)', company: 'B', city: 'Paris', country: 'France', latitude: 48.856614, longitude: 2.352222, job_type: '' },
  { id: 4, title: 'Paris 3', permalink: 'https://x.test/job/4', company: '', city: 'Paris', country: 'France', latitude: 48.856614, longitude: 2.352222, job_type: 'CDD' },
  { id: 5, title: 'Tokyo', permalink: 'https://x.test/job/5', company: 'T', city: 'Tokyo', country: 'Japan', latitude: 35.676192, longitude: 139.650311, job_type: '' },
  { id: 6, title: 'New York', permalink: 'https://x.test/job/6', company: 'N', city: 'New York', country: 'United States', latitude: 40.712776, longitude: -74.005974, job_type: '' },
  { id: 7, title: 'Invalide', permalink: 'https://x.test/job/7', latitude: 'abc', longitude: 999 }
];

async function map({ mapId, clustering, data, restFail, nonce }) {
  const i18n = JSON.stringify({ loading: 'Chargement', error: 'ERREUR', empty: 'VIDE', view: 'Voir offre', manyJobs: '%d offres ici', cluster: 'Groupe de %d offres' }).replace(/"/g, '&quot;');
  const html = '<div class="g2rd-wpjm-geo-map" data-rest-url="https://x.test/wp-json/g2rd-wpjm-geo/v1/jobs" data-per-page="4" data-clustering="' + (clustering ? 1 : 0) + '" data-map-id="' + (mapId || '') + '" data-nonce="' + (nonce || '') + '" data-i18n="' + i18n + '"><div class="g2rd-wpjm-geo-map__canvas"></div><p class="g2rd-wpjm-geo-map__status">Chargement</p></div>';
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://x.test/' });
  const w = dom.window, st = { markers: [], fetches: [], fit: null, center: null, info: null, cluster: null, mapOpts: null };
  class Base { constructor(o) { Object.assign(this, o); this.l = {}; st.markers.push(this); } addListener(e, f) { this.l[e] = f; } setMap(m) { this.map = m; } }
  class Marker extends Base { constructor(o) { super(o); this.kind = 'legacy'; } }
  class Adv extends Base { constructor(o) { super(o); this.kind = 'advanced'; } }
  const libs = {
    maps: { Map: function (el, o) { st.mapOpts = o; this.fitBounds = b => { st.fit = b; }; this.setCenter = c => { st.center = c; }; this.setZoom = () => {}; }, InfoWindow: function () { this.setContent = c => { st.info = c; }; this.open = () => {}; } },
    core: { LatLngBounds: function () { this.pts = []; this.extend = p => this.pts.push(p); }, Size: function () {}, Point: function () {} },
    marker: { Marker, AdvancedMarkerElement: Adv }
  };
  w.g2rdWpjmGeoLoader = { load: () => Promise.resolve({ importLibrary: n => Promise.resolve(libs[n]) }) };
  w.markerClusterer = { MarkerClusterer: function (o) { st.cluster = o; } };
  w.IntersectionObserver = undefined;
  w.fetch = (url, opt) => {
    st.fetches.push({ url, nonce: opt.headers['X-WP-Nonce'] });
    if (restFail) return Promise.resolve({ ok: false, status: 500, headers: { get: () => null } });
    if (nonce === 'expired' && opt.headers['X-WP-Nonce']) return Promise.resolve({ ok: false, status: 403, headers: { get: () => null } });
    const page = +new URL(url).searchParams.get('page'), per = 4;
    return Promise.resolve({ ok: true, status: 200, headers: { get: h => h === 'X-WP-TotalPages' ? String(Math.ceil(data.length / per) || 1) : null }, json: () => Promise.resolve(data.slice((page - 1) * per, page * per)) });
  };
  w.eval(src('js/map-common.js'));
  w.eval(src('js/jobs-map.js'));
  await wait(80);
  return { w, st, el: w.document.querySelector('.g2rd-wpjm-geo-map'), status: w.document.querySelector('.g2rd-wpjm-geo-map__status') };
}

async function osmMap({ data, clustering = true, noWebgl = false, styleFail = false, restFail = false, preload = true, admin = '' }) {
  const i18n = JSON.stringify({ loading: 'Chargement', error: 'ERREUR', empty: 'VIDE', view: 'Voir offre', manyJobs: '%d offres ici', regeocode: 'Re-geocoder' }).replace(/"/g, '&quot;');
  const html = '<div class="g2rd-wpjm-geo-map" data-rest-url="https://x.test/wp-json/g2rd-wpjm-geo/v1/jobs" data-per-page="4" data-clustering="' + (clustering ? 1 : 0) + '" data-admin-url="' + admin + '" data-nonce="" data-i18n="' + i18n + '"><div class="g2rd-wpjm-geo-map__canvas"></div><p class="g2rd-wpjm-geo-map__status">Chargement</p></div>';
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://x.test/' });
  const w = dom.window;
  const st = { map: null, mapOpts: null, sources: {}, layers: [], layout: [], popups: [], fit: null, jump: null, ease: null };
  class FakeMap {
    constructor(o) { st.mapOpts = o; st.map = this; this.events = {}; }
    on(ev, a, b) { const k = b ? ev + ':' + a : ev; (this.events[k] = this.events[k] || []).push(b || a); }
    fire(k, arg) { (this.events[k] || []).forEach(f => f(arg)); }
    getStyle() { return { layers: [
      { id: 'place-label', type: 'symbol', layout: { 'text-field': ['get', 'name'], 'text-font': ['Noto Sans Regular'] } },
      { id: 'road-ref', type: 'symbol', layout: { 'text-field': ['get', 'ref'], 'text-font': ['Noto Sans Bold'] } },
      { id: 'water', type: 'fill' }
    ] }; }
    setLayoutProperty(id, prop, value) { st.layout.push({ id, prop, value }); }
    addSource(id, s) { st.sources[id] = s; }
    addLayer(l) { st.layers.push(l); }
    getSource() { return { getClusterExpansionZoom: () => Promise.resolve(7) }; }
    easeTo(o) { st.ease = o; }
    fitBounds(b, o) { st.fit = { b, o }; }
    jumpTo(o) { st.jump = o; }
    getCanvas() { return { style: {} }; }
  }
  class Popup { constructor(o) { this.o = o; st.popups.push(this); } setLngLat(c) { this.c = c; return this; } setDOMContent(n) { this.node = n; return this; } addTo() { return this; } }
  class LngLatBounds { constructor() { this.pts = []; } extend(p) { this.pts.push(p); return this; } }
  const fake = { Map: FakeMap, Popup, LngLatBounds };
  if (preload) w.maplibregl = fake;
  w.WebGL2RenderingContext = noWebgl ? undefined : function () {};
  w.HTMLCanvasElement.prototype.getContext = function () { return noWebgl ? null : {}; };
  w.g2rdWpjmGeoOsm = { styleUrl: 'https://tiles.openfreemap.org/styles/liberty', maplibreJs: 'https://x.test/vendor/maplibre-gl.js', maplibreCss: 'https://x.test/vendor/maplibre-gl.css', language: 'fr' };
  w.IntersectionObserver = undefined;
  w.fetch = (url) => {
    if (restFail) return Promise.resolve({ ok: false, status: 500, headers: { get: () => null } });
    const page = +new URL(url).searchParams.get('page'), per = 4;
    return Promise.resolve({ ok: true, status: 200, headers: { get: h => h === 'X-WP-TotalPages' ? String(Math.ceil(data.length / per) || 1) : null }, json: () => Promise.resolve(data.slice((page - 1) * per, page * per)) });
  };
  w.eval(src('js/map-common.js'));
  w.eval(src('js/jobs-map-osm.js'));
  await wait(50);
  if (!preload) {
    const script = w.document.querySelector('script[src="https://x.test/vendor/maplibre-gl.js"]');
    st.injected = { script: !!script, css: !!w.document.querySelector('link[href="https://x.test/vendor/maplibre-gl.css"]') };
    if (script) { w.maplibregl = fake; script.onload(); await wait(50); }
  }
  if (st.map) { st.map.fire(styleFail ? 'error' : 'load', {}); }
  await wait(20);
  return { w, st, el: w.document.querySelector('.g2rd-wpjm-geo-map'), status: w.document.querySelector('.g2rd-wpjm-geo-map__status') };
}

(async () => {
  console.log('\nA. Autocompletion - cas nominal');
  let t = await autocomplete({});
  check('un seul champ de localisation, aucun champ ajoute', t.d.querySelectorAll('input').length === 2);
  check('requete : (cities), langue fr, jeton de session, aucune restriction de pays', t.calls.length === 1 && t.calls[0].includedPrimaryTypes[0] === '(cities)' && t.calls[0].language === 'fr' && !!t.calls[0].sessionToken && !('includedRegionCodes' in t.calls[0]) && !('region' in t.calls[0]));
  check('liste ouverte : 3 suggestions + attribution Google', !t.panel.hidden && t.panel.querySelectorAll('[role=option]').length === 3 && /Google/.test(t.panel.textContent));
  check('ARIA combobox : aria-expanded=true, autocomplete=off', t.input.getAttribute('aria-expanded') === 'true' && t.input.getAttribute('autocomplete') === 'off');
  check('suggestion malveillante inseree comme texte (aucun <img> cree)', t.panel.querySelectorAll('img').length === 0);
  key(t.w, t.input, 'ArrowDown'); key(t.w, t.input, 'ArrowDown');
  check('clavier : 2e option active', t.panel.querySelectorAll('[role=option]')[1].getAttribute('aria-selected') === 'true');
  const enter = key(t.w, t.input, 'Enter'); await wait(350);
  check('Entree choisit la ville sans soumettre le formulaire', enter.defaultPrevented && t.input.value === 'London, Ontario, Canada');
  check('valeur ecrite dans le champ natif, evenement change emis, liste fermee, pas de nouvelle recherche', t.formEvents() === 1 && t.panel.hidden && t.calls.length === 1);
  await type(t, 'Par');
  const esc = key(t.w, t.input, 'Escape');
  check('Echap ferme la liste', t.panel.hidden && esc.defaultPrevented);
  await type(t, 'P');
  check('moins de 2 caracteres : aucune requete', t.calls.length === 2);
  await type(t, 'Tok'); t.input.blur(); await wait(20);
  check('perte de focus : liste fermee', t.panel.hidden);

  console.log('\nB. Autocompletion - Google refuse (cities)');
  t = await autocomplete({ rejectCities: true });
  check('repli automatique sur les types explicites, suggestions affichees', t.calls.length === 2 && t.calls[1].includedPrimaryTypes.includes('locality') && !t.panel.hidden);
  await type(t, 'Londo');
  check('repli memorise (pas de nouvel essai avec (cities))', t.calls.length === 3 && t.calls[2].includedPrimaryTypes.includes('locality'));

  console.log('\nC. Autocompletion - API Google indisponible');
  t = await autocomplete({ failLoad: true });
  check('aucune liste, aucune erreur, le champ reste un champ texte utilisable', t.panel.hidden && t.input.value === 'Lond' && !t.input.disabled);

  console.log('\nD. Carte');
  let m = await map({ clustering: true, data: jobs });
  check('pagination : 2 pages demandees (7 offres, 4 par page)', m.st.fetches.length === 2 && /page=2/.test(m.st.fetches[1].url));
  const own = m.st.markers.filter(x => x.g2rdCount);
  check('4 marqueurs pour 6 offres valides : les 3 offres de Paris partagent un marqueur, coordonnees invalides ignorees', own.length === 4 && own.filter(x => x.g2rdCount === 3).length === 1);
  check('sans Map ID : marqueurs classiques', own.every(x => x.kind === 'legacy') && !('mapId' in m.st.mapOpts));
  check('clustering sur 4 marqueurs, cadrage automatique sur 4 points (3 continents)', !!m.st.cluster && m.st.cluster.markers.length === 4 && !!m.st.fit && m.st.fit.pts.length === 4);
  const cl = m.st.cluster.renderer.render({ markers: own, position: { lat: 0, lng: 0 } });
  check('le cluster affiche le nombre d offres (6), pas le nombre de lieux (4)', /6/.test(cl.title) && !!cl.label && cl.label.text === '6');
  own.find(x => x.g2rdCount === 3).l.click();
  let iw = m.st.info;
  check('clic sur le marqueur de Paris : liste des 3 offres', iw.querySelectorAll('li').length === 3 && /3 offres ici/.test(iw.textContent));
  check('titre malveillant affiche comme texte, lien javascript: refuse', iw.querySelectorAll('img').length === 0 && iw.querySelectorAll('a').length === 2 && [...iw.querySelectorAll('a')].every(a => /^https:/.test(a.href)));
  own.find(x => x.title === 'Dev WordPress').l.click(); iw = m.st.info;
  const link = iw.querySelector('a.g2rd-wpjm-geo-iw__link');
  check('offre seule : titre, entreprise, "Rouen, France", CDI, lien vers le permalien', /Dev WordPress/.test(iw.textContent) && /XYZ/.test(iw.textContent) && /Rouen, France/.test(iw.textContent) && /CDI/.test(iw.textContent) && link.href === 'https://x.test/job/1' && link.textContent === 'Voir offre');
  check('message d etat masque une fois la carte prete', m.status.hidden && m.el.classList.contains('is-ready'));
  m = await map({ mapId: 'MAP123', clustering: false, data: jobs });
  check('avec Map ID : marqueurs avances poses sur la carte, mapId transmis, pas de clustering', m.st.markers.length === 4 && m.st.markers.every(x => x.kind === 'advanced' && x.map) && m.st.mapOpts.mapId === 'MAP123' && !m.st.cluster);
  m = await map({ clustering: true, data: [] });
  check('aucune offre : message vide, pas d erreur', m.status.textContent === 'VIDE' && !m.el.classList.contains('has-error'));
  m = await map({ clustering: true, data: [jobs[0]] });
  check('une seule offre : centrage sur le point', !!m.st.center && m.st.center.lat === 49.443232 && !m.st.fit);
  m = await map({ clustering: true, data: jobs, restFail: true });
  check('endpoint en erreur : message d erreur', m.status.textContent === 'ERREUR' && m.el.classList.contains('has-error'));
  m = await map({ clustering: true, data: jobs, nonce: 'expired' });
  check('nonce expire (page en cache) : nouvelle tentative anonyme reussie', m.st.fetches[0].nonce === 'expired' && m.st.fetches[1].nonce === undefined && m.st.markers.length > 0 && !m.el.classList.contains('has-error'));
  m = await map({ clustering: true, data: jobs });
  m.w.document.dispatchEvent(new m.w.CustomEvent('g2rdWpjmGeoAuthFailure'));
  check('cle refusee par Google (gm_authFailure) : message d erreur', m.status.textContent === 'ERREUR');

  console.log('\nE. Chargeur Google');
  const blank = '<html><head></head><body></body></html>';
  const w = new JSDOM(blank, { runScripts: 'outside-only' }).window;
  w.g2rdWpjmGeoConfig = { apiKey: 'AIza KEY&x', language: 'fr' }; w.eval(src('js/google-loader.js'));
  check('aucun script Google tant que load() n est pas appele', w.document.querySelectorAll('script[src*="googleapis"]').length === 0);
  const p1 = w.g2rdWpjmGeoLoader.load(), p2 = w.g2rdWpjmGeoLoader.load();
  const tag = w.document.querySelector('script[src*="maps.googleapis.com"]');
  check('un seul script injecte, cle encodee, loading=async, langue', p1 === p2 && w.document.querySelectorAll('script[src*="googleapis"]').length === 1 && /key=AIza%20KEY%26x/.test(tag.src) && /loading=async/.test(tag.src) && /language=fr/.test(tag.src));
  w.google = { maps: { importLibrary: () => {} } }; w.g2rdWpjmGeoGoogleReady();
  check('promesse resolue au callback Google', (await p1) === w.google.maps);
  const w2 = new JSDOM(blank, { runScripts: 'outside-only' }).window; w2.g2rdWpjmGeoConfig = { apiKey: '' }; w2.eval(src('js/google-loader.js'));
  let err = null; await w2.g2rdWpjmGeoLoader.load().catch(e => { err = e.message; });
  check('cle absente : echec propre, aucun script injecte', err === 'google-maps-no-key' && w2.document.querySelectorAll('script').length === 0);
  const w3 = new JSDOM('<html><head><script src="https://maps.googleapis.com/maps/api/js?key=AUTRE"></script></head><body></body></html>', { runScripts: 'outside-only' }).window;
  w3.g2rdWpjmGeoConfig = { apiKey: 'k' }; w3.eval(src('js/google-loader.js'));
  const p3 = w3.g2rdWpjmGeoLoader.load(); setTimeout(() => { w3.google = { maps: { importLibrary: () => {} } }; }, 150);
  await p3;
  check('API deja chargee par une autre extension : reutilisee, pas de second script', w3.document.querySelectorAll('script[src*="googleapis"]').length === 1);

  console.log('\nF. Page de reglages');
  const settingsHtml = '<form class="g2rd-wpjm-geo-settings">'
    + '<label><input type="radio" name="o[provider]" value="google" checked></label><label><input type="radio" name="o[provider]" value="osm"></label>'
    + '<div data-g2rd-provider="google"><input name="o[browser_api_key]" value="k"></div>'
    + '<div data-g2rd-provider="osm" style="display:none"><input type="url" name="o[photon_url]" value="https://p.test/api/"></div>'
    + '<span class="components-form-toggle is-checked"><input class="components-form-toggle__input" type="checkbox" id="t" name="o[gofetch_replace]" value="1" checked></span>'
    + '<div class="g2rd-savebar"><button type="submit">ok</button></div></form>';
  const settings = async (before) => {
    const sw = new JSDOM(settingsHtml, { runScripts: 'outside-only' }).window;
    if (before) before(sw.document);
    sw.eval(src('js/admin-settings.js'));
    await wait(10);
    const sd = sw.document;
    return { sw, sd, bar: sd.querySelector('.g2rd-savebar'), t: sd.getElementById('t'), rows: v => [...sd.querySelectorAll('[data-g2rd-provider="' + v + '"]')].map(n => n.style.display).join() };
  };
  const fire = (s, el, type) => el.dispatchEvent(new s.sw.Event(type, { bubbles: true }));
  let s = await settings();
  check('barre masquee tant que rien ne change', s.bar.style.display === 'none');
  const osmRadio = s.sd.querySelector('input[value="osm"]');
  osmRadio.checked = true; fire(s, osmRadio, 'change');
  check('bascule OSM : lignes Google masquees, OSM affichees, barre visible', s.rows('google') === 'none' && s.rows('osm') === '' && s.bar.style.display === '');
  check('champs masques toujours soumis', new s.sw.FormData(s.sd.querySelector('form')).get('o[browser_api_key]') === 'k');
  const googleRadio = s.sd.querySelector('input[value="google"]');
  googleRadio.checked = true; fire(s, googleRadio, 'change');
  check('retour a la valeur enregistree : barre masquee', s.bar.style.display === 'none');
  s.t.checked = false; fire(s, s.t, 'change');
  check('interrupteur : classe is-checked suit la case', !s.t.parentNode.classList.contains('is-checked'));
  s = await settings(d => { d.getElementById('t').checked = false; d.querySelector('input[value="osm"]').checked = true; });
  check('etat restaure par le navigateur : interrupteur, lignes et barre synchronises', !s.t.parentNode.classList.contains('is-checked') && s.rows('google') === 'none' && s.bar.style.display === '');
  check('formulaire absent : aucune erreur', await (async () => { const ew = new JSDOM('<p></p>', { runScripts: 'outside-only' }).window; ew.eval(src('js/admin-settings.js')); await wait(10); return true; })());

  console.log('\nG. Outil Go Fetch Jobs');
  {
    const tw = new JSDOM('<div class="g2rd-wpjm-geo-tools" data-ajax-url="/a" data-action="x" data-nonce="n" data-i18n=\'{"job":"Offre","current":"Valeur actuelle :","next":"Nouvelle valeur :","reason":"Raison :","done":"Terminé."}\'><button data-g2rd-run="fix"></button><input type="checkbox" data-g2rd-dry-run checked><p class="g2rd-wpjm-geo-tools__status"></p><div class="g2rd-wpjm-geo-tools__stats"></div><div class="g2rd-wpjm-geo-tools__rows"></div></div>', { runScripts: 'outside-only' }).window;
    const toolStats = { analyzed: 1, email: 0, url: 1, empty: 0, toFix: 1, fixed: 0 };
    const pages = [
      { success: true, data: { stats: toolStats, rows: [{ id: 9, old: 'javascript:alert(1)//"><img src=x onerror=alert(1)>', new: '<svg onload=alert(2)>', reason: '<script>alert(3)</script>' }], cursor: 9, done: false } },
      { success: true, data: { stats: toolStats, rows: [{ id: 10, old: 'u', new: 'e', reason: 'r' }], cursor: 10, done: false } },
      { success: true, data: { stats: toolStats, rows: [], cursor: 10, done: true } }
    ];
    let page = 0;
    tw.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve(pages[page++]) });
    tw.eval(src('js/admin-tools.js'));
    tw.document.dispatchEvent(new tw.Event('DOMContentLoaded'));
    tw.document.querySelector('[data-g2rd-run="fix"]').click();
    await wait(20);
    const toolRows = tw.document.querySelector('.g2rd-wpjm-geo-tools__rows');
    check('outil : un seul tableau pour tous les lots', 1 === toolRows.querySelectorAll('table').length && 2 === toolRows.querySelectorAll('tbody tr').length);
    check('outil : en-tetes sans deux-points, scope="col"', 'Offre|Valeur actuelle|Nouvelle valeur|Raison' === [...toolRows.querySelectorAll('th')].map(t => t.textContent).join('|') && 4 === toolRows.querySelectorAll('th[scope="col"]').length);
    check('outil : valeurs importees inserees en texte', 0 === toolRows.querySelectorAll('img,svg,script').length && toolRows.querySelector('td.is-mono').textContent.startsWith('javascript:'));
  }

  console.log('\nH. Fonctions communes (map-common.js)');
  {
    const cw = new JSDOM('<div class="g2rd-wpjm-geo-map" id="m1"></div><div class="g2rd-wpjm-geo-map" id="m2"></div>', { runScripts: 'outside-only', url: 'https://x.test/' }).window;
    let io = null;
    cw.IntersectionObserver = function (cb, opt) { io = { cb, opt, targets: [] }; this.observe = t => io.targets.push(t); this.unobserve = () => {}; };
    cw.fetch = () => Promise.reject(new Error('unused'));
    cw.eval(src('js/map-common.js'));
    const C = cw.g2rdWpjmGeoMapCommon;
    check('API exposee : un seul objet global, 9 fonctions', 'parseJson,sprintf,safeUrl,placeLabel,buildInfoContent,groupByCoordinates,createFetcher,observe,status' === Object.keys(C).join(','));
    check('safeUrl : http(s) absolu seulement (relatif, //hote, javascript:, espace initial, non-chaine refuses)',
      C.safeUrl('HTTPS://a.test/x') === 'HTTPS://a.test/x' && ['/job/1', '//evil.test', 'javascript:alert(1)', ' javascript:alert(1)', 'https:evil', 'data:text/html,x', null, 42].every(u => C.safeUrl(u) === ''));
    const g = C.groupByCoordinates([{ latitude: '48.856614', longitude: '2.352222' }, { latitude: 48.8566141, longitude: 2.3522224 }, { latitude: 48.85662, longitude: 2.352222 }, { latitude: 90, longitude: 180 }, { latitude: 90.0001, longitude: 0 }, { latitude: '', longitude: '' }]);
    check('regroupement a 5 decimales, ordre conserve, bornes 90/180 incluses, hors bornes et vides ignores', 3 === g.length && 2 === g[0].jobs.length && 1 === g[1].jobs.length && 90 === g[2].position.lat);
    const urls = [];
    cw.fetch = url => { urls.push(url); return Promise.resolve({ ok: true, status: 200, headers: { get: () => '500' }, json: () => Promise.resolve([{}]) }); };
    const box = cw.document.createElement('div');
    box.setAttribute('data-rest-url', 'https://x.test/?rest_route=/g2rd-wpjm-geo/v1/jobs');
    const all = await C.createFetcher(box).fetchAll();
    check('pagination plafonnee a 200 pages, "&" si l URL contient deja "?", per_page par defaut 250', 200 === urls.length && 200 === all.length && /\?rest_route=[^?]+&page=1&per_page=250$/.test(urls[0]));
    urls.length = 0;
    cw.fetch = url => { urls.push(url); return Promise.resolve({ ok: true, status: 200, headers: { get: () => null }, json: () => Promise.resolve({ code: 'x' }) }); };
    check('en-tete X-WP-TotalPages absent et reponse non tableau : une page, aucune offre', 0 === (await C.createFetcher(box).fetchAll()).length && 1 === urls.length);
    const started = [];
    C.observe(c => started.push(c.id));
    await wait(20);
    check('demarrage paresseux : 2 cartes observees (marge 300px), aucune demarree avant d etre visible', !!io && 2 === io.targets.length && '300px' === io.opt.rootMargin && 0 === started.length);
    io.cb([{ isIntersecting: true, target: io.targets[1] }, { isIntersecting: false, target: io.targets[0] }]);
    io.cb([{ isIntersecting: true, target: io.targets[1] }]);
    check('seule la carte visible demarre, une seule fois', 'm2' === started.join(','));
    const sbox = cw.document.createElement('div');
    sbox.innerHTML = '<p class="g2rd-wpjm-geo-map__status"></p>';
    sbox.setAttribute('data-admin-url', 'https://x.test/wp-admin/admin.php?page=g2rd-wpjm-geo#g2rd-geo-outils');
    C.status(sbox, { empty: 'VIDE', error: 'ERREUR', regeocode: 'Re-geocoder' }).empty();
    const sp = sbox.querySelector('p');
    check('etat vide : message et lien Re-geocoder (http(s) seulement)', sbox.classList.contains('is-empty') && 'VIDE Re-geocoder' === sp.textContent && 1 === sp.querySelectorAll('a').length && !sp.hidden);
    sbox.setAttribute('data-admin-url', 'javascript:alert(1)');
    C.status(sbox, { empty: 'VIDE', regeocode: 'Re-geocoder' }).empty();
    check('etat vide : lien javascript: refuse', 0 === sp.querySelectorAll('a').length);
  }

  console.log('\nI. Carte en mode libre (MapLibre)');
  let o = await osmMap({ data: jobs });
  check('MapLibre initialise : style OpenFreeMap, gestes cooperatifs', !!o.st.map && o.st.mapOpts.style === 'https://tiles.openfreemap.org/styles/liberty' && o.st.mapOpts.cooperativeGestures === true);
  const srcJobs = o.st.sources['g2rd-jobs'];
  check('4 points pour 6 offres valides, clustering actif, clusters comptant les offres', !!srcJobs && srcJobs.data.features.length === 4 && srcJobs.cluster === true && JSON.stringify(srcJobs.clusterProperties.jobs) === JSON.stringify(['+', ['get', 'count']]));
  check('le point de Paris porte 3 offres', srcJobs.data.features[1].properties.count === 3);
  check('libelles traduits sur les seules couches de noms', o.st.layout.length === 1 && o.st.layout[0].id === 'place-label' && JSON.stringify(o.st.layout[0].value) === JSON.stringify(['coalesce', ['get', 'name:fr'], ['get', 'name']]));
  const countLayer = o.st.layers.find(l => l.id === 'g2rd-cluster-count');
  check('police des compteurs reprise du style', !!countLayer && JSON.stringify(countLayer.layout['text-font']) === JSON.stringify(['Noto Sans Regular']));
  o.st.map.fire('click:g2rd-points', { features: [{ properties: { gid: 1, count: 3 }, geometry: { coordinates: [2.352222, 48.856614] } }] });
  const pop = o.st.popups[0];
  check('clic sur Paris : fenetre avec les 3 offres, sans HTML injecte', !!pop && pop.node.querySelectorAll('li').length === 3 && pop.node.querySelectorAll('img').length === 0);
  o.st.map.fire('click:g2rd-clusters', { features: [{ properties: { cluster_id: 9 }, geometry: { coordinates: [2, 48] } }] });
  await wait(10);
  check('clic sur un cluster : zoom d expansion', !!o.st.ease && o.st.ease.zoom === 7);
  check('cadrage automatique sur les 4 lieux', !!o.st.fit && o.st.fit.b.pts.length === 4 && o.st.fit.o.padding === 48);
  check('carte prete, message masque', o.status.hidden && o.el.classList.contains('is-ready'));
  o = await osmMap({ data: jobs, clustering: false });
  check('clustering desactive', o.st.sources['g2rd-jobs'].cluster === false);
  o = await osmMap({ data: [jobs[0]] });
  check('une offre : centrage zoom 10', !!o.st.jump && o.st.jump.zoom === 10 && !o.st.fit);
  o = await osmMap({ data: [], admin: 'https://x.test/wp-admin/admin.php?page=g2rd-wpjm-geo#g2rd-geo-outils' });
  const adminLink = o.status.querySelector('a');
  check('aucune offre : message vide et lien de re-geocodage pour l administrateur', /VIDE/.test(o.status.textContent) && !!adminLink && adminLink.href.indexOf('#g2rd-geo-outils') !== -1);
  o = await osmMap({ data: [] });
  check('aucune offre, visiteur : pas de lien', !o.status.querySelector('a'));
  o = await osmMap({ data: jobs, noWebgl: true });
  check('sans WebGL 2 : message d erreur, MapLibre non initialise', !o.st.map && o.status.textContent === 'ERREUR');
  o = await osmMap({ data: jobs, styleFail: true });
  check('style injoignable : message d erreur', o.status.textContent === 'ERREUR' && o.el.classList.contains('has-error'));
  o = await osmMap({ data: jobs, restFail: true });
  check('endpoint en erreur : message d erreur', o.status.textContent === 'ERREUR');
  o = await osmMap({ data: jobs, preload: false });
  check('MapLibre charge a la demande depuis le plugin (script et feuille)', o.st.injected.script && o.st.injected.css && !!o.st.map);

  console.log('\nResultat JS : ' + pass + ' reussis, ' + fail + ' echoues');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('ERREUR BANC DE TEST:', e); process.exit(2); });
