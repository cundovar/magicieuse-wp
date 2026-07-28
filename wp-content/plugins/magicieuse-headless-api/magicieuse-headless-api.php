<?php
/**
 * Plugin Name: Magicieuse Headless API
 * Description: Endpoints REST et emplacements de menu pour le front headless React.
 * Version:     1.0.1
 * Author:      Magicieuse
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const MAGICIEUSE_ARTIST_CACHE_VERSION = '2';

function magicieuse_maybe_invalidate_artist_cache(): void {
    if ( get_option( 'magicieuse_artist_cache_version' ) === MAGICIEUSE_ARTIST_CACHE_VERSION ) {
        return;
    }

    delete_transient( 'magicieuse_artistes' );
    delete_transient( 'magicieuse_content_' . md5( 'artistes' ) );
    update_option( 'magicieuse_artist_cache_version', MAGICIEUSE_ARTIST_CACHE_VERSION, false );
}

add_action( 'init', 'magicieuse_maybe_invalidate_artist_cache', 1 );

function magicieuse_get_front_url(): string {
    $front_url = (string) get_option( 'magicieuse_front_url', 'http://localhost:5173' );

    return untrailingslashit( esc_url_raw( $front_url ) );
}

function magicieuse_is_wp_page_redirect_excluded( WP_Post $page ): bool {
    $excluded_page_ids = array_filter( array_map( 'absint', [
        get_option( 'woocommerce_myaccount_page_id' ),
        get_option( 'woocommerce_checkout_page_id' ),
    ] ) );

    $excluded_slugs = [
        'mon-compte',
        'my-account',
        'commande',
        'checkout',
        'validation-de-la-commande',
    ];

    /**
     * Permet d'ajouter d'autres pages WordPress qui doivent rester servies par WP.
     */
    $excluded_page_ids = apply_filters( 'magicieuse_headless_redirect_excluded_page_ids', $excluded_page_ids );
    $excluded_slugs    = apply_filters( 'magicieuse_headless_redirect_excluded_page_slugs', $excluded_slugs );

    return in_array( (int) $page->ID, $excluded_page_ids, true )
        || in_array( $page->post_name, $excluded_slugs, true );
}

function magicieuse_get_front_path_for_page( WP_Post $page ): string {
    if ( (int) get_option( 'page_on_front' ) === (int) $page->ID ) {
        return '/';
    }

    return '/' . trim( get_page_uri( $page ), '/' ) . '/';
}

/**
 * Cache-Control sur les réponses GET publiques de magicieuse/v1.
 * Sans ceci, WordPress envoie "no-cache, must-revalidate" par défaut
 * et le navigateur refait un aller-retour serveur à chaque navigation.
 * 5 min côté navigateur + stale-while-revalidate pour une UX instantanée.
 */
add_filter( 'rest_post_dispatch', function ( WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request ): WP_REST_Response {
    if ( $request->get_method() !== 'GET' ) {
        return $response;
    }

    $route = $request->get_route();
    if ( ! str_starts_with( $route, '/magicieuse/v1/' ) ) {
        return $response;
    }

    if ( $response->get_status() !== 200 ) {
        return $response;
    }

    if ( in_array( $route, [ '/magicieuse/v1/artistes', '/magicieuse/v1/content/artistes' ], true ) ) {
        $response->header( 'Cache-Control', 'private, no-cache, no-store, max-age=0, must-revalidate' );
        $response->header( 'Vary', 'Accept-Encoding' );

        return $response;
    }

    $response->header( 'Cache-Control', 'public, max-age=300, stale-while-revalidate=3600' );
    $response->header( 'Vary', 'Accept-Encoding' );

    return $response;
}, 10, 3 );

add_filter( 'allowed_redirect_hosts', function ( array $hosts ): array {
    $front_host = wp_parse_url( magicieuse_get_front_url(), PHP_URL_HOST );

    if ( $front_host && ! in_array( $front_host, $hosts, true ) ) {
        $hosts[] = $front_host;
    }

    return $hosts;
} );

add_action( 'template_redirect', function (): void {
    if ( is_admin() || wp_doing_ajax() || is_preview() || is_feed() ) {
        return;
    }

    $front_url = magicieuse_get_front_url();
    if ( $front_url === '' ) {
        return;
    }

    // Catégorie produit WooCommerce → /collections/:slug/
    if ( is_tax( 'product_cat' ) ) {
        $term = get_queried_object();
        if ( $term instanceof WP_Term ) {
            wp_safe_redirect( $front_url . '/collections/' . $term->slug . '/', 302 );
            exit;
        }
    }

    // Produit unique WooCommerce → /produit/:slug/
    if ( is_singular( 'product' ) ) {
        $post = get_queried_object();
        if ( $post instanceof WP_Post ) {
            $slug_product = apply_filters( 'magicieuse_slug_product', 'produit' );
            wp_safe_redirect( $front_url . '/' . $slug_product . '/' . $post->post_name . '/', 302 );
            exit;
        }
    }

    // Page WordPress classique
    if ( ! is_page() ) {
        return;
    }

    $page = get_queried_object();
    if ( ! $page instanceof WP_Post || magicieuse_is_wp_page_redirect_excluded( $page ) ) {
        return;
    }

    $target = $front_url . magicieuse_get_front_path_for_page( $page );
    if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
        $target .= '?' . sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) );
    }

    wp_safe_redirect( $target, 302 );
    exit;
} );

/**
 * Emplacements de menu declares ici plutot que dans le theme.
 * Comme ca, si le theme change, les emplacements et l'API restent disponibles.
 */
add_action( 'after_setup_theme', function () {
    register_nav_menus( [
        'primary' => 'Menu principal (headless)',
        'footer'  => 'Menu pied de page (headless)',
    ] );
} );

add_filter( 'block_categories_all', function ( array $categories ): array {
    $exists = array_filter( $categories, static function ( array $category ): bool {
        return ( $category['slug'] ?? '' ) === 'magicieuse';
    } );

    if ( $exists ) {
        return $categories;
    }

    $categories[] = [
        'slug'  => 'magicieuse',
        'title' => 'Magicieuse',
        'icon'  => null,
    ];

    return $categories;
} );

add_action( 'enqueue_block_editor_assets', function (): void {
    $asset_path = plugin_dir_path( __FILE__ ) . 'assets/editor-blocks.js';

    wp_enqueue_script(
        'magicieuse-editor-blocks',
        plugin_dir_url( __FILE__ ) . 'assets/editor-blocks.js',
        [ 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ],
        file_exists( $asset_path ) ? (string) filemtime( $asset_path ) : '1.0.0',
        true
    );
} );

add_action( 'init', function (): void {
    register_block_type( 'magicieuse/contact-form', [
        'title'       => 'Formulaire de contact',
        'category'    => 'magicieuse',
        'icon'        => null,
        'description' => 'Affiche le formulaire de contact React.',
        'supports'    => [ 'html' => false ],
    ] );

    register_block_type( 'magicieuse/related-products', [
        'title'           => 'Produits de la même collection',
        'category'        => 'magicieuse',
        'icon'            => 'grid-view',
        'description'     => 'Affiche les autres produits de la même collection sur la page produit.',
        'attributes'      => [
            'title' => [ 'type' => 'string', 'default' => 'Dans la même collection' ],
            'limit' => [ 'type' => 'integer', 'default' => 8 ],
        ],
        'supports'        => [ 'html' => false ],
    ] );
} );

add_action( 'init', function (): void {
    if ( ! function_exists( 'register_block_pattern' ) ) {
        return;
    }

    if ( function_exists( 'register_block_pattern_category' ) ) {
        register_block_pattern_category(
            'magicieuse',
            [ 'label' => 'Magicieuse' ]
        );
    }

    register_block_pattern(
        'magicieuse/reassurance',
        [
            'title'       => 'Réassurance Magicieuse',
            'description' => 'Composition réutilisable pour rassurer avant l’achat.',
            'categories'  => [ 'magicieuse' ],
            'content'     => '<!-- wp:group {"className":"magicieuse-reassurance","layout":{"type":"constrained"}} -->
<div class="wp-block-group magicieuse-reassurance"><!-- wp:columns {"className":"magicieuse-reassurance__columns"} -->
<div class="wp-block-columns magicieuse-reassurance__columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph {"className":"magicieuse-reassurance__icon"} -->
<p class="magicieuse-reassurance__icon"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="16" r="1"></circle><rect x="3" y="10" width="18" height="12" rx="2"></rect><path d="M7 10V7a5 5 0 0 1 10 0v3"></path></svg></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Paiement sécurisé</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Commande protégée via WooCommerce.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph {"className":"magicieuse-reassurance__icon"} -->
<p class="magicieuse-reassurance__icon"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 18V6a2 2 0 0 0-2-2H4"></path><path d="M15 18H9"></path><path d="M19 18h2"></path><path d="M3 18h2"></path><circle cx="17" cy="18" r="2"></circle><circle cx="7" cy="18" r="2"></circle></svg></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Livraison suivie</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Expédition avec suivi selon les options disponibles.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph {"className":"magicieuse-reassurance__icon"} -->
<p class="magicieuse-reassurance__icon"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.5 3.2a1 1 0 0 1 1 0l2.3 5a1 1 0 0 0 .6.6l5 2.3a1 1 0 0 1 0 1.8l-5 2.3a1 1 0 0 0-.6.6l-2.3 5a1 1 0 0 1-1.8 0l-2.3-5a1 1 0 0 0-.6-.6l-5-2.3a1 1 0 0 1 0-1.8l5-2.3a1 1 0 0 0 .6-.6z"></path></svg></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Sélection choisie</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Chaque produit est intégré avec soin à l’univers Magicieuse.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->',
        ]
    );

    register_block_pattern(
        'magicieuse/livraison-commande',
        [
            'title'       => 'Livraison & commande Magicieuse',
            'description' => 'Composition réutilisable pour expliquer livraison, délais et suivi.',
            'categories'  => [ 'magicieuse' ],
            'content'     => '<!-- wp:group {"className":"magicieuse-livraison-commande","layout":{"type":"constrained"}} -->
<div class="wp-block-group magicieuse-livraison-commande"><!-- wp:columns {"className":"magicieuse-livraison-commande__cols"} -->
<div class="wp-block-columns magicieuse-livraison-commande__cols"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph {"className":"magicieuse-livraison-commande__eyebrow"} -->
<p class="magicieuse-livraison-commande__eyebrow">Commande</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Livraison & suivi</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Les commandes sont préparées avec soin. Les options disponibles s’affichent au moment de la validation du panier.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/contact/">Une question ? Contactez-nous</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"className":"magicieuse-livraison-commande__etapes","layout":{"type":"default"}} -->
<div class="wp-block-group magicieuse-livraison-commande__etapes"><!-- wp:group {"className":"magicieuse-livraison-commande__etape","layout":{"type":"default"}} -->
<div class="wp-block-group magicieuse-livraison-commande__etape"><!-- wp:paragraph {"className":"magicieuse-livraison-commande__pastille"} -->
<p class="magicieuse-livraison-commande__pastille">📖</p>
<!-- /wp:paragraph -->

<!-- wp:group {"layout":{"type":"default"}} -->
<div class="wp-block-group"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Délais</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Préparation selon disponibilité des produits et rythme d’expédition indiqué lors de la commande.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"magicieuse-livraison-commande__etape","layout":{"type":"default"}} -->
<div class="wp-block-group magicieuse-livraison-commande__etape"><!-- wp:paragraph {"className":"magicieuse-livraison-commande__pastille"} -->
<p class="magicieuse-livraison-commande__pastille">📦</p>
<!-- /wp:paragraph -->

<!-- wp:group {"layout":{"type":"default"}} -->
<div class="wp-block-group"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Suivi</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Quand l’option le permet, un suivi accompagne l’envoi pour garder un œil sur le colis.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"magicieuse-livraison-commande__etape","layout":{"type":"default"}} -->
<div class="wp-block-group magicieuse-livraison-commande__etape"><!-- wp:paragraph {"className":"magicieuse-livraison-commande__pastille"} -->
<p class="magicieuse-livraison-commande__pastille">💬</p>
<!-- /wp:paragraph -->

<!-- wp:group {"layout":{"type":"default"}} -->
<div class="wp-block-group"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Question</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Un doute avant de commander ? Contacte-nous avant validation du panier.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->',
        ]
    );
} );

