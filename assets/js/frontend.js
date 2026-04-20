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

	// Init mobile toggle when DOM is ready
	function initAll() {
		initMobileToggle();
		updateChecklistTotal();
		observeMainPrice();
		// Re-check a few times in case tax-toggle plugins apply visibility after our init.
		setTimeout(updateChecklistTotal, 100);
		setTimeout(updateChecklistTotal, 500);
		setTimeout(updateChecklistTotal, 1500);
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})();
