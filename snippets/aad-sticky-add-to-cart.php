/**
 * Sticky add-to-cart bar για σελίδες προϊόντος WooCommerce (All About Diamonds)
 *
 * - Εμφανίζεται στο κάτω μέρος όταν ο χρήστης έχει κάνει scroll πέρα από την τιμή.
 * - Απλό προϊόν: φωτογραφία, τίτλος, τιμή και κουμπί προσθήκης στο καλάθι.
 * - Προϊόν με παραλλαγές: επιπλέον μικρό dropdown με τις παραλλαγές (με τιμή δίπλα).
 * - Προϊόντα με τον Jewelry Configurator (μονόπετρα, βέρες κ.λπ.):
 *     · η τιμή της μπάρας ακολουθεί τη ζωντανή τιμή της σελίδας (μέταλλο, καράτια, χάραξη…),
 *     · το κουμπί ελέγχει πρώτα τις επιλογές· αν λείπει κάτι, πηγαίνει στο πεδίο που λείπει,
 *     · αλλιώς στέλνει στο καλάθι ΟΛΕΣ τις επιλογές (χρειάζεται Jewelry Configurator 1.5.3+).
 * - Η προσθήκη στο καλάθι γίνεται με AJAX: η σελίδα δεν ξαναφορτώνει, δεν πηγαίνει πάνω και
 *   δεν βγαίνει notification. Το mini-cart ενημερώνεται.
 * - Όλα τα κείμενα είναι οι προεπιλεγμένες φράσεις του WooCommerce.
 */

/**
 * Κρύβει το μήνυμα "... προστέθηκε στο καλάθι" στις σελίδες προϊόντων.
 * Τα μηνύματα σφάλματος (π.χ. μη επαρκές απόθεμα) συνεχίζουν να εμφανίζονται.
 * Μπαίνει στο <head> για να μη "αναβοσβήνει" πριν κρυφτεί.
 */
add_action( 'wp_head', 'aad_hide_add_to_cart_notice' );
function aad_hide_add_to_cart_notice() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	echo '<style>.single-product .woocommerce-notices-wrapper .woocommerce-message{display:none!important;}</style>';
}

