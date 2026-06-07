<?php
/**
 * Plugin Name: Magicieuse Headless API
 * Description: Endpoints REST et emplacements de menu pour le front headless React.
 * Version:     1.0.0
 * Author:      Magicieuse
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
    $slug  = $request->get_param( 'slug' );
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

    $featured_image = null;
    $thumbnail_id   = get_post_thumbnail_id( $found->ID );
    if ( $thumbnail_id ) {
        $img = wp_get_attachment_image_src( $thumbnail_id, 'large' );
        if ( $img ) {
            $featured_image = [
                'url'    => $img[0],
                'width'  => (int) $img[1],
                'height' => (int) $img[2],
            ];
        }
    }

    wp_reset_postdata();

    return [
        'id'             => $found->ID,
        'featured_image' => $featured_image,
        'slug'    => $found->post_name,
        'type'    => $type,
        'title'   => $found->post_title,
        'content' => $content,
        'excerpt' => $excerpt,
        'date'    => $found->post_date,
    ];
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
    $query = new WP_Query( [
        'post_type'      => 'artiste_s',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'rand',
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

    return $artistes;
}

function magicieuse_rest_get_front_page() {
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

    return [
        'id'      => $page->ID,
        'slug'    => $page->post_name,
        'type'    => 'page',
        'title'   => $page->post_title,
        'content' => $content,
        'excerpt' => $excerpt,
        'date'    => $page->post_date,
    ];
}

function magicieuse_rest_get_front_page_blocks() {
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

    return magicieuse_rest_build_blocks_payload( $page, 'page' );
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
        'renderedHTML' => render_block( $block ),
        'data'         => magicieuse_rest_enrich_block( $block ),
    ];
}

function magicieuse_rest_enrich_block( array $block ) {
    $name  = $block['blockName'] ?? '';
    $attrs = $block['attrs'] ?? [];

    if ( $name === 'core/image' && ! empty( $attrs['id'] ) ) {
        return [
            'image' => magicieuse_rest_get_image_for_react( (int) $attrs['id'] ),
        ];
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
        ], true )
    ) {
        $products = magicieuse_rest_get_products_for_block( $block );

        return [
            'products' => $products,
        ];
    }

    return null;
}

function magicieuse_rest_get_image_for_react( int $attachment_id ): ?array {
    $src = wp_get_attachment_image_src( $attachment_id, 'large' );

    if ( ! $src ) {
        return null;
    }

    return [
        'id'     => $attachment_id,
        'url'    => $src[0],
        'width'  => (int) $src[1],
        'height' => (int) $src[2],
        'alt'    => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ?: '',
        'srcset' => wp_get_attachment_image_srcset( $attachment_id, 'large' ) ?: '',
        'sizes'  => wp_get_attachment_image_sizes( $attachment_id, 'large' ) ?: '',
        'caption' => wp_get_attachment_caption( $attachment_id ) ?: '',
    ];
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

function magicieuse_rest_get_products_for_block( array $block ): array {
    $attrs = $block['attrs'] ?? [];
    $ids   = [];

    foreach ( [ 'products', 'productIds', 'ids' ] as $key ) {
        if ( empty( $attrs[ $key ] ) || ! is_array( $attrs[ $key ] ) ) {
            continue;
        }

        $ids = array_map( 'absint', $attrs[ $key ] );
        break;
    }

    if ( empty( $ids ) && ! empty( $attrs['productId'] ) ) {
        $ids = [ absint( $attrs['productId'] ) ];
    }

    if ( empty( $ids ) ) {
        return [];
    }

    $products = [];

    foreach ( $ids as $id ) {
        $product = wc_get_product( $id );
        if ( ! $product || $product->get_status() !== 'publish' ) {
            continue;
        }

        $products[] = magicieuse_rest_get_product_for_react( $product );
    }

    return $products;
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

        $images[] = [
            'id'        => $image['id'],
            'src'       => $image['url'],
            'thumbnail' => wp_get_attachment_image_url( (int) $image_id, 'woocommerce_thumbnail' ) ?: $image['url'],
            'srcset'    => $image['srcset'],
            'sizes'     => $image['sizes'],
            'name'      => get_the_title( (int) $image_id ),
            'alt'       => $image['alt'],
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
            'price'               => (string) wc_get_price_to_display( $product ),
            'regular_price'       => (string) $product->get_regular_price(),
            'sale_price'          => (string) $product->get_sale_price(),
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
    $slug = $request->get_param( 'slug' );
    $term = get_term_by( 'slug', $slug, 'product_cat' );

    if ( ! $term || is_wp_error( $term ) ) {
        return new WP_Error( 'not_found', 'Collection introuvable', [ 'status' => 404 ] );
    }

    $image        = null;
    $thumbnail_id = get_term_meta( $term->term_id, 'thumbnail_id', true );
    if ( $thumbnail_id ) {
        $image = wp_get_attachment_url( $thumbnail_id ) ?: null;
    }

    return [
        'id'          => $term->term_id,
        'name'        => $term->name,
        'slug'        => $term->slug,
        'description' => $term->description,
        'count'       => (int) $term->count,
        'image'       => $image,
    ];
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
    $slug = $request->get_param( 'slug' );
    $page = get_page_by_path( $slug, OBJECT, 'page' );

    if ( ! $page || $page->post_status !== 'publish' ) {
        return new WP_Error( 'not_found', 'Page introuvable', [ 'status' => 404 ] );
    }

    global $post;
    $post = $page;
    setup_postdata( $post );

    $content = apply_filters( 'the_content', $page->post_content );

    wp_reset_postdata();

    return [
        'id'      => $page->ID,
        'slug'    => $page->post_name,
        'title'   => $page->post_title,
        'content' => $content,
    ];
}

function magicieuse_rest_get_page_blocks( WP_REST_Request $request ) {
    $slug = $request->get_param( 'slug' );
    $page = get_page_by_path( $slug, OBJECT, 'page' );

    if ( ! $page || $page->post_status !== 'publish' ) {
        return new WP_Error( 'not_found', 'Page introuvable', [ 'status' => 404 ] );
    }

    return magicieuse_rest_build_blocks_payload( $page, 'page' );
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

function magicieuse_rest_get_menu( WP_REST_Request $request ) {
    $location  = $request->get_param( 'location' );
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

    return array_values( array_map( function ( WP_Post $item ) use ( $site_url ) {
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
}
