/**
 * Consent Resolve — banner + preference center UI.
 * Vanilla, no dependencies. WCAG 2.2 AA: focus trap, keyboard, SR announce.
 * Accept and Reject are always rendered with identical prominence.
 */
(function (w, d) {
	'use strict';
	var CFG = w.CRBanner || {};
	var DATA = w.CRData || {};
	var COOKIE = DATA.cookie || 'cr_consent';
	var CATS = CFG.categories || {};
	var root = d.getElementById('cr-root');
	if (!root) return;

	// ---- cookies -------------------------------------------------------------
	function read(name) {
		var m = d.cookie.match('(?:^|; )' + name + '=([^;]*)');
		return m ? decodeURIComponent(m[1]) : null;
	}
	function write(name, value, days) {
		var exp = new Date(Date.now() + (days || 365) * 864e5).toUTCString();
		d.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + exp + ';path=/;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
	}
	function hasChoice() { return !!read(COOKIE); }

	// First-party consent receipt id (a receipt identifier, not an identity).
	function consentId() {
		try {
			var k = 'cr_consent_id', v = w.localStorage.getItem(k);
			if (!v) {
				v = (w.crypto && w.crypto.randomUUID) ? w.crypto.randomUUID()
					: 'cid-' + Date.now().toString(16) + '-' + Math.random().toString(16).slice(2);
				w.localStorage.setItem(k, v);
			}
			return v;
		} catch (e) { return ''; }
	}

	// ---- consent write -------------------------------------------------------
	function persist(cats, method) {
		var payload = { c: cats, t: Math.floor(Date.now() / 1000), v: 1 };
		write(COOKIE, JSON.stringify(payload));
		// Bridge to the WP Consent API so other plugins react to us.
		Object.keys(CATS).forEach(function (k) {
			var val = (k === 'functional') ? 'allow' : (cats[k] ? 'allow' : 'deny');
			write('wp_consent_' + k, val);
			if (typeof w.wp_set_consent === 'function') { try { w.wp_set_consent(k, cats[k] ? 'allow' : 'deny'); } catch (e) {} }
		});
		var full = { functional: true };
		Object.keys(CATS).forEach(function (k) { full[k] = (k === 'functional') || !!cats[k]; });
		if (w.CR && w.CR.apply) w.CR.apply(full);
		// Record server-side (fire-and-forget).
		if (DATA.rest) {
			try {
				fetch(DATA.rest, {
					method: 'POST', keepalive: true, credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DATA.nonce || '' },
					body: JSON.stringify({ categories: full, method: method || 'banner', event: 'update', vid: DATA.vid, consent_id: consentId(), action: method || 'save_preferences', page_url: location.href })
				});
			} catch (e) {}
		}
		d.dispatchEvent(new CustomEvent('cr:consent', { detail: full }));
	}
	function grantAll() { var o = {}; Object.keys(CATS).forEach(function (k) { o[k] = true; }); return o; }
	function denyAll() { var o = {}; Object.keys(CATS).forEach(function (k) { o[k] = (k === 'functional'); }); return o; }

	// ---- a11y helpers --------------------------------------------------------
	var lastFocus = null;
	function trap(container) {
		var f = container.querySelectorAll('button,[href],input,select,[tabindex]:not([tabindex="-1"])');
		if (!f.length) return function () {};
		var first = f[0], last = f[f.length - 1];
		function onKey(e) {
			if (e.key === 'Tab') {
				if (e.shiftKey && d.activeElement === first) { e.preventDefault(); last.focus(); }
				else if (!e.shiftKey && d.activeElement === last) { e.preventDefault(); first.focus(); }
			}
		}
		container.addEventListener('keydown', onKey);
		return function () { container.removeEventListener('keydown', onKey); };
	}

	// ---- DOM builders --------------------------------------------------------
	function el(tag, cls, html) { var n = d.createElement(tag); if (cls) n.className = cls; if (html != null) n.innerHTML = html; return n; }

	function buttons() {
		var wrap = el('div', 'cr-actions');
		var reject = el('button', 'cr-btn cr-btn-ghost', esc(CFG.reject));
		var accept = el('button', 'cr-btn cr-btn-primary', esc(CFG.accept));
		var prefs = el('button', 'cr-btn cr-btn-text', esc(CFG.prefs));
		reject.type = accept.type = prefs.type = 'button';
		reject.addEventListener('click', function () { persist(denyAll(), 'banner'); close(); });
		accept.addEventListener('click', function () { persist(grantAll(), 'banner'); close(); });
		prefs.addEventListener('click', openPrefs);
		// Reject first + primary equal to Accept — no dark pattern.
		wrap.appendChild(reject); wrap.appendChild(accept);
		var row2 = el('div', 'cr-actions-2'); row2.appendChild(prefs);
		if (CFG.doNotSell) {
			var dns = el('a', 'cr-dns', esc(CFG.dnsLabel)); dns.href = '#'; dns.setAttribute('role', 'button');
			dns.addEventListener('click', function (e) { e.preventDefault(); persist(denyAll(), 'do_not_sell'); close(); });
			row2.appendChild(dns);
		}
		var box = el('div'); box.appendChild(wrap); box.appendChild(row2);
		return box;
	}

	function esc(s) { var n = d.createElement('div'); n.textContent = s == null ? '' : s; return n.innerHTML; }

	function banner() {
		var b = el('div', 'cr-banner cr-layout-' + (CFG.layout || 'box') + ' cr-pos-' + (CFG.position || 'bottom-left'));
		b.setAttribute('role', 'dialog'); b.setAttribute('aria-modal', 'false');
		b.setAttribute('aria-label', esc(CFG.title));
		var inner = el('div', 'cr-inner');
		if (CFG.logo) { var lg = el('img', 'cr-logo'); lg.src = CFG.logo; lg.alt = ''; inner.appendChild(lg); }
		inner.appendChild(el('h2', 'cr-title', esc(CFG.title)));
		inner.appendChild(el('p', 'cr-msg', esc(CFG.message)));
		inner.appendChild(buttons());
		b.appendChild(inner);
		return b;
	}

	function prefsModal() {
		var overlay = el('div', 'cr-overlay');
		var m = el('div', 'cr-modal');
		m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true'); m.setAttribute('aria-label', esc(CFG.prefs));
		m.appendChild(el('h2', 'cr-title', esc(CFG.prefs)));
		var saved = {}; try { saved = (JSON.parse(read(COOKIE) || '{}').c) || {}; } catch (e) {}
		var grants = CFG.grants || ['functional'];
		function granted(k) { return grants.indexOf(k) !== -1; }
		var list = el('div', 'cr-cats');
		Object.keys(CATS).forEach(function (k) {
			var meta = CATS[k];
			var row = el('div', 'cr-cat');
			var head = el('div', 'cr-cat-head');
			var lab = el('label', 'cr-cat-label');
			var id = 'cr-cat-' + k;
			var input = d.createElement('input');
			input.type = 'checkbox'; input.id = id; input.setAttribute('data-cat', k);
			// With no prior choice, default to the REGION's default grants — not
			// blanket-checked. In an opt-in region (grants = functional only) that
			// leaves marketing/statistics unchecked, so a shopper who just clicks
			// Save doesn't grant them without an affirmative tick (no pre-ticked
			// consent). Opt-out regions pre-check per their default_grant policy.
			input.checked = meta.locked ? true : (hasChoice() ? !!saved[k] : granted(k));
			input.disabled = !!meta.locked;
			lab.setAttribute('for', id);
			lab.appendChild(input);
			lab.appendChild(el('span', 'cr-cat-name', esc(meta.label) + (meta.locked ? ' <span class="cr-cat-req">' + 'Always on' + '</span>' : '')));
			head.appendChild(lab);
			row.appendChild(head);
			row.appendChild(el('p', 'cr-cat-desc', esc(meta.desc)));
			list.appendChild(row);
		});
		m.appendChild(list);
		var save = el('button', 'cr-btn cr-btn-primary cr-save', esc(CFG.save)); save.type = 'button';
		save.addEventListener('click', function () {
			var chosen = {};
			list.querySelectorAll('input[data-cat]').forEach(function (i) { chosen[i.getAttribute('data-cat')] = i.checked; });
			chosen.functional = true;
			persist(chosen, 'pref_center'); closeModal(); close();
		});
		var acceptAll = el('button', 'cr-btn cr-btn-ghost', esc(CFG.accept)); acceptAll.type = 'button';
		acceptAll.addEventListener('click', function () { persist(grantAll(), 'pref_center'); closeModal(); close(); });
		var actions = el('div', 'cr-actions'); actions.appendChild(acceptAll); actions.appendChild(save);
		m.appendChild(actions);
		overlay.appendChild(m);
		return overlay;
	}

	// ---- lifecycle -----------------------------------------------------------
	var bannerEl = null, modalEl = null, releaseTrap = null;
	function show() {
		if (bannerEl || hasChoice()) return;
		bannerEl = banner();
		root.appendChild(bannerEl);
		announce();
	}
	function close() { if (bannerEl) { bannerEl.parentNode && bannerEl.parentNode.removeChild(bannerEl); bannerEl = null; } }
	function openPrefs() {
		lastFocus = d.activeElement;
		modalEl = prefsModal();
		root.appendChild(modalEl);
		releaseTrap = trap(modalEl.querySelector('.cr-modal'));
		var f = modalEl.querySelector('button,input:not([disabled])'); if (f) f.focus();
		d.addEventListener('keydown', escClose);
		modalEl.addEventListener('click', function (e) { if (e.target === modalEl) closeModal(); });
	}
	function closeModal() {
		if (releaseTrap) { releaseTrap(); releaseTrap = null; }
		d.removeEventListener('keydown', escClose);
		if (modalEl) { modalEl.parentNode && modalEl.parentNode.removeChild(modalEl); modalEl = null; }
		if (lastFocus && lastFocus.focus) lastFocus.focus();
	}
	function escClose(e) { if (e.key === 'Escape' && modalEl) closeModal(); }
	function announce() {
		var live = el('div', 'cr-sr'); live.setAttribute('role', 'status');
		live.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)';
		live.textContent = esc(CFG.title) + '. ' + esc(CFG.message);
		root.appendChild(live);
		setTimeout(function () { live.parentNode && live.parentNode.removeChild(live); }, 1500);
	}

	// Public: reopen preferences from a footer link / shortcode / floating tab.
	w.CR = w.CR || {};
	w.CR.openPreferences = openPrefs;
	w.CR.showBanner = function () { close(); write(COOKIE, '', -1); show(); };

	// Delegate clicks on [data-cr-open] triggers anywhere on the page.
	d.addEventListener('click', function (e) {
		var t = e.target.closest ? e.target.closest('[data-cr-open]') : null;
		if (t) { e.preventDefault(); openPrefs(); }
	});

	show();
})(window, document);
