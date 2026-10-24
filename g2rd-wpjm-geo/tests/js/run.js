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

// Attribution d'un style tiers : crédits réels mêlés à du balisage hostile (le plugin ne doit garder que du texte et des liens https).
const HOSTILE_ATTRIBUTION = '<a href="https://openfreemap.org" target="_blank">OpenFreeMap</a> <a href="https://www.openmaptiles.org/" target="_blank">&copy; OpenMapTiles</a> Data from <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>'
  + '<img src="x" onerror="window.pwned=1"><iframe srcdoc="<b>cadre</b>"></iframe><a href="javascript:window.pwned=2">piege</a><b data-poc="1" onmouseover="window.pwned=3">gras</b>';

async function osmMap({ data, clustering = true, noWebgl = false, styleFail = false, restFail = false, preload = true, admin = '', narrow = false, regionsFail = false, throwRender = false, badShape = false, italicFirst = false }) {
  const i18n = JSON.stringify({ loading: 'Chargement', error: 'ERREUR', empty: 'VIDE', view: 'Voir offre', manyJobs: '%d offres ici', regeocode: 'Re-geocoder', places: 'Liste', alsoAt: 'Aussi à :', regionTitle: '%s (toute la région)', oneJob: '1 offre', manyJobsShort: '%d offres', legendCity: 'Offre dans une ville', legendRegion: 'Offre sur toute une région' }).replace(/"/g, '&quot;');
  const html = '<div class="g2rd-wpjm-geo-map" data-rest-url="https://x.test/wp-json/g2rd-wpjm-geo/v1/jobs" data-per-page="4" data-clustering="' + (clustering ? 1 : 0) + '" data-admin-url="' + admin + '" data-nonce="" data-i18n="' + i18n + '"><div class="g2rd-wpjm-geo-map__canvas"></div><p class="g2rd-wpjm-geo-map__status">Chargement</p></div>';
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://x.test/' });
  const w = dom.window;
  const st = { map: null, mapOpts: null, sources: {}, layers: [], layout: [], popups: [], fit: null, jump: null, ease: null, controls: [], noRotate: false, before: {}, states: [], markers: [], rendered: [], regionFetches: 0, controlNodes: [], queryLayers: null, canvas: null };
  class FakeMap {
    constructor(o) {
      st.mapOpts = o; st.map = this; this.events = {};
      this.dragRotate = { disable: () => { st.noRotate = true; } };
      this.touchZoomRotate = { disableRotation: () => {} };
      this.touchPitch = { disable: () => {} };
    }
    addControl(c, pos) {
      st.controls.push(c.kind || (typeof c.render === 'function' ? 'attrib' : (typeof c.onAdd === 'function' ? 'legend' : '?')));
      if (typeof c.onAdd === 'function') { const node = c.onAdd(this); st.controlNodes.push({ node, pos, ctrl: c }); if (typeof c.render === 'function') { st.attribNode = node; st.attribPos = pos; } }
      return this;
    }
    on(ev, a, b) { const k = b ? ev + ':' + a : ev; (this.events[k] = this.events[k] || []).push(b || a); }
    fire(k, arg) { (this.events[k] || []).forEach(f => f(arg)); }
    getStyle() { return { sources: Object.assign({ openmaptiles: { type: 'vector', url: 'https://tiles.openfreemap.org/planet' } }, st.sources), layers: [
      ...(italicFirst ? [{ id: 'waterway_line_label', type: 'symbol', layout: { 'text-field': ['get', 'name'], 'text-font': ['Noto Sans Italic'] } }] : []),
      { id: 'place-label', type: 'symbol', layout: { 'text-field': ['get', 'name'], 'text-font': ['Noto Sans Regular'] } },
      { id: 'road-ref', type: 'symbol', layout: { 'text-field': ['get', 'ref'], 'text-font': ['Noto Sans Bold'] } },
      { id: 'water', type: 'fill' }
    ] }; }
    setLayoutProperty(id, prop, value) { st.layout.push({ id, prop, value }); }
    addSource(id, s) { st.sources[id] = s; }
    addLayer(l, before) { st.layers.push(l); if (before) st.before[l.id] = before; }
    getLayer(id) { return st.layers.find(l => l.id === id); }
    setFeatureState(f, s) { st.states.push({ f, s }); }
    queryRenderedFeatures(p, o) { st.queryLayers = o && o.layers; return st.rendered; }
    getSource(id) { if (throwRender && 'g2rd-regions' === id) throw new Error('render'); return id === 'openmaptiles' ? { attribution: HOSTILE_ATTRIBUTION } : { getClusterExpansionZoom: () => Promise.resolve(7) }; }
    easeTo(o) { st.ease = o; }
    fitBounds(b, o) { st.fit = { b, o }; }
    jumpTo(o) { st.jump = o; }
    getCanvas() { return st.canvas || (st.canvas = { style: {} }); }
  }
  class Popup { constructor(o) { this.o = o; st.popups.push(this); } setLngLat(c) { this.c = c; return this; } setDOMContent(n) { this.node = n; return this; } addTo() { return this; } remove() { this.removed = true; return this; } }
  class LngLatBounds { constructor() { this.pts = []; } extend(p) { this.pts.push(p); return this; } }
  class NavigationControl { constructor(o) { this.kind = 'nav'; this.o = o; } }
  class FullscreenControl { constructor() { this.kind = 'full'; } }
  const fake = { Map: FakeMap, Popup, LngLatBounds, NavigationControl, FullscreenControl };
  if (preload) w.maplibregl = fake;
  w.WebGL2RenderingContext = noWebgl ? undefined : function () {};
  w.HTMLCanvasElement.prototype.getContext = function () { return noWebgl ? null : {}; };
  if (narrow) w.innerWidth = 600;
  w.g2rdWpjmGeoOsm = { styleUrl: 'https://tiles.openfreemap.org/styles/liberty', maplibreJs: 'https://x.test/vendor/maplibre-gl.js', maplibreCss: 'https://x.test/vendor/maplibre-gl.css', language: 'fr', locale: { 'Popup.Close': 'Fermer', 'Map.Title': '' }, regionsUrl: 'https://x.test/data/regions-fr.geojson?ver=1', regionsCredit: '<a href="https://www.data.gouv.fr/fr/datasets/contours-administratifs/">Contours des régions : data.gouv.fr (ODbL)</a>' };
  w.IntersectionObserver = undefined;
  w.fetch = (url) => {
    if (url.indexOf('https://x.test/data/regions-fr.geojson') === 0) {
      st.regionFetches++;
      if (regionsFail) return Promise.resolve({ ok: false, status: 404, json: () => Promise.reject(new Error('404')) });
      const square = (x, y) => ({ type: 'Polygon', coordinates: [[[x, y], [x + 1, y], [x + 1, y + 1], [x, y]]] });
      return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ type: 'FeatureCollection', features: [
        { type: 'Feature', properties: { code: '28', nom: 'Normandie' }, geometry: badShape ? { type: 'Point', coordinates: [0, 49] } : square(0, 49) },
        { type: 'Feature', properties: { code: '53', nom: 'Bretagne' }, geometry: square(-3, 48) },
        { type: 'Feature', properties: { code: '11', nom: 'Île-de-France' }, geometry: square(2, 48) }
      ] }) });
    }
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