/**
 * Endpoint REST generique pour pages ET articles WordPress.
 * Cherche d'abord une page, puis un article en fallback.
 * Rend le contenu via apply_filters('the_content') : Gutenberg et Elementor inclus.
 *
 * GET /wp-json/magicieuse/v1/content/{slug}
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'magicieuse/v1', '/content/(?P<slug>[a-zA-Z0-9_-]+)', [
        'methods'             => 'GET',
        'callback'            => 'magicieuse_rest_get_content',
        'permission_callback' => '__return_true',
        'args'                => [
            'slug' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_title',
            ],
        ],
    ] );
} );

/**
 * Endpoint REST pour la page d'accueil active dans Reglages > Lecture.
 *
 * GET /wp-json/magicieuse/v1/front-page
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'magicieuse/v1', '/front-page', [
        'methods'             => 'GET',
        'callback'            => 'magicieuse_rest_get_front_page',
        'permission_callback' => '__return_true',
    ] );
} );

/**
 * Endpoint REST structure pour les blocs de la page d'accueil.
 *
 * GET /wp-json/magicieuse/v1/front-page-blocks
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'magicieuse/v1', '/front-page-blocks', [
        'methods'             => 'GET',
        'callback'            => 'magicieuse_rest_get_front_page_blocks',
        'permission_callback' => '__return_true',
    ] );
} );

/**
 * Endpoint unifie : theme + page + blocs en une seule requete.
 * Remplace les 3 appels separes front-page + front-page-blocks + theme.
 *
 * GET /wp-json/magicieuse/v1/front
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'magicieuse/v1', '/front', [
        'methods'             => 'GET',
        'callback'            => 'magicieuse_rest_get_front',
        'permission_callback' => '__return_true',
    ] );
} );

function magicieuse_rest_get_front() {
    $cached = get_transient( 'magicieuse_front' );
    if ( $cached !== false ) {
        return $cached;
    }

    $front_page_id = (int) get_option( 'page_on_front' );
    $theme         = (string) get_option( 'magicieuse_active_theme', 'magicieuse' );

    if ( ! $front_page_id ) {
        return new WP_Error(
            'no_front_page',
            "Aucune page d'accueil statique n'est definie dans Reglages > Lecture",
            [ 'status' => 404 ]
        );
    }

    $page = get_post( $front_page_id );

    if ( ! $page || $page->post_type !== 'page' || $page->post_status !== 'publish' ) {
        return new WP_Error( 'front_page_not_found', "Page d'accueil introuvable", [ 'status' => 404 ] );
    }

    global $post;
    $post = $page;
    setup_postdata( $post );
    $content = apply_filters( 'the_content', $page->post_content );
    $excerpt = $page->post_excerpt ?: wp_trim_excerpt( '', $page );
    wp_reset_postdata();

    $response = [
        'theme'  => $theme,
        'page'   => [
            'id'      => $page->ID,
            'slug'    => $page->post_name,
            'type'    => 'page',
            'title'   => $page->post_title,
            'content' => $content,
            'excerpt' => $excerpt,
            'date'    => $page->post_date,
        ],
        'blocks' => magicieuse_rest_build_blocks_payload( $page, 'page' ),
    ];

    set_transient( 'magicieuse_front', $response, HOUR_IN_SECONDS );

    return $response;
}

/**
 * Endpoint REST pour reprendre le template page_artistes.php en headless.
 *
 * GET /wp-json/magicieuse/v1/artistes
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'magicieuse/v1', '/artistes', [
        'methods'             => 'GET',
        'callback'            => 'magicieuse_rest_get_artistes',
        'permission_callback' => '__return_true',
    ] );
} );

function magicieuse_rest_get_content( WP_REST_Request $request ) {
    $slug      = $request->get_param( 'slug' );
    $cache_key = 'magicieuse_content_' . md5( $slug );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $type  = 'page';
    $found = get_page_by_path( $slug, OBJECT, 'page' );

    // Fallback : article
    if ( ! $found || $found->post_status !== 'publish' ) {
        $results = get_posts( [
            'name'           => $slug,
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
        ] );
        $found = $results[0] ?? null;
        $type  = 'post';
    }

    if ( ! $found || $found->post_status !== 'publish' ) {
        return new WP_Error( 'not_found', 'Contenu introuvable', [ 'status' => 404 ] );
    }

    global $post;
    $post = $found;
    setup_postdata( $post );

    if ( $found->post_name === 'artistes' ) {
        $content = magicieuse_render_artistes_html();
    } elseif ( $found->ID === (int) get_option( 'page_for_posts' ) || in_array( $found->post_name, [ 'blog', 'blog-2' ], true ) ) {
        $content = magicieuse_render_blog_html();
    } else {
        $content = apply_filters( 'the_content', $found->post_content );
    }
    $excerpt = $found->post_excerpt ?: wp_trim_excerpt( '', $found );

    $thumbnail_id   = (int) get_post_thumbnail_id( $found->ID );
    $featured_image = $thumbnail_id ? magicieuse_rest_get_image_for_react( $thumbnail_id ) : null;

    wp_reset_postdata();

    $response = [
        'id'             => $found->ID,
        'featured_image' => $featured_image,
        'slug'           => $found->post_name,
        'type'           => $type,
        'title'          => $found->post_title,
        'content'        => $content,
        'excerpt'        => $excerpt,
        'date'           => $found->post_date,
    ];

    set_transient( $cache_key, $response, HOUR_IN_SECONDS );

    return $response;
}

function magicieuse_get_custom_field( string $field_name, int $post_id ) {
    if ( function_exists( 'get_field' ) ) {
        return get_field( $field_name, $post_id );
    }

    return get_post_meta( $post_id, $field_name, true );
}

function magicieuse_normalize_text_field( $value ): string {
    if ( is_array( $value ) ) {
        $value = implode( ' ', array_filter( array_map( 'strval', $value ) ) );
    }

    return trim( wp_strip_all_tags( (string) $value ) );
}

function magicieuse_normalize_link_field( $value ): ?array {
    if ( is_array( $value ) ) {
        $url    = $value['url'] ?? '';
        $title  = $value['title'] ?? $url;
        $target = $value['target'] ?? null;
    } else {
        $url    = (string) $value;
        $title  = $url;
        $target = null;
    }

    $url = trim( $url );
    if ( $url === '' ) {
        return null;
    }

    $title = magicieuse_normalize_text_field( $title );
    if ( $title === '' ) {
        $title = $url;
    }

    return [
        'url'    => esc_url_raw( $url ),
        'title'  => $title,
        'target' => $target ? magicieuse_normalize_text_field( $target ) : null,
    ];
}

function magicieuse_render_artistes_html(): string {
    $query = new WP_Query( [
        'post_type'      => 'artiste_s',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'rand',
    ] );

    if ( ! $query->have_posts() ) {
        return '<p>Aucun artiste trouve.</p>';
    }

    $paragraph_fields = [
        'description',
        'para_1',
        'para_2',
        'para_3',
        'para_4',
        'para_5',
        'para_6',
        'para_7',
    ];
    $link_fields = [
        'lien',
        'lien-2',
        'lien-3',
        'lien-4',
        'lien-5',
        'lien-6',
        'lien-7',
        'lien-8',
    ];

    ob_start();

    while ( $query->have_posts() ) {
        $query->the_post();
        $artist_id = get_the_ID();
        $title     = magicieuse_normalize_text_field(
            magicieuse_get_custom_field( 'titre', $artist_id )
        );

        if ( $title === '' ) {
            $title = get_the_title( $artist_id );
        }
        ?>
        <div class="content_artiste">
            <div class="card-artiste">
                <h1 class="titre-artiste"><?php echo esc_html( $title ); ?></h1>
                <div class="text-artiste">
                    <?php foreach ( $paragraph_fields as $field_name ) : ?>
                        <?php
                        $paragraph = magicieuse_get_custom_field( $field_name, $artist_id );
                        $paragraph = is_array( $paragraph )
                            ? magicieuse_normalize_text_field( $paragraph )
                            : trim( (string) $paragraph );
                        ?>
                        <?php if ( $paragraph !== '' ) : ?>
                            <?php echo wp_kses_post( wpautop( $paragraph ) ); ?>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php foreach ( $link_fields as $field_name ) : ?>
                        <?php $link = magicieuse_normalize_link_field( magicieuse_get_custom_field( $field_name, $artist_id ) ); ?>
                        <?php if ( $link ) : ?>
                            <p>
                                <a
                                    href="<?php echo esc_url( $link['url'] ); ?>"
                                    <?php if ( $link['target'] ) : ?>
                                        target="<?php echo esc_attr( $link['target'] ); ?>"
                                        rel="noopener noreferrer"
                                    <?php endif; ?>
                                >
                                    <?php echo esc_html( $link['title'] ); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    wp_reset_postdata();

    return trim( ob_get_clean() );
}

function magicieuse_render_blog_html(): string {
    $query = new WP_Query( [
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'posts_per_page'      => 12,
        'ignore_sticky_posts' => true,
    ] );

    if ( ! $query->have_posts() ) {
        return '<p>Aucun article publie pour le moment.</p>';
    }

    ob_start();
    ?>
    <div class="blog-posts">
        <?php while ( $query->have_posts() ) : ?>
            <?php
            $query->the_post();
            $post_id = get_the_ID();
            $post_path = '/' . get_post_field( 'post_name', $post_id ) . '/';
            ?>
            <article class="blog-post-card">
                <?php if ( has_post_thumbnail( $post_id ) ) : ?>
                    <a class="blog-post-card__image" href="<?php echo esc_url( $post_path ); ?>">
                        <?php echo get_the_post_thumbnail( $post_id, 'large' ); ?>
                    </a>
                <?php endif; ?>

                <div class="blog-post-card__body">
                    <p class="blog-post-card__date"><?php echo esc_html( get_the_date( '', $post_id ) ); ?></p>
                    <h2 class="blog-post-card__title">
                        <a href="<?php echo esc_url( $post_path ); ?>">
                            <?php echo esc_html( get_the_title( $post_id ) ); ?>
                        </a>
                    </h2>
                    <div class="blog-post-card__excerpt">
                        <?php echo wp_kses_post( wpautop( get_the_excerpt( $post_id ) ) ); ?>
                    </div>
                </div>
            </article>
        <?php endwhile; ?>
    </div>
    <?php

    wp_reset_postdata();

    return trim( ob_get_clean() );
}

function magicieuse_rest_get_artistes() {
    $cached = get_transient( 'magicieuse_artistes' );
    if ( $cached !== false ) {
        return $cached;
    }

    $query = new WP_Query( [
        'post_type'      => 'artiste_s',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    $artistes = [];

    foreach ( $query->posts as $artist ) {
        $paragraph_fields = [
            'description',
            'para_1',
            'para_2',
            'para_3',
            'para_4',
            'para_5',
            'para_6',
            'para_7',
        ];
        $link_fields = [
            'lien',
            'lien-2',
            'lien-3',
            'lien-4',
            'lien-5',
            'lien-6',
            'lien-7',
            'lien-8',
        ];

        $paragraphs = [];
        foreach ( $paragraph_fields as $field_name ) {
            $text = magicieuse_normalize_text_field(
                magicieuse_get_custom_field( $field_name, $artist->ID )
            );
            if ( $text !== '' ) {
                $paragraphs[] = $text;
            }
        }

        $links = [];
        foreach ( $link_fields as $field_name ) {
            $link = magicieuse_normalize_link_field(
                magicieuse_get_custom_field( $field_name, $artist->ID )
            );
            if ( $link ) {
                $links[] = $link;
            }
        }

        $acf_title = magicieuse_normalize_text_field(
            magicieuse_get_custom_field( 'titre', $artist->ID )
        );

        $artistes[] = [
            'id'         => $artist->ID,
            'slug'       => $artist->post_name,
            'title'      => $acf_title !== '' ? $acf_title : $artist->post_title,
            'paragraphs' => $paragraphs,
            'links'      => $links,
        ];
    }

    wp_reset_postdata();

    shuffle( $artistes );

    set_transient( 'magicieuse_artistes', $artistes, HOUR_IN_SECONDS );

    return $artistes;
}

function magicieuse_rest_get_front_page() {
    $cached = get_transient( 'magicieuse_front_page' );
    if ( $cached !== false ) {
        return $cached;
    }

    $front_page_id = (int) get_option( 'page_on_front' );

    if ( ! $front_page_id ) {
        return new WP_Error(
            'no_front_page',
            "Aucune page d'accueil statique n'est definie dans Reglages > Lecture",
            [ 'status' => 404 ]
        );
    }

    $page = get_post( $front_page_id );

    if ( ! $page || $page->post_type !== 'page' || $page->post_status !== 'publish' ) {
        return new WP_Error( 'front_page_not_found', "Page d'accueil introuvable", [ 'status' => 404 ] );
    }

    global $post;
    $post = $page;
    setup_postdata( $post );

    $content = apply_filters( 'the_content', $page->post_content );
    $excerpt = $page->post_excerpt ?: wp_trim_excerpt( '', $page );

    wp_reset_postdata();

    $response = [
        'id'      => $page->ID,
        'slug'    => $page->post_name,
        'type'    => 'page',
        'title'   => $page->post_title,
        'content' => $content,
        'excerpt' => $excerpt,
        'date'    => $page->post_date,
    ];

    set_transient( 'magicieuse_front_page', $response, HOUR_IN_SECONDS );

    return $response;
}

function magicieuse_rest_get_front_page_blocks() {
    $cached = get_transient( 'magicieuse_front_blocks' );
    if ( $cached !== false ) {
        return $cached;
    }

    $front_page_id = (int) get_option( 'page_on_front' );

    if ( ! $front_page_id ) {
        return new WP_Error(
            'no_front_page',
            "Aucune page d'accueil statique n'est definie dans Reglages > Lecture",
            [ 'status' => 404 ]
        );
    }

    $page = get_post( $front_page_id );

    if ( ! $page || $page->post_type !== 'page' || $page->post_status !== 'publish' ) {
        return new WP_Error( 'front_page_not_found', "Page d'accueil introuvable", [ 'status' => 404 ] );
    }

    $response = magicieuse_rest_build_blocks_payload( $page, 'page' );
    set_transient( 'magicieuse_front_blocks', $response, HOUR_IN_SECONDS );

    return $response;
}

function magicieuse_rest_build_blocks_payload( WP_Post $post, string $type = 'page' ): array {
    $blocks = parse_blocks( $post->post_content );

    return [
        'meta'   => [
            'id'    => $post->ID,
            'slug'  => $post->post_name,
            'type'  => $type,
            'title' => get_the_title( $post ),
            'date'  => $post->post_date,
        ],
        'blocks' => array_values( array_map( 'magicieuse_rest_normalize_block', $blocks ) ),
    ];
}

function magicieuse_rest_normalize_block( array $block ): array {
    return [
        'blockName'    => $block['blockName'] ?? null,
        'attrs'        => $block['attrs'] ?? [],
        'innerHTML'    => $block['innerHTML'] ?? '',
        'innerContent' => $block['innerContent'] ?? [],
        'innerBlocks'  => array_values(
            array_map( 'magicieuse_rest_normalize_block', $block['innerBlocks'] ?? [] )
        ),
        'renderedHTML' => magicieuse_rest_should_render_block_html( $block ) ? render_block( $block ) : '',
        'data'         => magicieuse_rest_enrich_block( $block ),
    ];
}

function magicieuse_rest_should_render_block_html( array $block ): bool {
    $name = $block['blockName'] ?? '';

    if ( $name === '' || $name === null ) {
        return true;
    }

    if (
        in_array( $name, [
            'core/columns',
            'core/column',
            'core/group',
            'core/details',
            'core/image',
            'core/buttons',
            'woocommerce/product-collection',
            'woocommerce/handpicked-products',
            'woocommerce/products-by-category',
            'magicieuse/hero',
            'magicieuse/cta',
            'magicieuse/featured-products',
            'magicieuse/book-carousel',
            'magicieuse/image-text',
            'magicieuse/faq',
            'magicieuse/gallery',
            'magicieuse/testimonials',
            'magicieuse/category-grid',
            'magicieuse/css-grid',
            'magicieuse/product-highlight',
            'magicieuse/newsletter',
            'magicieuse/instagram-feed',
            'magicieuse/accordion',
            'magicieuse/tabs',
            'magicieuse/slider',
            'magicieuse/video-embed',
            'magicieuse/steps',
            'magicieuse/partners',
            'magicieuse/shop-filters',
            'magicieuse/related-products',
            'magicieuse/contact-form',
        ], true )
    ) {
        return false;
    }

    return true;
}

function magicieuse_rest_enrich_block( array $block ) {
    $name  = $block['blockName'] ?? '';
    $attrs = $block['attrs'] ?? [];

    if ( $name === 'core/image' && ! empty( $attrs['id'] ) ) {
        return [
            'image' => magicieuse_rest_get_image_for_react( (int) $attrs['id'] ),
        ];
    }

    if (
        in_array( $name, [ 'magicieuse/hero', 'magicieuse/image-text' ], true )
    ) {
        $data = [];

        if ( ! empty( $attrs['imageId'] ) ) {
            $data['image'] = magicieuse_rest_get_image_for_react( (int) $attrs['imageId'] );
        }

        $button = magicieuse_rest_get_custom_block_button_for_react( $attrs, $name === 'magicieuse/hero' );
        if ( $button ) {
            $data['button'] = $button;
        }

        return $data;
    }

    if ( $name === 'core/buttons' ) {
        return [
            'buttons' => magicieuse_rest_get_buttons_for_react( $block['innerBlocks'] ?? [] ),
        ];
    }

    if ( $name === 'core/button' ) {
        return [
            'button' => magicieuse_rest_get_button_for_react( $block ),
        ];
    }

    if (
        in_array( $name, [
            'woocommerce/product-collection',
            'woocommerce/handpicked-products',
            'woocommerce/products-by-category',
            'magicieuse/featured-products',
            'magicieuse/book-carousel',
        ], true )
    ) {
        $products = magicieuse_rest_get_products_for_block( $block );

        return [
            'products' => $products,
        ];
    }

    if ( $name === 'magicieuse/gallery' ) {
        return [
            'images' => magicieuse_rest_get_images_for_block( $block ),
        ];
    }

    if ( $name === 'magicieuse/category-grid' ) {
        return [
            'categories' => magicieuse_rest_get_categories_for_block( $block ),
        ];
    }

    if ( $name === 'magicieuse/product-highlight' ) {
        $product = magicieuse_rest_get_product_highlight_for_block( $block );

        return [
            'product' => $product,
        ];
    }

    if ( $name === 'magicieuse/shop-filters' ) {
        $show_theme = ! empty( $attrs['showTheme'] );

        $categories = magicieuse_rest_get_categories_for_block( $block );

        $min_products = wc_get_products( [ 'status' => 'publish', 'limit' => 1, 'orderby' => 'price', 'order' => 'ASC' ] );
        $max_products = wc_get_products( [ 'status' => 'publish', 'limit' => 1, 'orderby' => 'price', 'order' => 'DESC' ] );
        $price_min = ! empty( $min_products ) ? (float) $min_products[0]->get_price() : 0;
        $price_max = ! empty( $max_products ) ? (float) $max_products[0]->get_price() : 0;

        $themes = [];
        if ( $show_theme ) {
            $theme_terms = get_terms( [ 'taxonomy' => 'pa_theme', 'hide_empty' => true ] );
            if ( ! is_wp_error( $theme_terms ) && is_array( $theme_terms ) ) {
                $themes = array_map( function ( WP_Term $t ) {
                    return [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug ];
                }, $theme_terms );
            }
        }

        return [
            'categories' => $categories,
            'priceRange' => [ 'min' => $price_min, 'max' => $price_max ],
            'themes'     => $themes,
        ];
    }

    if ( $name === 'magicieuse/related-products' ) {
        return [
            'title' => ! empty( $attrs['title'] ) ? (string) $attrs['title'] : 'Dans la même collection',
            'limit' => isset( $attrs['limit'] ) ? max( 1, min( 16, (int) $attrs['limit'] ) ) : 8,
        ];
    }

    if ( $name === 'magicieuse/slider' ) {
        $slides_raw = $attrs['slides'] ?? null;

        if ( ! is_array( $slides_raw ) ) {
            return null; // Format legacy pipe-texte — géré côté React
        }

        $slides = array_map( function ( $slide ) {
            $slide = (array) $slide;
            $slide['image'] = ! empty( $slide['imageId'] )
                ? magicieuse_rest_get_image_for_react( (int) $slide['imageId'] )
                : null;
            return $slide;
        }, $slides_raw );

        return [ 'slides' => $slides ];
    }

    return null;
}

function magicieuse_rest_get_image_for_react( int $attachment_id ): ?array {
    static $memo = [];

    if ( array_key_exists( $attachment_id, $memo ) ) {
        return $memo[ $attachment_id ];
    }

    $src = wp_get_attachment_image_src( $attachment_id, 'large' );

    if ( ! $src ) {
        $memo[ $attachment_id ] = null;
        return null;
    }

    $result = [
        'id'      => $attachment_id,
        'url'     => $src[0],
        'width'   => (int) $src[1],
        'height'  => (int) $src[2],
        'alt'     => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ?: '',
        'srcset'  => wp_get_attachment_image_srcset( $attachment_id, 'large' ) ?: '',
        'sizes'   => wp_get_attachment_image_sizes( $attachment_id, 'large' ) ?: '',
        'caption' => wp_get_attachment_caption( $attachment_id ) ?: '',
    ];

    $memo[ $attachment_id ] = $result;

    return $result;
}

function magicieuse_rest_get_buttons_for_react( array $inner_blocks ): array {
    $buttons = [];

    foreach ( $inner_blocks as $inner_block ) {
        if ( ( $inner_block['blockName'] ?? '' ) !== 'core/button' ) {
            continue;
        }

        $button = magicieuse_rest_get_button_for_react( $inner_block );
        if ( $button ) {
            $buttons[] = $button;
        }
    }

    return $buttons;
}

function magicieuse_rest_get_button_for_react( array $block ): ?array {
    $attrs = $block['attrs'] ?? [];
    $html  = $block['innerHTML'] ?? '';
    $url   = $attrs['url'] ?? '';
    $label = '';

    if ( preg_match( '/<a\b[^>]*>(.*?)<\/a>/is', $html, $matches ) ) {
        $label = magicieuse_normalize_text_field( $matches[1] );
    } else {
        $label = magicieuse_normalize_text_field( $html );
    }

    if ( $label === '' && isset( $attrs['text'] ) ) {
        $label = magicieuse_normalize_text_field( $attrs['text'] );
    }

    if ( $url === '' && preg_match( '/href=[\'"]([^\'"]+)[\'"]/i', $html, $matches ) ) {
        $url = $matches[1];
    }

    if ( $label === '' || $url === '' ) {
        return null;
    }

    return [
        'label'     => $label,
        'url'       => esc_url_raw( $url ),
        'target'    => ! empty( $attrs['linkTarget'] ) ? magicieuse_normalize_text_field( $attrs['linkTarget'] ) : null,
        'rel'       => ! empty( $attrs['rel'] ) ? magicieuse_normalize_text_field( $attrs['rel'] ) : null,
        'className' => ! empty( $attrs['className'] ) ? sanitize_html_class( $attrs['className'] ) : null,
    ];
}

function magicieuse_rest_first_text_attr( array $attrs, array $keys ): string {
    foreach ( $keys as $key ) {
        if ( ! empty( $attrs[ $key ] ) && is_scalar( $attrs[ $key ] ) ) {
            $value = magicieuse_normalize_text_field( (string) $attrs[ $key ] );
            if ( $value !== '' ) {
                return $value;
            }
        }
    }

    return '';
}

function magicieuse_rest_get_custom_block_button_for_react( array $attrs, bool $hero = false ): ?array {
    $label_keys = $hero
        ? [ 'primaryButtonLabel', 'buttonLabel', 'ctaLabel', 'linkLabel' ]
        : [ 'buttonLabel', 'primaryButtonLabel', 'ctaLabel', 'linkLabel' ];
    $url_keys = $hero
        ? [ 'primaryButtonUrl', 'buttonUrl', 'ctaUrl', 'linkUrl', 'url' ]
        : [ 'buttonUrl', 'primaryButtonUrl', 'ctaUrl', 'linkUrl', 'url' ];

    $label = magicieuse_rest_first_text_attr( $attrs, $label_keys );
    $url   = magicieuse_rest_first_text_attr( $attrs, $url_keys );

    if ( $label === '' || $url === '' ) {
        return null;
    }

    return [
        'label'     => $label,
        'url'       => esc_url_raw( $url ),
        'target'    => ! empty( $attrs['linkTarget'] ) ? magicieuse_normalize_text_field( (string) $attrs['linkTarget'] ) : null,
        'rel'       => ! empty( $attrs['rel'] ) ? magicieuse_normalize_text_field( (string) $attrs['rel'] ) : null,
        'className' => ! empty( $attrs['className'] ) ? sanitize_html_class( (string) $attrs['className'] ) : null,
    ];
}

function magicieuse_rest_get_products_for_block( array $block ): array {
    $attrs = $block['attrs'] ?? [];
    $ids   = [];

    foreach ( [ 'products', 'productIds', 'ids' ] as $key ) {
        if ( empty( $attrs[ $key ] ) ) {
            continue;
        }

        if ( is_array( $attrs[ $key ] ) ) {
            $ids = array_map( 'absint', $attrs[ $key ] );
        } else {
            $ids = array_filter(
                array_map( 'absint', preg_split( '/\s*,\s*/', (string) $attrs[ $key ] ) )
            );
        }
        break;
    }

    if ( empty( $ids ) && ! empty( $attrs['productId'] ) ) {
        $ids = [ absint( $attrs['productId'] ) ];
    }

    $products = [];

    if ( ! empty( $ids ) ) {
        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product || $product->get_status() !== 'publish' ) {
                continue;
            }

            $products[] = magicieuse_rest_get_product_for_react( $product );
        }

        return $products;
    }

    $limit    = ! empty( $attrs['count'] ) ? max( 1, min( 24, absint( $attrs['count'] ) ) ) : 8;
    $category = ! empty( $attrs['category'] ) ? sanitize_title( (string) $attrs['category'] ) : '';
    $mode     = ! empty( $attrs['mode'] ) ? sanitize_key( (string) $attrs['mode'] ) : '';

    $query_args = [
        'status' => 'publish',
        'limit'  => $limit,
        'orderby' => 'date',
        'order'  => 'DESC',
    ];

    if ( $category !== '' ) {
        $query_args['category'] = [ $category ];
    }

    if ( $mode === 'featured' ) {
        $query_args['featured'] = true;
    }

    $queried_products = wc_get_products( $query_args );

    foreach ( $queried_products as $product ) {
        if ( ! $product instanceof WC_Product ) {
            continue;
        }

        $products[] = magicieuse_rest_get_product_for_react( $product );
    }

    return $products;
}

