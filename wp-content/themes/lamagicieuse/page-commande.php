<?php
/**
 * Template de page dedie au checkout WooCommerce.
 *
 * La page commande a besoin de toute la largeur disponible pour eviter que
 * les champs et le recapitulatif soient compresses par la sidebar.
 */

get_header();
?>

<section id="primary" class="content-area col-sm-12 checkout-content-area">
    <main id="main" class="site-main checkout-site-main" role="main">
        <?php
        while ( have_posts() ) :
            the_post();
            get_template_part( 'template-parts/content', 'page' );
        endwhile;
        ?>
    </main>
</section>

<?php
get_footer();