async function photonAc({ status400 = false, fail = false, slowFirst = false, realAbort = false, features: custom = null } = {}) {
  const dom = new JSDOM('<form><input type="text" id="job_location" name="job_location"><input id="other"></form>', { runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window, calls = [];
  w.g2rdWpjmGeoAutocomplete = { source: 'photon', photonUrl: 'https://photon.test/api/', lang: 'fr', selectors: ['#job_location'], minLength: 2, i18n: { poweredBy: 'Recherche (c) OpenStreetMap contributors', results: 'Suggestions' } };
  if (!realAbort) w.AbortController = class { constructor() { this.signal = { aborted: false }; } abort() { this.signal.aborted = true; } };
  const feature = (name, state, country, key) => ({ properties: { name, state, country, osm_key: key || 'place' } });
  w.fetch = (url, opt) => {
    calls.push({ url, opt });
    if (fail) return Promise.reject(new Error('reseau'));
    if (realAbort && calls.length === 1) return new Promise((res, rej) => opt.signal.addEventListener('abort', () => rej(new w.DOMException('aborted', 'AbortError'))));
    const filtered = url.indexOf('osm_tag=') !== -1;
    if (status400 && filtered) return Promise.resolve({ ok: false, status: 400, json: () => Promise.resolve({}) });
    const features = custom ? custom.slice() : [feature('Londres', 'Angleterre', 'Royaume-Uni'), feature('London', 'Ontario', 'Canada'), feature('<img src=x onerror=alert(1)>', 'X', 'Y')];
    if (!filtered && !custom) features.splice(1, 0, feature('London Pub', 'Angleterre', 'Royaume-Uni', 'amenity'));
    const reply = { ok: true, status: 200, json: () => Promise.resolve({ features }) };
    const stale = { ok: true, status: 200, json: () => Promise.resolve({ features: [feature('Lonely', 'X', 'Y')] }) };
    return slowFirst && calls.length === 1 ? new Promise(r => setTimeout(() => r(stale), 600)) : Promise.resolve(reply);
  };
  w.eval(src('js/location-autocomplete.js'));
  const d = w.document, input = d.getElementById('job_location');
  input.focus(); await wait(10);
  return { w, d, input, calls, panel: d.querySelector('.g2rd-wpjm-geo-ac') };
}

const REGIONS = [
  { code: '11', name: 'Île-de-France', aliases: ['IDF', 'Région parisienne'] },
  { code: '28', name: 'Normandie', aliases: ['Haute-Normandie', 'Basse-Normandie'] },
  { code: '84', name: 'Auvergne-Rhône-Alpes', aliases: ['AURA', 'Auvergne', 'Rhône-Alpes'] },
  { code: '93', name: "Provence-Alpes-Côte d'Azur", aliases: ['PACA', 'Région Sud'] }
];
const TAGS_I18N = { remove: 'Retirer %s', added: 'Lieu ajouté : %s', removed: 'Lieu retiré : %s', duplicate: '%s figure déjà dans la liste.', limit: '3 lieux au plus.', required: 'Indiquez au moins un lieu.' };

// Champ à étiquettes seul (sans l'autocomplétion), plafond de 3 lieux.
function tagField({ value = '', required = false, max = 3 } = {}) {
  const dom = new JSDOM('<form><label for="job_location">Lieu</label><input type="text" id="job_location" name="job_location" placeholder="ex. Paris"' + (required ? ' required' : '') + ' value="' + value + '"></form>', { runScripts: 'outside-only' });
  const w = dom.window;
  w.eval(src('js/location-tags.js'));
  const d = w.document, native = d.getElementById('job_location');
  let changes = 0; native.addEventListener('change', () => changes++);
  const field = w.g2rdWpjmGeoTags.create(native, { regions: REGIONS, max, i18n: TAGS_I18N });
  return { w, d, native, field, input: field.input, tags: () => [...d.querySelectorAll('.g2rd-wpjm-geo-tags__tag')], live: () => d.querySelector('.g2rd-wpjm-geo-tags__live').textContent, changes: () => changes };
}

// Autocomplétion Photon en mode étiquettes, comme le PHP la configure en mode libre.
async function tagsAc({ features = null, max = 10, required = false, regions = REGIONS } = {}) {
  const dom = new JSDOM('<form id="f"><label for="job_location">Lieu</label><input type="text" id="job_location" name="job_location" placeholder="ex. Paris"' + (required ? ' required' : '') + '><button id="go">Envoyer</button></form>', { runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window, calls = [];
  w.g2rdWpjmGeoAutocomplete = { source: 'photon', photonUrl: 'https://photon.test/api/', lang: 'fr', selectors: ['#job_location'], minLength: 2, tags: true, maxPlaces: max, regions, tagsI18n: TAGS_I18N,
    i18n: { poweredBy: 'Recherche (c) OpenStreetMap contributors', results: 'Suggestions', region: 'Région' } };
  w.AbortController = class { constructor() { this.signal = { aborted: false }; } abort() { this.signal.aborted = true; } };
  const feature = (name, county, state) => ({ properties: { name, county, state, country: 'France', osm_key: 'place' } });
  w.fetch = url => {
    calls.push(url);
    const q = new URL(url).searchParams.get('q').toLowerCase();
    const list = features || (q.indexOf('ro') === 0 ? [feature('Rouen', 'Seine-Maritime', 'Normandie'), feature('Roubaix', 'Nord', 'Hauts-de-France')] : []);
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ features: list }) });
  };
  w.document.hasFocus = () => true; // jsdom : hasFocus() est toujours faux (aucune fenetre active) ; un vrai navigateur renvoie vrai quand le focus change de champ.
  w.eval(src('js/location-tags.js'));
  w.eval(src('js/location-autocomplete.js'));
  await wait(10); // jsdom : document encore « loading », l'initialisation attend DOMContentLoaded.
  const d = w.document, native = d.getElementById('job_location');
  const input = d.querySelector('.g2rd-wpjm-geo-tags__input');
  if (input) { input.focus(); await wait(10); }
  return { w, d, native, input, calls, panel: d.querySelector('.g2rd-wpjm-geo-ac'), tags: () => [...d.querySelectorAll('.g2rd-wpjm-geo-tags__tag')], options: () => [...d.querySelectorAll('.g2rd-wpjm-geo-ac [role=option]')] };
}

