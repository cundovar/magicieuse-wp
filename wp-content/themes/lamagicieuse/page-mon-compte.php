<?php
/**
 * Template de page dedie a l'espace Mon compte WooCommerce.
 *
 * La page mon compte doit rester en pleine largeur pour ne pas afficher
 * la sidebar/widget du template de page par defaut.
 */

get_header();
?>

<section id="primary" class="content-area col-sm-12 account-content-area">
    <main id="main" class="site-main account-site-main" role="main">
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
