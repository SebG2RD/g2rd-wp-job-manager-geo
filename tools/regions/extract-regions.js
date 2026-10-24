#!/usr/bin/env node
/*
 * Extrait les 18 régions françaises du fichier « Contours administratifs »
 * (data.gouv.fr, millésime 2025, licence ODbL) et affiche leurs centres et leurs
 * boîtes englobantes pour g2rd-wpjm-geo/includes/Regions.php. Les géométries sont recopiées telles quelles.
 *
 * Usage : node tools/regions/extract-regions.js <regions-1000m.geojson> <sortie.geojson>
 * Source : https://etalab-datasets.geo.data.gouv.fr/contours-administratifs/2025/geojson/regions-1000m.geojson
 */
'use strict';

const fs = require('fs');
const crypto = require('crypto');

const SOURCE_SHA256 = '7f44a5c4deaeb710c9dc2b043ed4debcf50068a1b0740619a5873f9e878f9786';
const CODES = ['01', '02', '03', '04', '06', '11', '24', '27', '28', '32', '44', '52', '53', '75', '76', '84', '93', '94'];

function ringArea(ring) {
  let area = 0;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    area += ring[j][0] * ring[i][1] - ring[i][0] * ring[j][1];
  }
  return area / 2;
}

function ringCentroid(ring) {
  let area = 0, x = 0, y = 0;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const f = ring[j][0] * ring[i][1] - ring[i][0] * ring[j][1];
    area += f;
    x += (ring[j][0] + ring[i][0]) * f;
    y += (ring[j][1] + ring[i][1]) * f;
  }
  return [x / (3 * area), y / (3 * area)];
}

function inRing(point, ring) {
  let inside = false;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const [xi, yi] = ring[i], [xj, yj] = ring[j];
    if ((yi > point[1]) !== (yj > point[1]) && point[0] < (xj - xi) * (point[1] - yi) / (yj - yi) + xi) {
      inside = !inside;
    }
  }
  return inside;
}

function inPolygon(point, polygon) {
  return inRing(point, polygon[0]) && !polygon.slice(1).some(hole => inRing(point, hole));
}

function largestPolygon(geometry) {
  const polygons = 'Polygon' === geometry.type ? [geometry.coordinates] : geometry.coordinates;
  return polygons.reduce((best, p) => (Math.abs(ringArea(p[0])) > Math.abs(ringArea(best[0])) ? p : best));
}

// Centre de gravité du plus grand polygone ; s'il tombe dehors (contour concave),
// point intérieur d'une grille de 59 × 59 points le plus proche de lui.
function centre(geometry) {
  const polygon = largestPolygon(geometry);
  const c = ringCentroid(polygon[0]);
  if (inPolygon(c, polygon)) {
    return c;
  }
  const xs = polygon[0].map(p => p[0]), ys = polygon[0].map(p => p[1]);
  const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys);
  let best = null, bestDistance = Infinity;
  for (let i = 1; i < 60; i++) {
    for (let j = 1; j < 60; j++) {
      const p = [minX + (maxX - minX) * i / 60, minY + (maxY - minY) * j / 60];
      const d = (p[0] - c[0]) ** 2 + (p[1] - c[1]) ** 2;
      if (d < bestDistance && inPolygon(p, polygon)) {
        best = p;
        bestDistance = d;
      }
    }
  }
  return best;
}

// Boîte englobante [ouest, sud, est, nord] de toutes les coordonnées, arrondie vers l'extérieur à 4 décimales.
function bbox(geometry) {
  let w = Infinity, s = Infinity, e = -Infinity, n = -Infinity;
  (function walk(c) {
    if ('number' === typeof c[0]) {
      w = Math.min(w, c[0]); e = Math.max(e, c[0]); s = Math.min(s, c[1]); n = Math.max(n, c[1]);
    } else {
      c.forEach(walk);
    }
  })(geometry.coordinates);
  const floor = v => (Math.floor(v * 1e4) / 1e4).toFixed(4), ceil = v => (Math.ceil(v * 1e4) / 1e4).toFixed(4);
  return [floor(w), floor(s), ceil(e), ceil(n)];
}

const [source, target] = process.argv.slice(2);
if (!source || !target) {
  console.error('Usage : node tools/regions/extract-regions.js <regions-1000m.geojson> <sortie.geojson>');
  process.exit(2);
}
const raw = fs.readFileSync(source);
const hash = crypto.createHash('sha256').update(raw).digest('hex');
if (hash !== SOURCE_SHA256) {
  console.error('Empreinte du fichier source inattendue : ' + hash);
  process.exit(1);
}
const data = JSON.parse(raw.toString('utf8'));
const features = data.features
  .filter(f => CODES.includes(f.properties.code))
  .map(f => ({ type: 'Feature', properties: { code: f.properties.code, nom: f.properties.nom }, geometry: f.geometry }));
if (features.length !== CODES.length) {
  console.error('Régions trouvées : ' + features.length + ' sur ' + CODES.length);
  process.exit(1);
}
fs.writeFileSync(target, JSON.stringify({ type: 'FeatureCollection', features }) + '\n');
features.forEach(f => {
  const [lng, lat] = centre(f.geometry);
  console.log("'" + f.properties.code + "' => array( '" + f.properties.nom + "', '" + lat.toFixed(4) + "', '" + lng.toFixed(4) + "', array( '" + bbox(f.geometry).join("', '") + "' ) ),");
});
