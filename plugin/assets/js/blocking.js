/**
 * Consent Resolve — script blocking engine.
 * Inlined in <head> so it installs before any third-party <script> parses.
 * Vanilla, no dependencies. Restores blocked resources the instant consent lands.
 */
(function (w, d) {
	'use strict';
	var C = w.CRData || {};
	var RULES = C.rules || [];
	var COOKIE = C.cookie || 'cr_consent';

	// ---- consent state -------------------------------------------------------
	function readCookie(name) {
		var m = d.cookie.match('(?:^|; )' + name.replace(/([.*+?^${}()|[\]\\])/g, '\\$1') + '=([^;]*)');
		return m ? decodeURIComponent(m[1]) : null;
	}
	function gpc() { return C.signals && C.signals.gpc && w.navigator && w.navigator.globalPrivacyControl === true; }
	function dnt() {
		if (!(C.signals && C.signals.dnt)) return false;
		var v = w.navigator && (w.navigator.doNotTrack || w.doNotTrack || w.navigator.msDoNotTrack);
		return v === '1' || v === 'yes';
	}
	// Category => granted?
	function state() {
		var granted = { functional: true };
		var raw = readCookie(COOKIE), chosen = null;
		if (raw) { try { chosen = JSON.parse(raw).c; } catch (e) {} }
		if (chosen) {
			['preferences', 'statistics', 'statistics_anonymous', 'marketing'].forEach(function (k) { granted[k] = !!chosen[k]; });
			// GPC is a binding universal opt-out — honor it even over a prior
			// "accept" (CCPA/CPRA), so re-enabling GPC later still takes effect.
			if (gpc()) { granted.marketing = false; granted.statistics = false; granted.statistics_anonymous = false; }
		} else {
			// No explicit choice: region defaults, unless a universal opt-out signal is present.
			var suppress = gpc() || dnt();
			(C.grants || ['functional']).forEach(function (k) { granted[k] = suppress ? (k === 'functional') : true; });
			if (suppress) { granted.marketing = false; granted.statistics = false; granted.statistics_anonymous = false; }
		}
		return granted;
	}

	// ---- classification ------------------------------------------------------
	function matchRule(url) {
		if (!url) return null;
		for (var i = 0; i < RULES.length; i++) {
			var pats = RULES[i].p;
			for (var j = 0; j < pats.length; j++) {
				if (url.indexOf(pats[j]) !== -1) return RULES[i];
			}
		}
		return null;
	}
	function blocked(rule, st) {
		if (!rule) return false;
		if (rule.c === 'functional') return false;
		return !st[rule.c];
	}

	// ---- neutralize ----------------------------------------------------------
	function inertScript(node, rule) {
		if (node.type === 'text/plain') return;
		node.type = 'text/plain';
		node.setAttribute('data-cr-category', rule.c);
		if (node.src) { node.setAttribute('data-cr-src', node.src); node.removeAttribute('src'); }
	}
	function placeholder(frame, rule) {
		var box = d.createElement('div');
		box.className = 'cr-embed-ph';
		box.setAttribute('data-cr-category', rule.c);
		var r = frame.getAttribute('width'), h = frame.getAttribute('height');
		box.style.cssText = 'position:relative;min-height:' + (h ? h + 'px' : '220px') + ';display:flex;align-items:center;justify-content:center;text-align:center;background:#f1f3f8;border:1px solid #e6e8ef;border-radius:12px;padding:24px;color:#5c6270;font:14px/1.5 system-ui,sans-serif';
		box.innerHTML = '<div><p style="margin:0 0 12px">' + (C.i18n ? C.i18n.loadContent : 'Content blocked until you accept.') + '</p><button type="button" class="cr-embed-load" style="cursor:pointer;border:0;border-radius:8px;padding:8px 14px;background:' + '#1F6FEB' + ';color:#fff;font-weight:600">' + (C.i18n ? C.i18n.loadBtn : 'Load content') + '</button></div>';
		box.__cr_frame = frame.cloneNode(true);
		box.__cr_rule = rule;
		frame.parentNode.replaceChild(box, frame);
	}

	function scan(root) {
		var st = state();
		var scripts = root.querySelectorAll ? root.querySelectorAll('script[src]') : [];
		for (var i = 0; i < scripts.length; i++) {
			var s = scripts[i], rule = matchRule(s.src || s.getAttribute('data-cr-src'));
			if (blocked(rule, st)) inertScript(s, rule);
		}
		if (C.iframePh) {
			var frames = root.querySelectorAll ? root.querySelectorAll('iframe[src]') : [];
			for (var k = 0; k < frames.length; k++) {
				var f = frames[k], fr = matchRule(f.src);
				if (blocked(fr, st) && fr.m === 'iframe') placeholder(f, fr);
			}
		}
	}

	// Catch nodes as they are inserted, before external scripts fetch/execute.
	var mo = new MutationObserver(function (muts) {
		var st = state();
		for (var i = 0; i < muts.length; i++) {
			var added = muts[i].addedNodes;
			for (var j = 0; j < added.length; j++) {
				var n = added[j];
				if (n.nodeType !== 1) continue;
				if (n.tagName === 'SCRIPT' && (n.src || n.getAttribute('data-cr-src'))) {
					var r = matchRule(n.src || n.getAttribute('data-cr-src'));
					if (blocked(r, st)) inertScript(n, r);
				} else if (n.tagName === 'IFRAME' && C.iframePh && n.src) {
					var fr = matchRule(n.src);
					if (blocked(fr, st) && fr.m === 'iframe') placeholder(n, fr);
				} else if (n.querySelectorAll) {
					scan(n);
				}
			}
		}
	});
	try { mo.observe(d.documentElement, { childList: true, subtree: true }); } catch (e) {}

	// ---- restore on consent --------------------------------------------------
	function activate(node) {
		var s = d.createElement('script');
		for (var i = 0; i < node.attributes.length; i++) {
			var a = node.attributes[i];
			if (a.name === 'type' || a.name === 'data-cr-src' || a.name === 'data-cr-category') continue;
			s.setAttribute(a.name, a.value);
		}
		var src = node.getAttribute('data-cr-src');
		if (src) s.src = src; else s.text = node.text || node.textContent;
		node.parentNode.replaceChild(s, node);
	}
	function restore(st) {
		var blocked = d.querySelectorAll('script[type="text/plain"][data-cr-category]');
		for (var i = 0; i < blocked.length; i++) {
			var cat = blocked[i].getAttribute('data-cr-category');
			if (st[cat]) activate(blocked[i]);
		}
		var phs = d.querySelectorAll('.cr-embed-ph');
		for (var k = 0; k < phs.length; k++) {
			var cat2 = phs[k].getAttribute('data-cr-category');
			if (st[cat2] && phs[k].__cr_frame) phs[k].parentNode.replaceChild(phs[k].__cr_frame, phs[k]);
		}
	}

	// Consent Mode / UET update.
	function signals(st) {
		if (C.consentMode && typeof w.gtag === 'function') {
			w.gtag('consent', 'update', {
				ad_storage: st.marketing ? 'granted' : 'denied',
				ad_user_data: st.marketing ? 'granted' : 'denied',
				ad_personalization: st.marketing ? 'granted' : 'denied',
				analytics_storage: st.statistics ? 'granted' : 'denied'
			});
		}
		if (C.uet && w.uetq) { w.uetq.push('consent', 'update', { ad_storage: st.marketing ? 'granted' : 'denied' }); }
	}

	// Public API for the banner UI.
	w.CR = w.CR || {};
	w.CR.state = state;
	w.CR.apply = function (st) { restore(st); signals(st); w.CR._blockScan(); };
	w.CR._blockScan = function () { scan(d); };
	w.CR.onReady = function (cb) { (d.readyState !== 'loading') ? cb() : d.addEventListener('DOMContentLoaded', cb); };

	// Placeholder "Load content" clicks (event delegation).
	d.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.cr-embed-load') : null;
		if (!btn) return;
		var box = btn.closest('.cr-embed-ph');
		if (box && box.__cr_frame) box.parentNode.replaceChild(box.__cr_frame, box);
	});

	// Initial pass + apply current signals (in case Consent Mode already loaded).
	w.CR.onReady(function () { scan(d); signals(state()); });
	scan(d);
})(window, document);
