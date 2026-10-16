/*
 * BC Battery - product page script. Plain JavaScript, no libraries.
 * Markup: inc/pdp-render.php, inc/pdp-sections.php, inc/pdp-overlays.php, inc/templates/pdp-sheet.php.
 * Server settings: window.bcPdp (inc/pdp.php). Product data: <script type="application/json" id="bcp-data">.
 *
 *   1. Helpers
 *   2. Cart (/?wc-ajax=bc_pdp_cart, inc/pdp-cart.php)
 *   3. Buy box: variation pills, add to cart, in-cart stepper
 *   4. Gallery and photo viewer
 *   5. Dialogs: after-add sheet, messenger chooser
 *   6. Page: buy bar, tabs, description, reviews, phone scroll effect
 */
(function () {
	'use strict';

	var root = document.querySelector('.bcp');
	if (!root) {
		return;
	}

	/* ------------------------------------------------------------------ */
	/* 1. Helpers                                                          */
	/* ------------------------------------------------------------------ */
	var doc = document;
	var html = doc.documentElement;
	var cfg = window.bcPdp || {};
	html.classList.add('bcp-js');

	function $(sel, ctx) {
		return (ctx || doc).querySelector(sel);
	}
	function $$(sel, ctx) {
		return Array.prototype.slice.call((ctx || doc).querySelectorAll(sel));
	}
	function el(name, ctx) {
		return $('[data-bcp="' + name + '"]', ctx);
	}
	function on(node, type, fn, opts) {
		if (node) {
			node.addEventListener(type, fn, opts);
		}
	}
	function setText(node, text) {
		if (node) {
			node.textContent = text;
		}
	}
	function setHidden(node, hidden) {
		if (node) {
			node.hidden = !!hidden;
		}
	}
	function fa(v) {
		return String(v).replace(/\d/g, function (d) {
			return '۰۱۲۳۴۵۶۷۸۹'.charAt(d);
		});
	}
	function money(n) {
		return fa(Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '٬'));
	}
	function clamp(n, min, max) {
		return Math.min(max, Math.max(min, n));
	}
	function frame(fn) {
		var queued = false;
		return function () {
			if (!queued) {
				queued = true;
				requestAnimationFrame(function () {
					queued = false;
					fn();
				});
			}
		};
	}
	function safe(fn) {
		try {
			fn();
		} catch (e) {
			if (window.console) {
				console.error('[bcp]', e);
			}
		}
	}

	var reduceMotion = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
	var phone = matchMedia('(max-width: 767px)');
	var desktop = matchMedia('(min-width: 1025px)');
	function motion() {
		return reduceMotion ? 'auto' : 'smooth';
	}
	function scrollToNode(node, block) {
		if (node) {
			node.scrollIntoView({ behavior: motion(), block: block || 'start' });
		}
	}

	var data = null;
	try {
		data = JSON.parse($('#bcp-data').textContent);
	} catch (e) {
		data = null; // other product types: the standard WooCommerce form is on the page
	}
	var currency = (function () {
		var s = $('.bcp-price__now small');
		return s ? s.textContent : 'تومان';
	})();

	/* Small message at the bottom of the screen. Moves into the open dialog, because dialogs sit above everything else. */
	var toastNode = el('toast');
	var toastTimer = 0;
	function toast(message) {
		if (!toastNode) {
			return;
		}
		var host = $('dialog[open]') || doc.body;
		if (toastNode.parentNode !== host) {
			host.appendChild(toastNode);
		}
		toastNode.textContent = message;
		toastNode.hidden = false;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () {
			toastNode.hidden = true;
		}, 3200);
	}
	function errorText(e) {
		if (e && e.name === 'TypeError') {
			return 'اتصال برقرار نشد. دوباره تلاش کنید.';
		}
		var m = e && e.message ? String(e.message).replace(/<[^>]*>/g, '').trim() : '';
		return m || 'انجام نشد. دوباره تلاش کنید.';
	}
	function busy(node, on_) {
		if (node) {
			node.classList.toggle('is-loading', !!on_);
			node.setAttribute('aria-busy', on_ ? 'true' : 'false');
		}
	}

	/* ------------------------------------------------------------------ */
	/* 2. Cart: /?wc-ajax=bc_pdp_cart (inc/pdp-cart.php), never cached     */
	/* ------------------------------------------------------------------ */
	var cart = { items: [] };
	var cartListeners = [];
	var lastSync = 0;
	var queue = Promise.resolve();

	/*
	 * One request per change: the answer carries the cart lines this page needs AND the header mini-cart
	 * fragments, so nothing else is fetched. Requests run one after another: two at the same time would each
	 * save the cart session and the second would undo the first.
	 */
	function post(act, fields) {
		var body = fields instanceof FormData ? fields : new FormData();
		if (!(fields instanceof FormData)) {
			Object.keys(fields || {}).forEach(function (k) {
				body.append(k, fields[k]);
			});
		}
		body.set('act', act);
		function send() {
			return fetch(cfg.endpoint || '/?wc-ajax=bc_pdp_cart', {
				method: 'POST',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: { 'X-BC-PDP': '1' },
				body: body
			})
				.then(function (res) {
					return res.json().then(
						function (json) {
							return { res: res, json: json || {} };
						},
						function () {
							return { res: res, json: {} };
						}
					);
				})
				.then(function (r) {
					if (r.json.items) {
						lastSync = Date.now();
						applyCart(r.json);
					}
					if (r.json.fragments) {
						applyFragments(r.json.fragments, r.json.cart_hash);
					}
					if (!r.res.ok || true !== r.json.ok) {
						var err = new Error(r.json.msg || '');
						err.status = r.res.status;
						throw err;
					}
					if (r.json.msg) {
						toast(r.json.msg); // e.g. the quantity was capped at the stock
					}
					return r.json;
				});
		}
		var run = queue.then(send);
		queue = run.catch(function () {});
		return run;
	}

	function lineFor(id) {
		for (var i = 0; i < cart.items.length; i++) {
			if (cart.items[i].id === id) {
				return cart.items[i];
			}
		}
		return null;
	}
	function hasCartCookie() {
		return /(?:^|;\s*)woocommerce_items_in_cart=/.test(doc.cookie);
	}
	function applyCart(c) {
		if (c && c.items) {
			cart = { items: c.items };
		}
		cartListeners.forEach(function (fn) {
			safe(fn);
		});
		return c;
	}
	/* The header mini cart (WooCommerce / Woodmart fragments), stored the way cart-fragments.js stores them. */
	function applyFragments(fragments, hash) {
		Object.keys(fragments).forEach(function (selector) {
			safe(function () {
				// Woodmart marks some fragment keys with a "_wd" suffix that is not part of the real selector.
				$$(selector.replace('_wd', '')).forEach(function (node) {
					node.outerHTML = fragments[selector];
				});
			});
		});
		safe(function () {
			var params = window.wc_cart_fragments_params || window.wd_cart_fragments_params;
			if (params && params.fragment_name && params.cart_hash_key) {
				sessionStorage.setItem(params.fragment_name, JSON.stringify(fragments));
				sessionStorage.setItem(params.cart_hash_key, hash || '');
				localStorage.setItem(params.cart_hash_key, hash || ''); // other open tabs refresh their mini cart
				sessionStorage.setItem('wc_cart_created', String(Date.now()));
			}
		});
		safe(function () {
			if (window.jQuery) {
				window.jQuery(doc.body).trigger('wc_fragments_refreshed');
			}
		});
	}
	function addItem(id, qty, form) {
		var fields = form ? new FormData(form) : new FormData();
		// The product form also carries "add-to-cart": WooCommerce would add the product a second time if it saw it.
		fields.delete('add-to-cart');
		fields.set('id', String(id));
		fields.set('quantity', String(qty || 1));
		return post('add', fields);
	}
	function updateItem(key, qty) {
		return post('qty', { key: key, qty: qty });
	}
	function removeItem(key) {
		return post('remove', { key: key });
	}
	/* The visitor's cart, for the in-cart state. Only when the cart cookie says there is one; unless forced, at most once a minute. */
	function syncCart(force) {
		if (!hasCartCookie()) {
			applyCart({ items: [] });
			return;
		}
		if (!force && Date.now() - lastSync < 60000) {
			return;
		}
		lastSync = Date.now();
		post('get').catch(function () {});
	}

	/* ------------------------------------------------------------------ */
	/* 3. Buy box                                                          */
	/* ------------------------------------------------------------------ */
	var buy = $('#bcp-buy');
	var buybox = el('buybox');
	var form = el('form');
	var addBtn = el('add');
	var incartBox = el('incart');
	var variantsBox = el('variants');
	var barBtn = el('bar-btn');
	var attrs = data && data.attrs ? data.attrs : [];
	var vars = data && data.vars ? data.vars : [];
	var isVariable = !!(data && data.type === 'variable' && attrs.length && vars.length);
	var sel = {}; // chosen option per attribute key

	if (isVariable && data.sel) {
		Object.keys(data.sel).forEach(function (k) {
			sel[k] = data.sel[k];
		});
	}

	/* First variation that fits every chosen attribute ('' in a variation = any value). */
	function match(choice) {
		for (var i = 0; i < vars.length; i++) {
			var ok = true;
			for (var j = 0; j < attrs.length; j++) {
				var want = choice[attrs[j].key] || '';
				var has = vars[i].a[attrs[j].key] || '';
				if (!want || (has && has !== want)) {
					ok = false;
					break;
				}
			}
			if (ok) {
				return vars[i];
			}
		}
		return null;
	}
	function curVar() {
		return isVariable ? match(sel) : null;
	}
	function curId() {
		if (!data) {
			return 0;
		}
		if (isVariable) {
			var v = curVar();
			return v ? v.id : 0;
		}
		return data.id;
	}
	function optionLabel(attr, value) {
		for (var i = 0; i < attr.opts.length; i++) {
			if (attr.opts[i].v === value) {
				return attr.opts[i].l;
			}
		}
		return '';
	}
	/* Is there a variation with this option, given what is chosen in the other attributes? */
	function available(key, value) {
		var test = {};
		attrs.forEach(function (a) {
			if (a.key !== key && sel[a.key]) {
				test[a.key] = sel[a.key];
			}
		});
		test[key] = value;
		return vars.some(function (v) {
			return attrs.every(function (a) {
				var want = test[a.key] || '';
				var has = v.a[a.key] || '';
				return !want || !has || has === want;
			});
		});
	}
	function isSoldOut() {
		if (!data) {
			return false;
		}
		if (isVariable) {
			var v = curVar();
			return !!(v && v.st === 'outofstock');
		}
		return data.st === 'outofstock';
	}

	function renderPills() {
		if (!variantsBox) {
			return;
		}
		$$('.bcp-var', variantsBox).forEach(function (wrap) {
			var key = wrap.getAttribute('data-key');
			var attr = attrs.filter(function (a) {
				return a.key === key;
			})[0];
			var name = el('varname', wrap);
			var label = attr ? optionLabel(attr, sel[key]) : '';
			if (name) {
				name.textContent = label || 'انتخاب نشده';
				name.classList.toggle('is-empty', !label);
			}
			$$('.bcp-pill', wrap).forEach(function (pill) {
				var value = pill.getAttribute('data-value');
				var isOn = sel[key] === value;
				pill.classList.toggle('is-on', isOn);
				pill.setAttribute('aria-pressed', isOn ? 'true' : 'false');
				pill.classList.toggle('is-na', attrs.length > 1 && !available(key, value));
			});
		});
		setHidden(el('clear', variantsBox), !Object.keys(sel).some(function (k) {
			return sel[k];
		}));
	}

	function renderPrice() {
		var v = curVar();
		var p;
		var r;
		var from = false;
		if (isVariable) {
			if (v) {
				p = v.p;
				r = v.r;
			} else {
				p = data.min;
				r = 0;
				from = true;
			}
		} else {
			p = data.p;
			r = data.r;
		}
		r = r > p && p > 0 ? r : 0;
		var off = r ? Math.round(((r - p) / r) * 100) : 0;

		if (el('now')) {
			setText(el('now'), money(p));
			setText(el('old-n'), money(r));
			setHidden(el('old'), !r);
			setHidden(el('from'), !from);
		}
		setText(el('bar-now'), money(p));
		setText(el('bar-old-n'), money(r));
		setHidden(el('bar-old'), !r);
		setHidden(el('bar-from'), !from);
		var offNode = el('off');
		if (offNode) {
			offNode.hidden = !off;
			offNode.textContent = fa(off) + '٪ تخفیف';
		}
	}

	function renderMeta() {
		if (!isVariable) {
			return;
		}
		var v = curVar();
		var chip = el('stock');
		var map = {
			instock: ['موجود در انبار', 'ok'],
			outofstock: ['ناموجود', 'out'],
			onbackorder: ['پیش‌سفارش', 'back']
		};
		if (chip) {
			if (v && map[v.st]) {
				chip.textContent = map[v.st][0];
				chip.className = 'bcp-chip-stock bcp-chip-stock--' + map[v.st][1];
				chip.hidden = !(chip.getAttribute('data-always') === '1' || v.st === 'outofstock');
			} else {
				chip.hidden = true;
			}
		}
		var sku = el('sku');
		if (sku) {
			var b = $('b', sku);
			if (b && v) {
				b.textContent = v.sku;
			}
			sku.hidden = !(v && v.sku);
		}
	}

	function renderForm() {
		var v = curVar();
		var vid = el('vid');
		if (vid) {
			vid.value = v ? v.id : '';
		}
		$$('[data-bcp="attr"]').forEach(function (input) {
			input.value = sel[input.getAttribute('data-key')] || '';
		});
		var out = isSoldOut();
		if (addBtn) {
			addBtn.disabled = out;
			addBtn.textContent = out ? 'ناموجود' : 'افزودن به سبد خرید';
		}
		setHidden(el('soldout'), !out);
	}

	/* In-cart panel: shown instead of the add button when the chosen product / variation is in the cart. */
	var incart = {
		step: incartBox ? $('.bcp-step', incartBox) : null,
		qty: el('qty'),
		inc: el('inc'),
		dec: el('dec'),
		note: el('incart-note'),
		noteText: ''
	};
	incart.noteText = incart.note ? incart.note.textContent : '';

	function renderIncart() {
		if (!incartBox || !data) {
			return;
		}
		var id = curId();
		var item = id ? lineFor(id) : null;
		buybox.classList.toggle('is-incart', !!item);
		incartBox.hidden = !item;
		if (!item) {
			return;
		}
		var limit = item.max || 0; // 0 = no limit
		var atMax = !!limit && limit < 1000 && item.quantity >= limit;
		setText(incart.qty, fa(item.quantity));
		incart.step.classList.toggle('is-one', item.quantity <= 1);
		incart.dec.setAttribute('aria-label', item.quantity <= 1 ? 'حذف از سبد' : 'کاهش تعداد');
		incart.inc.disabled = atMax;
		if (incart.note) {
			incart.note.textContent = atMax ? 'فقط ' + fa(limit) + ' عدد از این کالا موجود است' : incart.noteText;
			incart.note.classList.toggle('is-warn', atMax);
		}
	}

	/* The buy bar button: add, or go to the cart when this product is already in it. */
	function renderBarButton() {
		if (!barBtn || !data) {
			return;
		}
		var out = isSoldOut();
		var id = curId();
		var inCart = !!(id && lineFor(id));
		barBtn.classList.toggle('is-disabled', out);
		if (out) {
			barBtn.setAttribute('aria-disabled', 'true');
		} else {
			barBtn.removeAttribute('aria-disabled');
		}
		barBtn.setAttribute('data-mode', inCart ? 'cart' : 'add');
		barBtn.setAttribute('href', inCart ? cfg.cart || '#bcp-buy' : '#bcp-buy');
		barBtn.textContent = out ? 'ناموجود' : inCart ? 'مشاهده سبد خرید' : 'افزودن به سبد خرید';
	}

	function renderBuy() {
		if (!data) {
			return;
		}
		if (isVariable) {
			renderPrice();
			renderMeta();
			renderPills();
			renderForm();
		}
		renderIncart();
		renderBarButton();
	}

	function choose(key, value) {
		sel[key] = value;
		var all = attrs.every(function (a) {
			return sel[a.key];
		});
		if (all && !match(sel)) {
			// no such combination: keep this choice, move the other attributes to the first variation that has it (in stock first)
			var fits = vars.filter(function (v) {
				return !v.a[key] || v.a[key] === value;
			});
			var alt =
				fits.filter(function (v) {
					return v.st !== 'outofstock';
				})[0] || fits[0];
			if (alt) {
				attrs.forEach(function (a) {
					if (a.key !== key) {
						sel[a.key] = alt.a[a.key] || sel[a.key] || a.opts[0].v;
					}
				});
			}
		}
		renderBuy();
		var v = curVar();
		if (v && v.img) {
			galleryShowImage(v.img);
		}
	}

	/* "Please choose ...": scroll to the pills, highlight them, show a short message. */
	function nudge() {
		if (!variantsBox) {
			return;
		}
		var missing = attrs.filter(function (a) {
			return !sel[a.key];
		})[0] || attrs[0];
		toast('لطفاً ' + missing.label + ' را انتخاب کنید');
		scrollToNode(variantsBox, 'center');
		variantsBox.classList.remove('is-nudge');
		void variantsBox.offsetWidth;
		variantsBox.classList.add('is-nudge');
		setTimeout(function () {
			variantsBox.classList.remove('is-nudge');
		}, 1200);
	}

	/*
	 * Add to cart. The after-add sheet opens at once (everything it shows is already on the page) with a spinner
	 * until the server confirms; on an error it closes again and the message is shown.
	 */
	var pendingAdd = null;
	var sheetDone = null;
	function sheetPending(on_) {
		var title = sheet ? $('#bcp-sheet-title', sheet) : null;
		if (!title) {
			return;
		}
		if (null === sheetDone) {
			sheetDone = title.textContent;
		}
		sheet.classList.toggle('is-pending', !!on_);
		setText(title, on_ ? 'در حال افزودن به سبد خرید…' : sheetDone);
	}

	function submitAdd(trigger) {
		if (!data || isSoldOut() || pendingAdd) {
			return;
		}
		var v = null;
		if (isVariable) {
			v = curVar();
			if (!v) {
				nudge();
				return;
			}
		}
		var withSheet = !!(cfg.sheet && sheet);
		busy(trigger, true);
		if (withSheet) {
			fillSheet(v);
			sheetPending(true);
			openDialog(sheet);
			compMetrics();
		}
		pendingAdd = addItem(v ? v.id : data.id, 1, form)
			.then(function () {
				if (withSheet) {
					sheetPending(false);
				} else {
					toast('به سبد خرید اضافه شد');
				}
			})
			.catch(function (e) {
				var message = errorText(e);
				if (withSheet && sheet.open) {
					closeDialog(sheet);
					setTimeout(function () {
						toast(message); // after the closing animation: a toast inside a closing dialog leaves with it
					}, 360);
				} else {
					toast(message);
				}
			})
			.then(function () {
				busy(trigger, false);
				pendingAdd = null;
			});
	}

	function initBuy() {
		if (!data) {
			return;
		}
		on(variantsBox, 'click', function (e) {
			var pill = e.target.closest('.bcp-pill');
			if (pill) {
				choose(pill.closest('.bcp-var').getAttribute('data-key'), pill.getAttribute('data-value'));
				return;
			}
			if (e.target.closest('[data-bcp="clear"]')) {
				sel = {};
				renderBuy();
			}
		});
		on(form, 'submit', function (e) {
			if (isVariable && !curVar()) {
				e.preventDefault();
				nudge();
				return;
			}
			if (cfg.ajax) {
				e.preventDefault();
				submitAdd(addBtn);
			}
		});

		function step(fn) {
			var id = curId();
			var item = id ? lineFor(id) : null;
			if (!item) {
				return;
			}
			incart.step.setAttribute('aria-busy', 'true');
			incart.inc.disabled = true;
			incart.dec.disabled = true;
			fn(item)
				.catch(function (e) {
					toast(errorText(e));
				})
				.then(function () {
					incart.step.removeAttribute('aria-busy');
					incart.dec.disabled = false;
					renderIncart();
				});
		}
		on(incart.inc, 'click', function () {
			step(function (item) {
				return updateItem(item.key, item.quantity + 1);
			});
		});
		on(incart.dec, 'click', function () {
			step(function (item) {
				return item.quantity <= 1 ? removeItem(item.key) : updateItem(item.key, item.quantity - 1);
			});
		});

		on(barBtn, 'click', function (e) {
			if (barBtn.classList.contains('is-disabled')) {
				e.preventDefault();
				return;
			}
			if (barBtn.getAttribute('data-mode') === 'cart') {
				return; // plain link to the cart
			}
			e.preventDefault();
			if (cfg.ajax && form) {
				submitAdd(barBtn);
			} else if (isVariable && !curVar()) {
				nudge();
			} else {
				scrollToNode(buy);
			}
		});

		cartListeners.push(function () {
			renderIncart();
			renderBarButton();
			syncComp();
		});
		renderBuy();
	}

	/*
	 * Product cards (related / upsells / the after-add sheet): simple products add straight from the card, and once
	 * a card's product is in the cart its add button gives way to a small stepper (+ / count / - or bin), so the
	 * visitor can change or remove it without leaving the page. The stepper follows the cart, whoever changed it.
	 */
	function miniCardId(mq) {
		var card = mq.closest('[data-pid]');
		return card ? parseInt(card.getAttribute('data-vid'), 10) || parseInt(card.getAttribute('data-pid'), 10) : 0;
	}
	function syncMini() {
		$$('[data-bcp-mq]').forEach(function (mq) {
			var card = mq.closest('[data-pid]');
			var line = lineFor(miniCardId(mq));
			var add = card && card.querySelector('[data-bcp-add], [data-bcp="sc-add"]');
			mq.hidden = !line;
			if (add) {
				add.hidden = !!line;
			}
			if (!line) {
				return;
			}
			var q = line.quantity;
			var one = q <= 1;
			var inc = mq.querySelector('[data-bcp-mq-act="inc"]');
			setText(mq.querySelector('[data-bcp-mq-n]'), fa(q));
			mq.classList.toggle('is-one', one);
			mq.querySelector('[data-bcp-mq-act="dec"]').setAttribute('aria-label', one ? '\u062D\u0630\u0641 \u0627\u0632 \u0633\u0628\u062F' : '\u06A9\u0627\u0647\u0634 \u062A\u0639\u062F\u0627\u062F');
			inc.disabled = !!(line.max && q >= line.max);
		});
	}
	function initCards() {
		cartListeners.push(syncMini);
		on(doc, 'click', function (e) {
			var step = e.target.closest('[data-bcp-mq-act]');
			if (step) {
				var mq = step.closest('[data-bcp-mq]');
				var line = lineFor(miniCardId(mq));
				if (!line || mq.getAttribute('aria-busy') === 'true') {
					return;
				}
				var q = line.quantity;
				var up = step.getAttribute('data-bcp-mq-act') === 'inc';
				busy(mq, true);
				(up ? updateItem(line.key, q + 1) : q <= 1 ? removeItem(line.key) : updateItem(line.key, q - 1))
					.catch(function (err) {
						toast(errorText(err));
					})
					.then(function () {
						busy(mq, false);
					});
				return;
			}
			var btn = e.target.closest('[data-bcp-add]');
			if (!btn) {
				return;
			}
			busy(btn, true);
			addItem(parseInt(btn.getAttribute('data-bcp-add'), 10), 1) // the cart answer swaps the button for the stepper
				.catch(function (err) {
					toast(errorText(err));
				})
				.then(function () {
					busy(btn, false);
				});
		});
	}

	/* ------------------------------------------------------------------ */
	/* 4. Gallery and photo viewer                                         */
	/* ------------------------------------------------------------------ */
	var gal = el('gal');
	var track = el('track');
	var slides = track ? $$('.bcp-gal__slide', track) : [];
	var thumbs = gal ? $$('.bcp-gal__thumb', gal) : [];
	var dots = gal ? $$('.bcp-gal__dots i', gal) : [];
	var counter = el('count');
	var galIndex = -1;

	function galleryMark(i) {
		if (i === galIndex) {
			return;
		}
		galIndex = i;
		thumbs.forEach(function (t, n) {
			t.classList.toggle('is-on', n === i);
		});
		dots.forEach(function (d, n) {
			d.classList.toggle('is-on', n === i);
		});
		setText(counter, fa(i + 1 + ' / ' + slides.length));
		var thumb = thumbs[i];
		if (thumb && thumb.parentNode.scrollWidth > thumb.parentNode.clientWidth) {
			var pr = thumb.parentNode.getBoundingClientRect();
			var tr = thumb.getBoundingClientRect();
			thumb.parentNode.scrollBy({ left: tr.left + tr.width / 2 - (pr.left + pr.width / 2), behavior: 'auto' });
		}
	}
	function galleryGo(i, instant) {
		var slide = slides[clamp(i, 0, slides.length - 1)];
		if (!slide) {
			return;
		}
		var box = track.getBoundingClientRect();
		var rect = slide.getBoundingClientRect();
		track.scrollBy({ left: rect.left - box.left, behavior: instant ? 'auto' : motion() });
	}
	function galleryShowImage(id) {
		for (var i = 0; i < slides.length; i++) {
			if (parseInt(slides[i].getAttribute('data-id'), 10) === id) {
				galleryGo(i);
				return;
			}
		}
	}

	/* Photo viewer (Woodmart "Photoswipe / zoom" setting: the zoom button exists only when it is on). */
	var lb = el('lb');
	var lbIndex = 0;
	function viewerShow(i) {
		lbIndex = clamp(i, 0, slides.length - 1);
		var slide = slides[lbIndex];
		var img = $('img', slide);
		var view = el('lb-img', lb);
		view.src = slide.getAttribute('data-full') || (img ? img.currentSrc || img.src : '');
		view.alt = img ? img.alt : '';
		setText(el('lb-count', lb), slides.length > 1 ? fa(lbIndex + 1 + ' / ' + slides.length) : '');
		el('lb-prev', lb).disabled = lbIndex === 0;
		el('lb-next', lb).disabled = lbIndex === slides.length - 1;
		setHidden(el('lb-prev', lb), slides.length < 2);
		setHidden(el('lb-next', lb), slides.length < 2);
	}
	function viewerOpen() {
		if (!lb || !slides.length) {
			return;
		}
		viewerShow(galIndex < 0 ? 0 : galIndex);
		openDialog(lb);
	}

	function initGallery() {
		if (!gal || !track) {
			return;
		}
		galleryMark(0);
		if (slides.length > 1 && 'IntersectionObserver' in window) {
			var io = new IntersectionObserver(
				function (entries) {
					entries.forEach(function (en) {
						if (en.isIntersecting && en.intersectionRatio >= 0.6) {
							galleryMark(slides.indexOf(en.target));
						}
					});
				},
				{ root: track, threshold: [0.6] }
			);
			slides.forEach(function (s) {
				io.observe(s);
			});
		}
		thumbs.forEach(function (t, i) {
			on(t, 'click', function () {
				galleryGo(i);
			});
		});
		if (lb) {
			on(el('zoom'), 'click', viewerOpen);
			on(track, 'click', function (e) {
				if (e.target.closest('img')) {
					viewerOpen();
				}
			});
			on(el('lb-prev', lb), 'click', function () {
				viewerShow(lbIndex - 1);
			});
			on(el('lb-next', lb), 'click', function () {
				viewerShow(lbIndex + 1);
			});
			on(lb, 'keydown', function (e) {
				if (e.key === 'ArrowLeft') {
					viewerShow(lbIndex + 1); // right-to-left: the next photo is on the left
				} else if (e.key === 'ArrowRight') {
					viewerShow(lbIndex - 1);
				}
			});
			on(lb, 'close', function () {
				galleryGo(lbIndex, true);
			});
		}
	}

	/* ------------------------------------------------------------------ */
	/* 5. Dialogs                                                          */
	/* ------------------------------------------------------------------ */
	var sheet = el('sheet');
	var msg = el('msg');

	function openDialog(d) {
		if (!d || d.open) {
			return;
		}
		d.classList.remove('is-closing');
		if (d.showModal) {
			d.showModal();
		} else {
			d.setAttribute('open', '');
		}
		html.classList.add('bcp-lock');
	}
	function closeDialog(d) {
		if (!d || !d.open) {
			return;
		}
		function finish() {
			d.classList.remove('is-closing');
			if (d.close) {
				d.close();
			} else {
				d.removeAttribute('open');
			}
		}
		var box = d.firstElementChild;
		if (reduceMotion || !box || !/bcp-(sheet|msg)/.test(d.className)) {
			finish();
			return;
		}
		var done = false;
		function end(e) {
			if (!done && (!e || e.target === box)) {
				done = true;
				finish();
			}
		}
		d.classList.add('is-closing');
		box.addEventListener('animationend', end);
		setTimeout(end, 320);
	}

	function initDialogs() {
		$$('dialog.bcp-sheet, dialog.bcp-msg, dialog.bcp-lb').forEach(function (d) {
			on(d, 'cancel', function (e) {
				e.preventDefault();
				closeDialog(d);
			});
			on(d, 'close', function () {
				if (toastNode && toastNode.parentNode === d) {
					doc.body.appendChild(toastNode);
					toastNode.hidden = true;
				}
				if (!$('dialog[open]')) {
					html.classList.remove('bcp-lock');
				}
			});
			on(d, 'click', function (e) {
				if (e.target === d || e.target.classList.contains('bcp-lb__stage')) {
					closeDialog(d);
				}
			});
		});
		on(doc, 'click', function (e) {
			var close = e.target.closest('[data-bcp-close]');
			if (close) {
				closeDialog(close.closest('dialog'));
			}
		});
		on(doc, 'click', function (e) {
			var link = e.target.closest('[data-bcp="messenger"]');
			if (link && msg) {
				e.preventDefault();
				openDialog(msg);
			}
		});
		// "View cart" in the sheet while the product is still on its way to the cart: go there once it is in.
		on(sheet, 'click', function (e) {
			var link = e.target.closest('.bcp-sheet__foot a[href]');
			if (!link || !pendingAdd) {
				return;
			}
			e.preventDefault();
			busy(link, true);
			pendingAdd.then(function () {
				if (sheet.open) {
					location.href = link.href;
				} else {
					busy(link, false); // the add failed and the sheet closed
				}
			});
		});
		initSheet();
	}

	/* After-add sheet: the added item (from the page, so it matches what the visitor saw), then complementary products. */
	function fillSheet(v) {
		setText(el('sheet-name', sheet), data.name);
		var imgBox = el('sheet-img', sheet);
		var src = data.img;
		if (v && v.img) {
			for (var i = 0; i < slides.length; i++) {
				if (parseInt(slides[i].getAttribute('data-id'), 10) === v.img) {
					var s = $('img', slides[i]);
					src = s ? s.currentSrc || s.src : src;
				}
			}
		}
		if (imgBox) {
			imgBox.textContent = '';
			if (src) {
				var img = new Image();
				img.alt = '';
				img.src = src;
				imgBox.appendChild(img);
			}
		}
		var label = '';
		if (isVariable) {
			label = attrs
				.map(function (a) {
					return a.label + ': ' + optionLabel(a, sel[a.key]);
				})
				.join('، ');
		}
		var varNode = el('sheet-var', sheet);
		setText(varNode, label);
		setHidden(varNode, !label);
		var priceNode = el('sheet-price', sheet);
		if (priceNode) {
			var price = v ? v.p : data.p;
			priceNode.textContent = money(price) + ' ';
			var small = doc.createElement('small');
			small.textContent = currency;
			priceNode.appendChild(small);
		}
	}

	var comp = {};
	function compMetrics() {
		if (!comp.track) {
			return;
		}
		var card = comp.cards[0];
		var step = card ? card.offsetWidth + 8 : 0;
		var pos = Math.abs(comp.track.scrollLeft);
		var max = comp.track.scrollWidth - comp.track.clientWidth;
		var scrolls = max > 4;
		setHidden(comp.nav, !scrolls);
		comp.prev.disabled = pos <= 2;
		comp.next.disabled = pos >= max - 2;
		if (comp.dots) {
			if (scrolls && !comp.dots.firstChild) {
				comp.cards.forEach(function () {
					comp.dots.appendChild(doc.createElement('i'));
				});
			}
			var index = step ? Math.round(pos / step) : 0;
			$$('i', comp.dots).forEach(function (d, n) {
				d.classList.toggle('is-on', n === index);
			});
		}
	}
	function compScroll(dir) {
		var card = comp.cards[0];
		var step = (card ? card.offsetWidth + 8 : 180) * (comp.track.clientWidth > 480 ? 2 : 1);
		var rtl = getComputedStyle(comp.track).direction === 'rtl';
		comp.track.scrollBy({ left: (rtl ? -1 : 1) * dir * step, behavior: motion() });
	}
	function cardId(card) {
		return parseInt(card.getAttribute('data-vid'), 10) || parseInt(card.getAttribute('data-pid'), 10);
	}
	function syncComp() {
		if (!comp.cards) {
			return;
		}
		comp.cards.forEach(function (card) {
			if (card.querySelector('[data-bcp="sc-add"]')) {
				card.classList.toggle('is-added', !!lineFor(cardId(card)));
			}
		});
	}

	function initSheet() {
		var section = el('comp', sheet);
		if (!section) {
			return;
		}
		comp.track = el('comp-track', section);
		comp.cards = $$('.bcp-sc', comp.track);
		comp.prev = el('comp-prev', section);
		comp.next = el('comp-next', section);
		comp.nav = comp.prev.parentNode;
		comp.dots = el('comp-dots', section);
		on(comp.prev, 'click', function () {
			compScroll(-1);
		});
		on(comp.next, 'click', function () {
			compScroll(1);
		});
		on(comp.track, 'scroll', frame(compMetrics), { passive: true });
		on(window, 'resize', frame(compMetrics));
		on(comp.track, 'click', function (e) {
			var pill = e.target.closest('.bcp-sp');
			var add = e.target.closest('[data-bcp="sc-add"]');
			var card = e.target.closest('.bcp-sc');
			if (pill && card) {
				card.setAttribute('data-vid', pill.getAttribute('data-vid'));
				$$('.bcp-sp', card).forEach(function (p) {
					var isOn = p === pill;
					p.classList.toggle('is-on', isOn);
					p.setAttribute('aria-pressed', isOn ? 'true' : 'false');
				});
				setText(el('sc-varname', card), pill.textContent);
				setText(el('sc-price', card), pill.getAttribute('data-price'));
				syncComp();
				syncMini(); // the chosen variant may be the one in the cart
			} else if (add && card && !card.classList.contains('is-added')) {
				card.classList.add('is-loading');
				addItem(cardId(card), 1)
					.catch(function (err) {
						toast(errorText(err));
					})
					.then(function () {
						card.classList.remove('is-loading');
					});
			}
		});
	}

	/* ------------------------------------------------------------------ */
	/* 6. Page                                                             */
	/* ------------------------------------------------------------------ */
	var media = $('.bcp-media', root);
	var cardNode = $('.bcp-card', root);
	var bar = el('bar');

	/*
	 * Woodmart's sticky header: height = its sticky rows together, bottom = how far down it reaches right now
	 * (0 while it is hidden by "hide on scroll down"). Used as the offset for the gallery, the anchors and the bar.
	 */
	var siteHeader = $('.whb-header');
	var stickyRows = $$('.whb-sticky-row');
	function stickyHeader() {
		var height = 0;
		var bottom = 0;
		var sticked = !!siteHeader && siteHeader.classList.contains('whb-sticked');
		stickyRows.forEach(function (row) {
			var box = row.getBoundingClientRect();
			if (box.height > 0) {
				height += box.height;
				if (sticked) {
					bottom = Math.max(bottom, box.bottom);
				}
			}
		});
		return { height: height, bottom: Math.max(0, bottom) };
	}

	var lastVars = {};
	function setVar(name, value) {
		if (lastVars[name] !== value) {
			lastVars[name] = value;
			doc.body.style.setProperty(name, value); // on <body>: the stylesheet declares the same variables there, so <html> would lose
		}
	}

	function onScroll() {
		var head = stickyHeader();
		var topBar = 0; // the buy bar sits at the bottom on every screen size, so it never covers the top of the page
		setVar('--bcp-anchor', Math.max(head.height, topBar) + 16 + 'px');
		setVar('--bcp-hdr', head.bottom + 'px');

		// Phones: the content card slides over the gallery, which fades and shrinks a little.
		if (media && cardNode) {
			if (phone.matches) {
				var rest = head.height + media.offsetHeight - 20; // where the card's top edge sits at scroll 0
				var end = head.height + 24;
				var p = clamp((rest - cardNode.getBoundingClientRect().top) / (rest - end), 0, 1);
				media.style.setProperty('--bcp-p', p.toFixed(3));
			} else {
				media.style.removeProperty('--bcp-p');
			}
		}

		// Tablet / desktop: the buy bar shows once the buy card has scrolled away.
		if (bar && buy) {
			var show = phone.matches || buy.getBoundingClientRect().bottom < head.bottom;
			bar.classList.toggle('is-on', show);
		}
	}

	function initTabs() {
		var tabs = $$('.bcp-tab');
		var blocks = tabs
			.map(function (t) {
				return $(t.getAttribute('href'));
			})
			.filter(Boolean);
		function mark(id) {
			tabs.forEach(function (t) {
				var isOn = t.getAttribute('href') === '#' + id;
				t.classList.toggle('is-on', isOn);
				if (isOn) {
					t.setAttribute('aria-current', 'true');
				} else {
					t.removeAttribute('aria-current');
				}
			});
		}
		on(doc, 'click', function (e) {
			var link = e.target.closest('a[href^="#bcp-"]');
			if (!link || e.defaultPrevented) {
				return;
			}
			var target = $(link.getAttribute('href'));
			if (target) {
				e.preventDefault();
				scrollToNode(target);
				if (link.classList.contains('bcp-tab')) {
					mark(target.id);
				}
			}
		});
		if (!$('#bcp-specs')) {
			$$('.bcp-chip--more').forEach(function (a) {
				a.hidden = true;
			});
		}
		if (blocks.length > 1 && 'IntersectionObserver' in window) {
			var io = new IntersectionObserver(
				function (entries) {
					entries.forEach(function (en) {
						if (en.isIntersecting) {
							mark(en.target.id);
						}
					});
				},
				{ rootMargin: '-25% 0px -65% 0px' }
			);
			blocks.forEach(function (b) {
				io.observe(b);
			});
		}
	}

	/* Description: three lines and "show more" when it is long. */
	function initDescription() {
		var desc = el('desc');
		var more = el('more');
		if (!desc || !more) {
			return;
		}
		var label = $('span', more);
		var closed = 'مشاهده بیشتر';
		var opened = 'مشاهده کمتر';
		var touched = false;
		function measure() {
			if (touched) {
				return;
			}
			var long = desc.scrollHeight > 120;
			desc.classList.toggle('is-clamped', long);
			more.hidden = !long;
			more.setAttribute('aria-expanded', 'false');
		}
		on(more, 'click', function () {
			touched = true;
			var open = desc.classList.toggle('is-clamped') === false;
			more.classList.toggle('is-open', open);
			more.setAttribute('aria-expanded', open ? 'true' : 'false');
			setText(label, open ? opened : closed);
		});
		measure();
		on(window, 'load', measure);
	}

	/* Reviews: with none yet, the form stays folded behind a button (and opens by itself when the address asks for it). */
	function initReviews() {
		var box = el('reviews');
		if (!box) {
			return;
		}
		function open() {
			box.classList.add('is-open');
			var field = $('#comment', box);
			if (field) {
				setTimeout(function () {
					field.focus({ preventScroll: true });
				}, 60);
			}
		}
		on(el('review-open'), 'click', open);
		if (/^#(comment|respond|reviews)/.test(location.hash)) {
			open();
		}
	}

	function init() {
		safe(initGallery);
		safe(initBuy);
		safe(initCards);
		safe(initDialogs);
		safe(initTabs);
		safe(initDescription);
		safe(initReviews);
		var scroll = frame(onScroll);
		on(window, 'scroll', scroll, { passive: true });
		on(window, 'resize', scroll);
		on(window, 'load', scroll);
		on(siteHeader, 'transitionend', scroll);
		safe(onScroll);
		if (data) {
			syncCart(true);
			on(window, 'pageshow', function (e) {
				if (e.persisted) {
					syncCart(true); // back from another page (the cart, say): it may have changed
				}
			});
			on(doc, 'visibilitychange', function () {
				if (!doc.hidden) {
					syncCart(false); // back to this tab: at most once a minute
				}
			});
		}
	}

	if (doc.readyState === 'loading') {
		doc.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