function magicieuse_rest_get_images_for_block( array $block ): array {
    $attrs = $block['attrs'] ?? [];
    $ids   = [];

    if ( ! empty( $attrs['imageIds'] ) ) {
        $ids = array_filter(
            array_map( 'absint', preg_split( '/\s*,\s*/', (string) $attrs['imageIds'] ) )
        );
    }

    $images = [];
    foreach ( $ids as $id ) {
        $image = magicieuse_rest_get_image_for_react( $id );
        if ( $image ) {
            $images[] = $image;
        }
    }

    return $images;
}

function magicieuse_rest_get_categories_for_block( array $block ): array {
    $attrs = $block['attrs'] ?? [];
    $limit = ! empty( $attrs['count'] ) ? max( 1, min( 24, absint( $attrs['count'] ) ) ) : 8;
    $slugs = [];

    if ( ! empty( $attrs['categorySlugs'] ) ) {
        $slugs = array_filter(
            array_map( 'sanitize_title', preg_split( '/\s*,\s*/', (string) $attrs['categorySlugs'] ) )
        );
    }

    $terms = get_terms( [
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'number'     => empty( $slugs ) ? $limit : 0,
        'slug'       => empty( $slugs ) ? '' : $slugs,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ] );

    if ( is_wp_error( $terms ) ) {
        return [];
    }

    return array_map( function ( WP_Term $term ) {
        $thumbnail_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );

        return [
            'id'          => $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'count'       => $term->count,
            'image'       => $thumbnail_id ? magicieuse_rest_get_image_for_react( $thumbnail_id ) : null,
        ];
    }, $terms );
}