const multiJobs = [
  { id: 11, title: 'Multi', permalink: 'https://x.test/job/11', company: 'M', city: 'Paris', country: 'France', latitude: 48.856614, longitude: 2.352222, job_type: '', places: [{ type: 'city', label: 'Paris', lat: 48.856614, lng: 2.352222 }, { type: 'city', label: 'Lyon', lat: 45.764, lng: 4.8357 }, { type: 'region', label: 'Normandie', lat: 49.1202, lng: 0.1128, region: '28', bbox: [-1.946, 48.182, 1.802, 50.069] }] },
  { id: 12, title: '<img src=x onerror=alert(1)>Region', permalink: 'https://x.test/job/12', company: 'R', city: '', country: 'France', latitude: 49.1202, longitude: 0.1128, job_type: '', places: [{ type: 'region', label: 'Normandie', lat: 49.1202, lng: 0.1128, region: '28', bbox: [-1.946, 48.182, 1.802, 50.069] }] },
  { id: 13, title: 'Bretagne seule', permalink: 'https://x.test/job/13', company: 'B', city: '', country: 'France', latitude: 48.1794, longitude: -2.8331, job_type: '', places: [{ type: 'region', label: 'Bretagne', lat: 48.1794, lng: -2.8331, region: '53', bbox: [-5.141, 47.2879, -1.0209, 48.867] }] }
];
const cityAndRegion = { id: 14, title: 'Lyon et Normandie', permalink: 'https://x.test/job/14', company: 'L', city: 'Lyon', country: 'France', latitude: 45.764, longitude: 4.8357, job_type: '', places: [{ type: 'city', label: 'Lyon', lat: 45.764, lng: 4.8357 }, { type: 'region', label: 'Normandie', lat: 49.1202, lng: 0.1128, region: '28', bbox: [-1.946, 48.182, 1.802, 50.069] }] };
const regionNoBox = { id: 15, title: 'Sans boite', permalink: 'https://x.test/job/15', company: 'S', city: '', country: 'France', latitude: 48.1794, longitude: -2.8331, job_type: '', places: [{ type: 'region', label: 'Bretagne', lat: 48.1794, lng: -2.8331, region: '53' }] };

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
    check('API exposee : un seul objet global, 14 fonctions', 'parseJson,sprintf,safeUrl,placeLabel,buildInfoContent,buildRegionContent,regionTitle,jobCount,groupByCoordinates,splitPlaces,createFetcher,observe,status,places' === Object.keys(C).join(','));
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
  check('cadrage automatique sur les 4 lieux, marge de 48 sans region', !!o.st.fit && o.st.fit.b.pts.length === 4 && o.st.fit.o.padding === 48);
  check('carte prete, message masque', o.status.hidden && o.el.classList.contains('is-ready'));
  o.st.rendered = [{ layer: { id: 'g2rd-points' } }]; o.st.map.fire('mousemove', { point: {} });
  const curOn = o.st.canvas.style.cursor;
  o.st.rendered = []; o.st.map.fire('mousemove', { point: {} });
  check('curseur sur les points et regroupements : main au-dessus, vide ailleurs, seuls les calques presents interroges', 'pointer' === curOn && '' === o.st.canvas.style.cursor && 'g2rd-points,g2rd-clusters' === o.st.queryLayers.join());
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
  o = await osmMap({ data: jobs });
  check('interface MapLibre traduite, valeurs vides retirees', 'Fermer' === o.st.mapOpts.locale['Popup.Close'] && !('Map.Title' in o.st.mapOpts.locale));
  check('attribution native de MapLibre desactivee (elle passe par innerHTML)', false === o.st.mapOpts.attributionControl);
  const at = o.st.attribNode;
  const links = at ? Array.from(at.querySelectorAll('a')) : [];
  check('attribution rendue par le plugin : credits du style en texte (obligation OpenStreetMap)', !!at && /OpenFreeMap/.test(at.textContent) && /OpenMapTiles/.test(at.textContent) && /OpenStreetMap/.test(at.textContent));
  check('attribution : seuls les liens http(s) du style sont conserves (javascript: retire)', 3 === links.length && links.every(a => a.href.indexOf('https://') === 0 && a.rel.indexOf('noopener') !== -1));
  check('attribution : aucun balisage du style (img, iframe, gras, gestionnaires) ni lien javascript:', !!at && 0 === at.querySelectorAll('img,iframe,b,script').length && !/javascript:|onerror|onmouseover|srcdoc/i.test(at.innerHTML) && !o.w.pwned);
  check('boutons de zoom et plein ecran, sans rotation, attribution en bas a droite', 'nav,full,attrib' === o.st.controls.join() && true === o.st.noRotate && 'bottom-right' === o.st.attribPos);
  check('grand ecran : attribution deployee, sans bouton', !!at && !at.classList.contains('maplibregl-compact') && !at.querySelector('button'));
  const narrowMap = await osmMap({ data: jobs, narrow: true });
  const nAt = narrowMap.st.attribNode;
  const nBtn = nAt ? nAt.querySelector('button.maplibregl-ctrl-attrib-button') : null;
  check('petit ecran : attribution compacte avec bouton nomme', !!nAt && nAt.classList.contains('maplibregl-compact') && !!nBtn && 'button' === nBtn.type && !!nBtn.getAttribute('aria-label'));
  if (nBtn) nBtn.click();
  const shown = !!nAt && nAt.classList.contains('maplibregl-compact-show');
  if (nBtn) nBtn.click();
  check('petit ecran : le bouton affiche puis masque les credits', shown && !nAt.classList.contains('maplibregl-compact-show'));
  const places = o.w.document.querySelector('.g2rd-wpjm-geo-map + .g2rd-wpjm-geo-map__places');
  check('liste des offres sous la carte, pour le clavier', !!places && 'Liste' === places.querySelector('summary').textContent && places.children.length > 1);
  o = await osmMap({ data: jobs, styleFail: true });
  o.st.map.fire('load', {});
  await wait(10);
  check('erreur passagere avant le chargement : etat d erreur retire', !o.el.classList.contains('has-error') && o.el.classList.contains('is-ready'));
  o = await osmMap({ data: [] });
  check('aucune offre : pas de liste vide', !o.w.document.querySelector('.g2rd-wpjm-geo-map__places'));

  console.log('\nJ. Autocompletion en mode libre (Photon)');
  let p = await photonAc();
  p.input.value = 'Lond'; p.input.dispatchEvent(new p.w.Event('input', { bubbles: true }));
  await wait(200);
  check('attente de 300 ms avant la requete', p.calls.length === 0);
  await wait(250);
  const u = p.calls[0] && p.calls[0].url;
  check('requete Photon : texte, limite 6, langue fr, 4 filtres de lieu, sans Google', p.calls.length === 1 && u.indexOf('https://photon.test/api/?q=Lond&limit=6&lang=fr') === 0 && ['city', 'town', 'village', 'municipality'].every(v => u.indexOf('osm_tag=place%3A' + v) !== -1) && !p.w.g2rdWpjmGeoLoader);
  check('3 suggestions, attribution OpenStreetMap', p.panel.querySelectorAll('[role=option]').length === 3 && /OpenStreetMap/.test(p.panel.textContent) && !p.panel.hidden);
  check('suggestion malveillante inseree comme texte', p.panel.querySelectorAll('img').length === 0);
  key(p.w, p.input, 'ArrowDown'); key(p.w, p.input, 'Enter'); await wait(10);
  check('libelle ecrit : Nom, Region, Pays', p.input.value === 'Londres, Angleterre, Royaume-Uni' && p.panel.hidden);
  await type(p, 'Lond');
  check('meme saisie : reponse memorisee, pas de nouvelle requete', p.calls.length === 1);
  p = await photonAc({ status400: true });
  await type(p, 'Lond');
  check('HTTP 400 : nouvel essai sans filtre', p.calls.length === 2 && p.calls[1].url.indexOf('osm_tag') === -1);
  check('repli : seuls les lieux sont proposes (pub ecarte)', p.panel.querySelectorAll('[role=option]').length === 3 && !/London Pub/.test(p.panel.textContent));
  await type(p, 'Londr');
  check('repli memorise : plus de filtre ensuite', p.calls.length === 3 && p.calls[2].url.indexOf('osm_tag') === -1);
  p = await photonAc({ slowFirst: true });
  p.input.value = 'Lon'; p.input.dispatchEvent(new p.w.Event('input', { bubbles: true })); await wait(350);
  p.input.value = 'Lond'; p.input.dispatchEvent(new p.w.Event('input', { bubbles: true })); await wait(350);
  check('frappe suivante : requete precedente annulee', p.calls.length === 2 && p.calls[0].opt.signal.aborted === true);
  await wait(400);
  check('reponse obsolete ignoree, liste de la derniere saisie', p.panel.querySelectorAll('[role=option]').length === 3 && !/Lonely/.test(p.panel.textContent));
  p = await photonAc({ realAbort: true });
  p.input.value = 'Lon'; p.input.dispatchEvent(new p.w.Event('input', { bubbles: true })); await wait(350);
  await type(p, 'Lond'); await type(p, 'Londr');
  check('requete annulee (AbortError) : autocompletion toujours active', p.calls.length === 3 && p.calls[0].opt.signal.aborted && p.panel.querySelectorAll('[role=option]').length === 3);
  p = await photonAc();
  await type(p, 'Lond'); key(p.w, p.input, 'ArrowDown');
  await type(p, 'Londr');
  check('nouvelle liste : aucune option active annoncee', !p.input.hasAttribute('aria-activedescendant'));
  const saintMartin = county => ({ properties: { name: 'Saint-Martin', county, state: 'Occitanie', country: 'France', osm_key: 'place' } });
  p = await photonAc({ features: [saintMartin('Gers'), saintMartin('Hautes-Pyrénées'), saintMartin('Gers')] });
  await type(p, 'Saint-Martin');
  check('homonymes d une meme region : departement ajoute, doublon retire', 2 === p.panel.querySelectorAll('[role=option]').length && /Hautes-Pyr/.test(p.panel.textContent));
  key(p.w, p.input, 'ArrowDown'); key(p.w, p.input, 'Enter'); await wait(10);
  check('libelle ecrit avec le departement', 'Saint-Martin, Gers, Occitanie, France' === p.input.value);
  p = await photonAc({ fail: true });
  await type(p, 'Lond');
  check('Photon injoignable : pas de liste, champ utilisable', p.panel.hidden && p.input.value === 'Lond');
  await type(p, 'Londr');
  check('apres une panne : plus aucune requete', p.calls.length === 1);

  console.log('\nK. Champ Lieu a etiquettes (location-tags.js)');
  {
    const tw = new JSDOM('<p></p>', { runScripts: 'outside-only' }).window;
    tw.eval(src('js/location-tags.js'));
    const T = tw.g2rdWpjmGeoTags;
    check('decoupage : separateurs forts et « et », mentions vagues et doublons retires', 'Paris|Lyon|Caen' === T.split('Paris et Lyon / Télétravail ; paris ; Caen').join('|'));
    check('decoupage comme le serveur : <br> separe, 30 morceaux au plus', 'Paris|Lyon' === T.split('Paris<br />Lyon').join('|') && 30 === T.split(Array.from({ length: 50 }, (_, i) => 'Lieu' + i).join(';')).length);
    check('decoupage comme le serveur : balises retirees, 2 000 caracteres lus', 'Paris|Lyon' === T.split('<p>Paris</p> ; Lyon').join('|') && 'Mo' === T.split('a'.repeat(1995) + ' ; Montpellier')[1]);
    const idx = T.regionIndex(REGIONS);
    check('region exacte : nom, sigle, article, prefixe « Region » ; ville refusee', '11' === T.findRegion("l'Île-de-France", idx).code && '93' === T.findRegion('paca', idx).code && '28' === T.findRegion('Région Normandie', idx).code && null === T.findRegion('Rouen', idx));
    check('recherche : debut d un nom (variante « Region Sud » comprise), puis debut d un mot (3 lettres)', 'Normandie' === T.searchRegions('norm', idx, 6)[0].region.name && '93' === T.searchRegions('Région Sud', idx, 6)[0].region.code && 2 === T.searchRegions('alpes', idx, 6).length && 0 === T.searchRegions('al', idx, 6).length);
  }
  let f = tagField({});
  check('champ natif masque (attribut et style) mais envoye, saisie visible juste apres', f.native.hidden && 'none' === f.native.style.display && 'job_location' === f.native.name && f.native.nextElementSibling.classList.contains('g2rd-wpjm-geo-tags') && f.native.nextElementSibling.contains(f.input));
  check('libelle redirige vers la saisie, exemple repris, un seul champ nomme', f.d.querySelector('label').htmlFor === f.input.id && 'ex. Paris' === f.input.placeholder && 1 === f.d.querySelectorAll('input[name]').length);
  f.field.add('Île-de-France', true);
  check('ajout d une region : etiquette coloree, champ natif et evenement, annonce', 1 === f.tags().length && f.tags()[0].classList.contains('g2rd-wpjm-geo-tags__tag--region') && 'Île-de-France' === f.native.value && 1 === f.changes() && 'Lieu ajouté : Île-de-France' === f.live());
  f.field.add('Rouen, Normandie, France');
  const rouen = f.tags()[1];
  check('ajout d une ville : libelle complet garde, nom seul affiche', 'Île-de-France ; Rouen, Normandie, France' === f.native.value && 'Rouen' === rouen.querySelector('.g2rd-wpjm-geo-tags__text').textContent && 'Rouen, Normandie, France' === rouen.title && !rouen.classList.contains('g2rd-wpjm-geo-tags__tag--region'));
  check('doublon refuse (accents, casse) et annonce', false === f.field.add('rouen, normandie, france') && 2 === f.tags().length && 'rouen, normandie, france figure déjà dans la liste.' === f.live());
  const remove = f.tags()[0].querySelector('button');
  check('bouton de retrait nomme', 'Retirer Île-de-France' === remove.getAttribute('aria-label') && 'button' === remove.type);
  remove.click();
  check('retrait par le bouton : champ natif mis a jour, annonce', 'Rouen, Normandie, France' === f.native.value && 'Lieu retiré : Île-de-France' === f.live());
  key(f.w, f.input, 'Backspace');
  check('Retour arriere dans une saisie vide : derniere etiquette retiree', 0 === f.tags().length && '' === f.native.value);
  f.input.value = 'Normandie ; Lille / normandie'; f.input.dispatchEvent(new f.w.Event('input', { bubbles: true }));
  check('collage avec separateurs : plusieurs etiquettes, doublon ignore, region reconnue', 'Normandie ; Lille' === f.native.value && f.tags()[0].classList.contains('g2rd-wpjm-geo-tags__tag--region') && !f.tags()[1].classList.contains('g2rd-wpjm-geo-tags__tag--region') && '' === f.input.value);
  f.input.value = 'Brest'; f.field.commit();
  check('texte en cours valide : etiquette ajoutee, saisie videe', 'Normandie ; Lille ; Brest' === f.native.value && '' === f.input.value);
  f.input.value = 'Caen'; f.field.commit();
  const notice = f.d.querySelector('.g2rd-wpjm-geo-tags__notice');
  check('plafond : lieu refuse, message visible et annonce, texte garde', 3 === f.tags().length && !notice.hidden && '3 lieux au plus.' === notice.textContent && '3 lieux au plus.' === f.live() && 'Caen' === f.input.value);
  f.tags()[2].querySelector('button').click();
  check('retrait sous le plafond : message masque', notice.hidden);
  f = tagField({ value: '&lt;img src=x onerror=alert(1)&gt; ; Normandie ; Paris, Lyon' });
  check('reouverture : balises retirees comme sur le serveur, region coloree, aucun evenement', 2 === f.tags().length && 0 === f.d.querySelectorAll('.g2rd-wpjm-geo-tags img').length && f.tags()[0].classList.contains('g2rd-wpjm-geo-tags__tag--region') && 'Paris' === f.tags()[1].querySelector('.g2rd-wpjm-geo-tags__text').textContent && 0 === f.changes());
  f.field.add('<b>Gras</b>');
  check('texte d une etiquette insere comme texte', 0 === f.d.querySelectorAll('.g2rd-wpjm-geo-tags b').length && '<b>Gras</b>' === f.tags()[2].querySelector('.g2rd-wpjm-geo-tags__text').textContent);
  f = tagField({ required: true });
  check('champ obligatoire : verifie sur la saisie des etiquettes', !f.native.required && !f.input.checkValidity() && 'Indiquez au moins un lieu.' === f.input.validationMessage);
  f.field.add('Brest');
  check('champ obligatoire : une etiquette suffit', f.input.checkValidity());
  f = tagField({ required: true });
  f.input.value = 'Télétravail'; f.field.commit();
  check('mention sans lieu (Teletravail) gardee en etiquette : offre en teletravail enregistrable', 1 === f.tags().length && 'Télétravail' === f.native.value && f.input.checkValidity() && 'true' === f.input.getAttribute('aria-required'));
  f = tagField({ value: 'Rouen ; Caen ; Brest ; Lille ; Nantes' });
  check('reouverture au-dela du plafond : rien de perdu, reste dans la saisie, aucun message', 3 === f.tags().length && 'Lille ; Nantes' === f.input.value && f.d.querySelector('.g2rd-wpjm-geo-tags__notice').hidden && '' === f.live());
  f.tags()[0].querySelector('button').click();
  check('retrait apres reouverture : le champ natif garde les lieux restes dans la saisie', 'Caen ; Brest ; Lille ; Nantes' === f.native.value);
  f.input.value = ''; f.field.commit();
  check('texte efface de la saisie : retire aussi du champ natif', 'Caen ; Brest' === f.native.value);
  f = tagField({ value: 'Auvergne ; Rhône-Alpes' });
  check('reouverture : deux anciens noms d une meme region gardes tels quels', 2 === f.tags().length && false === f.field.add('AURA') && 'Auvergne ; Rhône-Alpes' === f.native.value);
  f = tagField({ value: 'Rouen ; Caen ; Brest' });
  f.input.value = 'Rouen'; f.field.commit();
  check('au plafond, un doublon tape n est pas garde', 'Rouen ; Caen ; Brest' === f.native.value && '' === f.input.value);
  f = tagField({ value: 'Paris;Lyon' });
  f.field.commit();
  check('passage sans modification : texte enregistre non reecrit, aucun evenement', 'Paris;Lyon' === f.native.value && 0 === f.changes());
  f = tagField({ max: 10 });
  f.field.add('Rouen'); f.field.add('Caen');
  f.input.value = 'xyz';
  f.tags()[1].querySelector('button').click();
  f.input.value = ''; f.field.commit();
  check('texte non valide puis efface apres un retrait : absent du champ natif', 'Rouen' === f.native.value);
  f = tagField({ max: 10 });
  f.field.add('IDF');
  check('meme region sous deux ecritures : une seule etiquette', false === f.field.add('Île-de-France', '11') && false === f.field.add("Région l'Île-de-France") && 1 === f.tags().length);
  f.field.add("Saint-Martin $' test");
  check('libelle avec « $ » : intitule du bouton intact', "Retirer Saint-Martin $' test" === f.tags()[1].querySelector('button').getAttribute('aria-label'));
  f.input.dispatchEvent(new f.w.KeyboardEvent('keydown', { key: 'Backspace', repeat: true, bubbles: true }));
  check('Retour arriere maintenu (repetition) : aucune etiquette retiree', 2 === f.tags().length);

  console.log('\nL. Autocompletion a etiquettes (mode libre)');
  let g = await tagsAc({});
  check('etiquettes creees par l autocompletion, combobox sur la saisie visible', !!g.input && 'combobox' === g.input.getAttribute('role') && !g.native.hasAttribute('role') && g.native.hidden);
  await type(g, 'idf');
  check('sigle : region proposee en tete, avec le nom saisi et une icone', /Île-de-France/.test(g.options()[0].textContent) && /Région \(IDF\)/.test(g.options()[0].textContent) && !!g.options()[0].querySelector('.g2rd-wpjm-geo-ac__icon'));
  key(g.w, g.input, 'ArrowDown'); key(g.w, g.input, 'Enter'); await wait(10);
  check('choix au clavier : etiquette de region, saisie videe, liste fermee', 1 === g.tags().length && g.tags()[0].classList.contains('g2rd-wpjm-geo-tags__tag--region') && '' === g.input.value && 'Île-de-France' === g.native.value && g.panel.hidden);
  await type(g, 'Rou');
  check('villes Photon proposees', 2 === g.options().length && /Rouen/.test(g.options()[0].textContent));
  g.options()[0].dispatchEvent(new g.w.MouseEvent('mousedown', { bubbles: true, cancelable: true })); await wait(10);
  check('choix a la souris : ville avec son libelle complet', 'Île-de-France ; Rouen, Normandie, France' === g.native.value && '' === g.input.value);
  g.input.value = 'Brest';
  const enterFree = key(g.w, g.input, 'Enter');
  check('Entree sans proposition : texte libre ajoute, formulaire non envoye', enterFree.defaultPrevented && 'Île-de-France ; Rouen, Normandie, France ; Brest' === g.native.value);
  g.input.value = 'Le Havre'; g.input.blur(); await wait(10);
  check('sortie du champ : texte en cours ajoute', / ; Le Havre$/.test(g.native.value));
  g = await tagsAc({});
  await type(g, 'paca');
  check('PACA : Provence-Alpes-Cote d Azur', /Provence-Alpes-Côte d'Azur/.test(g.options()[0].textContent));
  await type(g, 'Région Sud');
  check('« Region Sud » : Provence-Alpes-Cote d Azur', /Provence-Alpes-Côte d'Azur/.test(g.options()[0].textContent));
  await type(g, 'alpes');
  check('mot du nom (alpes) : deux regions', 2 === g.options().filter(o => /Région/.test(o.textContent)).length);
  g = await tagsAc({ features: [] });
  g.w.fetch = () => Promise.reject(new Error('reseau'));
  await type(g, 'norm');
  check('Photon injoignable : les regions restent proposees', 1 === g.options().length && /Normandie/.test(g.options()[0].textContent));

  // Réponses Photon différées : le test les résout lui-même.
  const deferFetch = g => { const waiting = []; g.w.fetch = () => new Promise(res => waiting.push(res)); return features => waiting.splice(0).forEach(res => res({ ok: true, status: 200, json: () => Promise.resolve({ features }) })); };
  const place = (name, county, state) => ({ properties: { name, county, state, country: 'France', osm_key: 'place' } });
  g = await tagsAc({});
  const answer = deferFetch(g);
  await type(g, 'Rou');
  key(g.w, g.input, 'Enter'); await wait(10);
  answer([place('Rouen', 'Seine-Maritime', 'Normandie')]); await wait(20);
  check('reponse arrivee apres Entree : panneau reste ferme', g.panel.hidden && 'false' === g.input.getAttribute('aria-expanded') && 'Rou' === g.native.value);
  g = await tagsAc({});
  await type(g, 'Rou');
  const old = g.options()[0];
  const answer2 = deferFetch(g);
  g.input.value = 'Roue'; g.input.dispatchEvent(new g.w.Event('input', { bubbles: true })); await wait(350);
  old.dispatchEvent(new g.w.MouseEvent('mousedown', { bubbles: true, cancelable: true })); await wait(10);
  answer2([place('Rouen', 'Seine-Maritime', 'Normandie')]); await wait(20);
  check('choix dans l ancienne liste pendant une requete : la liste ne se rouvre pas', g.panel.hidden && 'Rouen, Normandie, France' === g.native.value);
  g = await tagsAc({});
  g.input.value = 'Brest'; key(g.w, g.input, 'Enter');
  g.input.value = 'Lille'; key(g.w, g.input, 'Enter');
  g.input.value = 'Nantes';
  const x = g.d.querySelector('.g2rd-wpjm-geo-tags__remove');
  x.focus(); await wait(10);
  check('« × » focalise : la sortie du champ valide la saisie sans le detacher', 3 === g.tags().length && x.isConnected && g.d.activeElement === x);
  x.click(); await wait(10);
  check('« × » : le clic retire bien la premiere etiquette', 'Lille ; Nantes' === g.native.value && 2 === g.tags().length);
  g = await tagsAc({ features: [place('Paris', 'Paris', 'Île-de-France')] });
  await type(g, 'Paris');
  check('ville avant la region trouvee par un mot de son alias', !/Région/.test(g.options()[0].textContent) && /Paris/.test(g.options()[0].textContent) && /Île-de-France/.test(g.options()[1].textContent) && /Région/.test(g.options()[1].textContent));

  // B1 : régions affichées sans attendre Photon.
  g = await tagsAc({});
  g.w.fetch = () => new Promise(() => {});
  await type(g, 'norm');
  check('Photon qui ne repond jamais : la region est proposee tout de suite', 1 === g.options().length && /Normandie/.test(g.options()[0].textContent));
  // B1 : l'option atteinte au clavier reste active quand la liste fusionnée arrive.
  g = await tagsAc({});
  const answer3 = deferFetch(g);
  g.input.value = 'norm'; g.input.dispatchEvent(new g.w.Event('input', { bubbles: true })); await wait(350);
  key(g.w, g.input, 'ArrowDown');
  answer3([place('Normandel', 'X', 'Normandie')]); await wait(30);
  check('liste fusionnee : option atteinte au clavier retrouvee', 2 === g.options().length && 'true' === g.options()[0].getAttribute('aria-selected') && /Normandie/.test(g.options()[0].textContent));
  // B2 : choix au plafond.
  g = await tagsAc({ max: 2 });
  g.input.value = 'Brest'; key(g.w, g.input, 'Enter');
  g.input.value = 'Lille'; key(g.w, g.input, 'Enter');
  await type(g, 'Rou');
  g.options()[0].dispatchEvent(new g.w.MouseEvent('mousedown', { bubbles: true, cancelable: true })); await wait(10);
  check('choix au plafond : etiquette refusee, texte tape garde dans la saisie', 2 === g.tags().length && 'Rou' === g.input.value);
  g = await tagsAc({ max: 2 });
  g.input.value = 'Normandie'; key(g.w, g.input, 'Enter');
  g.input.value = 'Lille'; key(g.w, g.input, 'Enter');
  await type(g, 'norm');
  g.options()[0].dispatchEvent(new g.w.MouseEvent('mousedown', { bubbles: true, cancelable: true })); await wait(10);
  check('choix au plafond : un doublon est abandonne', 2 === g.tags().length && '' === g.input.value);
  // B3 : séparateurs dans le libellé choisi.
  g = await tagsAc({ features: [place('Biel/Bienne', 'X', 'Berne')] });
  await type(g, 'Bie');
  g.options()[0].dispatchEvent(new g.w.MouseEvent('mousedown', { bubbles: true, cancelable: true })); await wait(10);
  check('libelle avec « / » : une seule etiquette, non decoupee a la reouverture', 1 === g.tags().length && 'Biel-Bienne, Berne, France' === g.native.value && 1 === g.w.g2rdWpjmGeoTags.split(g.native.value).length);
  // B4 : Entrée sur une saisie vide, ou panneau ouvert sans option active.
  g = await tagsAc({});
  const emptyEnter = key(g.w, g.input, 'Enter');
  check('Entree sur une saisie vide : le formulaire part normalement', !emptyEnter.defaultPrevented);
  await type(g, 'Rou');
  check('liste ouverte, aucune option active', !g.panel.hidden && g.panel.querySelector('.is-active') === null);
  const openEnter = key(g.w, g.input, 'Enter'); await wait(10);
  check('Entree liste ouverte sans option active : texte valide, formulaire non envoye', openEnter.defaultPrevented && 1 === g.tags().length && 'Rou' === g.native.value && g.panel.hidden);
  g = await tagsAc({});
  g.input.value = '   ';
  check('Entree sur des espaces : le formulaire part normalement', !key(g.w, g.input, 'Enter').defaultPrevented);
  // B5 : Tab.
  g = await tagsAc({});
  await type(g, 'Rou');
  key(g.w, g.input, 'ArrowDown');
  const tabEvent = key(g.w, g.input, 'Tab'); g.input.blur(); await wait(10);
  check('Tab sur une option atteinte au clavier : elle est choisie, le focus continue', !tabEvent.defaultPrevented && /Rouen, Normandie, France$/.test(g.native.value) && '' === g.input.value);
  g = await tagsAc({});
  await type(g, 'Rou');
  g.options()[0].dispatchEvent(new g.w.MouseEvent('mouseenter', { bubbles: true }));
  key(g.w, g.input, 'Tab'); g.input.blur(); await wait(10);
  check('Tab apres un simple survol : le texte tape est valide', 'Rou' === g.native.value);
  // B6 : changement de fenêtre.
  g = await tagsAc({});
  g.d.hasFocus = () => false;
  await type(g, 'Rou');
  g.input.blur(); await wait(10);
  check('fenetre sans focus : le texte reste dans la saisie, sans etiquette', 'Rou' === g.input.value && 0 === g.tags().length);
  // B7 : clic dans le panneau hors d'une option.
  g = await tagsAc({});
  await type(g, 'Rou');
  const down = new g.w.MouseEvent('mousedown', { bubbles: true, cancelable: true });
  g.panel.querySelector('.g2rd-wpjm-geo-ac__attribution').dispatchEvent(down);
  check('clic sur l attribution : mousedown annule, la liste reste ouverte', down.defaultPrevented && !g.panel.hidden);
  // B8 : position du panneau.
  g = await tagsAc({});
  g.input.getBoundingClientRect = () => ({ top: 10, bottom: 50, left: 120, width: 80 });
  g.d.querySelector('.g2rd-wpjm-geo-tags').getBoundingClientRect = () => ({ top: 8, bottom: 52, left: 40, width: 300 });
  await type(g, 'norm');
  check('panneau : sous la saisie, largeur et bord gauche de la zone a etiquettes', '50px' === g.panel.style.top && '40px' === g.panel.style.left && '300px' === g.panel.style.width);
  // B9 : styles et description du champ natif.
  {
    const dom = new JSDOM('<form><label for="job_location">Lieu</label><input type="text" class="input-text is-invalid" id="job_location" name="job_location"><small class="description">Aide</small></form>', { runScripts: 'outside-only' });
    dom.window.eval(src('js/location-tags.js'));
    const f = dom.window.g2rdWpjmGeoTags.create(dom.window.document.getElementById('job_location'), { regions: REGIONS, max: 3, i18n: TAGS_I18N });
    const desc = dom.window.document.querySelector('small.description');
    check('saisie visible : seule la classe input-text est reprise (pas une classe d etat)', f.input.classList.contains('input-text') && f.input.classList.contains('g2rd-wpjm-geo-tags__input') && !f.input.classList.contains('is-invalid'));
    check('saisie visible : aria-describedby vers la description', !!desc.id && f.input.getAttribute('aria-describedby') === desc.id);
    const dom2 = new JSDOM('<form><input type="text" id="job_location" aria-describedby="mine"><small class="description">Aide</small></form>', { runScripts: 'outside-only' });
    dom2.window.eval(src('js/location-tags.js'));
    const f2 = dom2.window.g2rdWpjmGeoTags.create(dom2.window.document.getElementById('job_location'), { regions: REGIONS, max: 3, i18n: TAGS_I18N });
    check('aria-describedby deja present sur le champ natif : recopie', 'mine' === f2.input.getAttribute('aria-describedby'));
  }
  // B12 : champ obligatoire avec l'autocomplétion.
  g = await tagsAc({ required: true });
  check('champ obligatoire : saisie invalide au depart', false === g.input.checkValidity());
  await type(g, 'Rou');
  g.options()[0].dispatchEvent(new g.w.MouseEvent('mousedown', { bubbles: true, cancelable: true })); await wait(10);
  check('champ obligatoire : valide apres le choix d une suggestion', true === g.input.checkValidity() && true === g.d.getElementById('f').checkValidity());

  // Rendu immediat : seules les regions dont le nom commence par la saisie, les autres apres les villes.
  g = await tagsAc({});
  const answer4 = deferFetch(g);
  await type(g, 'Paris');
  check('« Paris » : aucune option avant la reponse de Photon (region trouvee par un mot de son alias)', 0 === g.options().length);
  answer4([place('Paris', 'Paris', 'Île-de-France')]); await wait(30);
  check('« Paris » : la ville vient en premier a l arrivee de Photon', !/Région/.test(g.options()[0].textContent) && /Région/.test(g.options()[1].textContent));
  g = await tagsAc({});
  deferFetch(g);
  await type(g, 'norm');
  check('« norm » : Normandie tout de suite', 1 === g.options().length && /Normandie/.test(g.options()[0].textContent));
  g = await tagsAc({ regions: REGIONS.concat([{ code: '76', name: 'Occitanie', aliases: ['Languedoc-Roussillon'] }]) });
  const answer5 = deferFetch(g);
  await type(g, 'Rou');
  check('« Rou » : rien avant la reponse de Photon', 0 === g.options().length);
  answer5([place('Rouen', 'Seine-Maritime', 'Normandie'), place('Roubaix', 'Nord', 'Hauts-de-France')]); await wait(30);
  check('« Rou » : Rouen et Roubaix devant Occitanie', /Rouen/.test(g.options()[0].textContent) && /Roubaix/.test(g.options()[1].textContent) && /Occitanie/.test(g.options()[2].textContent));
  // Photon en echec : la liste locale complete reste affichee.
  g = await tagsAc({});
  g.w.fetch = () => Promise.reject(new Error('reseau'));
  await type(g, 'alpes');
  check('Photon en echec : regions trouvees par un mot affichees', 2 === g.options().length);
  // Toute frappe desactive l'option surlignee.
  for (const k of ['Tab', 'Enter']) {
    g = await tagsAc({});
    await type(g, 'Rou');
    key(g.w, g.input, 'ArrowDown');
    g.input.value = 'Roubaix'; g.input.dispatchEvent(new g.w.Event('input', { bubbles: true }));
    key(g.w, g.input, k); g.input.blur(); await wait(10);
    check('frappe apres une fleche (' + k + ') : la correction est gardee, pas la ville surlignee', /Roubaix$/.test(g.native.value) && !/Rouen/.test(g.native.value));
  }
  g = await tagsAc({});
  const answer6 = deferFetch(g);
  g.input.value = 'norm'; g.input.dispatchEvent(new g.w.Event('input', { bubbles: true })); await wait(350);
  key(g.w, g.input, 'ArrowDown');
  answer6([place('Normandel', 'X', 'Normandie')]); await wait(30);
  key(g.w, g.input, 'Tab'); g.input.blur(); await wait(10);
  check('Tab apres la fusion : l option atteinte au clavier est choisie', 'Normandie' === g.native.value);
  // B6 : retour dans la page.
  g = await tagsAc({});
  g.d.hasFocus = () => false;
  await type(g, 'Rou');
  g.input.blur(); await wait(10);
  g.d.hasFocus = () => true;
  g.input.focus(); await wait(10); g.input.blur(); await wait(10);
  check('retour dans la page : le texte laisse devient une etiquette', 'Rou' === g.native.value && 1 === g.tags().length);

  console.log('\nM. Lieux multiples (map-common.js)');
  {
    const lw = new JSDOM('<p></p>', { runScripts: 'outside-only', url: 'https://x.test/' }).window;
    lw.eval(src('js/map-common.js'));
    const C = lw.g2rdWpjmGeoMapCommon;
    const mi = { view: 'Voir', alsoAt: 'Aussi à :', regionTitle: '%s (toute la région)', oneJob: '1 offre', manyJobsShort: '%d offres' };
    const sp = C.splitPlaces([
      { id: 1, title: 'A', city: 'Paris', country: 'France', latitude: 48.85, longitude: 2.35, places: [{ type: 'city', label: 'Paris', lat: 48.85, lng: 2.35 }, { type: 'city', label: 'Lyon', lat: 45.76, lng: 4.83 }, { type: 'region', label: 'Normandie', lat: 49.12, lng: 0.11, region: '28' }] },
      { id: 2, title: '<img src=x onerror=alert(1)>', city: '', country: 'France', latitude: 49.12, longitude: 0.11, places: [{ type: 'region', label: 'Normandie', lat: 49.12, lng: 0.11, region: '28' }] },
      { id: 3, title: 'C', city: 'Rouen', country: 'France', latitude: 49.44, longitude: 1.09 },
      { id: 4, title: 'D', places: [{ type: 'region', label: 'Bretagne', lat: 'x', lng: 0, region: '53' }, { type: 'pays', label: 'France', lat: 46, lng: 2 }] }
    ]);
    check('un point par ville, une entree par region, offre sans liste gardee, lieux invalides ignores', 3 === sp.points.length && 1 === sp.regions.length && 2 === sp.regions[0].jobs.length && '28' === sp.regions[0].code && 49.12 === sp.regions[0].position.lat);
    check('point de Lyon : coordonnees et libelle du lieu, autres lieux de l offre', 45.76 === sp.points[1].latitude && 'Lyon' === sp.points[1].city && 'Paris, Normandie' === sp.points[1]._also.join(', '));
    check('offre sans liste : point d origine, aucun autre lieu', 49.44 === sp.points[2].latitude && 0 === sp.points[2]._also.length);
    const node = C.buildInfoContent([sp.points[1]], mi);
    check('fenetre : ligne « Aussi a » en texte', /Aussi à : Paris, Normandie/.test(node.textContent) && !!node.querySelector('.g2rd-wpjm-geo-iw__also'));
    const rnode = C.buildRegionContent(sp.regions[0], mi);
    check('fenetre de region : titre, nombre d offres, liste, titre malveillant en texte', /Normandie \(toute la région\) — 2 offres/.test(rnode.textContent) && 2 === rnode.querySelectorAll('li').length && 0 === rnode.querySelectorAll('img').length);
    check('nombre d offres : singulier et pluriel', '1 offre' === C.jobCount(1, mi) && '3 offres' === C.jobCount(3, mi));
    check('titre de region : motifs $ du libelle laisses tels quels', 'A$&B (toute la région) — 1 offre' === C.regionTitle({ label: 'A$&B', jobs: [{}] }, mi));
    const box = lw.document.createElement('div');
    lw.document.body.appendChild(box);
    C.places(box, C.groupByCoordinates(sp.points), { places: 'Liste', alsoAt: 'Aussi à :', regionTitle: '%s (toute la région)', oneJob: '1 offre', manyJobsShort: '%d offres' }, sp.regions);
    check('liste sous la carte : regions comprises', /Normandie \(toute la région\) — 2 offres/.test(box.nextElementSibling.textContent));
    let proto = null;
    try {
      proto = C.splitPlaces([{ id: 1, places: [{ type: 'region', label: 'X', lat: 1, lng: 1, region: 'constructor' }, { type: 'region', label: 'Y', lat: 2, lng: 2, region: '__proto__' }] }]);
    } catch (e) { proto = null; }
    check('code de region egal a une cle du prototype : deux entrees, sans exception', !!proto && 2 === proto.regions.length);
    const dupR = C.splitPlaces([{ id: 1, title: 'T', places: [{ type: 'region', label: 'Normandie', lat: 49, lng: 0, region: '28' }, { type: 'region', label: 'Normandie', lat: 49, lng: 0, region: '28' }] }]);
    check('meme region deux fois : une seule offre, pas de « Aussi a »', 1 === dupR.regions[0].jobs.length && !/Aussi à : Normandie/.test(C.buildInfoContent([dupR.regions[0].jobs[0]], mi).textContent));
    const dupC = C.splitPlaces([{ id: 1, places: [{ type: 'city', label: 'Paris', lat: 48.856611, lng: 2.35 }, { type: 'city', label: 'Paris 2', lat: 48.856613, lng: 2.35 }] }]);
    check('deux villes aux memes coordonnees (5 decimales) : un seul point', 1 === dupC.points.length);
    check('centre de region hors bornes : ecarte', 0 === C.splitPlaces([{ id: 1, places: [{ type: 'region', label: 'Z', lat: 200, lng: 0, region: '11' }] }]).regions.length);
    const noLbl = C.splitPlaces([{ id: 1, title: 'T', location: 'Rouen ; X ; Normandie', city: 'Rouen', places: [{ type: 'city', label: 'Rouen', lat: 49.44, lng: 1.09 }, { type: 'city', label: '', lat: 45.76, lng: 4.83 }] }]);
    check('ville d indice > 0 sans libelle : le champ Lieu brut n est pas affiche', !/Rouen ; X/.test(C.buildInfoContent([noLbl.points[1]], mi).textContent));
    const noI18n = C.buildInfoContent([{ title: 't', _also: ['Lyon'] }], {});
    check('« Aussi a » sans texte i18n : pas d espace initial', 'Lyon' === noI18n.querySelector('.g2rd-wpjm-geo-iw__also').textContent);
    const bb = C.splitPlaces([{ id: 1, places: [
      { type: 'region', label: 'N', lat: 49, lng: 0, region: '28', bbox: [-1.946, 48.182, 1.802, 50.069] },
      { type: 'region', label: 'B', lat: 48, lng: -2, region: '53', bbox: [-5, 48, -1, 'x'] },
      { type: 'region', label: 'C', lat: 47, lng: 2, region: '24', bbox: [3, 46, 1, 48] },
      { type: 'region', label: 'D', lat: 47, lng: 2, region: '11', bbox: [0, 46, 1, 100] },
      { type: 'region', label: 'E', lat: 47, lng: 2, region: '27' },
      { type: 'region', label: 'F', lat: 47, lng: 2, region: '44', bbox: [0, 46, 1] },
      { type: 'region', label: 'G', lat: 47, lng: 2, region: '32', bbox: ['0', '46', '1', '47'] }
    ] }]).regions;
    check('boite de region : recopiee quand valide, null sinon (non numerique, ouest >= est, hors bornes, absente, incomplete)', '-1.946,48.182,1.802,50.069' === bb[0].bounds.join() && bb.slice(1).every(function (r) { return null === r.bounds; }) && 6 === bb.length - 1);
    const again = C.splitPlaces(sp.points);
    check('second passage : pas de points en plus, aucune copie ne porte places', again.points.length === sp.points.length && again.points.every(function (p) { return !('places' in p); }) && sp.points.every(function (p) { return !('places' in p); }));
    const homo = C.splitPlaces([{ id: 1, places: [{ type: 'city', label: 'Saint-Denis', lat: 48.93, lng: 2.35 }, { type: 'city', label: 'Saint-Denis', lat: -20.88, lng: 55.45 }, { type: 'city', label: 'Rouen', lat: 49.44, lng: 1.09 }] }]);
    check('homonymes : « Aussi a » sans doublon de libelle', 'Saint-Denis' === homo.points[2]._also.join(', '));
  }

  console.log('\nN. Carte libre : plusieurs lieux et regions');
  o = await osmMap({ data: multiJobs });
  check('une offre a Paris et Lyon : un point dans chaque ville', 2 === o.st.sources['g2rd-jobs'].data.features.length);
  o.st.map.fire('click:g2rd-points', { features: [{ properties: { gid: 1, count: 1 }, geometry: { coordinates: [4.8357, 45.764] } }] });
  check('fenetre de Lyon : « Aussi a : Paris, Normandie »', /Aussi à : Paris, Normandie/.test(o.st.popups[0].node.textContent));
  check('contours charges une fois, depuis le plugin', 1 === o.st.regionFetches);
  const zone = o.st.sources['g2rd-regions'];
  check('zones : source GeoJSON des seules regions affichees, identifiant code, credit ODbL', !!zone && 2 === zone.data.features.length && 'code' === zone.promoteId && /ODbL/.test(zone.attribution));
  const fill = o.st.layers.find(l => l.id === 'g2rd-region-fill'), line = o.st.layers.find(l => l.id === 'g2rd-region-line');
  check('zones : remplissage et contour, sous les libelles du fond de carte (premier calque symbol du style)', !!fill && 'fill' === fill.type && !!line && 'line' === line.type && 'place-label' === o.st.before['g2rd-region-fill'] && 'place-label' === o.st.before['g2rd-region-line']);
  check('zones : contour #d35400 (contraste), remplissage #ef6c00', '#d35400' === line.paint['line-color'] && '#ef6c00' === fill.paint['fill-color']);
  const lsrc = o.st.sources['g2rd-region-labels'], llay = o.st.layers.find(l => l.id === 'g2rd-region-label');
  check('etiquettes dans le canevas : source (code, texte), calque symbol sous les regroupements, aucun marqueur DOM', !!lsrc && 2 === lsrc.data.features.length && 'Normandie · 2 offres' === lsrc.data.features[0].properties.text && '28' === lsrc.data.features[0].properties.code && 0.1128 === lsrc.data.features[0].geometry.coordinates[0] && !!llay && 'symbol' === llay.type && 'g2rd-clusters' === o.st.before['g2rd-region-label'] && 0 === o.st.markers.length);
  check('etiquettes : style (taille 13, halo blanc, couleur)', 13 === llay.layout['text-size'] && '#9a3412' === llay.paint['text-color'] && '#fff' === llay.paint['text-halo-color'] && 2 === llay.paint['text-halo-width']);
  check('etiquettes : sans chevauchement (collision active), ancrage variable, priorite au nombre d offres', false === llay.layout['text-allow-overlap'] && false === llay.layout['text-ignore-placement'] && 'top,bottom,left,right' === llay.layout['text-variable-anchor'].join() && 0.8 === llay.layout['text-radial-offset'] && !('text-anchor' in llay.layout) && !('text-offset' in llay.layout) && JSON.stringify(['-', 0, ['get', 'count']]) === JSON.stringify(llay.layout['symbol-sort-key']) && 2 === lsrc.data.features[0].properties.count && 1 === lsrc.data.features[1].properties.count);
  check('pas de point de secours quand les zones sont dessinees', !o.st.layers.some(l => l.id === 'g2rd-region-dot'));
  const label28 = { properties: { code: '28' }, geometry: { coordinates: [0.1128, 49.1202] } }, label53 = { properties: { code: '53' }, geometry: { coordinates: [-2.8331, 48.1794] } };
  o.st.map.fire('click:g2rd-region-label', { point: {}, features: [label28] });
  const rp = o.st.popups[o.st.popups.length - 1];
  check('clic sur l etiquette : fenetre des offres de la region, titre malveillant en texte', 2 === rp.node.querySelectorAll('li').length && 0 === rp.node.querySelectorAll('img').length && /toute la région/.test(rp.node.textContent) && 0.1128 === rp.c[0]);
  o.st.map.fire('click:g2rd-region-label', { point: {}, features: [label53] });
  o.st.map.fire('click:g2rd-points', { features: [{ properties: { gid: 0, count: 1 }, geometry: { coordinates: [2.352222, 48.856614] } }] });
  check('une seule fenetre ouverte : deux regions puis un point', 1 === o.st.popups.filter(p => !p.removed).length && !o.st.popups[o.st.popups.length - 1].removed);
  const before = o.st.popups.length;
  o.st.rendered = [{ layer: { id: 'g2rd-points' } }];
  o.st.map.fire('click:g2rd-region-label', { point: {}, features: [label28] });
  o.st.map.fire('click:g2rd-region-fill', { point: {}, lngLat: [0.5, 49.5], features: [{ properties: { code: '28' } }] });
  check('clic sur un point sous une etiquette ou dans une zone : aucune fenetre de region', before === o.st.popups.length);
  o.st.rendered = [{ layer: { id: 'g2rd-region-label' } }];
  o.st.map.fire('click:g2rd-region-fill', { point: {}, lngLat: [0.5, 49.5], features: [{ properties: { code: '28' } }] });
  check('clic sur une etiquette dans une zone : la zone ne double pas la fenetre, calque d etiquettes teste', before === o.st.popups.length && o.st.queryLayers.indexOf('g2rd-region-label') !== -1);
  o.st.rendered = [];
  o.st.map.fire('click:g2rd-region-fill', { point: {}, lngLat: [0.5, 49.5], features: [{ properties: { code: '28' } }] });
  check('clic dans la zone : fenetre de la region', before + 1 === o.st.popups.length && 1 === o.st.popups.filter(p => !p.removed).length);
  o.st.map.fire('mousemove:g2rd-region-fill', { features: [{ properties: { code: '53' } }] });
  check('survol : contour renforce (etat hover)', o.st.states.some(x => '53' === x.f.id && true === x.s.hover));
  const cur = [];
  o.st.rendered = [{ layer: { id: 'g2rd-region-fill' } }]; o.st.map.fire('mousemove', { point: {} }); cur.push(o.st.canvas.style.cursor);
  o.st.rendered = []; o.st.map.fire('mousemove', { point: {} }); cur.push(o.st.canvas.style.cursor);
  o.st.rendered = [{ layer: { id: 'g2rd-points' } }]; o.st.map.fire('mousemove', { point: {} }); cur.push(o.st.canvas.style.cursor);
  o.st.rendered = [{ layer: { id: 'g2rd-region-fill' } }, { layer: { id: 'g2rd-points' } }]; o.st.map.fire('mousemove', { point: {} }); cur.push(o.st.canvas.style.cursor);
  o.st.rendered = [{ layer: { id: 'g2rd-region-fill' } }]; o.st.map.fire('mousemove', { point: {} }); cur.push(o.st.canvas.style.cursor);
  o.st.map.fire('mouseout', {}); cur.push(o.st.canvas.style.cursor);
  check('curseur : main au-dessus d un calque cliquable, vide ailleurs, y compris du point vers la zone ; vide en quittant la carte', 'pointer,,pointer,pointer,pointer,' === cur.join());
  check('curseur : un seul ecouteur mousemove de carte, calques existants et cliquables seulement', 1 === o.st.map.events.mousemove.length && 'g2rd-points,g2rd-clusters,g2rd-region-fill,g2rd-region-label' === o.st.queryLayers.join() && !o.st.map.events['mouseenter:g2rd-points'] && !o.st.map.events['mouseenter:g2rd-region-fill'] && !o.st.map.events['mouseenter:g2rd-region-label']);
  const fp = o.st.fit.b.pts.map(p => p.join());
  check('cadrage : villes et angles de la boite de chaque region, avec marge, sans zoom excessif', 6 === fp.length && fp.indexOf('-1.946,48.182') !== -1 && fp.indexOf('1.802,50.069') !== -1 && fp.indexOf('-5.141,47.2879') !== -1 && fp.indexOf('-1.0209,48.867') !== -1 && JSON.stringify({ top: 72, right: 48, bottom: 48, left: 48 }) === JSON.stringify(o.st.fit.o.padding) && 12 === o.st.fit.o.maxZoom && 0 === o.st.fit.o.duration);
  const lg = o.st.controlNodes.filter(n => n.node.classList.contains('g2rd-wpjm-geo-map__legend'));
  const legend = lg[0] && lg[0].node;
  check('legende : controle MapLibre en haut a gauche, ajoute une seule fois', 1 === lg.length && 'top-left' === lg[0].pos && legend.classList.contains('maplibregl-ctrl') && 'legend' === o.st.controls[o.st.controls.length - 1] && 'bottom-right' === o.st.attribPos);
  check('legende : deux entrees, pastilles visuelles masquees aux lecteurs d ecran, texte sans couleur', !!legend && 2 === legend.querySelectorAll('li').length && 2 === legend.querySelectorAll('.g2rd-wpjm-geo-map__swatch[aria-hidden="true"]').length && /Offre dans une ville/.test(legend.textContent) && /Offre sur toute une région/.test(legend.textContent));
  const mlist = o.w.document.querySelector('.g2rd-wpjm-geo-map__places');
  check('liste sous la carte : regions comprises', /Normandie \(toute la région\) — 2 offres/.test(mlist.textContent) && /Bretagne \(toute la région\) — 1 offre/.test(mlist.textContent));
  check('credit des contours dans l attribution, lien https', /ODbL/.test(o.st.attribNode.textContent) && [...o.st.attribNode.querySelectorAll('a')].some(a => 0 === a.href.indexOf('https://www.data.gouv.fr/')));
  o = await osmMap({ data: multiJobs, regionsFail: true });
  const fsrc = o.st.sources['g2rd-region-labels'], dot = o.st.layers.find(l => l.id === 'g2rd-region-dot');
  check('contours injoignables : memes etiquettes, sans zone', !o.st.sources['g2rd-regions'] && !!fsrc && 'Normandie · 2 offres' === fsrc.data.features[0].properties.text && 1 === o.st.layers.filter(l => l.id === 'g2rd-region-label').length && !o.el.classList.contains('has-error'));
  check('contours injoignables : point de secours sous l etiquette, pas de legende', !!dot && 'circle' === dot.type && 5 === dot.paint['circle-radius'] && 'g2rd-region-label' === o.st.before['g2rd-region-dot'] && 0 === o.st.controlNodes.filter(n => n.pos === 'top-left').length);
  o.st.map.fire('click:g2rd-region-dot', { point: {}, features: [label28] });
  check('point de secours : clic ouvre la fenetre de la region', /toute la région/.test(o.st.popups[o.st.popups.length - 1].node.textContent) && 1 === o.st.popups.filter(p => !p.removed).length);
  o = await osmMap({ data: multiJobs, badShape: true });
  check('geometrie invalide (Point) : zone ecartee, etiquette conservee', 1 === o.st.sources['g2rd-regions'].data.features.length && '53' === o.st.sources['g2rd-regions'].data.features[0].properties.code && 2 === o.st.sources['g2rd-region-labels'].data.features.length);
  o = await osmMap({ data: jobs });
  check('aucune region : contours non charges, ni etiquette ni legende', 0 === o.st.regionFetches && !o.st.sources['g2rd-region-labels'] && 0 === o.st.controlNodes.filter(n => n.pos === 'top-left').length);
  o = await osmMap({ data: [multiJobs[2]] });
  check('une seule region : cadrage sur sa boite entiere, pas de saut de zoom', !o.st.jump && !!o.st.fit && '-5.141,47.2879|-1.0209,48.867' === o.st.fit.b.pts.map(p => p.join()).join('|') && 12 === o.st.fit.o.maxZoom);
  o = await osmMap({ data: [regionNoBox] });
  check('region sans boite, seule : saut au zoom 6 sur son centre, pas de fitBounds', !!o.st.jump && 6 === o.st.jump.zoom && '-2.8331,48.1794' === o.st.jump.center.join() && !o.st.fit);
  o = await osmMap({ data: [cityAndRegion] });
  const cb = o.st.fit.b.pts;
  check('une ville plus une region : la boite contient la region entiere et la ville', !o.st.jump && Math.min(...cb.map(p => p[0])) === -1.946 && Math.max(...cb.map(p => p[0])) === 4.8357 && Math.min(...cb.map(p => p[1])) === 45.764 && Math.max(...cb.map(p => p[1])) === 50.069);
  o = await osmMap({ data: [jobs[0]] });
  check('une seule ville sans region : saut au zoom 10', !!o.st.jump && 10 === o.st.jump.zoom && !o.st.fit);
  o = await osmMap({ data: multiJobs, italicFirst: true });
  check('police : la police italique du premier calque de texte est ecartee (Regular preferee)', ['Noto Sans Regular'].join() === o.st.layers.find(l => l.id === 'g2rd-region-label').layout['text-font'].join() && ['Noto Sans Regular'].join() === o.st.layers.find(l => l.id === 'g2rd-cluster-count').layout['text-font'].join());
  o = await osmMap({ data: multiJobs, throwRender: true });
  check('attribution.render() qui leve apres les zones : pas de point de secours, une seule couche d etiquettes', 1 === o.st.layers.filter(l => l.id === 'g2rd-region-label').length && !o.st.layers.some(l => l.id === 'g2rd-region-dot'));
  o = await osmMap({ data: multiJobs, clustering: false });
  check('sans regroupement : zones, etiquettes et legende dessinees', !!o.st.sources['g2rd-regions'] && !!o.st.sources['g2rd-region-labels'] && 1 === o.st.controlNodes.filter(n => n.pos === 'top-left').length);

  console.log('\nResultat JS : ' + pass + ' reussis, ' + fail + ' echoues');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('ERREUR BANC DE TEST:', e); process.exit(2); });
