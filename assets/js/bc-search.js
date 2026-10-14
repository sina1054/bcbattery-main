/**
 * BC Search front-end (works on top of Woodmart's AJAX search).
 * - Highlights every typed word, Persian-letter aware (ی/ي, ک/ك, ۷/7 ...).
 * - Out-of-stock badge in suggestions.
 * - Desktop hover details box with add to cart (like FiboSearch).
 * - Logs finished searches for the admin report.
 */
(function ($) {
	'use strict';

	var cfg = window.bcsSearch || {};
	if (!$ || !cfg.ajaxurl) {
		return;
	}

	/* ---------- Cache-busting: new index version => fresh AJAX responses ---------- */

	if (cfg.ver && $.ajaxPrefilter) {
		$.ajaxPrefilter(function (opts) {
			if (opts.url && opts.url.indexOf('action=woodmart_ajax_search') !== -1 && opts.url.indexOf('bcsv=') === -1) {
				opts.url += (opts.url.indexOf('?') === -1 ? '?' : '&') + 'bcsv=' + cfg.ver;
			}
		});
	}

	/* ---------- Persian-aware highlighting ---------- */

	var GROUPS = ['یيىئ', 'کك', 'اآأإٱ', 'هةۀ', 'وؤ', '0۰٠', '1۱١', '2۲٢', '3۳٣', '4۴٤', '5۵٥', '6۶٦', '7۷٧', '8۸٨', '9۹٩'];
	var CLASS_OF = {};
	GROUPS.forEach(function (g) {
		Array.from(g).forEach(function (ch) {
			CLASS_OF[ch] = '[' + g + ']';
		});
	});

	var splitRe;
	try {
		splitRe = new RegExp('[^\\p{L}\\p{N}]+', 'u');
	} catch (e) {
		splitRe = /[\s,.\-_\/()]+/;
	}

	function escRe(s) {
		return s.replace(/[.*+?^${}()|[\]\\\/-]/g, '\\$&');
	}

	function escHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function tokens(q) {
		return String(q || '')
			.toLowerCase()
			.replace(/\u200c/g, ' ')
			.split(splitRe)
			.filter(function (t) {
				return t.length > 1 || /[0-9\u06f0-\u06f9\u0660-\u0669]/.test(t);
			});
	}

	function buildRegex(q) {
		var toks = tokens(q);
		if (!toks.length) {
			return null;
		}
		toks.sort(function (a, b) {
			return b.length - a.length;
		});
		var parts = toks.map(function (t) {
			return Array.from(t).map(function (ch) {
				return CLASS_OF[ch] || escRe(ch);
			}).join('\u200c?');
		});
		try {
			return new RegExp('(' + parts.join('|') + ')', 'giu');
		} catch (e) {
			return null;
		}
	}

	function highlight(el, re) {
		var text = el.textContent;
		var html = '';
		var last = 0;
		var m;
		re.lastIndex = 0;
		while ((m = re.exec(text)) !== null) {
			if (!m[0].length) {
				re.lastIndex++;
				continue;
			}
			html += escHtml(text.slice(last, m.index)) + '<strong>' + escHtml(m[0]) + '</strong>';
			last = m.index + m[0].length;
		}
		html += escHtml(text.slice(last));
		el.innerHTML = html;
	}

	/* ---------- Helpers to reach Woodmart's autocomplete instance ---------- */

	function inputFor(el) {
		var $el = $(el);
		var $wrap = $el.closest('.wd-search-form, .wd-search-full-screen, .wd-search-full-screen-2, .wd-search-dropdown, .wd-header-search');
		var $in = $wrap.find('input[name="s"]').first();
		if (!$in.length) {
			$in = $el.closest('.wd-search-results').parent().find('input[name="s"]').first();
		}
		if (!$in.length && document.activeElement && document.activeElement.name === 's') {
			$in = $(document.activeElement);
		}
		return $in;
	}

	function instanceFor($in) {
		var inst = $in && $in.length ? $in.data('autocomplete') : null;
		return inst && inst.suggestions ? inst : null;
	}

	function suggestionFor(item) {
		var inst = instanceFor(inputFor(item));
		var idx = parseInt(item.getAttribute('data-index'), 10);
		return inst && !isNaN(idx) ? inst.suggestions[idx] || null : null;
	}

	/* ---------- Post-process rendered suggestions ---------- */

	function processBox(box) {
		var q = inputFor(box).val() || '';
		var re = buildRegex(q);
		$(box).find('.autocomplete-suggestion').each(function () {
			if (this.getAttribute('data-bcs') === q) {
				return;
			}
			this.setAttribute('data-bcs', q);

			var title = this.querySelector('.wd-entities-title');
			if (title && re) {
				highlight(title, re);
			}

			var s = suggestionFor(this);
			if (!s) {
				return;
			}
			if (s.bcs_oos && !this.querySelector('.bcs-oos')) {
				var content = this.querySelector('.wd-suggestion-content');
				if (content) {
					content.insertAdjacentHTML('beforeend', '<span class="bcs-oos">' + escHtml(cfg.oos || '') + '</span>');
				}
			}
			if (s.bcs_type === 'term') {
				this.classList.add('bcs-term');
			}
		});
	}

	var pending = false;

	function scan() {
		pending = false;
		$('.wd-search-results .autocomplete-suggestions').each(function () {
			processBox(this);
		});
		if ($panel && $panel.hasClass('is-open') && !$('.wd-search-results.wd-opened').length) {
			hidePanel();
		}
	}

	function schedule() {
		if (!pending) {
			pending = true;
			(window.requestAnimationFrame || window.setTimeout)(scan);
		}
	}

	function observe() {
		if (!window.MutationObserver) {
			return;
		}
		$('.wd-search-results').each(function () {
			if (this.bcsObserved) {
				return;
			}
			this.bcsObserved = true;
			new MutationObserver(schedule).observe(this, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
		});
	}

	/* ---------- Details box (desktop) ---------- */

	var $panel = null;
	var cache = {};
	var hideTimer = null;
	var currentId = 0;

	function panel() {
		if (!$panel) {
			$panel = $('<div id="bcs-details" class="bcs-details" aria-live="polite"></div>').appendTo(document.body);
			$panel.on('mouseenter', cancelHide).on('mouseleave', scheduleHide);
			$panel.on('input change', '.bcs-d-qty', function () {
				var v = Math.max(1, parseInt(this.value, 10) || 1);
				$(this).siblings('.bcs-d-atc').attr('data-quantity', v).data('quantity', v);
			});
		}
		return $panel;
	}

	function cancelHide() {
		clearTimeout(hideTimer);
	}

	function scheduleHide() {
		clearTimeout(hideTimer);
		hideTimer = setTimeout(hidePanel, 250);
	}

	function hidePanel() {
		if ($panel) {
			$panel.removeClass('is-open');
		}
		currentId = 0;
	}

	function place(resultsEl) {
		var r = resultsEl.getBoundingClientRect();
		var w = 320;
		var gap = 10;
		var vw = window.innerWidth;
		var rtl = document.documentElement.dir === 'rtl' || document.body.classList.contains('rtl');
		var left = rtl ? r.left - w - gap : r.right + gap;
		if (left < 8 || left + w > vw - 8) {
			left = rtl ? r.right + gap : r.left - w - gap;
		}
		if (left < 8 || left + w > vw - 8 || r.height < 60) {
			return false;
		}
		panel().css({
			left: left + 'px',
			top: Math.max(8, r.top) + 'px',
			width: w + 'px',
			maxHeight: Math.max(260, window.innerHeight - Math.max(8, r.top) - 16) + 'px'
		});
		return true;
	}

	function showDetails(item) {
		if (!cfg.details || window.innerWidth < (cfg.breakpoint || 992)) {
			return;
		}
		var $item = $(item);
		if ($item.closest('.wd-search-full-screen, .wd-search-full-screen-2').length) {
			return;
		}
		var results = $item.closest('.wd-search-results')[0];
		var s = suggestionFor(item);
		if (!results || !s || !s.bcs_id) {
			scheduleHide();
			return;
		}
		cancelHide();
		if (!place(results)) {
			return;
		}
		if (currentId === s.bcs_id && $panel.hasClass('is-open')) {
			return;
		}
		var id = s.bcs_id;
		currentId = id;

		if (cache[id]) {
			panel().html(cache[id]).addClass('is-open');
			return;
		}
		panel().html('<div class="bcs-d-loading"></div>').addClass('is-open');
		$.getJSON(cfg.ajaxurl, { action: 'bcs_details', id: id }).done(function (r) {
			if (r && r.success && r.data && r.data.html) {
				cache[id] = r.data.html;
				if (currentId === id) {
					panel().html(r.data.html);
				}
			} else if (currentId === id) {
				hidePanel();
			}
		}).fail(function () {
			if (currentId === id) {
				hidePanel();
			}
		});
	}

	$(document).on('mouseenter', '.wd-search-results .autocomplete-suggestion', function () {
		showDetails(this);
	});
	$(document).on('mouseleave', '.wd-search-results', scheduleHide);
	$(window).on('scroll resize', hidePanel);
	$(document).on('keydown', function (e) {
		if (e.key === 'Escape') {
			hidePanel();
		}
	});

	/* ---------- Analytics: one log per finished search ---------- */

	var lastLogged = '';
	var logTimer = null;

	function logQuery(q) {
		q = $.trim(q || '');
		if (!cfg.analytics || q.length < 2 || q === lastLogged) {
			return;
		}
		lastLogged = q;
		send({ action: 'bcs_log', q: q });
	}

	function send(data) {
		if (navigator.sendBeacon && window.FormData) {
			var fd = new FormData();
			Object.keys(data).forEach(function (k) {
				fd.append(k, data[k]);
			});
			navigator.sendBeacon(cfg.ajaxurl, fd);
		} else {
			$.post(cfg.ajaxurl, data);
		}
	}

	$(document).on('keyup', 'form.woodmart-ajax-search input[name="s"]', function () {
		var v = this.value;
		clearTimeout(logTimer);
		logTimer = setTimeout(function () {
			logQuery(v);
		}, 2500);
	});
	$(document).on('submit', 'form.woodmart-ajax-search, form.searchform', function () {
		clearTimeout(logTimer);
		logQuery($(this).find('input[name="s"]').val());
	});
	$(document).on('mousedown', '.wd-search-results .autocomplete-suggestion, .wd-search-results .wd-all-results', function () {
		var q = inputFor(this).val();
		clearTimeout(logTimer);
		logQuery(q);

		// Click learning: tell the engine which product was chosen for this query.
		var s = $(this).hasClass('autocomplete-suggestion') ? suggestionFor(this) : null;
		if (cfg.analytics && s && s.bcs_id && $.trim(q).length > 1) {
			send({ action: 'bcs_click', q: $.trim(q), id: s.bcs_id });
		}
	});

	/* ---------- Init ---------- */

	$(observe);
	window.addEventListener('wdUpdatedHeader', observe);
	$(document).on('focusin', 'form.woodmart-ajax-search input[name="s"]', observe);
})(window.jQuery);
