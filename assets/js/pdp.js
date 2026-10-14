/*
 * BC Battery - PDP behaviour.
 *  - gallery counter / thumbnails
 *  - full-width add-to-cart; after adding, the button is replaced by an "in your cart" stepper
 *    (state comes from the WooCommerce Store API, never from cached HTML)
 *  - sticky buy bar kept in sync (label, variation price, in-cart state)
 * Progressive enhancement: without JS the in-page form and the bar's own form post add-to-cart natively.
 */
(function () {
	'use strict';

	var doc = document;
	var cfg = window.bcPdp || {};
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var behavior = reduce ? 'auto' : 'smooth';

	var TXT = { options: 'انتخاب گزینه‌ها', add: 'افزودن به سبد خرید', viewCart: 'مشاهده سبد خرید', remove: 'حذف از سبد خرید', less: 'کم کردن تعداد' };
	var SVG = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
	var ICON = {
		trash: SVG + '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>',
		plus: SVG + '<path d="M12 5v14M5 12h14"/></svg>',
		minus: SVG + '<path d="M5 12h14"/></svg>'
	};

	function $(sel, ctx) { return (ctx || doc).querySelector(sel); }

	/* ---------- Gallery ---------- */
	function initGallery() {
		var track = $('.bc-gal__track');
		if (!track) { return; }
		var slides = [].slice.call(track.children);
		var thumbs = [].slice.call(doc.querySelectorAll('.bc-gal__thumb'));
		var counter = $('.bc-gal__count');
		if (slides.length < 2) { return; }

		function setActive(i) {
			if (i < 0) { return; }
			if (counter) { counter.textContent = (i + 1) + ' / ' + slides.length; }
			thumbs.forEach(function (t, k) {
				t.classList.toggle('is-active', k === i);
				t.setAttribute('aria-current', k === i ? 'true' : 'false');
			});
		}

		if ('IntersectionObserver' in window) {
			var io = new IntersectionObserver(function (entries) {
				entries.forEach(function (e) {
					if (e.isIntersecting) { setActive(slides.indexOf(e.target)); }
				});
			}, { root: track, threshold: 0.6 });
			slides.forEach(function (s) { io.observe(s); });
		}

		thumbs.forEach(function (t, k) {
			t.addEventListener('click', function () {
				slides[k].scrollIntoView({ behavior: behavior, inline: 'center', block: 'nearest' });
			});
		});
	}

	/* ---------- Buy box, in-cart stepper, sticky bar ---------- */
	function initBuy() {
		var box = $('#bc-buybox');
		if (!box) { return; }
		var bar = $('.bc-buybar');
		var barBtn = bar ? $('.bc-buybar__btn', bar) : null;
		var priceEl = bar ? $('.bc-buybar__price', bar) : null;
		var type = bar ? bar.getAttribute('data-type') : 'simple';
		var panel = $('.bc-incart', box);
		var step = panel ? $('.bc-step', panel) : null;
		var dec = panel ? $('.bc-step__dec', panel) : null;
		var inc = panel ? $('.bc-step__inc', panel) : null;
		var qtyEl = panel ? $('.bc-step__qty', panel) : null;
		var barLabel = barBtn ? barBtn.textContent : '';
		var items = [];
		var nonce = '';
		var syncTimer;

		if (dec) { dec.innerHTML = ICON.minus; }
		if (inc) { inc.innerHTML = ICON.plus; }

		function realBtn() { return $('.single_add_to_cart_button', box); }
		function needsOptions(rb) {
			return rb.classList.contains('disabled') ||
				rb.classList.contains('wc-variation-selection-needed') ||
				rb.classList.contains('wc-variation-is-unavailable');
		}
		function nudge(el) {
			el.scrollIntoView({ behavior: behavior, block: 'center' });
			el.classList.add('bc-nudge');
			setTimeout(function () { el.classList.remove('bc-nudge'); }, 900);
		}

		/* ----- Store API ----- */
		function api(path, method, body) {
			var headers = { 'Accept': 'application/json' };
			if (body) { headers['Content-Type'] = 'application/json'; }
			if (nonce) { headers.Nonce = nonce; headers['X-WC-Store-API-Nonce'] = nonce; }
			var url = cfg.api + path + '?_=' + Date.now(); // unique URL: cart data must never come from a cache
			return fetch(url, { method: method || 'GET', credentials: 'same-origin', cache: 'no-store', headers: headers, body: body ? JSON.stringify(body) : undefined })
				.then(function (res) {
					var n = res.headers.get('Nonce') || res.headers.get('X-WC-Store-API-Nonce');
					if (n) { nonce = n; }
					return res.text().then(function (t) {
						var data = null;
						try { data = t ? JSON.parse(t) : null; } catch (e) { data = null; }
						if (!res.ok) { var err = new Error('store-api'); err.status = res.status; throw err; }
						return data;
					});
				});
		}
		function mutate(path, body) {
			var run = function () { return api(path, 'POST', body); };
			var ready = nonce ? Promise.resolve() : api('cart');
			return ready.then(run).catch(function (e) {
				if (e && e.status === 403) { return api('cart').then(run); } // stale nonce: refresh once
				throw e;
			});
		}
		function refreshFragments() {
			if (window.jQuery) { window.jQuery(doc.body).trigger('wc_fragment_refresh'); }
		}

		/* ----- State ----- */
		function currentId() {
			if (type === 'variable') {
				var v = $('input[name="variation_id"]', box);
				return v ? (parseInt(v.value, 10) || 0) : 0;
			}
			return cfg.id;
		}
		function findItem() {
			var id = currentId();
			if (!id) { return null; }
			for (var i = 0; i < items.length; i++) {
				if (items[i].id === id) { return items[i]; }
			}
			return null;
		}
		function render() {
			var item = findItem();
			box.classList.toggle('is-incart', !!item);
			if (item && qtyEl) {
				var q = item.quantity;
				var lim = item.quantity_limits || {};
				qtyEl.textContent = q;
				dec.innerHTML = q <= 1 ? ICON.trash : ICON.minus;
				dec.setAttribute('aria-label', q <= 1 ? TXT.remove : TXT.less);
				inc.disabled = !!(lim.maximum && q >= lim.maximum);
			}
			if (barBtn) {
				barBtn.textContent = item ? TXT.viewCart : barLabel;
			}
		}
		function apply(cart) {
			if (cart && cart.items) { items = cart.items; render(); return Promise.resolve(); }
			return sync();
		}
		function sync() {
			return api('cart').then(function (cart) {
				items = (cart && cart.items) || [];
				render();
			}).catch(function () { /* keep current UI */ });
		}
		function syncSoon() {
			clearTimeout(syncTimer);
			syncTimer = setTimeout(sync, 300);
		}
		function busy(on) { if (step) { step.setAttribute('aria-busy', on ? 'true' : 'false'); } }

		/* ----- Stepper ----- */
		if (inc) {
			inc.addEventListener('click', function () {
				var item = findItem();
				if (!item) { return; }
				busy(true);
				mutate('cart/update-item', { key: item.key, quantity: item.quantity + 1 })
					.then(apply).then(refreshFragments)
					.catch(sync)
					.then(function () { busy(false); });
			});
		}
		if (dec) {
			dec.addEventListener('click', function () {
				var item = findItem();
				if (!item) { return; }
				busy(true);
				var req = item.quantity > 1
					? mutate('cart/update-item', { key: item.key, quantity: item.quantity - 1 })
					: mutate('cart/remove-item', { key: item.key });
				req.then(sync).then(refreshFragments)
					.catch(sync)
					.then(function () { busy(false); });
			});
		}

		/* ----- Sticky bar button ----- */
		if (barBtn) {
			barBtn.addEventListener('click', function (ev) {
				if (box.classList.contains('is-incart')) {
					ev.preventDefault();
					window.location.href = cfg.cart || '/cart/';
					return;
				}
				var rb = realBtn();
				if (!rb) { return; } // native fallback (form post / anchor)
				ev.preventDefault();
				if (needsOptions(rb)) { nudge($('.variations', box) || box); return; }
				rb.click(); // same path as the in-page button: theme AJAX add-to-cart
				setTimeout(syncSoon, 1800); // safety net if no theme event fires
			});
		}

		/* ----- Learn when the theme finished adding ----- */
		var rb0 = realBtn();
		if (rb0) {
			rb0.addEventListener('click', function () { setTimeout(syncSoon, 1800); });
			if ('MutationObserver' in window) {
				var wasLoading = false;
				new MutationObserver(function () {
					var loading = rb0.classList.contains('loading');
					if (barBtn) { barBtn.classList.toggle('is-loading', loading); }
					if (wasLoading && !loading) { syncSoon(); }
					wasLoading = loading;
				}).observe(rb0, { attributes: true, attributeFilter: ['class'] });
			}
		}
		if (window.jQuery) {
			window.jQuery(doc.body).on('added_to_cart removed_from_cart', syncSoon);
		}

		/* ----- Variable products: price, label and in-cart state follow the chosen variation ----- */
		if (window.jQuery && type === 'variable') {
			window.jQuery(function ($j) {
				var f = $j('.bc-buybox form.variations_form');
				if (!f.length) { return; }
				var original = priceEl ? priceEl.innerHTML : '';

				function syncLabel() {
					var rb = realBtn();
					if (rb) { barLabel = needsOptions(rb) ? TXT.options : TXT.add; }
					render();
				}
				f.on('found_variation', function (e, v) {
					if (priceEl && v && v.price_html) { priceEl.innerHTML = v.price_html; }
				});
				f.on('reset_data hide_variation', function () {
					if (priceEl) { priceEl.innerHTML = original; }
				});
				f.on('found_variation reset_data hide_variation show_variation woocommerce_variation_has_changed', function () {
					setTimeout(syncLabel, 0);
				});
				setTimeout(syncLabel, 300); // preselected default variation
			});
		}

		/* ----- Initial state: only ask the API when the visitor already has a cart ----- */
		function hasCartCookie() { return /(?:^|;\s*)woocommerce_items_in_cart=/.test(doc.cookie); }
		if (hasCartCookie()) { sync(); }
		window.addEventListener('pageshow', function (e) { if (e.persisted && hasCartCookie()) { sync(); } });
	}

	initGallery();
	initBuy();
})();
