/**
 * Accessory Tab — "Visa alla" toggle + qty selector + add-to-cart sync + stats tracking + checklist total.
 * v2.31.5 — CTA always goes via runBundleFlow (0-acc case also works).
 */
(function () {
	'use strict';

	// ── "Visa alla tillbehör" toggle (desktop) ──
	document.addEventListener('click', function (e) {
		var link = e.target.closest('.sijab-show-all-link');
		if (!link) return;

		e.preventDefault();
		var section = link.closest('.sijab-accessories-section');
		if (!section) return;

		var isExpanded = section.classList.toggle('sijab-show-all');

		if (isExpanded) {
			link.textContent = link.getAttribute('data-hide');
		} else {
			link.textContent = link.getAttribute('data-show');
		}
	});

	// ── Mobile: inject "Visa fler" link if more than 1 accessory ──
	function initMobileToggle() {
		var sections = document.querySelectorAll('.sijab-accessories-section:not(.sijab-bundle-section)');
		sections.forEach(function (section) {
			var items = section.querySelectorAll('.sijab-acc-item');
			if (items.length <= 1) return;
			// Don't add twice
			if (section.querySelector('.sijab-mobile-toggle')) return;

			var total = items.length;
			var wrapper = document.createElement('div');
			wrapper.className = 'sijab-mobile-toggle';
			var link = document.createElement('a');
			link.href = '#';
			link.textContent = 'Visa fler tillbehör (' + total + ')';
			link.setAttribute('data-show', 'Visa fler tillbehör (' + total + ')');
			link.setAttribute('data-hide', 'Visa färre');
			wrapper.appendChild(link);

			var list = section.querySelector('.sijab-accessories-section__list');
			if (list) {
				list.after(wrapper);
			}
		});
	}

	// ── Mobile toggle click ──
	document.addEventListener('click', function (e) {
		var link = e.target.closest('.sijab-mobile-toggle a');
		if (!link) return;

		e.preventDefault();
		var section = link.closest('.sijab-accessories-section');
		if (!section) return;

		var isExpanded = section.classList.toggle('sijab-show-all-mobile');

		if (isExpanded) {
			link.textContent = link.getAttribute('data-hide');
		} else {
			link.textContent = link.getAttribute('data-show');
		}
	});

	// ── Quantity +/- buttons (accessories + bundles) ──
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.sijab-qty-minus, .sijab-qty-plus');
		if (!btn) return;

		var qtyWrap = btn.closest('.sijab-acc-card__qty');
		if (!qtyWrap) return;

		var input = qtyWrap.querySelector('.sijab-qty-input');
		if (!input) return;

		var val = parseInt(input.value, 10) || 1;
		var minVal = parseInt(input.getAttribute('min'), 10) || 1;
		var maxVal = parseInt(input.getAttribute('max'), 10) || 0; // 0 = no limit

		if (btn.classList.contains('sijab-qty-minus')) {
			val = Math.max(minVal, val - 1);
		} else {
			val = val + 1;
			if (maxVal > 0) val = Math.min(maxVal, val);
		}

		input.value = val;

		// Sync quantity to the add-to-cart button (accessories)
		var row = btn.closest('.sijab-acc-card__qty-row');
		var atcBtn = row ? row.querySelector('.sijab-acc-atc') : null;
		if (atcBtn) {
			atcBtn.setAttribute('data-quantity', val);
		}
	});

	// Sync on manual input change.
	document.addEventListener('change', function (e) {
		if (!e.target.classList.contains('sijab-qty-input')) return;

		var minVal = parseInt(e.target.getAttribute('min'), 10) || 1;
		var maxVal = parseInt(e.target.getAttribute('max'), 10) || 0;
		var val = Math.max(minVal, parseInt(e.target.value, 10) || minVal);
		if (maxVal > 0) val = Math.min(maxVal, val);
		e.target.value = val;

		var row = e.target.closest('.sijab-acc-card__qty-row');
		var atcBtn = row ? row.querySelector('.sijab-acc-atc') : null;
		if (atcBtn) {
			atcBtn.setAttribute('data-quantity', val);
		}
	});

	// ── Variable product: update price + stock on variant select ──
	document.addEventListener('change', function (e) {
		var select = e.target.closest('.sijab-var-select');
		if (!select) return;

		var card     = select.closest('.sijab-acc-card');
		var selected = select.options[select.selectedIndex];
		var varId    = select.value;

		// Price
		var priceHtml = selected.getAttribute('data-price-html');
		var priceEl   = card.querySelector('.sijab-acc-card__price');
		if (priceEl && priceHtml) priceEl.innerHTML = priceHtml;

		// Stock badge
		var stockStatus = selected.getAttribute('data-stock') || '';
		var stockLabel  = selected.getAttribute('data-stock-label') || '';
		var stockEl     = card.querySelector('.sijab-acc-card__stock');
		if (stockEl) {
			stockEl.className = 'sijab-acc-card__stock' + (stockStatus ? ' sijab-acc-card__stock--' + stockStatus : '');
			stockEl.textContent = stockLabel;
		}

		// SKU
		var skuVal = selected.getAttribute('data-sku') || '';
		var skuEl  = card.querySelector('.sijab-acc-card__sku');
		if (skuEl) {
			skuEl.textContent = skuVal ? 'Art.nr: ' + skuVal : '';
		}

		// Enable/disable add-to-cart button
		var btn        = card.querySelector('.sijab-var-atc-btn');
		var purchasable = varId && selected.getAttribute('data-purchasable') === '1';
		if (btn) btn.disabled = !purchasable;
	});

	// ── Variable product: AJAX add to cart (uses custom handler) ──
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.sijab-var-atc-btn');
		if (!btn || btn.disabled) return;
		e.preventDefault();

		var card     = btn.closest('.sijab-acc-card');
		var select   = card ? card.querySelector('.sijab-var-select') : null;
		if (!select || !select.value) return;

		var varId    = select.value;
		var parentId = btn.getAttribute('data-parent-id');
		var selected = select.options[select.selectedIndex];
		var attrs    = {};
		try { attrs = JSON.parse(selected.getAttribute('data-attributes') || '{}'); } catch (err) {}

		// Get quantity from the qty input (if present)
		var qtyInput = card.querySelector('.sijab-var-qty-input');
		var qty = qtyInput ? Math.max(1, parseInt(qtyInput.value, 10) || 1) : 1;

		btn.disabled    = true;
		var origText    = btn.textContent;
		btn.textContent = '…';

		// Use our custom AJAX handler for reliable variable product add-to-cart.
		var ajaxUrl = (typeof sijabAccStats !== 'undefined') ? sijabAccStats.ajax_url : '/wp-admin/admin-ajax.php';

		var body = new FormData();
		body.append('action',       'sijab_add_to_cart');
		body.append('product_id',   parentId);
		body.append('variation_id', varId);
		body.append('quantity',     qty);
		Object.keys(attrs).forEach(function (key) { body.append(key, attrs[key]); });

		// Tag for order tracking.
		if (typeof sijabAccStats !== 'undefined' && sijabAccStats.parent_id) {
			body.append('sijab_acc_parent', sijabAccStats.parent_id);
		}

		fetch(ajaxUrl, { method: 'POST', body: body })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				btn.disabled    = false;
				btn.textContent = origText;
				if (res && res.success) {
					// Trigger WooCommerce cart update.
					if (res.data && res.data.fragments && typeof jQuery !== 'undefined') {
						jQuery(document.body).trigger('added_to_cart', [res.data.fragments, res.data.cart_hash, jQuery(btn)]);
					} else if (typeof jQuery !== 'undefined') {
						jQuery(document.body).trigger('wc_fragment_refresh');
					}
					// Visual feedback
					btn.textContent = '✓';
					setTimeout(function() { btn.textContent = origText; }, 1500);
				} else {
					// Show error message briefly
					var msg = (res && res.data && res.data.message) ? res.data.message : 'Fel';
					btn.textContent = msg;
					btn.style.fontSize = '11px';
					setTimeout(function() {
						btn.textContent = origText;
						btn.style.fontSize = '';
					}, 3000);
				}
			})
			.catch(function () {
				btn.disabled    = false;
				btn.textContent = origText;
			});
	});

	// ── Statistics tracking ──
	function getAccessoryId(el) {
		var card = el.closest('.sijab-acc-card');
		return card ? card.getAttribute('data-accessory-id') : null;
	}

	function trackEvent(accessoryId, eventType) {
		if (typeof sijabAccStats === 'undefined' || !accessoryId) return;
		var data = 'action=sijab_acc_track'
			+ '&parent_id=' + encodeURIComponent(sijabAccStats.parent_id)
			+ '&accessory_id=' + encodeURIComponent(accessoryId)
			+ '&event_type=' + encodeURIComponent(eventType);
		if (navigator.sendBeacon) {
			navigator.sendBeacon(
				sijabAccStats.ajax_url,
				new Blob([data], { type: 'application/x-www-form-urlencoded' })
			);
		}
	}

	// Track: "Lägg till" (simple product add to cart).
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.sijab-acc-atc');
		if (!btn) return;
		trackEvent(getAccessoryId(btn), 'add_to_cart');
	});

	// Track: "Lägg till" (variable product add to cart).
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.sijab-var-atc-btn');
		if (!btn || btn.disabled) return;
		trackEvent(getAccessoryId(btn), 'add_to_cart');
	});

	// Track: "Visa produkt" button click.
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.sijab-acc-atc-btn:not(.sijab-acc-atc):not(.sijab-var-atc-btn)');
		if (!btn) return;
		trackEvent(getAccessoryId(btn), 'view_product');
	});

	// Track: product name or image click.
	document.addEventListener('click', function (e) {
		var link = e.target.closest('.sijab-acc-card__name, .sijab-acc-card__image');
		if (!link) return;
		trackEvent(getAccessoryId(link), 'product_click');
	});

	// ── wcvat-toggle sync ──
	// Find a "reference" .product-tax-on / .product-tax-off elsewhere on the page
	// (outside accessories) and copy its computed display value to our targets.
	function syncTaxDisplay(scope) {
		if (!scope) scope = document;
		// Find a reference pair in the main product summary / page (not inside our section).
		var refOn = null, refOff = null;
		var candidates = document.querySelectorAll('.product-tax-on, .product-tax-off');
		for (var i = 0; i < candidates.length; i++) {
			var c = candidates[i];
			if (c.closest('.sijab-accessories-section')) continue; // skip our own
			if (c.classList.contains('product-tax-on') && !refOn) refOn = c;
			else if (c.classList.contains('product-tax-off') && !refOff) refOff = c;
			if (refOn && refOff) break;
		}
		var onDisplay  = refOn  ? window.getComputedStyle(refOn).display  : '';
		var offDisplay = refOff ? window.getComputedStyle(refOff).display : '';

		var onTargets  = scope.querySelectorAll('.product-tax-on');
		var offTargets = scope.querySelectorAll('.product-tax-off');
		if (refOn)  onTargets.forEach(function (el)  { el.style.display = (onDisplay  === 'none') ? 'none' : ''; });
		if (refOff) offTargets.forEach(function (el) { el.style.display = (offDisplay === 'none') ? 'none' : ''; });
	}

	// When wcvat-toggle changes the page tax mode, re-sync all our injected variant prices.
	function syncAllChecklistTax() {
		document.querySelectorAll('.sijab-acc-card--checklist .sijab-acc-card__price').forEach(function (priceEl) {
			if (priceEl.querySelector('.product-tax-on, .product-tax-off')) {
				syncTaxDisplay(priceEl);
			}
		});
	}

	// ── Checklist: variable product variant selector ──
	// When a variant is selected, populate the checkbox's data attrs and enable it.
	document.addEventListener('change', function (e) {
		var select = e.target.closest('.sijab-checklist__var-select');
		if (!select) return;

		var row  = select.closest('.sijab-acc-card--checklist');
		if (!row) return;
		var cb   = row.querySelector('.sijab-checklist__input');
		var opt  = select.options[select.selectedIndex];
		var varId = select.value;
		var purchasable = varId && opt && opt.getAttribute('data-purchasable') === '1';

		// Update checkbox data attrs for total calc + add-to-cart.
		if (cb) {
			if (purchasable) {
				cb.disabled = false;
				cb.setAttribute('data-variation_id', varId);
				cb.setAttribute('data-price-excl', opt.getAttribute('data-price-excl') || '0');
				cb.setAttribute('data-price-incl', opt.getAttribute('data-price-incl') || '0');
				cb.setAttribute('data-variation-attributes', opt.getAttribute('data-attributes') || '{}');
			} else {
				cb.disabled = true;
				cb.checked = false;
				cb.setAttribute('data-variation_id', '');
				cb.setAttribute('data-price-excl', '0');
				cb.setAttribute('data-price-incl', '0');
			}
		}

		// Update row price display + stock badge.
		var priceEl = row.querySelector('.sijab-acc-card__price');
		var priceHtml = opt ? opt.getAttribute('data-price-html') : '';
		if (priceEl && priceHtml) {
			priceEl.innerHTML = priceHtml;
			// wcvat-toggle has already set display state on existing .product-tax-on/off
			// elements on the page. Mirror that state into our injected HTML.
			syncTaxDisplay(priceEl);
		}

		var stockEl = row.querySelector('.sijab-acc-card__stock');
		var stockStatus = opt ? opt.getAttribute('data-stock') || '' : '';
		var stockLabel  = opt ? opt.getAttribute('data-stock-label') || '' : '';
		if (stockEl) {
			stockEl.className = 'sijab-acc-card__stock' + (stockStatus ? ' sijab-acc-card__stock--' + stockStatus : '');
			stockEl.textContent = stockLabel;
		}

		// Recalc total in case this row was already checked.
		updateChecklistTotal();
	});

	// ── Bundle add-to-cart: ONE request with main + all checked accessories ──
	// Server-side adds items sequentially in the same PHP process → no race
	// conditions on WC cart-session, and only a single round-trip.
	function buildBundleItems(form) {
		var items = [];

		// 1) Main product from form.cart fields.
		// NOTE: FormData(form) does NOT include submit button name/value unless that
		// button was the submitter. With form.requestSubmit() (no submitter arg),
		// the `add-to-cart` name/value on <button> is missing → read it manually.
		var fd = new FormData(form);
		var mainPid = parseInt(fd.get('add-to-cart') || fd.get('product_id') || 0, 10) || 0;
		if (!mainPid) {
			var atcBtn = form.querySelector('button[name="add-to-cart"], input[name="add-to-cart"]');
			if (atcBtn && atcBtn.value) mainPid = parseInt(atcBtn.value, 10) || 0;
		}
		// Fallback to global product id exposed by PHP via sijabAccStats.
		if (!mainPid && typeof sijabAccStats !== 'undefined' && sijabAccStats.parent_id) {
			mainPid = parseInt(sijabAccStats.parent_id, 10) || 0;
		}
		var mainVarId = parseInt(fd.get('variation_id') || 0, 10) || 0;
		var mainQty = parseInt(fd.get('quantity') || 1, 10) || 1;

		var mainAttrs = {};
		fd.forEach(function (v, k) {
			if (typeof k === 'string' && k.indexOf('attribute_') === 0) {
				mainAttrs[k] = v;
			}
		});

		if (mainPid) {
			items.push({
				product_id:   mainPid,
				variation_id: mainVarId,
				quantity:     mainQty,
				attributes:   mainAttrs
				// no parent_id on main → not tagged as accessory
			});
		}

		// 2) All checked accessories.
		var parentId = (typeof sijabAccStats !== 'undefined' && sijabAccStats.parent_id)
			? parseInt(sijabAccStats.parent_id, 10)
			: mainPid;

		document.querySelectorAll('.sijab-checklist__input:checked').forEach(function (cb) {
			var pid = parseInt(cb.getAttribute('data-product_id'), 10);
			if (!pid) return;

			var item = {
				product_id: pid,
				quantity:   1,
				parent_id:  parentId
			};

			if (cb.getAttribute('data-is-variable') === '1') {
				var varId = parseInt(cb.getAttribute('data-variation_id'), 10) || 0;
				if (varId) {
					item.variation_id = varId;
					try {
						item.attributes = JSON.parse(cb.getAttribute('data-variation-attributes') || '{}');
					} catch (err) {}
				}
			}

			items.push(item);
			trackEvent(pid, 'add_to_cart');

			// Install line-item (v2.33.0): if the customer selected "Jag vill
			// ha hjälp med montering…" on this accessory, push an extra item
			// with an `install` envelope. Server looks up the admin-configured
			// tier+price for (main, accessory) and appends an ARB cart line.
			var card = cb.closest('.sijab-acc-card');
			if (typeof window.sijabGetInstallItem === 'function') {
				var instItem = window.sijabGetInstallItem(pid, parentId, card);
				if (instItem) items.push(instItem);
			}

			// If customer confirmed companions for this accessory via popup,
			// add them to the batch too. parent_id is still the main product.
			if (window.sijabPendingCompanions && window.sijabPendingCompanions[pid]) {
				window.sijabPendingCompanions[pid].forEach(function (comp) {
					if (!comp || !comp.id) return;
					// Don't duplicate if the companion is already a checked accessory.
					var dup = items.some(function (it) { return it.product_id === comp.id && !it.variation_id; });
					if (dup) return;
					items.push({
						product_id: comp.id,
						quantity:   comp.qty || 1,
						parent_id:  parentId
					});
					trackEvent(comp.id, 'add_to_cart');
				});
			}
		});

		return items;
	}

	// Perform the batch add-to-cart flow. Shared by (a) native form.cart submit
	// when any accessory is checked, and (b) the dedicated cards-layout CTA which
	// always uses this path so main product is included reliably (form.requestSubmit()
	// without a submitter does NOT include the add-to-cart button's name/value).
	function runBundleFlow(form) {
		// Disable submit button (and CTA if present) to prevent double-clicks.
		var btn = form.querySelector('[type="submit"]');
		var cta = document.querySelector('.kr-bundle-cta');
		var origOpacity = btn ? btn.style.opacity : '';
		var origCtaText = cta ? cta.textContent : '';
		if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
		if (cta) {
			cta.disabled = true;
			cta.classList.add('kr-bundle-cta--loading');
			cta.textContent = 'Lägger till…';
		}

		function restoreBtn() {
			if (btn) { btn.disabled = false; btn.style.opacity = origOpacity; }
			if (cta) {
				cta.disabled = false;
				cta.classList.remove('kr-bundle-cta--loading');
				// Let updateCtaLabel recompute the correct label based on current
				// checkbox state (after success, accessories are unchecked → default
				// label; after failure, they're still checked → bundle label).
				if (typeof updateCtaLabel === 'function') updateCtaLabel();
				else cta.textContent = origCtaText;
			}
		}

		var items = buildBundleItems(form);
		if (!items.length) { restoreBtn(); return; }

		var ajaxUrl = (typeof sijabAccStats !== 'undefined' && sijabAccStats.ajax_url)
			? sijabAccStats.ajax_url
			: '/wp-admin/admin-ajax.php';

		var body = new FormData();
		body.append('action', 'sijab_bundle_add_to_cart');
		body.append('items', JSON.stringify(items));

		fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json().catch(function () { return {}; }); })
			.then(function (res) {
				if (res && res.success) {
					var data = res.data || {};
					if (window.jQuery) {
						var $ = window.jQuery;
						$(document.body).trigger('wc_fragment_refresh');
						$(document.body).trigger('added_to_cart', [data.fragments, data.cart_hash, $(btn || cta)]);
					}
					// Uncheck accessories so a second click doesn't re-add them.
					document.querySelectorAll('.sijab-checklist__input:checked').forEach(function (cb) {
						cb.checked = false;
						syncCardActiveState(cb);
					});
					if (typeof updateChecklistTotal === 'function') updateChecklistTotal();
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : 'Kunde inte lägga till i varukorgen';
					if (cta) cta.textContent = msg;
					else if (btn && btn.tagName === 'BUTTON') btn.textContent = msg;
					setTimeout(restoreBtn, 2500);
					return;
				}
				restoreBtn();
			})
			.catch(function () {
				restoreBtn();
			});
	}

	// Native form.cart submit: hijack ONLY when accessories are checked.
	// With 0 accessories, let WooCommerce handle submit natively (works correctly
	// because the real submit button is the submitter → add-to-cart name/value included).
	document.addEventListener('submit', function (e) {
		var form = e.target.closest('form.cart');
		if (!form) return;
		var checked = document.querySelectorAll('.sijab-checklist__input:checked');
		if (!checked.length) return;
		e.preventDefault();
		runBundleFlow(form);
	});

	// ── Checklist total: live-updated sum of main product (× qty) + checked accessories ──
	function formatPrice(amount, totalBox) {
		var decimals = parseInt(totalBox.getAttribute('data-decimals'), 10);
		if (isNaN(decimals)) decimals = 2;
		var decSep  = totalBox.getAttribute('data-dec-sep') || ',';
		var thouSep = totalBox.getAttribute('data-thou-sep') || ' ';
		var currency = totalBox.getAttribute('data-currency') || 'kr';

		var fixed = (Math.round(amount * Math.pow(10, decimals)) / Math.pow(10, decimals)).toFixed(decimals);
		var parts = fixed.split('.');
		// Insert thousands separator.
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thouSep);
		var numStr = parts.length === 2 ? parts[0] + decSep + parts[1] : parts[0];
		// Match WooCommerce wc_price() output: <span class="woocommerce-Price-amount amount"><bdi>NN,NN&nbsp;<span class="woocommerce-Price-currencySymbol">kr</span></bdi></span>
		return '<span class="woocommerce-Price-amount amount"><bdi>' + numStr + '&nbsp;<span class="woocommerce-Price-currencySymbol">' + currency + '</span></bdi></span>';
	}

	function getMainQty() {
		var qtyInput = document.querySelector('form.cart input.qty, form.cart input[name="quantity"]');
		if (!qtyInput) return 1;
		var val = parseInt(qtyInput.value, 10);
		return (isNaN(val) || val < 1) ? 1 : val;
	}

	function isVisible(el) {
		if (!el) return false;
		if (el.offsetParent === null) return false;
		var cs = window.getComputedStyle(el);
		if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) return false;
		return true;
	}

	function parseSwedishNumber(txt) {
		if (!txt) return NaN;
		// Keep digits, comma, dot, minus. Remove thousand separator (space or nbsp).
		var cleaned = txt.replace(/[\u00A0\s]/g, '').replace(/[^\d,.\-]/g, '');
		// If both dot and comma present → dot is thousand sep, comma is decimal.
		if (cleaned.indexOf(',') !== -1 && cleaned.indexOf('.') !== -1) {
			cleaned = cleaned.replace(/\./g, '').replace(',', '.');
		} else if (cleaned.indexOf(',') !== -1) {
			cleaned = cleaned.replace(',', '.');
		}
		return parseFloat(cleaned);
	}

	// Detect current tax mode by reading the visible main product price and comparing to excl/incl.
	function detectTaxMode(totalBox) {
		var excl = parseFloat(totalBox.getAttribute('data-main-price-excl')) || 0;
		var incl = parseFloat(totalBox.getAttribute('data-main-price-incl')) || 0;
		if (excl === incl) return totalBox.getAttribute('data-tax-display') || 'excl';

		// The page may render BOTH prices (incl + excl) and toggle visibility via CSS.
		// Collect all candidates, pick the visible one.
		var candidates = document.querySelectorAll('.product .summary .price .woocommerce-Price-amount, .product .summary p.price .woocommerce-Price-amount, .product-info .price .woocommerce-Price-amount, p.price .woocommerce-Price-amount');
		var visibleNum = null;
		for (var i = 0; i < candidates.length; i++) {
			if (isVisible(candidates[i])) {
				var n = parseSwedishNumber(candidates[i].textContent);
				if (!isNaN(n)) { visibleNum = n; break; }
			}
		}

		if (visibleNum === null) return totalBox.getAttribute('data-tax-display') || 'excl';
		return Math.abs(visibleNum - incl) < Math.abs(visibleNum - excl) ? 'incl' : 'excl';
	}

	function updateChecklistTotal() {
		var totalBox = document.querySelector('.sijab-checklist__total');
		if (!totalBox) return;

		var mode      = detectTaxMode(totalBox);
		var mainAttr  = mode === 'incl' ? 'data-main-price-incl' : 'data-main-price-excl';
		var mainPrice = parseFloat(totalBox.getAttribute(mainAttr)) || 0;

		var qty = getMainQty();
		var mainSum = mainPrice * qty;
		var accSum = 0;

		var priceAttr = mode === 'incl' ? 'data-price-incl' : 'data-price-excl';
		var checked = document.querySelectorAll('.sijab-checklist__input:checked');
		checked.forEach(function (cb) {
			var p = parseFloat(cb.getAttribute(priceAttr));
			// Fallback to legacy single attribute.
			if (isNaN(p)) p = parseFloat(cb.getAttribute('data-price')) || 0;
			accSum += p;
		});

		var sum = mainSum + accSum;

		var valueEl  = totalBox.querySelector('.sijab-checklist__total-value');
		var suffixEl = totalBox.querySelector('.sijab-checklist__total-suffix');
		if (valueEl) valueEl.innerHTML = formatPrice(sum, totalBox);
		if (suffixEl) {
			var lbl = mode === 'incl' ? totalBox.getAttribute('data-label-incl') : totalBox.getAttribute('data-label-excl');
			suffixEl.textContent = lbl || '';
		}

		// Cards-layout breakdown rows (only present when layout='cards').
		var productCell = totalBox.querySelector('.kr-bundle-total__product');
		if (productCell) productCell.innerHTML = formatPrice(mainSum, totalBox);
		var accCell = totalBox.querySelector('.kr-bundle-total__accessories');
		if (accCell) accCell.innerHTML = formatPrice(accSum, totalBox);

		// Cards-layout CTA: dynamic label + empty-state on summary box.
		updateCtaLabel();
	}

	// Update the cards-layout CTA button label based on how many accessories
	// are checked. 0 → "Lägg i varukorgen" (standard single-product add). 1+ →
	// "Lägg paket i varukorgen (X produkter)" where X = 1 main + N accessories.
	// Also toggles data-empty on .kr-bundle-summary so CSS can hide the
	// meaningless "0 kr tillbehör / Totalt == huvudpris" rows.
	function updateCtaLabel() {
		var cta = document.querySelector('.kr-bundle-cta');
		if (!cta) return;
		// Don't stomp on the loading label mid-request.
		if (cta.classList.contains('kr-bundle-cta--loading')) return;

		var checked = document.querySelectorAll('.sijab-checklist__input:checked');
		var n = checked.length;

		var labelDefault  = cta.getAttribute('data-label-default')  || 'Lägg i varukorgen';
		var labelBundle   = cta.getAttribute('data-label-bundle')   || 'Lägg paket i varukorgen';
		var labelProducts = cta.getAttribute('data-label-products') || 'produkter';

		if (n === 0) {
			cta.textContent = labelDefault;
		} else {
			// Total = 1 main product + N accessories.
			var total = 1 + n;
			cta.textContent = labelBundle + ' (' + total + ' ' + labelProducts + ')';
		}

		var summary = document.querySelector('.kr-bundle-summary');
		if (summary) {
			if (n === 0) summary.setAttribute('data-empty', '1');
			else summary.removeAttribute('data-empty');
		}
	}

	// ── Cards layout: toggle checkbox on card click + keep .kr-card--active in sync ──
	function syncCardActiveState(cb) {
		var card = cb.closest('.kr-card');
		if (!card) return;
		card.classList.toggle('kr-card--active', !!cb.checked);
	}

	document.addEventListener('click', function (e) {
		var card = e.target.closest('.kr-card');
		if (!card) return;
		if (card.classList.contains('kr-card--disabled')) return;

		// Don't hijack clicks on interactive children — they handle themselves.
		if (e.target.closest('select, option, a, input, button, label')) return;

		var cb = card.querySelector('.kr-card__input');
		if (!cb || cb.disabled) return;

		cb.checked = !cb.checked;
		// Dispatch change so existing listeners (updateChecklistTotal) fire.
		cb.dispatchEvent(new Event('change', { bubbles: true }));
	});

	// Sync active class whenever a .kr-card__input changes (from any source).
	document.addEventListener('change', function (e) {
		if (e.target && e.target.classList && e.target.classList.contains('kr-card__input')) {
			syncCardActiveState(e.target);
		}
	});

	// Cards layout: dedicated CTA button.
	// ALWAYS goes through runBundleFlow so main product is included even when no
	// accessories are checked. (form.requestSubmit() would NOT include the submit
	// button's name/value, causing WC to drop the main product.)
	document.addEventListener('click', function (e) {
		var cta = e.target.closest('.kr-bundle-cta');
		if (!cta || cta.disabled) return;
		e.preventDefault();
		var form = document.querySelector('form.cart');
		if (!form) return;
		runBundleFlow(form);
	});

	// Watch for tax toggle flip. Many plugins toggle a class on <body> or <html>,
	// which changes CSS visibility of dual price elements without mutating DOM.
	// So we observe attribute changes on body/html AND listen for clicks on likely toggle links.
	function observeMainPrice() {
		if (typeof MutationObserver === 'undefined') return;

		var priceWrap = document.querySelector('.product .summary .price, .product .summary p.price, .product-info .price');
		if (priceWrap) {
			// Watch BOTH childList/characterData AND attribute changes on descendants.
			// Some tax-toggle plugins (e.g. wcvat-toggle) swap `.product-tax-on`/`.product-tax-off`
			// classes on `.amount` parent elements to switch visibility — that's an attribute change.
			new MutationObserver(function () {
				updateChecklistTotal();
				syncAllChecklistTax();
			}).observe(priceWrap, {
				childList: true,
				subtree: true,
				characterData: true,
				attributes: true,
				attributeFilter: ['class', 'style']
			});
		}

		// Watch body + html class/attribute changes.
		var attrObs = new MutationObserver(function () {
			// Defer to next frame so CSS has applied.
			requestAnimationFrame(function () {
				updateChecklistTotal();
				syncAllChecklistTax();
			});
		});
		attrObs.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-tax-display'] });
		attrObs.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-tax-display'] });

		// Watch every .product-tax element (wcvat-toggle pattern) directly so class swaps are caught.
		var taxEls = document.querySelectorAll('.product-tax, .product-tax-on, .product-tax-off');
		taxEls.forEach(function (el) {
			if (el.closest('.sijab-accessories-section')) return; // skip our injected ones (mirror only)
			new MutationObserver(function () {
				requestAnimationFrame(function () {
					updateChecklistTotal();
					syncAllChecklistTax();
				});
			}).observe(el, { attributes: true, attributeFilter: ['class', 'style'] });
		});

		// Delegated click on anything that looks like a tax toggle.
		document.addEventListener('click', function (e) {
			var t = e.target;
			if (!t) return;
			var txt = (t.textContent || '').toLowerCase();
			var href = (t.getAttribute && t.getAttribute('href')) || '';
			if (
				/\b(inkl|exkl|incl|excl)\b.*(moms|vat|tax)/i.test(txt) ||
				/\b(moms|vat|tax)\b/i.test(txt) && /\b(inkl|exkl|incl|excl|toggle)\b/i.test(txt + ' ' + href) ||
				t.classList && (t.classList.contains('tax-toggle') || t.classList.contains('tax-switch'))
			) {
				// Recheck a few times to catch post-click class change.
				setTimeout(updateChecklistTotal, 50);
				setTimeout(updateChecklistTotal, 250);
				setTimeout(updateChecklistTotal, 700);
			}
		}, true);

		// Fallback safety net: also react to custom events some plugins emit.
		['tax-toggle-changed', 'wc-tax-display-changed', 'prices-toggled'].forEach(function (name) {
			document.addEventListener(name, updateChecklistTotal);
			window.addEventListener(name, updateChecklistTotal);
		});
	}

	// Update on checkbox change.
	document.addEventListener('change', function (e) {
		if (e.target && e.target.classList && e.target.classList.contains('sijab-checklist__input')) {
			updateChecklistTotal();
		}
	});

	// Update on main product qty change.
	document.addEventListener('input', function (e) {
		if (e.target && e.target.matches && e.target.matches('form.cart input.qty, form.cart input[name="quantity"]')) {
			updateChecklistTotal();
		}
	});
	document.addEventListener('change', function (e) {
		if (e.target && e.target.matches && e.target.matches('form.cart input.qty, form.cart input[name="quantity"]')) {
			updateChecklistTotal();
		}
	});

	// ─────────────────────────────────────────────────────────────────
	// Companion-popup: when an accessory with configured requirements is
	// checked, show a modal that tells the customer which product they also
	// need (e.g. flödesmätare needs adapter). Stored per-session so if the
	// customer already dismissed it for this accessory, it won't re-appear.
	// ─────────────────────────────────────────────────────────────────

	window.sijabPendingCompanions = window.sijabPendingCompanions || {};
	var sessionDismissedCompanions = {};  // accessory_id -> true

	// Render a single product row inside the popup. `role` controls the small
	// badge displayed on the right (main=Huvudprodukt, self=Ditt val, required=Krävs för att passa).
	function buildCompanionRow(product, role) {
		var li = document.createElement('li');
		li.className = 'sijab-companion-modal__item sijab-companion-modal__item--' + role;

		var img = document.createElement('img');
		img.src = product.image || '';
		img.className = 'sijab-companion-modal__img';
		img.alt = product.name;

		var info = document.createElement('div');
		info.className = 'sijab-companion-modal__info';

		var name = document.createElement('div');
		name.className = 'sijab-companion-modal__name';
		name.textContent = product.name;
		info.appendChild(name);

		var meta = document.createElement('div');
		meta.className = 'sijab-companion-modal__meta';
		var metaHtml = (product.price_html || '');
		if (product.stock_label) {
			if (metaHtml) metaHtml += ' &middot; ';
			metaHtml += '<span class="sijab-companion-modal__stock sijab-companion-modal__stock--' +
				(product.stock_status || '') + '">' + product.stock_label + '</span>';
		}
		meta.innerHTML = metaHtml;
		info.appendChild(meta);

		var badgeLabels = {
			'main':     'Huvudprodukt',
			'self':     'Ditt val',
			'required': 'Krävs för att passa'
		};
		var badgeText = badgeLabels[role] || '';
		if (badgeText) {
			var badge = document.createElement('span');
			badge.className = 'sijab-companion-modal__badge sijab-companion-modal__badge--' + role;
			badge.textContent = badgeText;
			info.appendChild(badge);
		}

		li.appendChild(img);
		li.appendChild(info);
		return li;
	}

	function buildCompanionModal(accessoryName, mainProductName, companions, onAccept, onReject, opts) {
		opts = opts || {};

		// Remove any existing modal first
		var existing = document.querySelector('.sijab-companion-modal-overlay');
		if (existing) existing.remove();

		var overlay = document.createElement('div');
		overlay.className = 'sijab-companion-modal-overlay';

		var modal = document.createElement('div');
		modal.className = 'sijab-companion-modal';

		var title = document.createElement('div');
		title.className = 'sijab-companion-modal__title';
		title.textContent = 'För att ' + accessoryName + ' ska passa' + (mainProductName ? ' på ' + mainProductName : '') + ' krävs:';

		var list = document.createElement('ul');
		list.className = 'sijab-companion-modal__list';

		// Optional: main product row (horizontal-layout popup only — signals that
		// the tank will be auto-added to cart if not already there).
		if (opts.mainInfo) {
			list.appendChild(buildCompanionRow(opts.mainInfo, 'main'));
		}

		// Optional: the accessory the customer clicked/checked — so they see
		// exactly what's being added, not just the required companion.
		if (opts.selfInfo) {
			list.appendChild(buildCompanionRow(opts.selfInfo, 'self'));
		}

		companions.forEach(function (c) {
			list.appendChild(buildCompanionRow(c, 'required'));
		});

		var actions = document.createElement('div');
		actions.className = 'sijab-companion-modal__actions';

		var btnAccept = document.createElement('button');
		btnAccept.type = 'button';
		btnAccept.className = 'sijab-companion-modal__btn sijab-companion-modal__btn--primary';
		btnAccept.textContent = 'Lägg till alla';
		btnAccept.addEventListener('click', function () {
			overlay.remove();
			onAccept && onAccept();
		});

		var btnReject = document.createElement('button');
		btnReject.type = 'button';
		btnReject.className = 'sijab-companion-modal__btn sijab-companion-modal__btn--secondary';
		btnReject.textContent = 'Endast ' + accessoryName;
		btnReject.addEventListener('click', function () {
			overlay.remove();
			onReject && onReject();
		});

		actions.appendChild(btnAccept);
		actions.appendChild(btnReject);

		modal.appendChild(title);
		modal.appendChild(list);
		modal.appendChild(actions);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);

		// Click outside OR Esc = same as "Endast X"
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) { overlay.remove(); onReject && onReject(); }
		});
		document.addEventListener('keydown', function escHandler(e) {
			if (e.key === 'Escape') {
				overlay.remove();
				document.removeEventListener('keydown', escHandler);
				onReject && onReject();
			}
		});
	}

	// Horizontal/grid/compact layouts: intercept the per-accessory "LÄGG TILL"
	// button click so the popup can show. If customer accepts "Lägg till båda",
	// both products are added via the bundle-atomic AJAX endpoint (one round-trip,
	// no race). If they reject, the original AJAX add proceeds for just the accessory.
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.sijab-acc-atc-btn[data-has-companions="1"]');
		if (!btn) return;

		// Bypass flag set by the "Endast accessory" branch → let WC AJAX run normally
		if (btn.getAttribute('data-sijab-bypass-popup') === '1') {
			btn.removeAttribute('data-sijab-bypass-popup');
			return;
		}

		var accId = parseInt(btn.getAttribute('data-product_id'), 10);
		var mainId = parseInt(btn.getAttribute('data-main-product'), 10);
		if (!accId || !mainId) return;

		// Dismissal: user already chose "Endast accessory" for this acc in this session
		if (sessionDismissedCompanions[accId]) return;

		var mainMap = (window.sijabCompanions || {})[mainId] || {};
		var companions = mainMap[accId] || [];
		if (!companions.length) return;

		// Prevent WC's default AJAX add-to-cart so we can show the popup first.
		e.preventDefault();
		e.stopPropagation();

		// Extract accessory info from its card DOM for the popup row.
		var selfInfo = extractAccessoryInfo(btn);
		var accessoryName = selfInfo.name || 'tillbehöret';

		var h1 = document.querySelector('.product .summary h1, .product_title, h1.product_title');
		var mainProductName = h1 ? h1.textContent.trim() : '';

		// Determine if we can include the main product.
		// - Simple product: yes, always include (skip_if_in_cart handles dedup).
		// - Variable product: only include if the customer has selected a variation;
		//   otherwise we'd silently fail (WC rejects add_to_cart of variable product
		//   without variation_id) and the tank would be missing from cart.
		var mainInfo = (window.sijabMainProductInfo || {})[mainId] || null;
		var canIncludeMain = true;
		var form = document.querySelector('form.cart');
		if (form && form.classList.contains('variations_form')) {
			var varIdInput = form.querySelector('input[name="variation_id"]');
			var varId = varIdInput ? parseInt(varIdInput.value, 10) || 0 : 0;
			if (!varId) {
				canIncludeMain = false;  // variable but not chosen → don't try to add main
			} else if (mainInfo) {
				// Enrich mainInfo with the selected variation's details so the popup
				// row shows the exact variant name, price and stock — not just the
				// parent product's "Från X kr" base-price string.
				mainInfo = enrichMainInfoWithVariation(mainInfo, form, varId);
			}
		}
		var mainInfoForPopup = canIncludeMain ? mainInfo : null;

		buildCompanionModal(accessoryName, mainProductName, companions,
			// Accept: atomic bundle add-to-cart (accessory + all companions)
			function () {
				sendHorizontalBundleAdd(accId, companions, mainId, canIncludeMain);
			},
			// Reject: remember dismissal and let the original WC AJAX add just the accessory
			function () {
				sessionDismissedCompanions[accId] = true;
				btn.setAttribute('data-sijab-bypass-popup', '1');
				btn.click();
			},
			{ selfInfo: selfInfo, mainInfo: mainInfoForPopup }
		);
	}, true);

	// When the main product is variable and a variation is chosen, upgrade the
	// main-product info (originally the parent's "Från X kr" base-price string)
	// with the variation's actual name, price and stock. Data comes from
	// form.variations_form[data-product_variations] which WC injects with the
	// full variations array.
	function enrichMainInfoWithVariation(baseInfo, form, variationId) {
		try {
			var raw = form.getAttribute('data-product_variations');
			if (!raw) return baseInfo;
			var variations = JSON.parse(raw);
			if (!Array.isArray(variations)) return baseInfo;
			var match = null;
			for (var i = 0; i < variations.length; i++) {
				if (parseInt(variations[i].variation_id, 10) === variationId) { match = variations[i]; break; }
			}
			if (!match) return baseInfo;

			// Build a label from the chosen attribute values.
			var attrs = match.attributes || {};
			var labels = [];
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (!v) return;
				// attribute value is usually a slug (e.g. "utan-lock") — prettify.
				labels.push(String(v).replace(/-/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }));
			});
			var variantLabel = labels.join(' / ');

			// Clone to avoid mutating the global mainInfo.
			var enriched = Object.assign({}, baseInfo);
			if (variantLabel) {
				enriched.name = baseInfo.name + ' — ' + variantLabel;
			}
			if (match.price_html) enriched.price_html = match.price_html;
			if (match.is_in_stock) {
				enriched.stock_label  = enriched.stock_label || 'I lager';
				enriched.stock_status = 'instock';
			} else {
				enriched.stock_label  = 'Slut i lager';
				enriched.stock_status = 'outofstock';
			}
			if (match.image && match.image.src) {
				enriched.image = match.image.src;
			}
			return enriched;
		} catch (err) {
			return baseInfo;
		}
	}

	// Pull name/image/price_html/stock from an accessory's card DOM. Used to
	// render the "Ditt val"-row in the popup — we don't want to round-trip
	// server-side for data that's already visible on the page.
	function extractAccessoryInfo(el) {
		var card = el.closest('.sijab-acc-card, .kr-card');
		if (!card) return { name: '', image: '', price_html: '', stock_label: '', stock_status: '' };
		var nameEl   = card.querySelector('.sijab-acc-card__name, .kr-card__name');
		var imgEl    = card.querySelector('img');
		var priceEl  = card.querySelector('.sijab-acc-card__price, .kr-card__price');
		var stockEl  = card.querySelector('.sijab-acc-card__stock, .kr-card__stock');
		// Extract stock_status from class like "sijab-acc-card__stock--instock"
		var stockStatus = '';
		if (stockEl) {
			var m = stockEl.className.match(/sijab-acc-card__stock--(\w+)/);
			if (m) stockStatus = m[1];
		}
		return {
			name:         nameEl ? nameEl.textContent.trim() : '',
			image:        imgEl ? (imgEl.currentSrc || imgEl.src) : '',
			price_html:   priceEl ? priceEl.innerHTML : '',
			stock_label:  stockEl ? stockEl.textContent.trim() : '',
			stock_status: stockStatus
		};
	}

	function sendHorizontalBundleAdd(accId, companions, mainId, includeMain) {
		var ajaxUrl = (typeof sijabAccStats !== 'undefined' && sijabAccStats.ajax_url)
			? sijabAccStats.ajax_url
			: '/wp-admin/admin-ajax.php';

		var items = [];
		// Main product — only added if includeMain is true AND not already in
		// cart (server-side skip_if_in_cart check). For variable products we MUST
		// pass variation_id + attributes, otherwise WC's add_to_cart rejects it
		// silently and the tank disappears from cart. Reading from form.cart
		// captures the customer's currently-selected variation.
		if (mainId && includeMain) {
			var mainItem = { product_id: mainId, quantity: 1, skip_if_in_cart: true };
			var form = document.querySelector('form.cart');
			if (form) {
				var fd = new FormData(form);
				var variationId = parseInt(fd.get('variation_id') || 0, 10) || 0;
				if (variationId) {
					mainItem.variation_id = variationId;
					var attrs = {};
					fd.forEach(function (v, k) {
						if (typeof k === 'string' && k.indexOf('attribute_') === 0) attrs[k] = v;
					});
					mainItem.attributes = attrs;
				}
			}
			items.push(mainItem);
		}
		items.push({ product_id: accId, quantity: 1, parent_id: mainId });
		companions.forEach(function (c) {
			items.push({ product_id: c.id, quantity: c.qty || 1, parent_id: mainId });
		});

		// Install line-item (v2.33.0): look up the accessory's card on the page
		// and, if its install radio is set to "yes", append the install envelope
		// so server adds the ARB line alongside the accessory.
		if (typeof window.sijabGetInstallItem === 'function') {
			var accCard = document.querySelector('.sijab-acc-card[data-accessory-id="' + accId + '"]');
			var instItem = window.sijabGetInstallItem(accId, mainId, accCard);
			if (instItem) items.push(instItem);
		}

		var body = new FormData();
		body.append('action', 'sijab_bundle_add_to_cart');
		body.append('items', JSON.stringify(items));

		fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json().catch(function () { return {}; }); })
			.then(function (res) {
				if (res && res.success) {
					if (window.jQuery) {
						var $ = window.jQuery;
						$(document.body).trigger('wc_fragment_refresh');
						$(document.body).trigger('added_to_cart', [(res.data && res.data.fragments) || {}, (res.data && res.data.cart_hash) || '', $('body')]);
					}
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : 'Kunde inte lägga till i varukorgen';
					alert(msg);
				}
			})
			.catch(function () { alert('Nätverksfel. Försök igen.'); });
	}

	// Listen for checkbox changes to trigger the popup.
	document.addEventListener('change', function (e) {
		var cb = e.target;
		if (!cb || !cb.classList || !cb.classList.contains('sijab-checklist__input')) return;
		if (!cb.checked) {
			// Unchecked: forget any pending companion promise for this accessory
			var accId = parseInt(cb.getAttribute('data-product_id'), 10);
			if (accId && window.sijabPendingCompanions[accId]) {
				delete window.sijabPendingCompanions[accId];
			}
			return;
		}

		// Checked → maybe show popup
		if (cb.getAttribute('data-has-companions') !== '1') return;

		var accId = parseInt(cb.getAttribute('data-product_id'), 10);
		var mainId = parseInt(cb.getAttribute('data-main-product'), 10);
		if (!accId || !mainId) return;

		// Already dismissed in this session? Don't re-prompt.
		if (sessionDismissedCompanions[accId]) return;

		var mainMap = (window.sijabCompanions || {})[mainId] || {};
		var companions = mainMap[accId] || [];
		if (!companions.length) return;

		// If the companion is already checked as its own accessory, skip popup.
		var allAlreadyChecked = companions.every(function (c) {
			return !!document.querySelector('.sijab-checklist__input[data-product_id="' + c.id + '"]:checked');
		});
		if (allAlreadyChecked) return;

		// Build accessory info from its card DOM for the popup.
		var selfInfo = extractAccessoryInfo(cb);
		var accessoryName = selfInfo.name || 'tillbehöret';

		// Main product name from the page H1 (approximate — good enough for UX).
		var h1 = document.querySelector('.product .summary h1, .product_title, h1.product_title');
		var mainProductName = h1 ? h1.textContent.trim() : '';

		buildCompanionModal(accessoryName, mainProductName, companions,
			// Accept: remember companions for cart-add, check the companion's own accessory checkbox if visible
			function () {
				window.sijabPendingCompanions[accId] = companions.map(function (c) { return { id: c.id, qty: c.qty || 1 }; });
				// Also try to check the companion's accessory checkbox (so it shows in totals).
				companions.forEach(function (c) {
					var compCb = document.querySelector('.sijab-checklist__input[data-product_id="' + c.id + '"]');
					if (compCb && !compCb.checked && !compCb.disabled) {
						compCb.checked = true;
						compCb.dispatchEvent(new Event('change', { bubbles: true }));
					}
				});
				updateChecklistTotal();
			},
			// Reject: remember dismissal so same accessory doesn't re-prompt this session
			function () {
				sessionDismissedCompanions[accId] = true;
				// Do NOT uncheck the accessory — customer decided "just this".
			},
			// opts: show the accessory as "Ditt val"-row. No main row — cards/checklist
			// adds the main product via the separate bundle CTA, not via the popup.
			{ selfInfo: selfInfo }
		);
	}, true);  // capture phase so we see the change before other listeners

	// ────────────────────────────────────────────────────────────────
	// Installation radios (v2.33.0)
	//
	// Admin configures on the main product which accessories can be
	// installed by staff, and at what tier (Liten 50 % / Stor 100 % / Eget).
	// PHP emits window.sijabInstallations = { mainId: { accId: {...} } }.
	// We scan accessory cards on the page and inject a 2-radio UI:
	//   ( ) Ingen montering
	//   ( ) Jag vill ha hjälp med montering av <accessory> – <price>
	// When the customer batch-adds (cards/checklist) OR adds via the
	// popup-companion horizontal flow, an extra `{install:{...}}` item
	// is pushed to the payload so the server adds the ARB product line.
	// ────────────────────────────────────────────────────────────────

	function getInstallConfig(mainId, accId) {
		var all = window.sijabInstallations || {};
		var forMain = all[mainId] || all[String(mainId)] || {};
		return forMain[accId] || forMain[String(accId)] || null;
	}

	function buildInstallRadioGroup(mainId, accId, cfg) {
		var name = 'sijab-install-' + mainId + '-' + accId;
		var wrap = document.createElement('div');
		wrap.className = 'sijab-install-options';
		wrap.setAttribute('data-install-acc-id', accId);
		wrap.setAttribute('data-install-main-id', mainId);

		// "No install" option — default checked.
		var labelNo = document.createElement('label');
		labelNo.className = 'sijab-install-radio sijab-install-radio--no';
		var inNo = document.createElement('input');
		inNo.type = 'radio'; inNo.name = name; inNo.value = 'no'; inNo.checked = true;
		var spanNo = document.createElement('span');
		spanNo.className = 'sijab-install-label';
		spanNo.textContent = 'Ingen montering';
		labelNo.appendChild(inNo); labelNo.appendChild(spanNo);

		// "Yes install" option — dynamic text with accessory name + price.
		var labelYes = document.createElement('label');
		labelYes.className = 'sijab-install-radio sijab-install-radio--yes';
		var inYes = document.createElement('input');
		inYes.type = 'radio'; inYes.name = name; inYes.value = 'yes';
		var spanYes = document.createElement('span');
		spanYes.className = 'sijab-install-label';
		spanYes.textContent = 'Jag vill ha hjälp med montering av ' + cfg.accessory_name + ' – ' + cfg.price_formatted;
		labelYes.appendChild(inYes); labelYes.appendChild(spanYes);

		wrap.appendChild(labelNo);
		wrap.appendChild(labelYes);
		return wrap;
	}

	function injectInstallRadios() {
		var all = window.sijabInstallations || {};
		if (!all || Object.keys(all).length === 0) return;

		// Checklist/cards layout: the input carries data-product_id + data-main-product.
		document.querySelectorAll('.sijab-checklist__input[data-main-product]').forEach(function (cb) {
			var accId  = parseInt(cb.getAttribute('data-product_id'), 10);
			var mainId = parseInt(cb.getAttribute('data-main-product'), 10);
			if (!accId || !mainId) return;
			var cfg = getInstallConfig(mainId, accId);
			if (!cfg) return;
			var card = cb.closest('.sijab-acc-card');
			if (!card || card.querySelector('.sijab-install-options')) return;
			var group = buildInstallRadioGroup(mainId, accId, cfg);
			// Checklist/cards: install radio is only meaningful when the
			// accessory is actually selected. Hide until checked.
			group.classList.add('sijab-install-options--collapsed');
			if (cb.checked) group.classList.remove('sijab-install-options--collapsed');
			card.appendChild(group);
		});

		// Wire up visibility + reset-on-uncheck for checklist/cards mode.
		if (!window.sijabInstallListenerBound) {
			window.sijabInstallListenerBound = true;
			document.addEventListener('change', function (ev) {
				var cb = ev.target;
				if (!cb || !cb.classList || !cb.classList.contains('sijab-checklist__input')) return;
				var card = cb.closest('.sijab-acc-card');
				if (!card) return;
				var group = card.querySelector('.sijab-install-options');
				if (!group) return;
				if (cb.checked) {
					group.classList.remove('sijab-install-options--collapsed');
				} else {
					group.classList.add('sijab-install-options--collapsed');
					// Reset radio so an unchecked accessory doesn't trigger install.
					var noRadio = group.querySelector('input[value="no"]');
					if (noRadio) noRadio.checked = true;
				}
			});
		}

		// Horizontal / grid / compact: the LÄGG TILL button carries data-main-product
		// + data-product-id. We skip cards where the checklist injection already ran
		// (those already have .sijab-install-options).
		document.querySelectorAll('.sijab-acc-atc-btn[data-main-product]').forEach(function (btn) {
			var accId  = parseInt(btn.getAttribute('data-product-id') || btn.getAttribute('data-parent-id'), 10);
			var mainId = parseInt(btn.getAttribute('data-main-product'), 10);
			if (!accId || !mainId) {
				// Try reading from the parent card.
				var cardEl = btn.closest('.sijab-acc-card');
				if (cardEl && cardEl.hasAttribute('data-accessory-id')) {
					accId = parseInt(cardEl.getAttribute('data-accessory-id'), 10);
				}
			}
			if (!accId || !mainId) return;
			var cfg = getInstallConfig(mainId, accId);
			if (!cfg) return;
			var card = btn.closest('.sijab-acc-card');
			if (!card || card.querySelector('.sijab-install-options')) return;
			var group = buildInstallRadioGroup(mainId, accId, cfg);
			card.appendChild(group);
		});
	}

	/**
	 * For a given accessory cart-entry (checklist checkbox OR accessory card),
	 * return a payload object `{ install: { main_id, for_accessory_id } }` if
	 * the customer selected "yes" on the install radio — or null otherwise.
	 */
	function getInstallItemForAccessory(accId, mainId, card) {
		if (!card) return null;
		var group = card.querySelector('.sijab-install-options[data-install-acc-id="' + accId + '"]');
		if (!group) return null;
		var yes = group.querySelector('input[value="yes"]');
		if (!yes || !yes.checked) return null;
		return { install: { main_id: mainId, for_accessory_id: accId } };
	}

	// Expose so other functions in this IIFE can reach it.
	window.sijabGetInstallItem = getInstallItemForAccessory;

	// Init mobile toggle when DOM is ready
	function initAll() {
		initMobileToggle();
		updateChecklistTotal();
		observeMainPrice();
		injectInstallRadios();
		// Re-check a few times in case tax-toggle plugins apply visibility after our init.
		setTimeout(updateChecklistTotal, 100);
		setTimeout(updateChecklistTotal, 500);
		setTimeout(updateChecklistTotal, 1500);
		setTimeout(injectInstallRadios, 500);
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})();