function magicieuse_rest_get_product_highlight_for_block( array $block ): ?array {
    $attrs      = $block['attrs'] ?? [];
    $product_id = ! empty( $attrs['productId'] ) ? absint( $attrs['productId'] ) : 0;

    // Produit choisi manuellement dans l'éditeur.
    if ( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( $product && $product->get_status() === 'publish' ) {
            return magicieuse_rest_get_product_for_react( $product );
        }
    }

    // Aucun produit manuel → on prend le premier produit ⭐ WooCommerce.
    $featured = wc_get_products( [
        'status'   => 'publish',
        'featured' => true,
        'limit'    => 1,
        'orderby'  => 'date',
        'order'    => 'DESC',
    ] );

    return ! empty( $featured ) ? magicieuse_rest_get_product_for_react( $featured[0] ) : null;
}

function magicieuse_rest_price_to_minor_unit( $price ): string {
    if ( $price === '' || $price === null ) {
        return '';
    }

    $minor_unit = wc_get_price_decimals();
    $normalized = (float) wc_format_decimal( $price, $minor_unit );

    return (string) (int) round( $normalized * ( 10 ** $minor_unit ) );
}

function magicieuse_rest_get_product_for_react( WC_Product $product ): array {
    $image_ids = array_filter( array_merge(
        [ $product->get_image_id() ],
        $product->get_gallery_image_ids()
    ) );

    $images = [];
    foreach ( $image_ids as $image_id ) {
        $image = magicieuse_rest_get_image_for_react( (int) $image_id );
        if ( ! $image ) {
            continue;
        }

        $thumbnail = wp_get_attachment_image_src( (int) $image_id, 'woocommerce_thumbnail' );

        $images[] = [
            'id'               => $image['id'],
            'src'              => $image['url'],
            'thumbnail'        => $thumbnail[0] ?? $image['url'],
            'width'            => $image['width'],
            'height'           => $image['height'],
            'thumbnail_width'  => (int) ( $thumbnail[1] ?? 0 ),
            'thumbnail_height' => (int) ( $thumbnail[2] ?? 0 ),
            'thumbnail_srcset' => wp_get_attachment_image_srcset( (int) $image_id, 'woocommerce_thumbnail' ) ?: '',
            'thumbnail_sizes'  => wp_get_attachment_image_sizes( (int) $image_id, 'woocommerce_thumbnail' ) ?: '',
            'srcset'           => $image['srcset'],
            'sizes'            => $image['sizes'],
            'name'             => get_the_title( (int) $image_id ),
            'alt'              => $image['alt'],
        ];
    }

    return [
        'id'                => $product->get_id(),
        'name'              => $product->get_name(),
        'slug'              => $product->get_slug(),
        'permalink'         => get_permalink( $product->get_id() ),
        'type'              => $product->get_type(),
        'short_description' => $product->get_short_description(),
        'description'       => $product->get_description(),
        'prices'            => [
            'price'               => magicieuse_rest_price_to_minor_unit( wc_get_price_to_display( $product ) ),
            'regular_price'       => magicieuse_rest_price_to_minor_unit( $product->get_regular_price() ),
            'sale_price'          => magicieuse_rest_price_to_minor_unit( $product->get_sale_price() ),
            'currency_code'       => get_woocommerce_currency(),
            'currency_symbol'     => get_woocommerce_currency_symbol(),
            'currency_minor_unit' => wc_get_price_decimals(),
        ],
        'images'            => $images,
        'categories'        => array_map( function ( WP_Term $term ) {
            return [
                'id'   => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }, wc_get_product_terms( $product->get_id(), 'product_cat' ) ),
        'is_purchasable'    => $product->is_purchasable(),
        'is_in_stock'       => $product->is_in_stock(),
        'add_to_cart'       => [
            'text'        => $product->add_to_cart_text(),
            'description' => '',
            'url'         => $product->add_to_cart_url(),
        ],
    ];
}

/**
 * Endpoint REST public pour exposer les menus au front React.
 *
 * GET /wp-json/magicieuse/v1/menu/{location}
 * Exemples :
 *   /wp-json/magicieuse/v1/menu/primary
 *   /wp-json/magicieuse/v1/menu/footer
 */
/**
 * Endpoint REST pour les pages WordPress avec rendu complet (Elementor inclus).
 *
 * GET /wp-json/magicieuse/v1/page/{slug}
 *
 * Le endpoint standard /wp/v2/pages ne rend pas le contenu Elementor
 * car le frontend Elementor n'est pas initialise en contexte REST.
 * Ce endpoint force setup_postdata + apply_filters('the_content') cote PHP.
 */
/**
 * Endpoint REST pour les informations d'une collection (categorie produit WooCommerce).
 * Contrairement au Store API qui n'expose que les categories avec des produits,
 * ce endpoint retourne toutes les categories publiees, meme celles a 0 produit.
 *
 * GET /wp-json/magicieuse/v1/collection/{slug}
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'magicieuse/v1', '/collection/(?P<slug>[a-zA-Z0-9_-]+)', [
        'methods'             => 'GET',
        'callback'            => 'magicieuse_rest_get_collection',
        'permission_callback' => '__return_true',
        'args'                => [
            'slug' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_title',
            ],
        ],
    ] );
} );

function magicieuse_rest_get_collection( WP_REST_Request $request ) {
    $slug      = $request->get_param( 'slug' );
    $cache_key = 'magicieuse_collection_' . sanitize_key( $slug );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $term = get_term_by( 'slug', $slug, 'product_cat' );

    if ( ! $term || is_wp_error( $term ) ) {
        return new WP_Error( 'not_found', 'Collection introuvable', [ 'status' => 404 ] );
    }

    $thumbnail_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );

    $response = [
        'id'          => $term->term_id,
        'name'        => $term->name,
        'slug'        => $term->slug,
        'description' => $term->description,
        'count'       => (int) $term->count,
        'image'       => $thumbnail_id ? magicieuse_rest_get_image_for_react( $thumbnail_id ) : null,
        'parent'      => (int) $term->parent,
    ];

    set_transient( $cache_key, $response, HOUR_IN_SECONDS );

    return $response;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'magicieuse/v1', '/page/(?P<slug>[a-zA-Z0-9_-]+)', [
        'methods'             => 'GET',
        'callback'            => 'magicieuse_rest_get_page',
        'permission_callback' => '__return_true',
        'args'                => [
            'slug' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_title',
            ],
        ],
    ] );
} );

add_action( 'rest_api_init', function () {
    register_rest_route( 'magicieuse/v1', '/page/(?P<slug>[a-zA-Z0-9_-]+)/blocks', [
        'methods'             => 'GET',
        'callback'            => 'magicieuse_rest_get_page_blocks',
        'permission_callback' => '__return_true',
        'args'                => [
            'slug' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_title',
            ],
        ],
    ] );
} );

function magicieuse_rest_get_page( WP_REST_Request $request ) {
    $slug      = $request->get_param( 'slug' );
    $cache_key = 'magicieuse_page_' . md5( $slug );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $page = get_page_by_path( $slug, OBJECT, 'page' );

    if ( ! $page || $page->post_status !== 'publish' ) {
        return new WP_Error( 'not_found', 'Page introuvable', [ 'status' => 404 ] );
    }

    global $post;
    $post = $page;
    setup_postdata( $post );

    $content = apply_filters( 'the_content', $page->post_content );

    wp_reset_postdata();

    $response = [
        'id'      => $page->ID,
        'slug'    => $page->post_name,
        'title'   => $page->post_title,
        'content' => $content,
    ];

    set_transient( $cache_key, $response, HOUR_IN_SECONDS );

    return $response;
}

function magicieuse_rest_get_page_blocks( WP_REST_Request $request ) {
    $slug      = $request->get_param( 'slug' );
    $cache_key = 'magicieuse_page_blocks_' . md5( $slug );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $page = get_page_by_path( $slug, OBJECT, 'page' );

    if ( ! $page || $page->post_status !== 'publish' ) {
        return new WP_Error( 'not_found', 'Page introuvable', [ 'status' => 404 ] );
    }

    $response = magicieuse_rest_build_blocks_payload( $page, 'page' );
    set_transient( $cache_key, $response, HOUR_IN_SECONDS );

    return $response;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'magicieuse/v1', '/menu/(?P<location>[a-zA-Z0-9_-]+)', [
        'methods'             => 'GET',
        'callback'            => 'magicieuse_rest_get_menu',
        'permission_callback' => '__return_true',
        'args'                => [
            'location' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_key',
            ],
        ],
    ] );
} );

/**
 * Expose les posts Instagram mis en cache par Smash Balloon Instagram Feed.
 * Ne lit aucun token et n'appelle pas Instagram depuis le navigateur.
 *
 * GET /wp-json/magicieuse/v1/instagram?limit=8&feed_id=1
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'magicieuse/v1', '/instagram', [
        'methods'             => 'GET',
        'callback'            => 'magicieuse_rest_get_instagram_feed',
        'permission_callback' => '__return_true',
        'args'                => [
            'limit' => [
                'required'          => false,
                'default'           => 8,
                'sanitize_callback' => 'absint',
            ],
            'feed_id' => [
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ] );

    register_rest_route( 'magicieuse/v1', '/instagram/image', [
        'methods'             => 'GET',
        'callback'            => 'magicieuse_rest_proxy_instagram_image',
        'permission_callback' => '__return_true',
        'args'                => [
            'url' => [
                'required'          => true,
                'sanitize_callback' => 'esc_url_raw',
            ],
        ],
    ] );
} );

function magicieuse_rest_get_instagram_feed( WP_REST_Request $request ) {
    global $wpdb;

    $limit = max( 1, min( 24, absint( $request->get_param( 'limit' ) ?: 8 ) ) );
    $feed_id = magicieuse_rest_normalize_instagram_feed_id( (string) $request->get_param( 'feed_id' ) );
    $cache_key = magicieuse_rest_instagram_cache_key( $feed_id, $limit );
    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    $posts_table = $wpdb->prefix . 'sbi_instagram_posts';
    $feeds_posts_table = $wpdb->prefix . 'sbi_instagram_feeds_posts';

    $posts_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $posts_table ) );
    if ( $posts_exists !== $posts_table ) {
        $response = [
            'configured' => magicieuse_is_instagram_feed_available(),
            'items'      => magicieuse_rest_get_instagram_items_from_shortcode( $feed_id, $limit ),
        ];

        set_transient( $cache_key, $response, magicieuse_rest_instagram_cache_ttl( $response['items'] ) );

        return $response;
    }

    if ( $feed_id !== '' ) {
        $feeds_posts_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $feeds_posts_table ) );

        if ( $feeds_posts_exists === $feeds_posts_table ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.instagram_id, p.time_stamp, p.media_id, p.mime_type, p.aspect_ratio, p.json_data
                    FROM {$posts_table} p
                    INNER JOIN {$feeds_posts_table} fp ON fp.instagram_id = p.instagram_id
                    WHERE fp.feed_id = %s
                    ORDER BY p.time_stamp DESC
                    LIMIT %d",
                    $feed_id,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = [];
        }
    } else {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT instagram_id, time_stamp, media_id, mime_type, aspect_ratio, json_data
                FROM {$posts_table}
                ORDER BY time_stamp DESC
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }

    $items = array_values( array_filter( array_map( 'magicieuse_rest_normalize_instagram_post', $rows ?: [] ) ) );

    if ( empty( $items ) ) {
        $items = magicieuse_rest_get_instagram_items_from_feed_cache( $feed_id, $limit );
    }

    if ( empty( $items ) ) {
        $items = magicieuse_rest_get_instagram_items_from_shortcode( $feed_id, $limit );
    }

    $response = [
        'configured' => magicieuse_is_instagram_feed_available(),
        'items'      => $items,
    ];

    set_transient( $cache_key, $response, magicieuse_rest_instagram_cache_ttl( $items ) );

    return $response;
}

function magicieuse_is_instagram_feed_available(): bool {
    return defined( 'SBIVER' )
        || class_exists( 'SB_Instagram_Feed' )
        || function_exists( 'sb_instagram_feed_init' );
}

function magicieuse_rest_normalize_instagram_post( array $row ): ?array {
    $raw_json = (string) ( $row['json_data'] ?? '' );

    // Le plugin Instagram Feed chiffre json_data en AES-256-CTR
    if ( class_exists( 'SB_Instagram_Data_Encryption' ) ) {
        $encryption = new SB_Instagram_Data_Encryption();
        $decrypted  = $encryption->decrypt( $raw_json );
        if ( $decrypted !== false ) {
            $raw_json = $decrypted;
        }
    }

    $json = json_decode( $raw_json, true );
    if ( ! is_array( $json ) ) {
        $json = [];
    }

    $media_url = magicieuse_rest_first_text_attr( $json, [ 'media_url', 'thumbnail_url', 'full_url', 'url' ] );
    $permalink = magicieuse_rest_first_text_attr( $json, [ 'permalink', 'link' ] );
    $caption   = magicieuse_rest_first_text_attr( $json, [ 'caption', 'text' ] );
    $media_type = magicieuse_rest_first_text_attr( $json, [ 'media_type', 'type' ] );
    $timestamp = magicieuse_rest_first_text_attr( $json, [ 'timestamp' ] );
    $username  = magicieuse_rest_first_text_attr( $json, [ 'username', 'user_name' ] );

    if ( $timestamp === '' ) {
        $timestamp = (string) ( $row['time_stamp'] ?? '' );
    }

    return [
        'id'           => (string) ( $row['instagram_id'] ?? '' ),
        'media_id'     => (string) ( $row['media_id'] ?? '' ),
        'media_type'   => $media_type ?: (string) ( $row['mime_type'] ?? '' ),
        'media_url'    => esc_url_raw( $media_url ),
        'permalink'    => esc_url_raw( $permalink ),
        'caption'      => wp_strip_all_tags( $caption ),
        'timestamp'    => $timestamp,
        'username'     => $username,
        'aspect_ratio' => isset( $row['aspect_ratio'] ) ? (float) $row['aspect_ratio'] : null,
    ];
}

function magicieuse_rest_normalize_instagram_feed_id( string $feed_id ): string {
    return preg_replace( '/[^A-Za-z0-9_-]/', '', trim( $feed_id ) );
}

function magicieuse_rest_proxy_instagram_image( WP_REST_Request $request ) {
    $url = esc_url_raw( (string) $request->get_param( 'url' ) );
    if ( $url === '' ) {
        return new WP_Error( 'invalid_url', 'URL image invalide', [ 'status' => 400 ] );
    }

    $host = wp_parse_url( $url, PHP_URL_HOST );
    $allowed_hosts = [
        'instagram.com',
        'www.instagram.com',
        'scontent.cdninstagram.com',
        'instagram.fcdn.net',
        'lookaside.instagram.com',
        'cdninstagram.com',
    ];

    $is_allowed = false;
    foreach ( $allowed_hosts as $allowed_host ) {
        if ( $host === $allowed_host || ( $host && str_ends_with( $host, '.' . $allowed_host ) ) ) {
            $is_allowed = true;
            break;
        }
    }

    if ( ! $is_allowed ) {
        return new WP_Error( 'forbidden_host', 'Host image non autorise', [ 'status' => 403 ] );
    }

    $cache_key = 'magicieuse_instagram_proxy_' . md5( $url );
    $uploads = wp_upload_dir();
    $cache_dir = trailingslashit( $uploads['basedir'] ) . 'magicieuse-instagram-cache';
    $cache_url = trailingslashit( $uploads['baseurl'] ) . 'magicieuse-instagram-cache';
    $cache_id = md5( $url );

    $cached_file = glob( $cache_dir . '/' . $cache_id . '.*' );
    if ( ! empty( $cached_file[0] ) && is_readable( $cached_file[0] ) ) {
        $path = $cached_file[0];
        $content_type = function_exists( 'mime_content_type' ) ? ( mime_content_type( $path ) ?: 'image/jpeg' ) : 'image/jpeg';
        nocache_headers();
        header( 'Content-Type: ' . $content_type );
        header( 'Cache-Control: public, max-age=86400, stale-while-revalidate=604800' );
        readfile( $path );
        exit;
    }

    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) && isset( $cached['body'], $cached['content_type'] ) ) {
        if ( ! is_dir( $cache_dir ) ) {
            wp_mkdir_p( $cache_dir );
        }
        $extension = magicieuse_rest_image_extension_from_content_type( $cached['content_type'] );
        $path = trailingslashit( $cache_dir ) . $cache_id . $extension;
        @file_put_contents( $path, $cached['body'] );
        nocache_headers();
        header( 'Content-Type: ' . $cached['content_type'] );
        header( 'Cache-Control: public, max-age=86400, stale-while-revalidate=604800' );
        echo $cached['body'];
        exit;
    }

    $response = wp_remote_get( $url, [
        'timeout'     => 20,
        'redirection' => 3,
        'headers'     => [
            'Accept' => 'image/avif,image/webp,image/*,*/*;q=0.8',
        ],
        'user-agent'  => 'MagicieuseHeadlessProxy/1.0',
    ] );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'fetch_failed', $response->get_error_message(), [ 'status' => 502 ] );
    }

    $status = (int) wp_remote_retrieve_response_code( $response );
    if ( $status === 403 || $status === 401 ) {
        // Instagram CDN blocks server-side fetches for some image types (e.g. carousel);
        // redirect the browser so it fetches the CDN URL directly instead.
        header( 'Location: ' . $url, true, 302 );
        exit;
    }
    if ( $status < 200 || $status >= 300 ) {
        return new WP_Error( 'upstream_error', 'Erreur lors de la recuperation de l\'image', [ 'status' => 502 ] );
    }

    $body = wp_remote_retrieve_body( $response );
    if ( $body === '' ) {
        return new WP_Error( 'empty_body', 'Image vide', [ 'status' => 502 ] );
    }

    $content_type = wp_remote_retrieve_header( $response, 'content-type' ) ?: 'image/jpeg';
    if ( ! is_dir( $cache_dir ) ) {
        wp_mkdir_p( $cache_dir );
    }
    $extension = magicieuse_rest_image_extension_from_content_type( $content_type );
    $path = trailingslashit( $cache_dir ) . $cache_id . $extension;
    @file_put_contents( $path, $body );
    set_transient( $cache_key, [
        'body'         => $body,
        'content_type' => $content_type,
    ], DAY_IN_SECONDS );

    nocache_headers();
    header( 'Content-Type: ' . $content_type );
    header( 'Cache-Control: public, max-age=86400, stale-while-revalidate=604800' );
    echo $body;
    exit;
}

