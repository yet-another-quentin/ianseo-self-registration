/*
 * Self registration — public form. Context: JSON block #sr-ctx written by register.php. Texts: i18n.js.
 * The DOM is built with h() (text nodes only, never innerHTML with data). Steps: identify → choose → done.
 * The identify step is built once per visit of the step, so the Turnstile widget is not destroyed by re-renders.
 */
(function () {
  'use strict';
  const ctx = JSON.parse(document.getElementById('sr-ctx').textContent);
  const { t, locale } = window.SRi18n.make(ctx.lang);
  const app = document.getElementById('sr-app');

  const state = {
    step: 'identify', busy: false, error: '', info: '',
    license: '', dob: '', captcha: '', widget: null,
    archer: null, offer: null, allowFace: false,
    division: '', face: 0, sessions: new Set(), email: '', comment: '', consent: false,
    reference: '', booked: [], waiting: [],
  };

  // h('div', {class: 'x', onclick: fn}, 'text', child…): strings become text nodes
  function h(tag, attrs, ...children) {
    const el = document.createElement(tag);
    for (const k in attrs || {}) {
      const v = attrs[k];
      if (v == null || v === false) continue;
      if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else if (k === 'class') el.className = v;
      else if (k === 'value') el.value = v;
      else if (k === 'checked' || k === 'disabled') el[k] = !!v;
      else el.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children.flat()) {
      if (c == null || c === false) continue;
      el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
    }
    return el;
  }

  const fmtDate = d => (d ? new Date(d.slice(0, 10) + 'T00:00:00').toLocaleDateString(locale, { day: 'numeric', month: 'long', year: 'numeric' }) : '');
  const fmtDateTime = d => (d ? new Date(d.replace(' ', 'T')).toLocaleString(locale, { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) : '');

  function errorText(key, params) {
    const p = Object.assign({}, params || {});
    if (key === 'err.sessionUnavailable') p.reason = t('session.' + (p.reason || 'full'));
    const s = t(key, p);
    return s === key ? t('err.server') : s;
  }

  // ---------- API ----------

  async function api(action, body) {
    let r;
    try {
      r = await fetch('register-api.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-SR-Token': ctx.csrf },
        // the form's language: the emails to the archer are written in it
        body: JSON.stringify(Object.assign({ action, c: ctx.tour.code, lang: ctx.lang }, body || {})),
      });
    } catch (e) {
      return { error: 'err.network' };
    }
    try { return await r.json(); } catch (e) { return { error: r.status === 429 ? 'err.rate' : 'err.server' }; }
  }

  // ---------- page frame ----------

  const tourDates = tour => (tour.from === tour.to ? fmtDate(tour.from) : t('tour.dates', { from: fmtDate(tour.from), to: fmtDate(tour.to) }));
  // venue and town, without repeating the town when the venue already contains it
  const tourPlace = tour => [tour.venue, tour.venue && tour.where && tour.venue.toLowerCase().includes(tour.where.toLowerCase()) ? '' : tour.where].filter(Boolean).join(', ');
  const logo = (src, cls) => (src ? h('img', { src, alt: '', class: cls, loading: 'lazy', onerror: e => e.target.remove() }) : null);

  // Competition details as entered in IANSEO (Competition › Competition data): logos, name, organiser, place, dates
  function header() {
    const tour = ctx.tour;
    if (!tour) return h('header', { class: 'sr-head' }, h('h1', null, t('list.title')));
    const img = tour.images || {};
    // logo | name + organiser | logo; an empty cell keeps the name centred when only one logo exists
    return h('header', { class: 'sr-head' },
      h('div', { class: 'sr-head-row' + (img.L || img.R ? '' : ' sr-no-logo') },
        h('div', { class: 'sr-logo-cell' }, logo(img.L, 'sr-logo')),
        h('div', { class: 'sr-head-text' },
          h('h1', null, tour.name),
          tour.organiser ? h('p', { class: 'sr-muted sr-organiser' }, t('tour.organiser', { name: tour.organiser })) : null),
        h('div', { class: 'sr-logo-cell' }, logo(img.R, 'sr-logo'))),
      h('ul', { class: 'sr-facts' },
        h('li', null, h('span', { class: 'sr-fact-label' }, t('tour.when')), tourDates(tour)),
        tourPlace(tour) ? h('li', null, h('span', { class: 'sr-fact-label' }, t('tour.where')), tourPlace(tour)) : null));
  }

  // Sessions open for registration, with their start time (places are shown after identification)
  function schedule() {
    const list = (ctx.tour && ctx.tour.sessions) || [];
    if (!list.length) return null;
    return h('section', { class: 'sr-card sr-schedule' },
      h('h2', null, t('tour.schedule')),
      h('ul', null, list.map(s => h('li', null,
        h('strong', null, t('session.label', { n: s.order }) + (s.name ? ' – ' + s.name : '')),
        s.start ? h('span', { class: 'sr-muted' }, ' · ' + fmtDateTime(s.start)) : null))));
  }

  function footer() {
    const b = ctx.tour && ctx.tour.images && ctx.tour.images.B;
    return b ? h('footer', { class: 'sr-foot' }, logo(b, 'sr-banner')) : null;
  }

  function message() {
    return h('div', { id: 'sr-msg', role: 'alert', class: state.error ? 'sr-alert sr-err' : (state.info ? 'sr-alert sr-info' : 'sr-alert'), hidden: !state.error && !state.info },
      state.error || state.info);
  }

  function setMessage(error, info) {
    state.error = error || '';
    state.info = info || '';
    const old = document.getElementById('sr-msg');
    if (old) old.replaceWith(message());
  }

  function render() {
    app.replaceChildren(...[header(), body(), ctx.state === 'form' && state.step === 'identify' ? schedule() : null, footer()].filter(Boolean));
  }

  function body() {
    if (ctx.state === 'list') return renderList();
    if (ctx.state !== 'form') return h('p', { class: 'sr-alert sr-info' }, t('state.' + ctx.state));
    if (state.step === 'done') return renderDone();
    if (state.step === 'choose') return renderChoose();
    return renderIdentify();
  }

  // ---------- competitions open for registration ----------

  function renderList() {
    if (!ctx.tournaments.length) return h('p', { class: 'sr-alert sr-info' }, t('list.empty'));
    return h('ul', { class: 'sr-list' }, ctx.tournaments.map(c => h('li', null,
      h('a', { href: '?c=' + encodeURIComponent(c.code), class: 'sr-list-item' },
        logo(c.images && c.images.L, 'sr-list-logo'),
        h('span', null,
          h('strong', null, c.name),
          h('span', { class: 'sr-muted' }, [tourDates(c), tourPlace(c), c.organiser].filter(Boolean).join(' · ')))))));
  }

  // ---------- step 1: license + birth date + captcha ----------

  function renderIdentify() {
    const form = h('form', { class: 'sr-card', novalidate: true, onsubmit: e => { e.preventDefault(); verify(); } },
      h('h2', null, t('identify.title')),
      h('p', { class: 'sr-muted' }, t('identify.help')),
      h('label', null, t('identify.license'),
        h('input', { id: 'sr-license', name: 'license', autocomplete: 'off', inputmode: 'text', maxlength: '12', required: true, placeholder: '1234567A', value: state.license,
          oninput: e => { state.license = e.target.value; } })),
      h('label', null, t('identify.dob'),
        h('input', { id: 'sr-dob', name: 'dob', type: 'date', required: true, value: state.dob, max: new Date().toISOString().slice(0, 10),
          oninput: e => { state.dob = e.target.value; } })),
      h('div', { id: 'sr-captcha', class: 'sr-captcha' }),
      message(),
      h('button', { type: 'submit', class: 'sr-primary', id: 'sr-verify' }, t('identify.button')));
    mountCaptcha();
    return form;
  }

  // Turnstile is loaded asynchronously (render=explicit): wait for it, then render into #sr-captcha
  function mountCaptcha() {
    state.captcha = '';
    state.widget = null;
    const tryMount = () => {
      const el = document.getElementById('sr-captcha');
      if (!el) return;
      if (!window.turnstile) { setTimeout(tryMount, 200); return; }
      if (el.childElementCount) return;
      state.widget = window.turnstile.render(el, {
        sitekey: ctx.turnstileSite, language: ctx.lang === 'fr' ? 'fr' : 'en',
        callback: tok => { state.captcha = tok; },
        'expired-callback': () => { state.captcha = ''; },
        'error-callback': () => { state.captcha = ''; },
      });
    };
    setTimeout(tryMount, 0);
  }

  function resetCaptcha() {
    state.captcha = '';
    if (window.turnstile && state.widget != null) window.turnstile.reset(state.widget);
  }

  async function verify() {
    if (state.busy) return;
    const license = state.license.replace(/[\s.\-]/g, '').toUpperCase();
    if (!/^[0-9]{6,7}[A-Z]$/.test(license)) return setMessage(t('err.licenseFormat'));
    if (!/^\d{4}-\d{2}-\d{2}$/.test(state.dob)) return setMessage(t('err.dobFormat'));
    if (!state.captcha) return setMessage(t('err.captchaPending'));
    state.busy = true;
    document.getElementById('sr-verify').disabled = true;
    setMessage('', t('identify.checking'));
    const res = await api('verify', { license, dob: state.dob, captcha: state.captcha });
    state.busy = false;
    if (res.error) {
      resetCaptcha();
      const btn = document.getElementById('sr-verify');
      if (btn) btn.disabled = false;
      return setMessage(errorText(res.error, res.params));
    }
    state.license = license;
    applyOffer(res);
    state.step = 'choose';
    state.error = state.info = '';
    render();
    window.scrollTo(0, 0);
  }

  function applyOffer(res) {
    state.archer = res.archer || state.archer;
    state.offer = res.offer;
    state.allowFace = !!res.allowFace || (res.allowFace === undefined && state.allowFace);
    const divs = state.offer.divisions;
    if (!divs.some(d => d.id === state.division)) state.division = divs.length === 1 ? divs[0].id : '';
    const div = currentDivision();
    if (!div || !div.faces.some(f => f.id === state.face)) state.face = div && div.faces.length ? div.faces[0].id : 0;
    // drop sessions that can no longer be chosen (a session that filled up stays chosen when it offers a waiting list)
    if (div) for (const s of [...state.sessions]) if (!div.sessions.some(x => x.order === s && (x.available || x.waitlist))) state.sessions.delete(s);
  }

  const currentDivision = () => (state.offer ? state.offer.divisions.find(d => d.id === state.division) : null);
  const sessionInfo = order => state.offer.sessions.find(s => s.order === order) || { order, name: '' };

  // ---------- step 2: division, face, sessions, contact ----------

  function renderChoose() {
    const a = state.archer;
    const divs = state.offer.divisions;
    const div = currentDivision();
    return h('form', { class: 'sr-card', novalidate: true, onsubmit: e => { e.preventDefault(); submit(); } },
      h('div', { class: 'sr-who' },
        h('strong', null, a.firstName + ' ' + a.lastName),
        h('button', { type: 'button', class: 'sr-link', onclick: restart }, t('choose.notMe'))),
      h('p', { class: 'sr-muted' }, [t('choose.license', { code: a.code }), a.club].filter(Boolean).join(' · ')),

      h('fieldset', null, h('legend', null, t('choose.division')),
        divs.map(d => h('label', { class: 'sr-choice' },
          h('input', { type: 'radio', name: 'division', value: d.id, checked: d.id === state.division,
            onchange: () => { state.division = d.id; state.sessions.clear(); applyOffer({ offer: state.offer }); render(); } }),
          h('span', null, d.name, h('span', { class: 'sr-muted' }, ' — ' + t('choose.class', { name: d.class.name })))))),

      div ? renderFace(div) : null,
      div ? renderSessions(div) : h('p', { class: 'sr-muted' }, t('choose.pickDivision')),

      h('label', null, t('choose.email'),
        h('input', { type: 'email', name: 'email', autocomplete: 'email', maxlength: '100', required: true, value: state.email,
          oninput: e => { state.email = e.target.value; } })),
      h('label', null, t('choose.comment'),
        h('textarea', { name: 'comment', maxlength: '500', rows: '3', oninput: e => { state.comment = e.target.value; } }, state.comment)),
      h('label', { class: 'sr-choice' },
        h('input', { type: 'checkbox', name: 'consent', checked: state.consent, onchange: e => { state.consent = e.target.checked; } }),
        h('span', null, t('choose.consent'))),
      h('p', { class: 'sr-muted sr-small' }, t('choose.privacy')),
      message(),
      h('button', { type: 'submit', class: 'sr-primary', id: 'sr-submit', disabled: state.busy }, t('choose.submit')));
  }

  function renderFace(div) {
    if (!div.faces.length) return null;
    if (!state.allowFace || div.faces.length === 1) {
      return h('p', null, t('choose.faceDefault', { name: div.faces[0].name }));
    }
    return h('label', null, t('choose.face'),
      h('select', { name: 'face', onchange: e => { state.face = parseInt(e.target.value, 10); } },
        div.faces.map((f, i) => h('option', { value: String(f.id), selected: f.id === state.face ? 'selected' : null },
          f.name + (i === 0 ? ' (' + t('choose.default') + ')' : '')))));
  }

  function renderSessions(div) {
    return h('fieldset', null, h('legend', null, t('choose.sessions')),
      div.sessions.some(s => !s.available && s.waitlist) ? h('p', { class: 'sr-muted sr-small' }, t('choose.waitlistHelp')) : null,
      div.sessions.map(s => {
        const info = sessionInfo(s.order);
        const wait = !s.available && s.waitlist;
        let detail;
        if (wait) detail = t('session.waitlist', { reason: t('session.' + s.reason) });
        else if (!s.available) detail = t('session.' + s.reason);
        else if (s.quotaFree !== null && s.quotaFree < s.free) detail = t('session.quotaFree', { n: s.quotaFree, div: div.name });
        else detail = t('session.free', { n: s.free });
        return h('label', { class: 'sr-choice' + (s.available || wait ? '' : ' sr-off') },
          h('input', { type: 'checkbox', name: 'sessions', value: String(s.order), disabled: !s.available && !wait, checked: state.sessions.has(s.order),
            onchange: e => { e.target.checked ? state.sessions.add(s.order) : state.sessions.delete(s.order); } }),
          h('span', null,
            t('session.label', { n: s.order }) + (info.name ? ' – ' + info.name : ''),
            info.start ? h('span', { class: 'sr-muted' }, ' · ' + fmtDateTime(info.start)) : null,
            h('span', { class: s.available ? 'sr-badge' : (wait ? 'sr-badge sr-badge-wait' : 'sr-badge sr-badge-off') }, detail)));
      }));
  }

  function restart() {
    state.step = 'identify';
    state.archer = state.offer = null;
    state.sessions.clear();
    state.error = state.info = '';
    render();
  }

  async function submit() {
    if (state.busy) return;
    const div = currentDivision();
    if (!div) return setMessage(t('err.division'));
    if (!state.sessions.size) return setMessage(t('err.noSession'));
    if (!/^[a-z0-9._#-]+@[a-z0-9._-]+$/i.test(state.email.trim())) return setMessage(t('err.email'));
    if (!state.consent) return setMessage(t('err.consent'));
    state.busy = true;
    document.getElementById('sr-submit').disabled = true;
    setMessage('', t('choose.sending'));
    const sessions = [...state.sessions].sort((a, b) => a - b);
    const res = await api('submit', {
      division: state.division, face: state.face, sessions,
      // the full sessions the archer saw as "waiting list": the server books them only on the list
      waitlist: sessions.filter(o => div.sessions.some(s => s.order === o && !s.available && s.waitlist)),
      email: state.email.trim(), comment: state.comment, consent: state.consent,
    });
    state.busy = false;
    if (res.error) {
      if (res.error === 'err.expired') {
        restart();
        return setMessage(t('err.expired'));
      }
      if (res.offer) {
        applyOffer({ offer: res.offer });
        render();
      }
      const btn = document.getElementById('sr-submit');
      if (btn) btn.disabled = false;
      return setMessage(errorText(res.error, res.params));
    }
    state.reference = res.reference;
    state.emailSent = !!res.emailSent;
    state.booked = res.sessions;
    state.waiting = res.waitlist || [];
    state.step = 'done';
    render();
    window.scrollTo(0, 0);
  }

  // ---------- step 3: confirmation ----------

  function renderDone() {
    const div = currentDivision();
    const list = orders => h('ul', null, orders.map(o => {
      const info = sessionInfo(o);
      return h('li', null, t('session.label', { n: o }) + (info.name ? ' – ' + info.name : '') + (info.start ? ' · ' + fmtDateTime(info.start) : ''));
    }));
    const waiting = state.waiting.length > 0;
    return h('div', { class: 'sr-card' },
      h('h2', null, t('done.title')),
      h('p', null, t('done.reference', { ref: state.reference })),
      state.booked.length && waiting ? h('strong', null, t('done.sessions')) : null,
      state.booked.length ? list(state.booked) : null,
      waiting ? h('strong', null, t('done.waitlist')) : null,
      waiting ? list(state.waiting) : null,
      div ? h('p', null, t('done.category', { division: div.name, cls: div.class.name })) : null,
      state.emailSent ? h('p', null, t('done.emailSent', { email: state.email.trim() })) : null,
      state.booked.length ? h('p', { class: 'sr-alert sr-info' }, t('done.pending')) : null,
      waiting ? h('p', { class: 'sr-alert sr-wait' }, t('done.waitlistInfo')) : null,
      h('button', { type: 'button', class: 'sr-link', onclick: () => { state.sessions.clear(); state.comment = ''; restart(); } }, t('done.another')));
  }

  render();
})();
