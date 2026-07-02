<?php

/**
 * Display category image on category archive
 */
function woocommerce_category_image() {
    if ( is_product_category() ){
	    global $wp_query;
	    $cat = $wp_query->get_queried_object();
	    $thumbnail_id = get_term_meta( $cat->term_id, 'thumbnail_id', true );
	    $image = wp_get_attachment_url( $thumbnail_id );
	    if ( $image ) {
		    echo '<div class="text-center my-4"><img src="' . $image . '" alt="' . $cat->name . '" /></div>' ;
		}
	}
}
add_action( 'woocommerce_archive_description', 'woocommerce_category_image', 10, 2  );

/**
 * Recharge le CSS du theme enfant avec une version basee sur la date du fichier.
 * Utile en local pour voir immediatement les ajustements WooCommerce sans cache navigateur.
 */
function lamagicieuse_enqueue_child_style_cache_bust() {
    $style_path = get_stylesheet_directory() . '/style.css';

    wp_enqueue_style(
        'lamagicieuse-style-cache-bust',
        get_stylesheet_uri(),
        array( 'wp-bootstrap-starter-style' ),
        file_exists( $style_path ) ? filemtime( $style_path ) : wp_get_theme()->get( 'Version' )
    );
}
add_action( 'wp_enqueue_scripts', 'lamagicieuse_enqueue_child_style_cache_bust', 30 );