function magicieuse_rest_image_extension_from_content_type( string $content_type ): string {
    $content_type = strtolower( trim( strtok( $content_type, ';' ) ?: $content_type ) );
    return match ( $content_type ) {
        'image/png' => '.png',
        'image/webp' => '.webp',
        'image/gif' => '.gif',
        'image/avif' => '.avif',
        default => '.jpg',
    };
}

function magicieuse_rest_instagram_cache_key( string $feed_id, int $limit ): string {
    return 'magicieuse_instagram_' . md5( $feed_id . '|' . $limit );
}

function magicieuse_rest_instagram_cache_ttl( array $items ): int {
    return empty( $items ) ? 5 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS;
}

function magicieuse_rest_get_instagram_items_from_feed_cache( string $feed_id, int $limit ): array {
    global $wpdb;

    if ( ! class_exists( 'SB_Instagram_Data_Encryption' ) ) {
        return [];
    }

    $cache_table = $wpdb->prefix . 'sbi_feed_caches';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache_table ) ) !== $cache_table ) {
        return [];
    }

    // Resolve numeric feed ID from feed slug if needed
    $numeric_id = '';
    if ( $feed_id !== '' ) {
        $feeds_table = $wpdb->prefix . 'sbi_feeds';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $feeds_table ) ) === $feeds_table ) {
            $numeric_id = (string) $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$feeds_table} WHERE feed_id = %s LIMIT 1", $feed_id )
            );
        }
        if ( $numeric_id === '' && is_numeric( $feed_id ) ) {
            $numeric_id = $feed_id;
        }
    }

    // Try to get cache rows: prefer the customizer variant, then the base feed
    $candidates = [];
    if ( $numeric_id !== '' ) {
        $candidates[] = $numeric_id . '_CUSTOMIZER';
        $candidates[] = $numeric_id;
    } else {
        // No feed_id: pick any feed that has a posts cache
        $all = $wpdb->get_col(
            "SELECT DISTINCT feed_id FROM {$cache_table} WHERE cache_key IN ('posts','posts_backup') ORDER BY feed_id"
        ) ?: [];
        // Prefer _CUSTOMIZER variants
        usort( $all, fn( $a, $b ) => str_contains( $b, '_CUSTOMIZER' ) <=> str_contains( $a, '_CUSTOMIZER' ) );
        $candidates = $all;
    }

    $enc = new SB_Instagram_Data_Encryption();

    foreach ( $candidates as $cid ) {
        foreach ( [ 'posts', 'posts_backup' ] as $cache_key ) {
            $raw = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT cache_value FROM {$cache_table} WHERE feed_id = %s AND cache_key = %s LIMIT 1",
                    $cid,
                    $cache_key
                )
            );

            if ( empty( $raw ) ) {
                continue;
            }

            $decrypted = $enc->decrypt( $raw );
            if ( $decrypted === false ) {
                continue;
            }

            $parsed = json_decode( $decrypted, true );
            $posts  = is_array( $parsed ) && isset( $parsed['data'] ) && is_array( $parsed['data'] )
                ? $parsed['data']
                : ( is_array( $parsed ) ? $parsed : [] );

            if ( empty( $posts ) ) {
                continue;
            }

            $items = [];
            foreach ( array_slice( $posts, 0, $limit ) as $post ) {
                if ( ! is_array( $post ) ) {
                    continue;
                }
                $media_url = magicieuse_rest_first_text_attr( $post, [ 'media_url', 'thumbnail_url', 'full_url', 'url' ] );
                if ( $media_url === '' ) {
                    continue;
                }
                $items[] = [
                    'id'           => (string) ( $post['id'] ?? '' ),
                    'media_id'     => (string) ( $post['id'] ?? '' ),
                    'media_type'   => strtolower( (string) ( $post['media_type'] ?? 'image' ) ),
                    'media_url'    => esc_url_raw( $media_url ),
                    'permalink'    => esc_url_raw( (string) ( $post['permalink'] ?? '' ) ),
                    'caption'      => wp_strip_all_tags( (string) ( $post['caption'] ?? '' ) ),
                    'timestamp'    => (string) ( $post['timestamp'] ?? '' ),
                    'username'     => (string) ( $post['username'] ?? '' ),
                    'aspect_ratio' => null,
                ];
            }

            if ( ! empty( $items ) ) {
                return $items;
            }
        }
    }

    return [];
}

