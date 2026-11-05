/* Crypto Direct Checkout — payment page: QR, copy buttons, timer, live status. */
(function () {
	'use strict';

	var box = document.querySelector('.cdc-pay');
	if (!box) { return; }

	var cfg;
	try { cfg = JSON.parse(box.getAttribute('data-config')); } catch (e) { return; }

	/* ---- Show the box first, right under the "Order received" heading ----
	   Block themes put payment info at the bottom of the page, where a
	   customer might never scroll. */
	var anchor = document.querySelector('.wc-block-order-confirmation-status') ||
		document.querySelector('.woocommerce-order .woocommerce-notice--success') ||
		document.querySelector('.woocommerce-thankyou-order-received');
	if (anchor && anchor.parentNode && !anchor.contains(box)) {
		anchor.parentNode.insertBefore(box, anchor.nextSibling);
	}

	function $(name) { return box.querySelector('[data-cdc="' + name + '"]'); }

	var PILL = {
		waiting: 'Waiting for your payment',
		paid: 'Paid',
		late_paid: 'Paid — awaiting store check',
		expired: 'Time ran out',
		seen: 'Payment seen — confirming'
	};

	var state = cfg.status;
	var deadline = Date.now() + cfg.expiresIn * 1000;
	var startedAt = Date.now();
	var pollTimer = null;

	/* ---- QR code ---- */
	var qrEl = $('qr');
	if (qrEl && typeof window.qrcode === 'function') {
		try {
			var qr = window.qrcode(0, 'M');
			qr.addData(qrEl.getAttribute('data-value'));
			qr.make();
			qrEl.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
		} catch (e) { qrEl.style.display = 'none'; }
	}

	/* ---- Copy buttons ---- */
	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			var t = document.createElement('textarea');
			t.value = text;
			t.setAttribute('readonly', '');
			t.style.position = 'fixed';
			t.style.opacity = '0';
			document.body.appendChild(t);
			t.select();
			try { document.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); }
			document.body.removeChild(t);
		});
	}
	Array.prototype.forEach.call(box.querySelectorAll('.cdc-copy'), function (btn) {
		var label = btn.querySelector('span');
		var original = label ? label.textContent : '';
		btn.addEventListener('click', function () {
			copyText(btn.getAttribute('data-copy')).then(function () {
				btn.classList.add('is-done');
				if (label) { label.textContent = 'Copied'; }
				setTimeout(function () {
					btn.classList.remove('is-done');
					if (label) { label.textContent = original; }
				}, 1800);
			}, function () {
				if (label) { label.textContent = 'Select and copy'; }
			});
		});
	});

	/* ---- State ---- */
	function setState(next, paidText) {
		if (!next || next === state) { return; }
		state = next;
		box.setAttribute('data-state', next);
		var pill = $('pill');
		if (pill) { pill.textContent = PILL[next] || ''; }
		if (paidText) {
			var p = $('paid-text');
			if (p) { p.textContent = paidText; }
		}
		if (next === 'paid' || next === 'late_paid') {
			stopPolling();
			// Reload once so WooCommerce shows the updated order details and the transaction link.
			setTimeout(function () { window.location.reload(); }, 2500);
		}
	}

	/* ---- Timer ---- */
	var timerEl = $('timer');
	var bar = $('bar');
	function tick() {
		var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
		if (timerEl) {
			var m = Math.floor(left / 60);
			var s = left % 60;
			timerEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
			timerEl.classList.toggle('is-low', left < 300);
		}
		if (bar) {
			var frac = cfg.totalSecs > 0 ? Math.min(1, left / cfg.totalSecs) : 0;
			bar.style.transform = 'scaleX(' + frac + ')';
		}
		if (left === 0 && state === 'waiting') {
			poll(); // Ask the server to confirm the expiry.
		}
	}
	tick();
	var tickTimer = setInterval(function () {
		if (state !== 'waiting') { clearInterval(tickTimer); return; }
		tick();
	}, 1000);

	/* ---- Live status ---- */
	function url(base) {
		return base + (base.indexOf('?') === -1 ? '?' : '&') + 'key=' + encodeURIComponent(cfg.key);
	}
	function poll() {
		return fetch(url(cfg.statusUrl), { credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				if (!data) { return; }
				if (typeof data.expires_in === 'number' && state === 'waiting') {
					deadline = Date.now() + data.expires_in * 1000;
				}
				setState(data.status);
			})
			.catch(function () { /* Offline for a moment — try again next time. */ });
	}
	function nextDelay() {
		// Every 10s for the first 10 minutes, then every 20s.
		return Date.now() - startedAt < 600000 ? 10000 : 20000;
	}
	function schedule() {
		stopPolling();
		if (state === 'paid' || state === 'late_paid') { return; }
		// Keep watching for 24h-late payments while the page is open after expiry, but slower.
		var delay = (state === 'expired' || state === 'seen') ? 30000 : nextDelay();
		pollTimer = setTimeout(function () { poll().then(schedule); }, delay);
	}
	function stopPolling() {
		if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
	}
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden && (state === 'waiting' || state === 'expired' || state === 'seen')) {
			poll().then(schedule);
		}
	});
	schedule();

	/* ---- Transaction ID backup ---- */
	var form = $('txid-form');
	var msg = $('txid-msg');
	if (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var input = form.querySelector('input');
			var btn = form.querySelector('button');
			var txid = (input.value || '').trim();

			msg.className = 'cdc-msg';
			var pattern = new RegExp(cfg.txidRegex || '^(0x)?[0-9a-fA-F]{64}$');
			if (!pattern.test(txid)) {
				msg.textContent = 'That isn\'t a valid transaction ID for ' + (cfg.network || 'this network') + '. Copy it from your wallet\'s transaction details.';
				msg.classList.add('is-error');
				input.focus();
				return;
			}

			btn.disabled = true;
			btn.classList.add('is-loading');
			msg.textContent = 'Checking the blockchain…';

			fetch(url(cfg.txidUrl), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ txid: txid })
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					msg.textContent = (data && data.message) || 'Something went wrong. Please try again.';
					msg.classList.add(data && data.ok ? 'is-ok' : 'is-error');
					if (data && data.status) { setState(data.status); }
				})
				.catch(function () {
					msg.textContent = 'Connection problem. Please check your internet and try again.';
					msg.classList.add('is-error');
				})
				.then(function () {
					btn.disabled = false;
					btn.classList.remove('is-loading');
				});
		});
	}
})();
