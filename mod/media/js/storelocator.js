/**
 * Lightweight Store Locator – frontend.
 * (C) 2026 Stefan Kirchhoff, webkiste.net – GNU General Public License version 2 or later
 *
 * Leaflet (bundled, no CDN) is loaded only after consent. The town search uses
 * OpenStreetMap Nominatim directly from the visitor's browser.
 */
(() => {
  'use strict';

  const CONSENT_KEY = 'lwsl_map_consent';
  const NOMINATIM = 'https://nominatim.openstreetmap.org/search';
  const GEO_OPTIONS = { enableHighAccuracy: false, timeout: 15000, maximumAge: 300000 };
  let leafletPromise = null;

  const readJson = (root, selector, fallback) => {
    const el = root.querySelector(selector);
    if (!el) {
      return fallback;
    }
    try {
      return JSON.parse(el.textContent);
    } catch (e) {
      return fallback;
    }
  };

  const escapeHtml = (text) => String(text == null ? '' : text)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

  // Only http(s) links; addresses without scheme ("www.example.com") get https://
  const safeUrl = (url) => {
    if (!url) {
      return '';
    }
    const u = String(url).trim();
    if (/^https?:\/\//i.test(u)) {
      return u;
    }
    if (/^[a-z0-9.-]+\.[a-z]{2,}(\/.*)?$/i.test(u)) {
      return `https://${u}`;
    }
    return '';
  };

  const distance = (lat1, lon1, lat2, lon2) => {
    const R = 6371;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLon = ((lon2 - lon1) * Math.PI) / 180;
    const a = Math.sin(dLat / 2) ** 2
      + Math.cos((lat1 * Math.PI) / 180) * Math.cos((lat2 * Math.PI) / 180) * Math.sin(dLon / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  };

  const hasConsent = () => {
    try {
      return window.localStorage.getItem(CONSENT_KEY) === '1';
    } catch (e) {
      return false;
    }
  };

  const loadLeaflet = (base) => {
    if (window.L) {
      return Promise.resolve(window.L);
    }
    if (!leafletPromise) {
      leafletPromise = new Promise((resolve, reject) => {
        const css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = `${base}leaflet.css`;
        document.head.appendChild(css);

        const js = document.createElement('script');
        js.src = `${base}leaflet.js`;
        js.onload = () => resolve(window.L);
        js.onerror = () => {
          leafletPromise = null;
          reject(new Error('Leaflet'));
        };
        document.head.appendChild(js);
      });
    }
    return leafletPromise;
  };

  const geoErrorText = (t, err) => {
    if (err && err.code === 1) {
      return t.geoDenied;
    }
    if (err && err.code === 3) {
      return t.geoTimeout;
    }
    return t.geoUnavailable;
  };

  /**
   * Asks the browser for the position; calls done(err, lat, lng).
   * A watchdog catches browsers that never answer (e.g. a dismissed prompt in Firefox).
   */
  const locate = (done) => {
    let finished = false;
    const watchdog = setTimeout(() => {
      if (!finished) {
        finished = true;
        done({ code: 3 });
      }
    }, 30000);

    navigator.geolocation.getCurrentPosition((pos) => {
      if (finished) {
        return;
      }
      finished = true;
      clearTimeout(watchdog);
      done(null, pos.coords.latitude, pos.coords.longitude);
    }, (err) => {
      if (finished) {
        return;
      }
      finished = true;
      clearTimeout(watchdog);
      done(err);
    }, GEO_OPTIONS);
  };

  // ------------------------------------------------------------------
  // Compact quick search (teaser)
  // ------------------------------------------------------------------
  const initCompact = (root) => {
    const cfg = readJson(root, '.lwsl-config', {});
    const t = cfg.text || {};
    const btn = root.querySelector('.lwsl-geo');
    const label = btn.textContent;

    btn.addEventListener('click', () => {
      if (!navigator.geolocation) {
        window.alert(t.geoNotSupported);
        return;
      }
      btn.textContent = t.locating;
      btn.disabled = true;

      locate((err, lat, lng) => {
        btn.textContent = label;
        btn.disabled = false;

        if (err) {
          window.alert(geoErrorText(t, err));
          return;
        }
        const target = cfg.targetUrl || window.location.pathname;
        window.location.href = `${target}${target.indexOf('?') !== -1 ? '&' : '?'}lat=${lat.toFixed(5)}&lng=${lng.toFixed(5)}`;
      });
    });
  };

  // ------------------------------------------------------------------
  // Full store locator (map and list)
  // ------------------------------------------------------------------
  const initFull = (root) => {
    const cfg = readJson(root, '.lwsl-config', {});
    const locations = readJson(root, '.lwsl-data', []);
    const t = cfg.text || {};
    const $ = (sel) => root.querySelector(sel);

    const el = {
      q: $('.lwsl-q'),
      search: $('.lwsl-search-btn'),
      radius: $('.lwsl-radius'),
      country: $('.lwsl-country'),
      geo: $('.lwsl-geo'),
      status: $('.lwsl-status'),
      reset: $('.lwsl-reset'),
      count: $('.lwsl-count'),
      list: $('.lwsl-list'),
      map: $('.lwsl-map'),
      consent: $('.lwsl-consent'),
      consentBtn: $('.lwsl-consent-btn'),
      remember: $('.lwsl-consent-remember'),
      revokeWrap: $('.lwsl-revoke-wrap'),
      revoke: $('.lwsl-revoke'),
    };

    const geoLabel = el.geo.textContent;
    const initialHint = el.list.innerHTML;
    let regionNames = null;

    try {
      regionNames = new Intl.DisplayNames([cfg.lang || 'en'], { type: 'region' });
    } catch (e) {
      regionNames = null;
    }

    const countryName = (code) => {
      if (!code) {
        return '';
      }
      try {
        return regionNames ? regionNames.of(String(code).toUpperCase()) : String(code).toUpperCase();
      } catch (e) {
        return String(code).toUpperCase();
      }
    };

    const formatKm = (km) => new Intl.NumberFormat(cfg.lang || undefined, { maximumFractionDigits: 1, minimumFractionDigits: 1 }).format(km);

    // Country filter (optional)
    if (el.country) {
      const codes = [...new Set(locations.map((l) => l.country).filter(Boolean))]
        .sort((a, b) => countryName(a).localeCompare(countryName(b)));
      el.country.innerHTML = `<option value="">${escapeHtml(t.allCountries)}</option>${
        codes.map((c) => `<option value="${escapeHtml(c)}">${escapeHtml(countryName(c))}</option>`).join('')}`;
      el.country.addEventListener('change', () => filter());
    }

    let L = null;
    let map = null;
    let loading = false;
    let pending = null;
    let center = null;
    let centerLabel = '';
    let centerMarker = null;
    let markers = [];

    const setStatus = (text) => {
      el.status.textContent = text;
    };

    const initMap = () => {
      if (el.consent) {
        el.consent.hidden = true;
      }
      el.map.hidden = false;
      if (el.revokeWrap) {
        el.revokeWrap.hidden = false;
      }

      map = L.map(el.map, { scrollWheelZoom: false });
      map.on('focus', () => map.scrollWheelZoom.enable());
      L.tileLayer(cfg.tiles, { maxZoom: 19, attribution: cfg.attribution }).addTo(map);

      if (locations.length) {
        map.fitBounds(locations.map((l) => [l.lat, l.lng]), { padding: [30, 30], maxZoom: 10 });
      } else {
        map.setView([50, 10], 4);
      }
    };

    const loadMap = () => {
      if (map || loading) {
        return;
      }
      loading = true;
      loadLeaflet(cfg.leaflet).then((leaflet) => {
        L = leaflet;
        loading = false;
        initMap();
        if (pending) {
          const action = pending;
          pending = null;
          action();
        }
      }).catch(() => {
        loading = false;
        setStatus(t.mapError);
      });
    };

    // Runs an action as soon as the map is ready – or remembers it and points to the consent box
    const withMap = (action) => {
      if (map) {
        action();
        return;
      }
      pending = action;
      if (!cfg.consent) {
        loadMap();
        return;
      }
      if (loading) {
        return;
      }
      setStatus(t.consentRequired);
      el.consent.scrollIntoView({ behavior: 'smooth', block: 'center' });
      el.consent.classList.add('lwsl-attention');
      setTimeout(() => el.consent.classList.remove('lwsl-attention'), 1500);
    };

    const clearMarkers = () => {
      markers.forEach((m) => map && map.removeLayer(m));
      markers = [];
    };

    const setCenter = (lat, lng, label) => {
      center = [lat, lng];
      centerLabel = label;
      if (centerMarker) {
        map.removeLayer(centerMarker);
      }
      const icon = L.divIcon({ className: 'lwsl-me', iconSize: [20, 20], iconAnchor: [10, 10] });
      centerMarker = L.marker(center, { icon, keyboard: false }).addTo(map).bindPopup(`<b>${escapeHtml(label)}</b>`);
    };

    const directionsUrl = (loc) => {
      const address = [loc.address, `${loc.postal} ${loc.town}`.trim(), countryName(loc.country)].filter(Boolean).join(', ');
      switch (cfg.directions) {
        case 'none':
          return '';
        case 'apple':
          return `https://maps.apple.com/?daddr=${encodeURIComponent(address)}`;
        case 'osm':
          return `https://www.openstreetmap.org/directions?route=${center ? `${center[0]}%2C${center[1]}` : ''}%3B${loc.lat}%2C${loc.lng}#map=14/${loc.lat}/${loc.lng}`;
        default:
          return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(address)}`;
      }
    };

    const addLocation = (loc) => {
      const place = `${escapeHtml(loc.postal)} ${escapeHtml(loc.town)}`.trim()
        + (loc.country ? ` (${escapeHtml(countryName(loc.country))})` : '');
      const website = cfg.showWebsite ? safeUrl(loc.website) : '';
      const phone = cfg.showPhone && loc.phone ? loc.phone : '';
      const route = directionsUrl(loc);

      const popup = `<b>${escapeHtml(loc.name)}</b><br>${escapeHtml(loc.address)}<br>${place}`
        + (phone ? `<br>${escapeHtml(t.phone)} <a href="tel:${escapeHtml(phone.replace(/[^+\d]/g, ''))}">${escapeHtml(phone)}</a>` : '')
        + (website ? `<br><a href="${escapeHtml(website)}" target="_blank" rel="noopener">${escapeHtml(t.website)} →</a>` : '')
        + (route ? `<br><a class="lwsl-popup-route" href="${escapeHtml(route)}" target="_blank" rel="noopener">${escapeHtml(t.directions)} →</a>` : '');

      const marker = L.marker([loc.lat, loc.lng], { title: loc.name }).addTo(map).bindPopup(popup);
      markers.push(marker);

      const item = document.createElement('div');
      item.className = 'lwsl-item';
      item.innerHTML = `<button type="button" class="lwsl-item-name">${escapeHtml(loc.name)}</button>`
        + `<div class="lwsl-item-address">${escapeHtml(loc.address)}<br>${place}</div>`
        + (phone ? `<div class="lwsl-item-phone">${escapeHtml(t.phone)} <a href="tel:${escapeHtml(phone.replace(/[^+\d]/g, ''))}">${escapeHtml(phone)}</a></div>` : '')
        + (website ? `<div class="lwsl-item-web"><a href="${escapeHtml(website)}" target="_blank" rel="noopener">${escapeHtml(t.website)}</a></div>` : '')
        + (route ? `<a class="lwsl-route" href="${escapeHtml(route)}" target="_blank" rel="noopener">${formatKm(loc.distance)} km · ${escapeHtml(t.route)} →</a>` : `<div class="lwsl-item-dist">${formatKm(loc.distance)} km</div>`);

      item.querySelector('.lwsl-item-name').addEventListener('click', () => {
        map.setView([loc.lat, loc.lng], 14);
        marker.openPopup();
        el.map.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      });

      el.list.appendChild(item);
    };

    const filter = () => {
      if (!map || !center) {
        return;
      }
      const radius = parseInt(el.radius.value, 10);
      const country = el.country ? el.country.value : '';
      clearMarkers();
      el.list.innerHTML = '';

      const matches = locations
        .filter((l) => !country || l.country === country)
        .map((l) => ({ ...l, distance: distance(center[0], center[1], l.lat, l.lng) }))
        .filter((l) => l.distance <= radius)
        .sort((a, b) => a.distance - b.distance);

      el.count.textContent = String(matches.length);
      setStatus(t.centerMsg.replace('%label%', centerLabel).replace('%radius%', radius));

      if (matches.length) {
        matches.forEach(addLocation);
        map.fitBounds([center, ...matches.map((l) => [l.lat, l.lng])], { padding: [40, 40], maxZoom: 13 });
      } else {
        el.list.innerHTML = `<p class="lwsl-hint">${escapeHtml(t.noResults.replace('%s', radius))}</p>`;
        map.setView(center, 10);
      }
    };

    const searchPlace = () => {
      const value = el.q.value.trim();
      if (!value) {
        return;
      }
      withMap(() => {
        setStatus(t.searching.replace('%s', value));
        const params = new URLSearchParams({ format: 'jsonv2', limit: '1', q: value, 'accept-language': cfg.lang || 'en' });
        if (cfg.countries && cfg.countries.length) {
          params.set('countrycodes', cfg.countries.join(','));
        }
        fetch(`${NOMINATIM}?${params.toString()}`)
          .then((r) => {
            if (!r.ok) {
              throw new Error(`HTTP ${r.status}`);
            }
            return r.json();
          })
          .then((data) => {
            if (data && data.length) {
              setCenter(parseFloat(data[0].lat), parseFloat(data[0].lon), String(data[0].display_name || value).split(',')[0]);
              filter();
            } else {
              setStatus(t.notFound.replace('%s', value));
            }
          })
          .catch(() => setStatus(t.searchError));
      });
    };

    const locateUser = () => {
      if (!navigator.geolocation) {
        window.alert(t.geoNotSupported);
        return;
      }
      withMap(() => {
        el.geo.textContent = t.locating;
        el.geo.disabled = true;
        setStatus(t.fetching);
        locate((err, lat, lng) => {
          el.geo.textContent = geoLabel;
          el.geo.disabled = false;
          if (err) {
            setStatus(geoErrorText(t, err));
            return;
          }
          setCenter(lat, lng, t.myLocation);
          filter();
        });
      });
    };

    const reset = () => {
      center = null;
      pending = null;
      if (centerMarker && map) {
        map.removeLayer(centerMarker);
      }
      centerMarker = null;
      clearMarkers();
      el.q.value = '';
      el.count.textContent = '0';
      setStatus(t.initialHint);
      el.list.innerHTML = initialHint;
      if (map && locations.length) {
        map.fitBounds(locations.map((l) => [l.lat, l.lng]), { padding: [30, 30], maxZoom: 10 });
      }
    };

    el.search.addEventListener('click', searchPlace);
    el.q.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        searchPlace();
      }
    });
    el.radius.addEventListener('change', filter);
    el.geo.addEventListener('click', locateUser);
    el.reset.addEventListener('click', reset);

    if (el.consentBtn) {
      el.consentBtn.addEventListener('click', () => {
        if (el.remember && el.remember.checked) {
          try {
            window.localStorage.setItem(CONSENT_KEY, '1');
          } catch (e) {
            // storage not available – consent applies to this page view only
          }
        }
        loadMap();
      });
    }

    if (el.revoke) {
      el.revoke.addEventListener('click', () => {
        try {
          window.localStorage.removeItem(CONSENT_KEY);
        } catch (e) {
          // ignore
        }
        window.location.reload();
      });
    }

    if (!cfg.consent || hasConsent()) {
      loadMap();
    }

    // Parameters from the quick search
    const url = new URLSearchParams(window.location.search);
    const zip = (url.get('sl_zip') || '').trim();
    const lat = parseFloat(url.get('lat'));
    const lng = parseFloat(url.get('lng'));

    if (zip) {
      el.q.value = zip;
      searchPlace();
    } else if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
      withMap(() => {
        setCenter(lat, lng, t.myLocation);
        filter();
      });
    }
  };

  const init = () => {
    document.querySelectorAll('[data-lwsl]').forEach((root) => {
      if (root.dataset.lwslReady) {
        return;
      }
      root.dataset.lwslReady = '1';
      if (root.dataset.lwsl === 'compact') {
        initCompact(root);
      } else {
        initFull(root);
      }
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