add_action( 'wp_footer', 'aad_sticky_price_bar' );
function aad_sticky_price_bar() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	$product = wc_get_product( get_queried_object_id() );
	if ( ! $product ) {
		return;
	}

	$is_variable = $product->is_type( 'variable' );
	$variations  = array();

	if ( $is_variable ) {
		foreach ( $product->get_available_variations() as $v ) {
			$variation = wc_get_product( $v['variation_id'] );
			if ( ! $variation ) {
				continue;
			}

			$name  = wc_get_formatted_variation( $variation, true, false, false );
			$price = html_entity_decode( wp_strip_all_tags( wc_price( $v['display_price'] ) ), ENT_QUOTES, 'UTF-8' );
			$label = $name . ' — ' . $price;
			if ( ! $v['is_in_stock'] ) {
				$label .= ' (' . __( 'Out of stock', 'woocommerce' ) . ')';
			}

			$variations[] = array(
				'id'         => (int) $v['variation_id'],
				'label'      => $label,
				'available'  => (bool) ( $v['is_in_stock'] && $v['is_purchasable'] ),
				'attributes' => $v['attributes'],
				'price_html' => $v['price_html'] ? $v['price_html'] : $variation->get_price_html(),
				'thumb'      => isset( $v['image']['gallery_thumbnail_src'] ) ? $v['image']['gallery_thumbnail_src'] : '',
			);
		}

		$action = $variations ? 'variable' : 'none';
		$label  = $variations ? __( 'Add to cart', 'woocommerce' ) : __( 'Out of stock', 'woocommerce' );
	} elseif ( $product->is_purchasable() && $product->is_in_stock() ) {
		$action = 'add';
		$label  = $product->single_add_to_cart_text();
	} else {
		$action = 'none';
		$label  = __( 'Out of stock', 'woocommerce' );
	}
	?>
	<div id="aad-sticky-bar" class="aad-sticky-bar<?php echo $is_variable ? ' is-variable' : ''; ?>" aria-hidden="true">
		<div class="aad-sticky-inner">
			<div class="aad-sticky-thumb"><?php echo $product->get_image( 'woocommerce_gallery_thumbnail' ); ?></div>

			<div class="aad-sticky-info">
				<span class="aad-sticky-title"><?php echo esc_html( $product->get_name() ); ?></span>
				<span class="aad-sticky-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
			</div>

			<div class="aad-sticky-actions">
				<?php if ( $is_variable && $variations ) : ?>
					<select class="aad-sticky-select" aria-label="<?php echo esc_attr( __( 'Choose an option', 'woocommerce' ) ); ?>">
						<option value=""><?php echo esc_html( __( 'Choose an option', 'woocommerce' ) ); ?></option>
						<?php foreach ( $variations as $v ) : ?>
							<option value="<?php echo esc_attr( $v['id'] ); ?>" <?php disabled( ! $v['available'] ); ?>>
								<?php echo esc_html( $v['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>

				<button type="button"
					class="aad-sticky-btn"
					data-action="<?php echo esc_attr( $action ); ?>"
					data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
					data-url="<?php echo esc_url( $product->add_to_cart_url() ); ?>"
					data-ajax="<?php echo esc_url( WC_AJAX::get_endpoint( 'add_to_cart' ) ); ?>"
					<?php disabled( $action, 'none' ); ?>>
					<?php echo esc_html( $label ); ?>
				</button>
			</div>
		</div>
	</div>

	<style>
		.aad-sticky-bar {
			position: fixed;
			left: 0;
			right: 0;
			bottom: 0;
			z-index: 9999;
			background: linear-gradient(
				145deg,
				#f2f2f2 0%,
				#e7e7e7 22%,
				#cecece 48%,
				#ededed 68%,
				#c0c0c0 100%
			);
			color: #000;
			border: 1px solid rgba(0, 0, 0, 0.33);
			box-shadow: 0 -6px 18px rgba(0, 0, 0, .10);
			padding: 12px 24px calc(12px + env(safe-area-inset-bottom));
			transform: translateY(110%);
			transition: transform .25s ease;
		}
		.aad-sticky-bar.is-visible { transform: translateY(0); }

		/* Φωτογραφία | τίτλος + τιμή | (dropdown) κουμπί — όλα στο ίδιο κέντρο ύψους */
		.aad-sticky-inner {
			max-width: 1200px;
			margin: 0 auto;
			display: flex;
			align-items: center;
			gap: 16px;
		}
		.aad-sticky-thumb { flex: 0 0 auto; line-height: 0; }
		.aad-sticky-thumb img {
			width: 56px;
			height: 56px;
			object-fit: contain;
			display: block;
			background: #fff;
			border: 1px solid rgba(0, 0, 0, .15);
			border-radius: 6px;
			padding: 2px;
			box-sizing: border-box;
		}
		.aad-sticky-info {
			flex: 1 1 auto;
			min-width: 0;
			display: flex;
			flex-direction: column;
			justify-content: center;
			gap: 2px;
			line-height: 1.25;
		}
		.aad-sticky-title {
			font-weight: 700;
			font-size: 15px;
			letter-spacing: .02em;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}
		.aad-sticky-price {
			font-size: 15px;
			font-weight: 600;
		}
		.aad-sticky-price del { opacity: .55; margin-right: 6px; font-weight: 400; }
		.aad-sticky-price ins { text-decoration: none; }

		.aad-sticky-actions {
			flex: 0 0 auto;
			display: flex;
			align-items: center;
			gap: 10px;
		}

		/* !important γιατί τα θέματα συνήθως δίνουν width:100% / ύψος / φόντο σε όλα τα select */
		.aad-sticky-bar .aad-sticky-select {
			flex: 0 1 auto !important;
			width: auto !important;
			min-width: 0 !important;
			max-width: 240px !important;
			height: 42px !important;
			margin: 0 !important;
			padding: 0 30px 0 12px !important;
			border: 1px solid rgba(0, 0, 0, .33) !important;
			border-radius: 6px !important;
			background-color: rgba(255, 255, 255, .55) !important;
			color: #000 !important;
			font-size: 14px !important;
			line-height: 1.2 !important;
			text-overflow: ellipsis;
		}

		.aad-sticky-btn {
			flex: 0 0 auto;
			min-width: 170px;
			height: 42px;
			margin: 0;
			padding: 0 26px;
			border: 1px solid #000;
			border-radius: 0;
			background: rgba(255, 255, 255, .55);
			color: #000;
			font-family: inherit;
			font-size: 15px;
			font-weight: 700;
			letter-spacing: .02em;
			line-height: 1;
			cursor: pointer;
			white-space: nowrap;
			transition: background .2s ease, color .2s ease;
		}
		.aad-sticky-btn:hover,
		.aad-sticky-btn:focus-visible { background: #000; color: #fff; outline: none; }
		.aad-sticky-btn[disabled] { opacity: .5; cursor: not-allowed; background: rgba(255, 255, 255, .55); color: #000; }
		.aad-sticky-btn.is-loading { opacity: .6; cursor: wait; }

		/* Κούνημα του dropdown αν πατηθεί το κουμπί χωρίς επιλογή */
		.aad-shake { animation: aadShake .4s ease; border-color: #c00 !important; }
		@keyframes aadShake {
			0%, 100% { transform: translateX(0); }
			25%      { transform: translateX(-5px); }
			75%      { transform: translateX(5px); }
		}

		/* Κινητό: φωτογραφία | τίτλος + τιμή | κουμπί, και το dropdown από κάτω σε όλο το πλάτος */
		@media (max-width: 640px) {
			.aad-sticky-bar { padding: 10px 12px calc(10px + env(safe-area-inset-bottom)); }
			.aad-sticky-inner { flex-wrap: wrap; gap: 10px; }
			.aad-sticky-thumb img { width: 44px; height: 44px; }
			.aad-sticky-title,
			.aad-sticky-price { font-size: 13px; }
			.aad-sticky-btn { min-width: 0; padding: 0 14px; font-size: 13px; }
			.aad-sticky-bar.is-variable .aad-sticky-actions { flex: 1 1 100%; }
			.aad-sticky-bar.is-variable .aad-sticky-select {
				flex: 1 1 auto !important;
				max-width: none !important;
				font-size: 16px !important; /* κάτω από 16px το iPhone κάνει zoom */
			}
		}
	</style>

	<script>
	(function () {
		var bar = document.getElementById('aad-sticky-bar');
		if (!bar) return;

		// Το στοιχείο της τιμής που παρακολουθούμε (πρώτο που υπάρχει). Στο YOOtheme
		// η τιμή του configurator έχει την κλάση jpc-live-price.
		var target = document.querySelector('.jpc-live-price, .tm-element-woo-price, .summary .price, .product .price, form.cart');
		if (!target || !('IntersectionObserver' in window)) return;

		var variations = <?php echo wp_json_encode( $variations ); ?>;
		var $ = window.jQuery;

		var btn = bar.querySelector('.aad-sticky-btn');
		var btnLabel = btn.textContent.trim();
		var select = bar.querySelector('.aad-sticky-select');
		var priceEl = bar.querySelector('.aad-sticky-price');
		var thumbImg = bar.querySelector('.aad-sticky-thumb img');
		var mainForm = document.querySelector('form.variations_form') || document.querySelector('form.cart');

		// Προϊόν με τον Jewelry Configurator (μονόπετρα, βέρες κ.λπ.)
		var hasConfigurator = !!document.querySelector('.jewelry-configurator, .jpc-wedding') ||
			!!(mainForm && mainForm.querySelector('[name="jc_configurator_enabled"], [name="jcw_enabled"]'));

		var originalPrice = priceEl ? priceEl.innerHTML : '';
		var originalThumb = thumbImg ? thumbImg.getAttribute('src') : '';
		var originalSrcset = thumbImg ? thumbImg.getAttribute('srcset') : null;

		// Κενό στο τέλος της σελίδας όσο το πραγματικό ύψος της μπάρας (στο κινητό, με
		// dropdown, η μπάρα έχει δύο σειρές), ώστε να μην κρύβει το footer.
		function show(on) {
			bar.classList.toggle('is-visible', on);
			bar.setAttribute('aria-hidden', on ? 'false' : 'true');
			document.body.classList.toggle('aad-sticky-on', on);
			document.body.style.paddingBottom = on ? bar.offsetHeight + 'px' : '';
		}

		// Εμφάνιση μόνο όταν η τιμή έχει περάσει ΠΑΝΩ από την οθόνη.
		new IntersectionObserver(function (entries) {
			var e = entries[0];
			show(!e.isIntersecting && e.boundingClientRect.top < 0);
		}).observe(target);

		// Configurator: η τιμή της μπάρας ακολουθεί τη ζωντανή τιμή της σελίδας.
		if (hasConfigurator && priceEl && target.tagName !== 'FORM' && 'MutationObserver' in window) {
			var mirror = function () {
				var text = (target.innerText || target.textContent || '').replace(/\s+/g, ' ').trim();
				if (text) priceEl.textContent = text;
			};
			new MutationObserver(mirror).observe(target, { childList: true, subtree: true, characterData: true });
		}

		function findVariation(id) {
			id = parseInt(id, 10);
			for (var i = 0; i < variations.length; i++) {
				if (variations[i].id === id) return variations[i];
			}
			return null;
		}

		function setThumb(src) {
			if (!thumbImg) return;
			if (src) {
				thumbImg.removeAttribute('srcset');
				thumbImg.setAttribute('src', src);
			} else {
				thumbImg.setAttribute('src', originalThumb);
				if (originalSrcset) thumbImg.setAttribute('srcset', originalSrcset);
			}
		}

		function updateBar(v) {
			// Με configurator την τιμή τη δίνει η σελίδα (βλ. πάνω).
			if (priceEl && !hasConfigurator) priceEl.innerHTML = v ? v.price_html : originalPrice;
			setThumb(v ? v.thumb : '');
		}

		// Από το sticky dropdown -> στην κανονική φόρμα
		function syncMainForm(v) {
			if (!mainForm || !$) return;
			var $form = $(mainForm);
			if (!v) {
				$form.find('.reset_variations').trigger('click');
				return;
			}
			Object.keys(v.attributes).forEach(function (name) {
				var val = v.attributes[name];
				if (val === '') return; // "οποιαδήποτε τιμή" - το αφήνουμε όπως είναι
				$form.find('[name="' + name + '"]').val(val);
			});
			$form.find('.variations select').trigger('change');
		}

		if (select) {
			select.addEventListener('change', function () {
				var v = findVariation(select.value);
				updateBar(v);
				syncMainForm(v);
			});
		}

		// Από την κανονική φόρμα -> στο sticky dropdown
		if ($ && mainForm && select) {
			$(mainForm)
				.on('found_variation', function (event, variation) {
					var v = findVariation(variation.variation_id);
					if (v) select.value = String(v.id);
					updateBar(v);
				})
				.on('reset_data', function () {
					select.value = '';
					updateBar(null);
				});
		}

		function addVariationByUrl(v) {
			var url = new URL(window.location.href);
			url.search = '';
			url.hash = '';
			url.searchParams.set('add-to-cart', btn.getAttribute('data-product-id'));
			url.searchParams.set('variation_id', v.id);
			url.searchParams.set('quantity', getQty());
			Object.keys(v.attributes).forEach(function (name) {
				if (v.attributes[name] !== '') url.searchParams.set(name, v.attributes[name]);
			});
			window.location.href = url.toString();
		}

		// Ποσότητα από την κανονική φόρμα (αν ο χρήστης την έχει αλλάξει)
		function getQty() {
			var q = mainForm ? mainForm.querySelector('input.qty') : null;
			var n = q ? parseFloat(q.value) : 1;
			return n > 0 ? n : 1;
		}

		// Τα δεδομένα για το καλάθι. Με configurator στέλνονται και όλες οι επιλογές
		// της φόρμας (μέταλλο, χρώμα, νούμερα, χάραξη…), αλλιώς τις απορρίπτει ο έλεγχος.
		function cartData(productId) {
			var data = [];
			if (hasConfigurator && mainForm && $) {
				data = $(mainForm).serializeArray().filter(function (field) {
					return ['add-to-cart', 'product_id', 'quantity', 'variation_id'].indexOf(field.name) === -1;
				});
			}
			data.push({ name: 'product_id', value: productId }, { name: 'quantity', value: getQty() });
			return $.param(data);
		}

		function showAdded() {
			btn.textContent = '✓';
			setTimeout(function () { btn.textContent = btnLabel; }, 2000);
		}

		// Προσθήκη στο καλάθι χωρίς επαναφόρτωση της σελίδας
		function ajaxAdd(productId, fallback) {
			if (!$ || !btn.getAttribute('data-ajax')) { fallback(); return; }

			btn.classList.add('is-loading');
			btn.disabled = true;

			$.post(btn.getAttribute('data-ajax'), cartData(productId))
				.done(function (res) {
					if (!res || res.error) {
						// Π.χ. μη επαρκές απόθεμα: φόρτωσε τη σελίδα για να δει το μήνυμα σφάλματος
						if (res && res.product_url) window.location.href = res.product_url;
						else fallback();
						return;
					}
					// Ενημερώνει το mini-cart / το μετρητή του καλαθιού στο header
					$(document.body).trigger('added_to_cart', [res.fragments, res.cart_hash, null]);
					showAdded();
				})
				.fail(fallback)
				.always(function () {
					btn.classList.remove('is-loading');
					btn.disabled = false;
				});
		}

		// Configurator: έλεγχος επιλογών πριν το καλάθι. Αν λείπει κάτι, η σελίδα πηγαίνει
		// στο πεδίο που λείπει (όπως το κανονικό κουμπί) και η μπάρα δεν στέλνει τίποτα.
		function configuratorReady() {
			if (!hasConfigurator) return true;
			if (typeof window.JPCValidate === 'function') return window.JPCValidate();
			// Παλιά έκδοση plugin: άφησε το κανονικό κουμπί να κάνει τον έλεγχο.
			var mainBtn = document.querySelector('form.cart .single_add_to_cart_button');
			if (mainBtn) mainBtn.click();
			return false;
		}

		btn.addEventListener('click', function () {
			var action = btn.getAttribute('data-action');
			var mainBtn = document.querySelector('form.cart .single_add_to_cart_button');

			if (action === 'variable') {
				var v = findVariation(select.value);
				if (!v) {
					select.classList.remove('aad-shake');
					void select.offsetWidth; // restart animation
					select.classList.add('aad-shake');
					select.focus();
					return;
				}
				if (!configuratorReady()) return;

				// Παραλλαγές με "οποιαδήποτε τιμή" χρειάζονται τη φόρμα για να σταλούν οι επιλογές
				var hasAny = Object.keys(v.attributes).some(function (k) { return v.attributes[k] === ''; });
				var fallback = function () {
					if (mainBtn && !mainBtn.classList.contains('disabled') && !mainBtn.disabled) mainBtn.click();
					else addVariationByUrl(v);
				};

				if (hasAny) fallback();
				else ajaxAdd(v.id, fallback);
				return;
			}

			if (action === 'add') {
				if (!configuratorReady()) return;
				ajaxAdd(btn.getAttribute('data-product-id'), function () {
					if (mainBtn) mainBtn.click();
					else window.location.href = btn.getAttribute('data-url');
				});
			}
		});
	})();
	</script>
	<?php
}
