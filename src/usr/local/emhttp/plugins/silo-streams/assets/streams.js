(function (global) {
  'use strict';
  const number = value => typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null;
  function clock(value) {
    value = number(value);
    if (value === null) return 'Unknown';
    const seconds = Math.floor(value);
    const minutes = Math.floor(seconds / 60);
    return (minutes >= 60 ? Math.floor(minutes / 60) + ':' + String(minutes % 60).padStart(2, '0') : String(minutes)) + ':' + String(seconds % 60).padStart(2, '0');
  }
  function bitrate(value) {
    value = number(value);
    return value === null ? 'Unknown bitrate' : (value / 1000).toLocaleString(undefined, {maximumFractionDigits: 1}) + ' Mbps';
  }
  function safePoster(value) {
    if (typeof value !== 'string' || /[\x00-\x20\x7f]/.test(value)) return null;
    if (/^\/plugins\/silo-streams\/poster\.php\?session_id=[A-Za-z0-9._~%\-]+$/.test(value)) return value;
    if (/^(?:\.\/)?posters\/[a-z0-9_-]+\.svg$/i.test(value)) return value;
    try {
      const url = new URL(value);
      return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : null;
    } catch (_) { return null; }
  }
  function summaryText(summary) {
    const count = number(summary.session_count) ?? 0;
    const amount = bitrate(number(summary.known_bitrate_kbps));
    const complete = summary.bitrate_complete === true;
    const known = number(summary.known_bitrate_count) ?? 0;
    return count + ' stream' + (count === 1 ? '' : 's') + ' · ' + (complete ? amount : known ? amount + ' known subtotal · total unknown' : 'Total bitrate unknown');
  }
  function element(doc, tag, className, text) {
    const el = doc.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
  }
  function icon(doc, name) {
    const svg = doc.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.7');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    const paths = {
      film: 'M3 4h18v16H3zM7 4v16M17 4v16M3 8h4M3 12h4M3 16h4M17 8h4M17 12h4M17 16h4',
      gear: 'M10 2h4l.6 3 2 .9 2.6-1.5 2 3.4-2.2 2v2.4l2.2 2-2 3.4-2.6-1.5-2 .9-.6 3h-4l-.6-3-2-.9-2.6 1.5-2-3.4 2.2-2V9.8l-2.2-2 2-3.4L7.4 6l2-.9zM15.5 11a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0',
      play: 'M7 4l14 8-14 8z',
      pause: 'M6 4h4v16H6zM15 4h4v16h-4z',
      unknown: 'M9 7a3 3 0 0 1 6 0c0 3-3 3-3 6M12 18v.1'
    };
    const path = doc.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', paths[name] || paths.unknown);
    if (name === 'play' || name === 'pause') path.setAttribute('fill', 'currentColor');
    svg.append(path);
    return svg;
  }
  function inferTheme(root) {
    if (root.getAttribute && root.getAttribute('data-theme')) return;
    const win = root.ownerDocument.defaultView;
    if (!win || !win.getComputedStyle) return;
    const parts = win.getComputedStyle(root).color.match(/[0-9.]+/g);
    if (parts && parts.length >= 3) {
      const brightness = Number(parts[0]) * .2126 + Number(parts[1]) * .7152 + Number(parts[2]) * .0722;
      root.setAttribute('data-theme', brightness > 150 ? 'dark' : 'light');
    }
  }
  function shell(root) {
    if (root.querySelector('.silo-streams__rows')) return;
    const doc = root.ownerDocument;
    inferTheme(root);
    root.classList.add('silo-streams');
    const header = element(doc, 'header', 'silo-streams__header');
    const film = element(doc, 'span', 'silo-streams__film');
    const brand = element(doc, 'img');
    brand.src = root.dataset?.icon || '/plugins/silo-streams/icon.png';
    brand.alt = '';
    brand.width = 24;
    brand.height = 24;
    brand.addEventListener('error', () => { brand.remove(); film.append(icon(doc, 'film')); }, {once:true});
    film.append(brand);
    header.append(film, element(doc, 'h2', '', 'Silo Streams'));
    header.firstChild.setAttribute('aria-hidden', 'true');
    const gear = element(doc, 'a', 'silo-streams__settings');
    gear.append(icon(doc, 'gear'));
    gear.href = '/Settings/SiloStreams';
    gear.setAttribute('aria-label', 'Silo Streams settings');
    header.append(gear);
    const status = element(doc, 'p', 'silo-streams__status', 'Connecting to Silo…');
    status.setAttribute('role', 'status');
    const rows = element(doc, 'div', 'silo-streams__rows');
    root.append(header, element(doc, 'p', 'silo-streams__summary'), status, rows);
  }
  function row(root, item, serverName) {
    const doc = root.ownerDocument;
    const card = element(doc, 'article', 'silo-streams__row');
    if (item.play_method_key === 'transcode') card.classList.add('silo-streams__row--transcode');
    else if (typeof item.stream_location === 'string' && item.stream_location.toLowerCase() === 'remote') card.classList.add('silo-streams__row--remote');
    if (item.playback_state === 'paused') card.classList.add('silo-streams__row--paused');
    const artwork = element(doc, 'div', 'silo-streams__poster');
    const placeholder = element(doc, 'span', 'silo-streams__poster-fallback');
    placeholder.append(icon(doc, 'film'));
    placeholder.setAttribute('aria-hidden', 'true');
    artwork.append(placeholder);
    const poster = safePoster(item.poster_url);
    if (poster) {
      const img = element(doc, 'img');
      img.alt = '';
      img.referrerPolicy = 'no-referrer';
      img.addEventListener('error', () => img.remove(), {once:true});
      img.src = poster;
      artwork.append(img);
    }
    const body = element(doc, 'div', 'silo-streams__body');
    body.append(element(doc, 'h3', 'silo-streams__title', item.series_name || item.media_title || 'Unknown title'));
    let episodeCode = '';
    if (number(item.season_number) !== null) episodeCode += 'S' + String(item.season_number).padStart(2,'0');
    if (number(item.episode_number) !== null) episodeCode += 'E' + String(item.episode_number).padStart(2,'0');
    const episode = [episodeCode, item.episode_name || (item.series_name && item.media_title !== item.series_name ? item.media_title : '')].filter(Boolean);
    if (episode.length) body.append(element(doc, 'p', 'silo-streams__episode', episode.join(' · ')));
    const metadata = element(doc, 'div', 'silo-streams__metadata');
    const viewer = item.profile_name || item.username || 'Unknown viewer';
    const viewerLabel = element(doc, 'span', 'silo-streams__viewer', viewer + ' · ' + (serverName || 'Silo'));
    if (item.node_display_name) viewerLabel.title = 'Execution node: ' + item.node_display_name;
    metadata.append(viewerLabel);
    const badges = element(doc, 'div', 'silo-streams__badges');
    const method = element(doc, 'span', 'silo-streams__badge', item.play_method_label || 'Unknown method');
    if (item.play_method_key === 'transcode') method.classList.add('silo-streams__badge--transcode');
    badges.append(method);
    const quality = [item.video_output_resolution, item.video_output_codec].filter(Boolean).join(' · ');
    if (quality) badges.append(element(doc, 'span', 'silo-streams__badge', quality));
    badges.append(element(doc, 'span', 'silo-streams__badge', bitrate(item.stream_bitrate_kbps ?? item.bitrate_kbps)));
    metadata.append(badges);
    body.append(metadata);
    const client = item.client_label || item.client_name || item.device_platform || 'Unknown client';
    const timing = element(doc, 'div', 'silo-streams__timing');
    timing.append(element(doc, 'span', 'silo-streams__client', (item.stream_location || 'Unknown location') + ' · ' + client));
    timing.append(element(doc, 'span', 'silo-streams__time'), element(doc, 'span', 'silo-streams__end'));
    const progress = element(doc, 'div', 'silo-streams__progress');
    progress.setAttribute('role', 'progressbar');
    progress.setAttribute('aria-label', 'Playback progress');
    progress.append(element(doc, 'span', 'silo-streams__progress-fill'));
    body.append(timing, progress);
    const state = item.playback_state === 'playing' ? 'Playing' : item.playback_state === 'paused' ? 'Paused' : 'Unknown playback state';
    const indicator = element(doc, 'span', 'silo-streams__state');
    indicator.append(icon(doc, state === 'Playing' ? 'play' : state === 'Paused' ? 'pause' : 'unknown'));
    if (state === 'Playing') indicator.classList.add('silo-streams__state--playing');
    indicator.setAttribute('role', 'img');
    indicator.setAttribute('aria-label', state);
    indicator.title = state;
    card.append(artwork, body, indicator);
    card._siloItem = item;
    return card;
  }
  function tick(root, advance) {
    root.querySelectorAll('.silo-streams__row').forEach(card => {
      const item = card._siloItem;
      const duration = number(item.file_duration ?? item.duration_seconds);
      let position = number(item.position_seconds);
      if (position !== null && item.playback_state === 'playing') position += advance;
      if (position !== null && duration !== null) position = Math.min(position, duration);
      card.querySelector('.silo-streams__time').textContent = clock(position) + ' / ' + clock(duration);
      const end = card.querySelector('.silo-streams__end');
      end.textContent = '';
      if (duration !== null && position !== null && item.playback_state === 'playing' && !root._siloStale) {
        end.textContent = 'Ends ≈ ' + new Date(Date.now() + Math.max(0,duration-position)*1000).toLocaleTimeString([], {hour:'numeric',minute:'2-digit'});
      }
      const progress = card.querySelector('.silo-streams__progress');
      const fraction = position !== null && duration !== null && duration > 0 ? Math.max(0,Math.min(100,position/duration*100)) : null;
      progress.firstChild.style.width = (fraction ?? 0) + '%';
      if (fraction !== null) {
        progress.setAttribute('aria-valuenow', String(Math.round(fraction)));
        progress.setAttribute('aria-valuemin', '0'); progress.setAttribute('aria-valuemax','100');
        progress.removeAttribute('aria-valuetext');
      } else { progress.removeAttribute('aria-valuenow'); progress.setAttribute('aria-valuetext','Unknown duration or position'); }
    });
  }
  function render(root, payload) {
    shell(root);
    root._siloStale = false;
    root._siloRenderedAt = Date.now();
    root._siloFrozenAdvance = 0;
    root.querySelector('.silo-streams__summary').textContent = summaryText(payload.summary || {});
    root.querySelector('.silo-streams__status').textContent = '';
    const rows = root.querySelector('.silo-streams__rows');
    rows.replaceChildren();
    const items = Array.isArray(payload.items) ? payload.items : [];
    items.forEach(item => rows.append(row(root, item, payload.server && payload.server.name)));
    if (!items.length) rows.append(element(root.ownerDocument, 'p', 'silo-streams__empty', 'No active streams'));
    tick(root, 0);
  }
  function mount(root, options) {
    options = options || {};
    if (root._siloUnmount) root._siloUnmount();
    shell(root);
    const doc = root.ownerDocument;
    const win = doc.defaultView || global;
    let stopped = false, timer, controller, inFlight = false, successful = false;
    let interval = Math.max(5,Math.min(60,Number(options.pollInterval) || 5));
    const endpoint = options.endpoint || '/plugins/silo-streams/api.php';
    async function poll() {
      if (stopped || doc.hidden || inFlight) return;
      inFlight = true;
      controller = new AbortController();
      const timeout = win.setTimeout(() => controller.abort(), 20000);
      try {
        const response = await win.fetch(endpoint, {credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}});
        if (!response.ok) throw new Error('Request failed');
        const payload = await response.json();
        if (!payload || payload.ok !== true || !Array.isArray(payload.items)) throw new Error('Invalid response');
        if (!stopped && !doc.hidden) {
          render(root,payload); successful = true;
          interval = Math.max(5,Math.min(60,Number(payload.poll_interval) || interval));
        }
      } catch (_) {
        if (!stopped && !doc.hidden) {
          root._siloStale = true;
          root.querySelector('.silo-streams__status').textContent = successful ? 'Unable to refresh · showing last received streams. Progress is paused.' : 'Unable to load streams. Check Silo Streams settings.';
          tick(root,root._siloFrozenAdvance || 0);
        }
      } finally {
        win.clearTimeout(timeout); inFlight = false;
        if (!stopped && !doc.hidden) timer = win.setTimeout(poll,interval*1000);
      }
    }
    const clockTimer = win.setInterval(() => {
      if (!root._siloStale && !doc.hidden && successful) {
        root._siloFrozenAdvance = Math.max(0,(Date.now()-root._siloRenderedAt)/1000);
        tick(root,root._siloFrozenAdvance);
      }
    },1000);
    function visibility() {
      win.clearTimeout(timer);
      if (doc.hidden) { if(controller) controller.abort(); }
      else { root._siloStale = true; poll(); }
    }
    doc.addEventListener('visibilitychange',visibility);
    poll();
    const unmount = () => { stopped = true; win.clearTimeout(timer); win.clearInterval(clockTimer); if(controller) controller.abort(); doc.removeEventListener('visibilitychange',visibility); };
    root._siloUnmount = unmount;
    return unmount;
  }
  const api = {mount,render,clock,bitrate,safePoster,summaryText};
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  global.SiloStreams = api;
})(typeof window !== 'undefined' ? window : globalThis);