function magicieuse_rest_get_instagram_items_from_shortcode( string $feed_id, int $limit ): array {
    if ( ! shortcode_exists( 'instagram-feed' ) ) {
        return [];
    }

    $feed_id = magicieuse_rest_normalize_instagram_feed_id( $feed_id );
    $shortcode = $feed_id !== ''
        ? sprintf( '[instagram-feed feed="%s"]', esc_attr( $feed_id ) )
        : '[instagram-feed]';

    $html = do_shortcode( $shortcode );
    if ( ! is_string( $html ) || $html === '' || $html === $shortcode ) {
        return [];
    }

    return magicieuse_rest_parse_instagram_shortcode_html( $html, $limit );
}

function magicieuse_rest_parse_instagram_shortcode_html( string $html, int $limit ): array {
    if ( ! class_exists( 'DOMDocument' ) ) {
        return magicieuse_rest_parse_instagram_shortcode_html_with_regex( $html, $limit );
    }

    $items = [];
    $previous = libxml_use_internal_errors( true );
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous );

    if ( ! $loaded ) {
        return magicieuse_rest_parse_instagram_shortcode_html_with_regex( $html, $limit );
    }

    $xpath = new DOMXPath( $dom );
    $selectors = [
        '//a[contains(concat(" ", normalize-space(@class), " "), " sbi_photo ")]',
        '//a[contains(@href, "instagram.com/p/")]',
        '//a[contains(@href, "instagram.com/reel/")]',
        '//a[contains(@href, "instagram.com/tv/")]',
    ];

    $links = [];
    foreach ( $selectors as $selector ) {
        $query = $xpath->query( $selector );
        if ( ! $query || $query->length === 0 ) {
            continue;
        }

        foreach ( $query as $node ) {
            if ( $node instanceof DOMElement ) {
                $links[] = $node;
            }
        }
    }

    if ( empty( $links ) ) {
        $query = $xpath->query( '//a[.//img]' );
        if ( $query ) {
            foreach ( $query as $node ) {
                if ( $node instanceof DOMElement ) {
                    $links[] = $node;
                }
            }
        }
    }

    $links = array_values( array_reduce( $links, function ( array $carry, DOMElement $link ): array {
        $href = magicieuse_rest_clean_instagram_url( $link->getAttribute( 'href' ) );
        $key  = $href !== '' ? $href : spl_object_id( $link );

        if ( ! isset( $carry[ $key ] ) ) {
            $carry[ $key ] = $link;
        }

        return $carry;
    }, [] ) );

    foreach ( $links as $link ) {
        if ( count( $items ) >= $limit || ! $link instanceof DOMElement ) {
            break;
        }

        $media_url = magicieuse_rest_clean_instagram_url( $link->getAttribute( 'data-full-res' ) );
        if ( $media_url === '' ) {
            $media_url = magicieuse_rest_clean_instagram_url( $link->getAttribute( 'data-src' ) );
        }
        if ( $media_url === '' ) {
            $media_url = magicieuse_rest_clean_instagram_url( $link->getAttribute( 'data-img-src' ) );
        }
        if ( $media_url === '' ) {
            $media_url = magicieuse_rest_get_instagram_url_from_srcset_attr( $link->getAttribute( 'data-img-src-set' ) );
        }

        $img = $xpath->query( './/img', $link )->item( 0 );
        $caption = '';
        if ( $img instanceof DOMElement ) {
            $caption = $img->getAttribute( 'alt' );
            if ( $media_url === '' ) {
                $media_url = magicieuse_rest_clean_instagram_url( $img->getAttribute( 'data-src' ) ?: $img->getAttribute( 'src' ) );
            }
            if ( $media_url === '' ) {
                $media_url = magicieuse_rest_clean_instagram_url( $img->getAttribute( 'data-lazy-src' ) );
            }
        }

        if ( $media_url === '' || str_contains( $media_url, '/instagram-feed/img/placeholder' ) ) {
            continue;
        }

        $items[] = [
            'id'           => preg_replace( '/^sbi_/', '', $link->parentNode instanceof DOMElement ? (string) $link->parentNode->parentNode?->getAttribute( 'id' ) : '' ),
            'media_id'     => '',
            'media_type'   => 'image',
            'media_url'    => esc_url_raw( $media_url ),
            'permalink'    => esc_url_raw( magicieuse_rest_clean_instagram_url( $link->getAttribute( 'href' ) ) ),
            'caption'      => wp_strip_all_tags( html_entity_decode( $caption, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
            'timestamp'    => '',
            'username'     => '',
            'aspect_ratio' => 1,
        ];
    }

    return $items;
}

function magicieuse_rest_parse_instagram_shortcode_html_with_regex( string $html, int $limit ): array {
    preg_match_all(
        '/<a\b[^>]*>/i',
        $html,
        $matches
    );
    $items = [];

    foreach ( $matches[0] as $tag ) {
        if ( count( $items ) >= $limit ) {
            break;
        }

        $href = magicieuse_rest_clean_instagram_url( magicieuse_rest_get_html_attr_from_tag( $tag, 'href' ) );
        $is_instagram_link = $href !== '' && preg_match( '#instagram\.com/(p|reel|tv)/#i', $href );
        $has_instagram_class = preg_match( '/\bsbi_photo\b/i', $tag );
        $has_img = preg_match( '/<img\b/i', $tag );

        if ( ! $is_instagram_link && ! $has_instagram_class && ! $has_img ) {
            continue;
        }

        $media_url = magicieuse_rest_clean_instagram_url( magicieuse_rest_get_html_attr_from_tag( $tag, 'data-full-res' ) );
        if ( $media_url === '' ) {
            $media_url = magicieuse_rest_clean_instagram_url( magicieuse_rest_get_html_attr_from_tag( $tag, 'data-src' ) );
        }
        if ( $media_url === '' ) {
            $media_url = magicieuse_rest_clean_instagram_url( magicieuse_rest_get_html_attr_from_tag( $tag, 'data-img-src' ) );
        }
        if ( $media_url === '' ) {
            $media_url = magicieuse_rest_get_instagram_url_from_srcset_attr( magicieuse_rest_get_html_attr_from_tag( $tag, 'data-img-src-set' ) );
        }

        if ( $media_url === '' || str_contains( $media_url, '/instagram-feed/img/placeholder' ) ) {
            continue;
        }

        $items[] = [
            'id'           => '',
            'media_id'     => '',
            'media_type'   => 'image',
            'media_url'    => esc_url_raw( $media_url ),
            'permalink'    => esc_url_raw( $href ),
            'caption'      => '',
            'timestamp'    => '',
            'username'     => '',
            'aspect_ratio' => 1,
        ];
    }

    return $items;
}

function magicieuse_rest_get_html_attr_from_tag( string $tag, string $attr ): string {
    if ( preg_match( '/\s' . preg_quote( $attr, '/' ) . '=(["\'])(.*?)\1/is', $tag, $match ) ) {
        return html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    return '';
}

function magicieuse_rest_get_instagram_url_from_srcset_attr( string $srcset ): string {
    $decoded = json_decode( html_entity_decode( $srcset, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
    if ( ! is_array( $decoded ) ) {
        return '';
    }

    foreach ( [ '640', '320', '150', 'd' ] as $key ) {
        if ( ! empty( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
            return magicieuse_rest_clean_instagram_url( $decoded[ $key ] );
        }
    }

    return '';
}

function magicieuse_rest_clean_instagram_url( string $url ): string {
    return html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Thème front — liste des thèmes disponibles (extensible via filtre).
 */
function magicieuse_get_available_themes(): array {
    return apply_filters( 'magicieuse_available_themes', [
        'magicieuse'       => 'Magicieuse — Dark Luxury',
        'magicieuse-clair' => 'Magicieuse — Clair & Coloré',
        'field-folio'      => 'Field & Folio — Éditorial',
    ] );
}

/**
 * GET /wp-json/magicieuse/v1/theme — retourne le thème actif (public).
 * POST /wp-json/magicieuse/v1/theme — met à jour le thème (admin uniquement).
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'magicieuse/v1', '/theme', [
        [
            'methods'             => 'GET',
            'callback'            => function () {
                return [ 'theme' => get_option( 'magicieuse_active_theme', 'magicieuse' ) ];
            },
            'permission_callback' => '__return_true',
        ],
        [
            'methods'             => 'POST',
            'callback'            => function ( WP_REST_Request $req ) {
                $theme     = sanitize_key( $req->get_param( 'theme' ) );
                $available = array_keys( magicieuse_get_available_themes() );

                if ( ! in_array( $theme, $available, true ) ) {
                    return new WP_Error( 'invalid_theme', 'Thème inconnu.', [ 'status' => 400 ] );
                }

                update_option( 'magicieuse_active_theme', $theme );
                magicieuse_flush_front_cache();
                return [ 'theme' => $theme ];
            },
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [
                'theme' => [ 'required' => true, 'type' => 'string' ],
            ],
        ],
    ] );
} );

/**
 * Page admin Réglages > Thème front.
 */
add_action( 'admin_menu', function () {
    add_options_page(
        'Thème front',
        'Thème front',
        'manage_options',
        'magicieuse-theme',
        'magicieuse_theme_settings_page'
    );
} );

function magicieuse_theme_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $notice = '';

    if (
        isset( $_POST['magicieuse_theme_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['magicieuse_theme_nonce'] ) ), 'magicieuse_save_theme' )
    ) {
        $theme     = sanitize_key( $_POST['magicieuse_active_theme'] ?? '' );
        $available = array_keys( magicieuse_get_available_themes() );

        if ( in_array( $theme, $available, true ) ) {
            update_option( 'magicieuse_active_theme', $theme );
            magicieuse_flush_front_cache();
            $notice = '<div class="notice notice-success is-dismissible"><p>Thème enregistré.</p></div>';
        } else {
            $notice = '<div class="notice notice-error"><p>Thème invalide.</p></div>';
        }
    }

    $current = get_option( 'magicieuse_active_theme', 'magicieuse' );
    $themes  = magicieuse_get_available_themes();
    ?>
    <div class="wrap">
        <h1>Thème du front React</h1>
        <?php echo wp_kses_post( $notice ); ?>
        <form method="post">
            <?php wp_nonce_field( 'magicieuse_save_theme', 'magicieuse_theme_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="magicieuse_active_theme">Thème actif</label></th>
                    <td>
                        <select name="magicieuse_active_theme" id="magicieuse_active_theme">
                            <?php foreach ( $themes as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            Le front React applique ce thème au prochain chargement de page.
                            Prévisualiser sur <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank">le site</a>.
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Enregistrer le thème' ); ?>
        </form>
    </div>
    <?php
}

function magicieuse_rest_get_menu( WP_REST_Request $request ) {
    $location  = $request->get_param( 'location' );
    $cache_key = 'magicieuse_menu_' . $location;
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $locations = get_nav_menu_locations();

    if ( empty( $locations[ $location ] ) ) {
        return new WP_Error(
            'no_menu',
            "Aucun menu assigne a l'emplacement : {$location}",
            [ 'status' => 404 ]
        );
    }

    $items = wp_get_nav_menu_items( (int) $locations[ $location ] );
    if ( ! $items ) {
        return [];
    }

    $site_url = rtrim( home_url( '/' ), '/' );

    $result = array_values( array_map( function ( WP_Post $item ) use ( $site_url ) {
        $url         = $item->url;
        $object_type = $item->object; // 'page', 'post', 'product_cat', 'custom', ...
        $is_external = strpos( $url, $site_url ) !== 0;
        $path        = $is_external
            ? $url
            : ( '/' . ltrim( substr( $url, strlen( $site_url ) ), '/' ) );

        // Reecriture des categories WooCommerce vers la route React /collections/:slug/
        if ( $object_type === 'product_cat' ) {
            $term = get_term( (int) $item->object_id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $path        = '/collections/' . $term->slug . '/';
                $is_external = false;
            }
        }

        return [
            'id'          => $item->ID,
            'title'       => $item->title,
            'url'         => $url,
            'path'        => $path,
            'target'      => $item->target ?: null,
            'parent'      => (int) $item->menu_item_parent,
            'order'       => (int) $item->menu_order,
            'is_external' => $is_external,
            'object_type' => $object_type,
        ];
    }, $items ) );

    set_transient( $cache_key, $result, HOUR_IN_SECONDS );

    return $result;
}

// ---------------------------------------------------------------------------
// Gestion du cache — invalidation automatique
// ---------------------------------------------------------------------------

function magicieuse_flush_front_cache(): void {
    delete_transient( 'magicieuse_front' );
    delete_transient( 'magicieuse_front_page' );
    delete_transient( 'magicieuse_front_blocks' );
}

function magicieuse_flush_content_cache( int $post_id ): void {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return;
    }
    delete_transient( 'magicieuse_content_' . md5( $post->post_name ) );
    delete_transient( 'magicieuse_page_' . md5( $post->post_name ) );
    delete_transient( 'magicieuse_page_blocks_' . md5( $post->post_name ) );
}

function magicieuse_flush_collection_cache( int $term_id ): void {
    $term = get_term( $term_id, 'product_cat' );
    if ( $term && ! is_wp_error( $term ) ) {
        delete_transient( 'magicieuse_collection_' . sanitize_key( $term->slug ) );
    }
}

function magicieuse_flush_instagram_cache(): void {
    global $wpdb;

    $wpdb->query(
        "DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_magicieuse_instagram_%'
        OR option_name LIKE '_transient_timeout_magicieuse_instagram_%'"
    );
}

function magicieuse_flush_menu_cache(): void {
    foreach ( array_keys( get_nav_menu_locations() ) as $location ) {
        delete_transient( 'magicieuse_menu_' . $location );
    }
}

// Page ou article modifie → vider les caches content/page correspondants
add_action( 'save_post', function ( int $post_id ): void {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }
    magicieuse_flush_content_cache( $post_id );

    if ( (int) get_option( 'page_on_front' ) === $post_id ) {
        magicieuse_flush_front_cache();
        magicieuse_flush_instagram_cache();
    }
} );

// Artiste modifie → vider le cache artistes
add_action( 'save_post_artiste_s', function ( int $post_id ): void {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }
    delete_transient( 'magicieuse_artistes' );
    delete_transient( 'magicieuse_content_' . md5( 'artistes' ) );
} );

// Produit modifie → vider les caches brands du slug correspondant
add_action( 'save_post_product', function ( int $post_id ): void {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }
    $post = get_post( $post_id );
    if ( $post ) {
        delete_transient( 'magicieuse_brands_' . md5( $post->post_name ) );
    }
} );

// Categorie produit modifiee → vider le cache front + collection
add_action( 'edited_product_cat', function ( int $term_id ): void {
    magicieuse_flush_front_cache();
    magicieuse_flush_collection_cache( $term_id );
} );
add_action( 'created_product_cat', function (): void {
    magicieuse_flush_front_cache();
} );
add_action( 'deleted_product_cat', function ( int $term_id ): void {
    magicieuse_flush_front_cache();
    magicieuse_flush_collection_cache( $term_id );
} );

// Menu modifie → vider le cache menus
add_action( 'wp_update_nav_menu', function (): void {
    magicieuse_flush_menu_cache();
} );

// Feed Instagram modifie par Smash Balloon → forcer un rechargement frais.
add_action( 'sbi_feed_update', 'magicieuse_flush_instagram_cache' );
add_action( 'sbi_before_feed_update', 'magicieuse_flush_instagram_cache' );

/**
 * Retourne les auteurs/illustrateurs d'un produit (taxonomie pwb-brand).
 * Regroupe les artistes par rôle (catégorie parente du brand).
 *
 * GET /wp-json/magicieuse/v1/product/{slug}/brands
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'magicieuse/v1', '/contact', [
        'methods'             => 'POST',
        'callback'            => 'magicieuse_rest_contact_form',
        'permission_callback' => '__return_true',
        'args'                => [
            'name'    => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
            'email'   => [ 'required' => true,  'sanitize_callback' => 'sanitize_email' ],
            'subject' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'message' => [ 'required' => true,  'sanitize_callback' => 'sanitize_textarea_field' ],
        ],
    ] );

    register_rest_route( 'magicieuse/v1', '/product/(?P<slug>[a-zA-Z0-9_-]+)/brands', [
        'methods'             => 'GET',
        'callback'            => 'magicieuse_rest_get_product_brands',
        'permission_callback' => '__return_true',
        'args'                => [
            'slug' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_title',
            ],
        ],
    ] );
} );

function magicieuse_rest_get_product_brands( WP_REST_Request $request ) {
    $slug      = $request->get_param( 'slug' );
    $cache_key = 'magicieuse_brands_' . md5( $slug );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $posts = get_posts( [
        'post_type'   => 'product',
        'name'        => $slug,
        'post_status' => 'publish',
        'numberposts' => 1,
    ] );

    if ( empty( $posts ) ) {
        return new WP_Error( 'not_found', 'Produit introuvable', [ 'status' => 404 ] );
    }

    $product_id = $posts[0]->ID;
    $brands     = get_the_terms( $product_id, 'pwb-brand' );

    if ( ! $brands || is_wp_error( $brands ) ) {
        return [];
    }

    // Indexer tous les parents (rôles) pour éviter plusieurs get_term()
    $parent_ids = array_unique( array_filter( wp_list_pluck( $brands, 'parent' ) ) );
    $roles      = [];
    foreach ( $parent_ids as $pid ) {
        $parent = get_term( $pid, 'pwb-brand' );
        if ( $parent && ! is_wp_error( $parent ) ) {
            $roles[ $pid ] = [ 'id' => $pid, 'name' => $parent->name, 'slug' => $parent->slug, 'people' => [] ];
        }
    }

    foreach ( $brands as $brand ) {
        if ( $brand->parent === 0 ) {
            continue; // ignorer les catégories parentes elles-mêmes
        }
        if ( isset( $roles[ $brand->parent ] ) ) {
            $roles[ $brand->parent ]['people'][] = [
                'id'   => $brand->term_id,
                'name' => $brand->name,
                'slug' => $brand->slug,
            ];
        }
    }

    $response = array_values( $roles );
    set_transient( $cache_key, $response, HOUR_IN_SECONDS );

    return $response;
}

// ---------------------------------------------------------------------------
// Instagram cache auto-refresh (every 24 h via WP-Cron)
// ---------------------------------------------------------------------------

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'magicieuse_instagram_refresh' ) ) {
        wp_schedule_event( time(), 'daily', 'magicieuse_instagram_refresh' );
    }
} );

add_action( 'magicieuse_instagram_refresh', 'magicieuse_instagram_do_refresh' );

function magicieuse_instagram_do_refresh(): void {
    global $wpdb;

    if ( ! class_exists( 'SB_Instagram_Data_Encryption' ) ) {
        return;
    }

    $enc = new SB_Instagram_Data_Encryption();

    // Find the account_id(s) linked to published feeds (settings->id field)
    $feeds_table      = $wpdb->prefix . 'sbi_feeds';
    $preferred_ids    = [];
    $feed_settings_rows = $wpdb->get_col( "SELECT settings FROM {$feeds_table} WHERE status='publish'" ) ?: [];
    foreach ( $feed_settings_rows as $raw ) {
        $s = json_decode( $raw, true );
        if ( ! empty( $s['id'] ) ) {
            $preferred_ids[] = (string) $s['id'];
        }
    }

    // Order sources so feed-linked accounts come first
    $sources = $wpdb->get_results( "SELECT account_id, access_token FROM {$wpdb->prefix}sbi_sources", ARRAY_A ) ?: [];
    usort( $sources, function ( $a, $b ) use ( $preferred_ids ) {
        return (int) ! in_array( $a['account_id'], $preferred_ids, true )
             - (int) ! in_array( $b['account_id'], $preferred_ids, true );
    } );

    if ( empty( $sources ) ) {
        return;
    }

    $token = '';
    foreach ( $sources as $src ) {
        $decrypted = $enc->decrypt( (string) $src['access_token'] );
        if ( $decrypted !== false && $decrypted !== '' ) {
            $token = $decrypted;
            break;
        }
    }

    if ( $token === '' ) {
        return;
    }

    $api_url = add_query_arg( [
        'fields'       => 'id,media_type,media_url,thumbnail_url,permalink,caption,timestamp,username,like_count,comments_count',
        'access_token' => $token,
        'limit'        => 20,
    ], 'https://graph.instagram.com/v22.0/me/media' );

    $resp = wp_remote_get( $api_url, [ 'timeout' => 20 ] );
    if ( is_wp_error( $resp ) ) {
        return;
    }

    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
        return;
    }

    $cache_table = $wpdb->prefix . 'sbi_feed_caches';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache_table ) ) !== $cache_table ) {
        return;
    }

    $payload   = json_encode( [
        'last_requested' => time(),
        'last_retrieve'  => time(),
        'atts'           => [],
        'data'           => $body['data'],
        'pagination'     => $body['paging'] ?? [],
        'errors'         => [],
    ] );
    $encrypted = $enc->encrypt( $payload );
    $now       = current_time( 'mysql' );

    // Detect feed IDs actually present in the table
    $feed_ids = $wpdb->get_col( "SELECT DISTINCT feed_id FROM {$cache_table}" ) ?: [];
    // Fallback: always write feed 2 (the default SBI feed)
    if ( empty( $feed_ids ) ) {
        $feed_ids = [ '2', '2_CUSTOMIZER' ];
    }

    foreach ( $feed_ids as $feed_id ) {
        foreach ( [ 'posts', 'posts_backup' ] as $cache_key ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$cache_table} WHERE feed_id=%s AND cache_key=%s",
                $feed_id, $cache_key
            ) );
            if ( $existing ) {
                $wpdb->update(
                    $cache_table,
                    [ 'cache_value' => $encrypted, 'last_updated' => $now ],
                    [ 'feed_id' => $feed_id, 'cache_key' => $cache_key ]
                );
            } else {
                $wpdb->insert( $cache_table, [
                    'feed_id'      => $feed_id,
                    'cache_key'    => $cache_key,
                    'cache_value'  => $encrypted,
                    'cron_update'  => 'yes',
                    'last_updated' => $now,
                ] );
            }
        }
    }

    // Bust our REST-level transient so fresh data is served immediately
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_magicieuse_instagram_%'
         OR option_name LIKE '_transient_timeout_magicieuse_instagram_%'"
    );

    // Delete on-disk proxy image cache so stale CDN images are re-fetched
    $uploads   = wp_upload_dir();
    $cache_dir = trailingslashit( $uploads['basedir'] ) . 'magicieuse-instagram-cache';
    if ( is_dir( $cache_dir ) ) {
        array_map( 'unlink', glob( $cache_dir . '/*' ) ?: [] );
    }
}

// ---------------------------------------------------------------------------
// Formulaire de contact — POST /wp-json/magicieuse/v1/contact
// ---------------------------------------------------------------------------

function magicieuse_rest_contact_form( WP_REST_Request $request ) {
    $name    = $request->get_param( 'name' );
    $email   = $request->get_param( 'email' );
    $subject = $request->get_param( 'subject' ) ?: 'Message depuis le formulaire de contact';
    $message = $request->get_param( 'message' );

    if ( ! is_email( $email ) ) {
        return new WP_Error( 'invalid_email', 'Adresse email invalide.', [ 'status' => 422 ] );
    }

    if ( strlen( $message ) < 10 ) {
        return new WP_Error( 'message_too_short', 'Le message est trop court.', [ 'status' => 422 ] );
    }

    // Limite : 3 envois par IP par heure
    $ip       = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
    $rate_key = 'magicieuse_contact_rate_' . md5( $ip );
    $count    = (int) get_transient( $rate_key );
    if ( $count >= 3 ) {
        return new WP_Error( 'rate_limited', 'Trop de messages envoyés. Réessayez dans une heure.', [ 'status' => 429 ] );
    }
    set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

    // Sauvegarde en base (visible dans WP Admin > Messages de contact)
    $post_id = wp_insert_post( [
        'post_type'    => 'contact_message',
        'post_title'   => sanitize_text_field( $subject ),
        'post_content' => sanitize_textarea_field( $message ),
        'post_status'  => 'private',
        'meta_input'   => [
            '_contact_name'  => sanitize_text_field( $name ),
            '_contact_email' => sanitize_email( $email ),
        ],
    ] );

    if ( is_wp_error( $post_id ) || ! $post_id ) {
        return new WP_Error( 'save_failed', 'Impossible d\'enregistrer le message.', [ 'status' => 500 ] );
    }

    // Tentative d'envoi email (fonctionne si SMTP configuré en production)
    $to           = get_option( 'admin_email' );
    $site_name    = get_bloginfo( 'name' );
    $admin_domain = substr( strrchr( $to, '@' ), 1 ) ?: 'example.com';
    $from_email   = 'noreply@' . $admin_domain;
    $mail_subject = '[' . $site_name . '] ' . $subject;
    $mail_body    = "Nom : {$name}\nEmail : {$email}\n\nMessage :\n{$message}\n";
    $headers      = [
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $site_name . ' <' . $from_email . '>',
        'Reply-To: ' . $name . ' <' . $email . '>',
    ];
    wp_mail( $to, $mail_subject, $mail_body, $headers );

    return [ 'success' => true, 'message' => 'Votre message a bien été envoyé.' ];
}

// Enregistrement du post type "contact_message"
add_action( 'init', function (): void {
    register_post_type( 'contact_message', [
        'label'               => 'Messages de contact',
        'labels'              => [
            'name'          => 'Messages de contact',
            'singular_name' => 'Message de contact',
            'all_items'     => 'Tous les messages',
            'view_item'     => 'Voir le message',
        ],
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'capability_type'     => 'post',
        'supports'            => [ 'title', 'editor', 'custom-fields' ],
        'menu_icon'           => 'dashicons-email-alt',
    ] );
} );
