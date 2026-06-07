<?php
/**
 * The template for displaying all pages
 *
 * This is the template that displays all pages by default.
 * Please note that this is the WordPress construct of pages
 * and that other 'pages' on your WordPress site may use a
 * different template.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package WP_Bootstrap_Starter
 */

get_header(); ?>

	<section id="primary" class="content-area col-sm-12 col-lg-8">
		<main id="main" class="site-main" role="main">

			<?php
			while ( have_posts() ) : the_post();

				get_template_part( 'template-parts/content', 'page' );


			endwhile; // End of the loop.

$cat_args = array(
    'orderby'    => 'name',
    'order'      => 'asc',
    'hide_empty' => false,
);

$product_categories = get_terms( 'product_cat', $cat_args );

if( !empty($product_categories) ){
	echo '

<ul>';
	foreach ($product_categories as $key => $category) {
		echo '

<li>';
		echo '<a href="'.get_term_link($category).'" >';
		echo $category->name;
		echo '</a>';
		echo '</li>';
	}
	echo '</ul>


';}    
			?>

		</main><!-- #main -->
	</section><!-- #primary -->

<?php
get_sidebar();
get_footer();
