/*
 * Self registration — administration page. Depends on i18n.js and window.SR_CONTEXT (provided by index.php).
 * Single state, full re-render through innerHTML: every value coming from the database or a visitor goes
 * through esc() / th(). Settings being edited live in state.draft so a re-render never loses input.
 */
(function () {
  'use strict';
  let ctx = window.SR_CONTEXT;
  const { t, locale } = window.SRi18n.make(ctx.lang);
  const app = document.getElementById('sr-admin');

  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // Translation with escaped params, for use inside HTML
  const th = (key, params) => {
    if (!params) return t(key);
    const p = {};
    for (const k in params) p[k] = esc(params[k]);
    return t(key, p);
  };

  const state = {
    draft: null, filter: 'pending', selected: new Set(), busy: false, flash: '', flashErr: false,
    notes: {},   // notes being typed, per submission (kept across re-renders until saved)
    ts: { site: ctx.turnstileSite, secret: '', trustCf: ctx.trustCf },
    formUrl: ctx.formUrl || '',
    channel: ctx.channel || 'stable', update: null, updateMsg: '',
  };

  function freshDraft() {
    const s = ctx.settings;
    return {
      open: s.open, deadline: s.deadline, allowFace: s.allowFace, waitlist: s.waitlist,
      sessions: new Set(s.sessions), divisions: new Set(s.divisions),
      quotas: JSON.parse(JSON.stringify(s.quotas || {})),
      mails: Object.assign({}, s.mails),
    };
  }
  state.draft = freshDraft();

  // ---------- API ----------

  async function api(action, body) {
    const r = await fetch('api.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ action }, body || {})),
    });
    let j;
    try { j = await r.json(); } catch (e) { throw new Error('HTTP ' + r.status); }
    if (j && j.error) throw new Error(j.error);
    return j;
  }

  async function run(fn, okMsg) {
    if (state.busy) return;
    state.busy = true;
    render();
    try {
      const res = await fn();
      if (res && res.tour) ctx = res;
      flash(typeof okMsg === 'function' ? okMsg(res) : okMsg, false);
    } catch (e) {
      flash(t('admin.failed', { error: e.message }), true);
    } finally {
      state.busy = false;
      render();
    }
  }

  let flashTimer;
  function flash(m, err) {
    state.flash = m || '';
    state.flashErr = !!err;
    clearTimeout(flashTimer);
    if (m) flashTimer = setTimeout(() => { state.flash = ''; render(); }, 6000);
  }

  // ---------- actions ----------

  function saveSettings() {
    const d = state.draft;
    run(async () => {
      const res = await api('settings', { settings: {
        open: d.open, deadline: d.deadline, allowFace: d.allowFace, waitlist: d.waitlist,
        sessions: [...d.sessions], divisions: [...d.divisions], quotas: d.quotas, mails: d.mails,
      } });
      ctx = res;
      state.draft = freshDraft();
      return res;
    }, t('admin.saved'));
  }

  function saveTurnstile() {
    run(async () => {
      const res = await api('turnstile', state.ts);
      state.ts.secret = '';
      return res;
    }, t('admin.saved'));
  }

  function saveFormUrl() {
    run(async () => {
      const res = await api('formUrl', { url: state.formUrl });
      state.formUrl = res.formUrl || '';
      return res;
    }, t('admin.saved'));
  }

  // Marks the requests validated and posts the lines to IANSEO's "List load", which imports them and shows its report.
  function validate(ids) {
    if (!ids.length || state.busy) return;
    // the report tab is opened now, during the click: opened after the network call, popup blockers would stop it
    const win = window.open('', 'sr-listload');
    run(async () => {
      let res;
      try {
        res = await api('validate', { ids });
      } catch (e) {
        if (win) win.close();
        throw e;
      }
      state.selected.clear();
      if (!res.lines.length) {
        if (win) win.close();
        return api('context');
      }
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = ctx.listLoadUrl;
      const ta = document.createElement('textarea');
      ta.name = 'txtList';
      ta.value = res.lines.join('\n');
      const flag = document.createElement('input');
      flag.type = 'hidden';
      flag.name = 'TextList';
      flag.value = '1';
      form.append(ta, flag);
      // IANSEO's report opens in another tab; back here, the page is refreshed: the requests whose entry now exists
      // get their "validated" email (Mailer)
      // blocked anyway (no window): the report replaces this page, the emails go when the admin comes back here
      form.target = win ? 'sr-listload' : '_self';
      form.style.display = 'none';
      document.body.appendChild(form);
      form.submit();
      form.remove();
      const refresh = () => { window.removeEventListener('focus', refresh); api('context').then(res => { ctx = res; render(); }).catch(() => {}); };
      window.addEventListener('focus', refresh);
      setTimeout(refresh, 8000);
      return api('context');
    }, t('requests.importOpened'));
  }

  function setStatus(action, ids) {
    if (!ids.length) return;
    run(async () => { state.selected.clear(); return api(action, { ids }); }, t('admin.saved'));
  }

  // Sessions (and bow types with a quota) that entering these waiting-list requests would overfill
  function overfilled(ids) {
    const rows = ctx.requests.filter(r => ids.includes(r.id) && r.status === 3);
    const over = new Set();
    const count = {};
    for (const r of rows) {
      count[r.session] = (count[r.session] || 0) + 1;
      count[r.session + '|' + r.division] = (count[r.session + '|' + r.division] || 0) + 1;
      const s = ctx.sessions.find(x => x.order === r.session);
      const u = ctx.usage[r.session] || { total: 0, div: {} };
      const quota = (ctx.settings.quotas[r.session] || {})[r.division];
      if (s && count[r.session] > s.capacity - u.total) over.add(sessionLabel(s));
      else if (quota && count[r.session + '|' + r.division] > quota - ((u.div || {})[r.division] || 0)) over.add((s ? sessionLabel(s) : r.session) + ' (' + r.division + ')');
    }
    return [...over];
  }

  // Entering from the waiting list = validating: same List load import. The organiser may go over the places.
  function promote(ids) {
    if (!ids.length || state.busy) return;
    const over = overfilled(ids);
    if (over.length && !window.confirm(t('requests.overConfirm', { list: over.join(', ') }))) return;
    validate(ids);
  }

  // Saved on its own (not through run()): a note left while another action is running must not be dropped.
  // The draft stays in state.notes until the server has it, so a re-render meanwhile keeps the text.
  function saveNote(group) {
    if (!(group in state.notes)) return;
    const note = state.notes[group];
    api('note', { group, note }).then(res => {
      ctx = res;
      if (state.notes[group] === note) delete state.notes[group];
      flash(t('admin.saved'));
    }, e => flash(t('admin.failed', { error: e.message }), true)).then(render);
  }

  function purge() {
    const days = parseInt(document.getElementById('sr-purge-days').value, 10);
    if (!(days >= 0)) return;
    run(() => api('purge', { days }), res => t('requests.purged', { n: res.purged }));
  }

  async function checkUpdate(force) {
    try {
      state.update = await api('update-check', { force: !!force });
      state.updateMsg = state.update.error ? t('update.checkFailed', { error: state.update.error }) : '';
    } catch (e) {
      state.updateMsg = t('update.checkFailed', { error: e.message });
    }
    render();
  }

  async function setChannel(channel) {
    try {
      await api('update-channel', { channel });
      state.channel = channel;
      await checkUpdate(true);
    } catch (e) {
      flash(t('admin.failed', { error: e.message }), true);
      render();
    }
  }

  function installUpdate() {
    run(async () => {
      const res = await api('update-install');
      state.update = null;
      state.updateMsg = t('update.done', { version: res.version });
      return res;
    }, '');
  }

  // ---------- rendering ----------

  const sessionLabel = s => t('session.label', { n: s.order }) + (s.name ? ' – ' + s.name : '');
  const fmtDateTime = d => (d ? new Date(d.replace(' ', 'T')).toLocaleString(locale, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '');

  function render() {
    const focus = document.activeElement && document.activeElement.id;
    app.innerHTML = [
      renderHeader(),
      renderAlerts(),
      state.flash ? `<div class="sr-alert ${state.flashErr ? 'sr-err' : 'sr-ok'}">${esc(state.flash)}</div>` : '',
      renderSettings(),
      renderPlaces(),
      renderRequests(),
      ctx.isAdmin ? renderFormUrl() : '',
      ctx.isAdmin ? renderTurnstile() : '',
    ].join('');
    bind();
    if (focus) {
      const el = document.getElementById(focus);
      if (el) el.focus();
    }
  }

  function renderHeader() {
    let upd = '';
    if (ctx.isAdmin) {
      const u = state.update;
      upd = `<span class="sr-upd">
        <label>${th('update.channel')} <select id="sr-channel" ${state.busy ? 'disabled' : ''}>
          ${['stable', 'nightly'].map(c => `<option value="${c}" ${state.channel === c ? 'selected' : ''}>${th('update.channel.' + c)}</option>`).join('')}
        </select></label>
        ${u && u.available ? `<span>${th('update.available', { latest: u.latest, current: ctx.version })}</span>
          <button type="button" id="sr-update" ${state.busy ? 'disabled' : ''}>${th('update.install')}</button>` : ''}
        ${u && !u.available && !u.error ? `<span class="sr-muted">${th('update.upToDate')}</span>` : ''}
        <button type="button" class="sr-linkbtn" id="sr-update-check">${th('update.check')}</button>
        ${state.updateMsg ? `<span>${esc(state.updateMsg)}</span>` : ''}
      </span>`;
    }
    return `<div class="sr-head"><h2>${th('admin.title')}</h2><span class="sr-muted">v${esc(ctx.version)}</span>${upd}</div>`;
  }

  function renderAlerts() {
    const a = [];
    if (!ctx.lookupCount) a.push(['sr-err', th('alert.noLookup', { ioc: ctx.tour.ioc || '?', url: ctx.syncUrl })]);
    if (!ctx.sessions.length) a.push(['sr-err', th('alert.noSessions')]);
    if (!ctx.turnstile) a.push(['sr-err', th('alert.noTurnstile')]);
    if (!ctx.https) a.push(['sr-err', th('alert.noHttps')]);
    if (ctx.settings.open && ctx.closed) a.push(['sr-warn', th('alert.closed.' + ctx.closed)]);
    // places freed (refusal, entry deleted in IANSEO…) while archers wait: the organiser decides who goes up
    for (const s of ctx.sessions) {
      const waiting = waitingCount(s.order);
      const free = Math.max(0, s.capacity - (ctx.usage[s.order] || { total: 0 }).total);
      if (waiting && free) a.push(['sr-warn', th('alert.waitlistFree', { n: s.order, free, waiting })]);
    }
    return a.map(([c, m]) => `<div class="sr-alert ${c}">${m}</div>`).join('');
  }

  function renderSettings() {
    const d = state.draft;
    const isOpen = ctx.settings.open && !ctx.closed && ctx.turnstile && ctx.https;
    return `<div class="sr-box">
      <h3>${th('settings.title')}</h3>
      <div class="sr-status ${isOpen ? 'sr-on' : ''}">${th(isOpen ? 'settings.isOpen' : 'settings.isClosed')}</div>
      <div class="sr-row">
        <label class="sr-chk"><input type="checkbox" id="sr-open" ${d.open ? 'checked' : ''}> ${th('settings.open')}</label>
        <label>${th('settings.deadline')} <input type="date" id="sr-deadline" value="${esc(d.deadline)}"></label>
        <label class="sr-chk"><input type="checkbox" id="sr-allowface" ${d.allowFace ? 'checked' : ''}> ${th('settings.allowFace')}</label>
        <label class="sr-chk"><input type="checkbox" id="sr-waitlist" ${d.waitlist ? 'checked' : ''}> ${th('settings.waitlist')}</label>
      </div>
      <div class="sr-row sr-link-row">
        <span>${th('settings.publicUrl')}</span>
        <input type="text" id="sr-url" readonly value="${esc(ctx.publicUrl)}">
        <button type="button" id="sr-copy">${th('settings.copy')}</button>
      </div>
      <div class="sr-cols">
        <fieldset><legend>${th('settings.sessions')}</legend>
          ${ctx.sessions.map(s => `<label class="sr-chk"><input type="checkbox" class="sr-ses" value="${s.order}" ${d.sessions.has(s.order) ? 'checked' : ''}>
            ${esc(sessionLabel(s))} <span class="sr-muted">(${th('settings.capacity', { n: s.capacity })})</span></label>`).join('') || `<span class="sr-muted">${th('alert.noSessions')}</span>`}
        </fieldset>
        <fieldset><legend>${th('settings.divisions')}</legend>
          <div class="sr-muted sr-small">${th('settings.divisionsHelp')}</div>
          ${ctx.divisions.map(v => `<label class="sr-chk"><input type="checkbox" class="sr-div" value="${esc(v.id)}" ${d.divisions.has(v.id) ? 'checked' : ''}>
            ${esc(v.id)} – ${esc(v.name)}</label>`).join('')}
        </fieldset>
      </div>
      ${renderQuotas()}
      ${renderMails()}
      <div class="sr-row"><button type="button" class="sr-primary" id="sr-save" ${state.busy ? 'disabled' : ''}>${th('settings.save')}</button></div>
    </div>`;
  }

  // Emails through the optional Mailer module
  function renderMails() {
    const m = ctx.mailer;
    const d = state.draft;
    let head;
    if (!m.installed) head = `<div class="sr-muted sr-small">${th('mails.notInstalled')}</div>`;
    else if (!m.available) head = `<div class="sr-alert sr-warn">${th('mails.notReady', { url: m.url })}</div>`;
    else head = `<div class="sr-muted sr-small">${th('mails.ready', { url: m.url })}</div>`;
    const box = (k, extra) => `<label class="sr-chk"><input type="checkbox" class="sr-mail" value="${k}" ${d.mails[k] ? 'checked' : ''} ${m.installed ? '' : 'disabled'}> ${th('mails.' + k)}${extra || ''}</label>`;
    return `<h4>${th('mails.title')}</h4>${head}
      <div class="sr-cols"><div>
        ${box('received')}${box('validated')}${box('promoted')}${box('refused')}
        ${box('organiser', m.available ? (m.contact ? ` <span class="sr-muted">(${esc(m.contact)})</span>` : ` <span class="sr-warn-text">${th('mails.noContact', { url: m.url })}</span>`) : '')}
      </div></div>`;
  }

  function quotaDivisions() {
    const d = state.draft;
    return ctx.divisions.filter(v => !d.divisions.size || d.divisions.has(v.id));
  }

  function renderQuotas() {
    const d = state.draft;
    const sessions = ctx.sessions.filter(s => d.sessions.has(s.order));
    if (!sessions.length) return '';
    const divs = quotaDivisions();
    return `<h4>${th('settings.quotas')}</h4>
      <div class="sr-muted sr-small">${th('settings.quotasHelp')}</div>
      <div class="sr-scroll"><table class="sr-table">
        <tr><th>${th('settings.session')}</th>${divs.map(v => `<th>${esc(v.id)}</th>`).join('')}</tr>
        ${sessions.map(s => `<tr><td>${esc(sessionLabel(s))}</td>${divs.map(v => {
          const q = (d.quotas[s.order] || {})[v.id];
          const used = ((ctx.usage[s.order] || {}).div || {})[v.id] || 0;
          return `<td><input type="number" min="0" max="9999" class="sr-quota" id="sr-q-${s.order}-${esc(v.id)}" data-ses="${s.order}" data-div="${esc(v.id)}" value="${q ? esc(q) : ''}" placeholder="–">
            <span class="sr-muted sr-small">${th('settings.used', { n: used })}</span></td>`;
        }).join('')}</tr>`).join('')}
      </table></div>`;
  }

  const waitingCount = order => ctx.requests.filter(r => r.status === 3 && r.session === order).length;

  function renderPlaces() {
    if (!ctx.sessions.length) return '';
    return `<div class="sr-box"><h3>${th('places.title')}</h3>
      <div class="sr-scroll"><table class="sr-table">
        <tr><th>${th('settings.session')}</th><th>${th('places.capacity')}</th><th>${th('places.taken')}</th><th>${th('places.free')}</th><th>${th('places.waiting')}</th><th>${th('places.byDivision')}</th></tr>
        ${ctx.sessions.map(s => {
          const u = ctx.usage[s.order] || { total: 0, div: {} };
          const free = Math.max(0, s.capacity - u.total);
          return `<tr class="${free ? '' : 'sr-full'}"><td>${esc(sessionLabel(s))}</td><td>${s.capacity}</td><td>${u.total}</td><td>${free}</td><td>${waitingCount(s.order) || ''}</td>
            <td>${Object.keys(u.div || {}).sort().map(k => `<span class="sr-chip">${esc(k)} ${u.div[k]}${(ctx.settings.quotas[s.order] || {})[k] ? '/' + ctx.settings.quotas[s.order][k] : ''}</span>`).join(' ')}</td></tr>`;
        }).join('')}
      </table></div>
      <div class="sr-muted sr-small">${th('places.help')}</div></div>`;
  }

  const STATUS = { 0: 'pending', 1: 'validated', 2: 'refused', 3: 'waitlist' };
  const PRIORITY = { 1: 'high', 0: 'normal', '-1': 'low' };

  // Rows of the current tab; the waiting list in the order places are offered (session, priority, first come)
  function filteredRows() {
    const rows = ctx.requests.filter(r => state.filter === 'all' || STATUS[r.status] === state.filter);
    if (state.filter === 'waitlist') rows.sort((a, b) => a.session - b.session || a.rank - b.rank);
    return rows;
  }

  function renderStatus(r) {
    let s = th('requests.st.' + STATUS[r.status]);
    if (r.status === 3) {
      s += `<div class="sr-small">${th('requests.rank', { n: r.rank })}</div>
        <label class="sr-small">${th('requests.priority')} <select class="sr-prio" data-id="${r.id}" ${state.busy ? 'disabled' : ''}>
          ${[1, 0, -1].map(p => `<option value="${p}" ${r.priority === p ? 'selected' : ''}>${th('priority.' + PRIORITY[p])}</option>`).join('')}
        </select></label>`;
    } else if (r.waited) {
      s += `<div class="sr-muted sr-small">${th('requests.waited')}</div>`;
    }
    if (r.status === 1 && !r.hasEntry) s += `<div class="sr-warn-text">${th('requests.noEntry')}</div>`;
    return s;
  }

  function renderRequests() {
    const all = ctx.requests;
    const counts = { pending: 0, waitlist: 0, validated: 0, refused: 0, all: all.length };
    for (const r of all) counts[STATUS[r.status]]++;
    const rows = filteredRows();
    const sel = rows.filter(r => state.selected.has(r.id));
    const sessionsBy = {};
    for (const s of ctx.sessions) sessionsBy[s.order] = s;
    const tabs = ['pending', 'waitlist', 'validated', 'refused', 'all'].filter(f => f !== 'waitlist' || ctx.settings.waitlist || counts.waitlist);
    const nPending = sel.filter(r => r.status === 0).length;
    const nWaiting = sel.filter(r => r.status === 3).length;
    return `<div class="sr-box"><h3>${th('requests.title')}</h3>
      <div class="sr-row">${tabs.map(f =>
        `<button type="button" class="sr-tab ${state.filter === f ? 'on' : ''}" data-filter="${f}">${th('requests.' + f)} (${counts[f]})</button>`).join('')}</div>
      ${rows.length ? `<div class="sr-row">
        <button type="button" class="sr-primary" id="sr-validate-sel" ${nPending && !state.busy ? '' : 'disabled'}>${th('requests.validateSel', { n: nPending })}</button>
        ${counts.waitlist ? `<button type="button" class="sr-primary" id="sr-promote-sel" ${nWaiting && !state.busy ? '' : 'disabled'}>${th('requests.promoteSel', { n: nWaiting })}</button>` : ''}
        <button type="button" id="sr-refuse-sel" ${sel.filter(r => r.status !== 2).length && !state.busy ? '' : 'disabled'}>${th('requests.refuseSel')}</button>
        <button type="button" id="sr-reset-sel" ${sel.filter(r => r.status === 1 || r.status === 2).length && !state.busy ? '' : 'disabled'}>${th('requests.resetSel')}</button>
        <span class="sr-muted sr-small">${th('requests.validateHelp')}</span>
      </div>
      <div class="sr-scroll"><table class="sr-table sr-requests">
        <tr><th><input type="checkbox" id="sr-all" ${sel.length === rows.length ? 'checked' : ''}></th>
          <th>${th('requests.date')}</th><th>${th('requests.archer')}</th><th>${th('requests.category')}</th><th>${th('requests.face')}</th>
          <th>${th('settings.session')}</th><th>${th('requests.contact')}</th><th>${th('requests.note')}</th><th>${th('requests.status')}</th><th></th></tr>
        ${rows.map(r => {
          const s = sessionsBy[r.session];
          return `<tr class="sr-st-${r.status}">
            <td><input type="checkbox" class="sr-sel" value="${r.id}" ${state.selected.has(r.id) ? 'checked' : ''}></td>
            <td>${esc(fmtDateTime(r.created))}<div class="sr-muted sr-small">${esc(r.group.slice(0, 8).toUpperCase())}</div></td>
            <td><b>${esc(r.lastName)}</b> ${esc(r.firstName)}<div class="sr-muted sr-small">${esc(r.code)} · ${esc(r.club)}</div></td>
            <td>${esc(r.division)} ${esc(r.class)}</td>
            <td>${esc(r.faceName)}</td>
            <td>${esc(s ? sessionLabel(s) : t('session.label', { n: r.session }))}</td>
            <td>${esc(r.email)}${r.comment ? `<div class="sr-comment">${esc(r.comment)}</div>` : ''}</td>
            <td><textarea class="sr-note" id="sr-note-${r.id}" data-group="${esc(r.group)}" rows="2" maxlength="1000" placeholder="${esc(t('requests.notePlaceholder'))}">${esc(r.group in state.notes ? state.notes[r.group] : r.note)}</textarea></td>
            <td>${renderStatus(r)}</td>
            <td class="sr-actions">
              ${r.status === 0 ? `<button type="button" data-act="validate" data-id="${r.id}">${th('requests.validate')}</button>
                <button type="button" data-act="refuse" data-id="${r.id}">${th('requests.refuse')}</button>`
                : r.status === 3 ? `<button type="button" data-act="promote" data-id="${r.id}">${th('requests.promote')}</button>
                <button type="button" data-act="refuse" data-id="${r.id}">${th('requests.refuse')}</button>`
                : `<button type="button" data-act="reset" data-id="${r.id}">${th('requests.reset')}</button>`}
            </td></tr>`;
        }).join('')}
      </table></div>` : `<p class="sr-muted">${th('requests.none')}</p>`}
      <div class="sr-row sr-purge">${th('requests.purge')}
        <input type="number" id="sr-purge-days" min="0" value="30" style="width:5em"> ${th('requests.days')}
        <button type="button" id="sr-purge" ${state.busy ? 'disabled' : ''}>${th('requests.purgeButton')}</button></div>
    </div>`;
  }

  function renderFormUrl() {
    return `<div class="sr-box"><h3>${th('formUrl.title')}</h3>
      <div class="sr-muted sr-small">${th('formUrl.help')}</div>
      <div class="sr-row">
        <input type="url" id="sr-form-url" autocomplete="off" placeholder="https://inscription.example.org/" value="${esc(state.formUrl)}" style="width:24em">
        <button type="button" id="sr-form-url-save" ${state.busy ? 'disabled' : ''}>${th('settings.save')}</button>
      </div>
    </div>`;
  }

  function renderTurnstile() {
    const ts = state.ts;
    return `<div class="sr-box"><h3>${th('turnstile.title')}</h3>
      <div class="sr-muted sr-small">${th('turnstile.help')}</div>
      <div class="sr-grid">
        <label>${th('turnstile.site')}<input type="text" id="sr-ts-site" autocomplete="off" value="${esc(ts.site)}"></label>
        <label>${th('turnstile.secret')}<input type="password" id="sr-ts-secret" autocomplete="new-password" value="${esc(ts.secret)}" placeholder="${ctx.turnstile ? esc(t('turnstile.secretKept')) : ''}"></label>
      </div>
      <label class="sr-chk"><input type="checkbox" id="sr-ts-cf" ${ts.trustCf ? 'checked' : ''}> ${th('turnstile.trustCf')}</label>
      <div class="sr-row"><button type="button" id="sr-ts-save" ${state.busy ? 'disabled' : ''}>${th('settings.save')}</button></div>
    </div>`;
  }

  // ---------- events ----------

  function bind() {
    const $ = id => document.getElementById(id);
    const on = (id, ev, fn) => { const el = $(id); if (el) el.addEventListener(ev, fn); };
    const d = state.draft;

    on('sr-open', 'change', e => { d.open = e.target.checked; });
    on('sr-deadline', 'change', e => { d.deadline = e.target.value; });
    on('sr-allowface', 'change', e => { d.allowFace = e.target.checked; });
    on('sr-waitlist', 'change', e => { d.waitlist = e.target.checked; });
    app.querySelectorAll('.sr-mail').forEach(el => el.addEventListener('change', () => { d.mails[el.value] = el.checked; }));
    app.querySelectorAll('.sr-ses').forEach(el => el.addEventListener('change', () => {
      const n = parseInt(el.value, 10);
      el.checked ? d.sessions.add(n) : d.sessions.delete(n);
      render();
    }));
    app.querySelectorAll('.sr-div').forEach(el => el.addEventListener('change', () => {
      el.checked ? d.divisions.add(el.value) : d.divisions.delete(el.value);
      render();
    }));
    app.querySelectorAll('.sr-quota').forEach(el => el.addEventListener('input', () => {
      const ses = el.dataset.ses, div = el.dataset.div, n = parseInt(el.value, 10);
      if (!d.quotas[ses]) d.quotas[ses] = {};
      if (n > 0) d.quotas[ses][div] = n; else delete d.quotas[ses][div];
    }));
    on('sr-save', 'click', saveSettings);
    on('sr-copy', 'click', () => {
      const url = $('sr-url');
      url.select();
      (navigator.clipboard ? navigator.clipboard.writeText(url.value) : Promise.reject())
        .then(() => { flash(t('settings.copied')); render(); }, () => document.execCommand('copy'));
    });

    app.querySelectorAll('.sr-tab').forEach(el => el.addEventListener('click', () => {
      state.filter = el.dataset.filter;
      state.selected.clear();
      render();
    }));
    app.querySelectorAll('.sr-sel').forEach(el => el.addEventListener('change', () => {
      const id = parseInt(el.value, 10);
      el.checked ? state.selected.add(id) : state.selected.delete(id);
      render();
    }));
    on('sr-all', 'change', e => {
      state.selected = new Set(e.target.checked ? filteredRows().map(r => r.id) : []);
      render();
    });
    const selected = pred => ctx.requests.filter(r => state.selected.has(r.id) && pred(r)).map(r => r.id);
    on('sr-validate-sel', 'click', () => validate(selected(r => r.status === 0)));
    on('sr-promote-sel', 'click', () => promote(selected(r => r.status === 3)));
    on('sr-refuse-sel', 'click', () => setStatus('refuse', selected(r => r.status !== 2)));
    on('sr-reset-sel', 'click', () => setStatus('reset', selected(r => r.status === 1 || r.status === 2)));
    app.querySelectorAll('[data-act]').forEach(el => el.addEventListener('click', () => {
      const id = parseInt(el.dataset.id, 10);
      if (el.dataset.act === 'validate') validate([id]);
      else if (el.dataset.act === 'promote') promote([id]);
      else setStatus(el.dataset.act, [id]);
    }));
    app.querySelectorAll('.sr-prio').forEach(el => el.addEventListener('change', () => {
      run(() => api('priority', { ids: [parseInt(el.dataset.id, 10)], priority: parseInt(el.value, 10) }), t('admin.saved'));
    }));
    app.querySelectorAll('.sr-note').forEach(el => {
      el.addEventListener('input', () => { state.notes[el.dataset.group] = el.value; });
      el.addEventListener('change', () => saveNote(el.dataset.group));
    });
    on('sr-purge', 'click', purge);

    on('sr-ts-site', 'input', e => { state.ts.site = e.target.value; });
    on('sr-ts-secret', 'input', e => { state.ts.secret = e.target.value; });
    on('sr-ts-cf', 'change', e => { state.ts.trustCf = e.target.checked; });
    on('sr-ts-save', 'click', saveTurnstile);
    on('sr-form-url', 'input', e => { state.formUrl = e.target.value; });
    on('sr-form-url-save', 'click', saveFormUrl);

    on('sr-channel', 'change', e => setChannel(e.target.value));
    on('sr-update-check', 'click', () => checkUpdate(true));
    on('sr-update', 'click', installUpdate);
  }

  render();
  if (ctx.isAdmin) checkUpdate(false);
})();