function lamagicieuse_print_checkout_styles() {
    if ( ! function_exists( 'is_checkout' ) || ( ! is_checkout() && ! is_page( 'commande' ) ) ) {
        return;
    }
    ?>
    <style id="lamagicieuse-checkout-inline-css">
        body.woocommerce-checkout #content.site-content,
        body.page-id-9 #content.site-content { background: #f7f4ee !important; padding: 3rem 0 !important; }
        body.woocommerce-checkout #content.site-content > .container,
        body.page-id-9 #content.site-content > .container { max-width: 1180px !important; width: 100% !important; }
        body.woocommerce-checkout #primary,
        body.page-id-9 #primary { flex: 0 0 100% !important; max-width: 100% !important; }
        body.woocommerce-checkout #secondary,
        body.page-id-9 #secondary { display: none !important; }
        body.woocommerce-checkout article.page,
        body.page-id-9 article.page { max-width: 73rem !important; margin: 0 auto !important; padding: 0 1.25rem !important; }
        body.woocommerce-checkout .entry-title,
        body.page-id-9 .entry-title { margin-bottom: 2rem !important; color: #313135 !important; font-size: 2.4rem !important; line-height: 1.1 !important; text-align: center !important; }
        body.woocommerce-checkout form.checkout,
        body.page-id-9 form.checkout { display: grid !important; grid-template-columns: minmax(0, 1fr) minmax(22rem, 26rem) !important; grid-template-areas: "customer order-title" "customer order-review" !important; gap: 0.75rem 1.5rem !important; align-items: start !important; }
        body.woocommerce-checkout form.checkout::before,
        body.woocommerce-checkout form.checkout::after,
        body.page-id-9 form.checkout::before,
        body.page-id-9 form.checkout::after { display: none !important; }
        body.woocommerce-checkout #customer_details,
        body.woocommerce-checkout #order_review,
        body.page-id-9 #customer_details,
        body.page-id-9 #order_review { background: #fff !important; border: 1px solid #e2d8ca !important; border-radius: 0.5rem !important; box-shadow: 0 1rem 2rem rgba(49, 49, 53, 0.08) !important; }
        body.woocommerce-checkout #customer_details .col-1,
        body.woocommerce-checkout #customer_details .col-2,
        body.page-id-9 #customer_details .col-1,
        body.page-id-9 #customer_details .col-2 { max-width: 100% !important; width: 100% !important; padding: 0 !important; }
        body.woocommerce-checkout #customer_details .form-row,
        body.page-id-9 #customer_details .form-row { float: none !important; clear: both !important; width: 100% !important; margin: 0 0 1rem !important; padding: 0 !important; }
        body.woocommerce-checkout #customer_details .form-row-first,
        body.woocommerce-checkout #customer_details .form-row-last,
        body.page-id-9 #customer_details .form-row-first,
        body.page-id-9 #customer_details .form-row-last { display: inline-block !important; width: calc(50% - 0.5rem) !important; vertical-align: top !important; }
        body.woocommerce-checkout #customer_details .form-row-first,
        body.page-id-9 #customer_details .form-row-first { margin-right: 1rem !important; }
        body.woocommerce-checkout #customer_details .form-row-wide,
        body.page-id-9 #customer_details .form-row-wide { width: 100% !important; }
        body.woocommerce-checkout #customer_details,
        body.page-id-9 #customer_details,
        body.woocommerce-checkout #order_review,
        body.page-id-9 #order_review { padding: 1.5rem !important; }
        body.woocommerce-checkout #customer_details,
        body.page-id-9 #customer_details { grid-area: customer !important; }
        body.woocommerce-checkout #order_review_heading,
        body.woocommerce-checkout #order_review,
        body.page-id-9 #order_review_heading,
        body.page-id-9 #order_review { grid-column: 2 !important; }
        body.woocommerce-checkout #order_review_heading,
        body.page-id-9 #order_review_heading { grid-area: order-title !important; margin: 0 !important; }
        body.woocommerce-checkout #order_review,
        body.page-id-9 #order_review { grid-area: order-review !important; }
        body.woocommerce-checkout .woocommerce-input-wrapper,
        body.page-id-9 .woocommerce-input-wrapper { display: block !important; width: 100% !important; }
        body.woocommerce-checkout .woocommerce-input-wrapper input.input-text,
        body.woocommerce-checkout .woocommerce-input-wrapper textarea,
        body.woocommerce-checkout .woocommerce-input-wrapper select,
        body.woocommerce-checkout .select2-container,
        body.woocommerce-checkout .select2-container .select2-selection--single,
        body.page-id-9 .woocommerce-input-wrapper input.input-text,
        body.page-id-9 .woocommerce-input-wrapper textarea,
        body.page-id-9 .woocommerce-input-wrapper select,
        body.page-id-9 .select2-container,
        body.page-id-9 .select2-container .select2-selection--single { width: 100% !important; min-height: 2.85rem !important; border: 1px solid #d6cbbb !important; border-radius: 0.35rem !important; background: #fff !important; color: #313135 !important; }
        body.woocommerce-checkout .woocommerce-input-wrapper input.input-text,
        body.woocommerce-checkout .woocommerce-input-wrapper textarea,
        body.woocommerce-checkout .woocommerce-input-wrapper select,
        body.page-id-9 .woocommerce-input-wrapper input.input-text,
        body.page-id-9 .woocommerce-input-wrapper textarea,
        body.page-id-9 .woocommerce-input-wrapper select { padding: 0.65rem 0.75rem !important; }
        body.woocommerce-checkout .woocommerce-input-wrapper textarea,
        body.page-id-9 .woocommerce-input-wrapper textarea { min-height: 7rem !important; resize: vertical !important; }
        body.woocommerce-checkout #ship-to-different-address,
        body.page-id-9 #ship-to-different-address { margin: 1.25rem 0 1rem !important; font-size: 1.15rem !important; letter-spacing: 0.08em !important; }
        body.woocommerce-checkout #place_order,
        body.page-id-9 #place_order { width: 100% !important; min-height: 3rem !important; border-radius: 0.35rem !important; background: #8a3f3a !important; color: #fff !important; font-weight: 700 !important; }
        body.woocommerce-checkout #place_order:hover,
        body.woocommerce-checkout #place_order:focus,
        body.page-id-9 #place_order:hover,
        body.page-id-9 #place_order:focus { background: #6f312e !important; }
        @media (max-width: 48rem) {
            body.woocommerce-checkout #content.site-content,
            body.page-id-9 #content.site-content { padding: 1.5rem 0 !important; }
            body.woocommerce-checkout .entry-title,
            body.page-id-9 .entry-title { font-size: 2rem !important; text-align: left !important; }
            body.woocommerce-checkout form.checkout,
            body.page-id-9 form.checkout { display: block !important; grid-template-areas: none !important; }
            body.woocommerce-checkout #customer_details .form-row-first,
            body.woocommerce-checkout #customer_details .form-row-last,
            body.page-id-9 #customer_details .form-row-first,
            body.page-id-9 #customer_details .form-row-last { display: block !important; width: 100% !important; margin-right: 0 !important; }
            body.woocommerce-checkout #order_review_heading,
            body.woocommerce-checkout #order_review,
            body.page-id-9 #order_review_heading,
            body.page-id-9 #order_review { grid-column: auto !important; }
        }
    </style>
    <?php
}
add_action( 'wp_head', 'lamagicieuse_print_checkout_styles', 99 );

/**
 * Personnalisation de l'espace Mon compte WooCommerce.
 */
function lamagicieuse_account_menu_items( $items ) {
    unset( $items['downloads'] );

    return array(
        'dashboard'       => $items['dashboard'] ?? 'Tableau de bord',
        'orders'          => 'Mes commandes',
        'edit-address'    => 'Mes adresses',
        'edit-account'    => 'Mes informations',
        'customer-logout' => 'Déconnexion',
    );
}
add_filter( 'woocommerce_account_menu_items', 'lamagicieuse_account_menu_items', 20 );

function lamagicieuse_account_intro() {
    if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
        return;
    }
    ?>
    <section class="lamagicieuse-account-intro" aria-label="Introduction de l'espace client">
        <p class="lamagicieuse-account-intro__eyebrow">Espace client</p>
        <h1>Mon compte</h1>
        <p>Retrouvez vos commandes, vos adresses et vos informations personnelles.</p>
    </section>
    <?php
}
add_action( 'woocommerce_before_account_navigation', 'lamagicieuse_account_intro', 5 );

function lamagicieuse_account_help_block() {
    ?>
    <aside class="lamagicieuse-account-help" aria-label="Aide espace client">
        <h2>Besoin d'aide ?</h2>
        <p>Pour une question sur une commande ou un paiement, contactez La Magicieuse.</p>
        <a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Nous contacter</a>
    </aside>
    <?php
}
add_action( 'woocommerce_after_my_account', 'lamagicieuse_account_help_block', 20 );
