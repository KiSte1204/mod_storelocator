/**
 * Lightweight Store Locator – geocoding in the backend.
 * (C) 2026 Stefan Kirchhoff, webkiste.net – GNU General Public License version 2 or later
 *
 * Calls the geocoding task in small batches until all open locations are processed,
 * so no request runs into the PHP time limit. Nominatim's rate limit is enforced on the server.
 */
(() => {
  'use strict';

  const box = document.getElementById('lwsl-geocode');

  if (!box) {
    return;
  }

  const startBtn = box.querySelector('.lwsl-geocode-start');
  const stopBtn = box.querySelector('.lwsl-geocode-stop');
  const progress = box.querySelector('.lwsl-geocode-progress');
  const bar = box.querySelector('.progress-bar');
  const status = box.querySelector('.lwsl-geocode-status');
  const log = box.querySelector('.lwsl-geocode-log');
  const url = box.dataset.url;
  const t = (key) => (window.Joomla && Joomla.Text ? Joomla.Text._(key) : key);

  let total = parseInt(box.dataset.open, 10) || 0;
  let running = false;
  let changed = false;

  const sleep = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

  const addLog = (lines) => {
    if (!lines || !lines.length) {
      return;
    }
    log.textContent += `${lines.join('\n')}\n`;
    log.scrollTop = log.scrollHeight;
  };

  const setProgress = (remaining) => {
    const done = Math.max(0, total - remaining);
    const pct = total ? Math.round((done / total) * 100) : 100;
    bar.style.width = `${pct}%`;
    bar.parentElement.setAttribute('aria-valuenow', String(pct));
    status.textContent = t('COM_LWSTORELOCATOR_GEOCODE_RUNNING').replace('%1$s', done).replace('%2$s', total);
  };

  const finish = (message) => {
    running = false;
    stopBtn.hidden = true;
    startBtn.hidden = false;
    status.textContent = message;

    if (changed) {
      // Reload the list so the new coordinates and states are shown
      setTimeout(() => { window.location.reload(); }, 2500);
    }
  };

  const run = async () => {
    running = true;
    startBtn.hidden = true;
    stopBtn.hidden = false;
    progress.hidden = false;
    setProgress(total);

    while (running) {
      let json;

      try {
        const response = await fetch(url, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        });
        json = await response.json();
      } catch (e) {
        finish(t('COM_LWSTORELOCATOR_GEOCODE_ERROR'));
        return;
      }

      if (!json || !json.success || !json.data) {
        finish((json && json.message) || t('COM_LWSTORELOCATOR_GEOCODE_ERROR'));
        return;
      }

      const data = json.data;
      addLog(data.log);

      if (data.processed > 0) {
        changed = true;
      }

      setProgress(data.remaining);

      if (data.remaining <= 0) {
        finish(t('COM_LWSTORELOCATOR_GEOCODE_DONE'));
        return;
      }

      if (data.aborted) {
        // Nominatim is not reachable or asks us to slow down: wait a minute, then continue
        for (let s = 60; s > 0 && running; s -= 1) {
          status.textContent = t('COM_LWSTORELOCATOR_GEOCODE_PAUSED').replace('%s', s);
          await sleep(1000);
        }
      } else {
        await sleep(300);
      }
    }

    finish(t('COM_LWSTORELOCATOR_GEOCODE_STOPPED'));
  };

  startBtn.addEventListener('click', () => {
    if (!running) {
      total = parseInt(box.dataset.open, 10) || total;
      run();
    }
  });

  stopBtn.addEventListener('click', () => {
    running = false;
    status.textContent = t('COM_LWSTORELOCATOR_GEOCODE_STOPPED');
  });
})();
