<?php
/*
   Template Name: ARTISTES
*/

 get_header(); ?>

 <section id="primary" class="content-area col-sm-12 col-lg-8">
     <main id="main" class="site-main" role="main">

         <?php
         while ( have_posts() ) : the_post();

             get_template_part( 'content_artiste' );

             if ( comments_open() || get_comments_number() ) :
                comments_template();
            endif;


         endwhile; // End of the loop.
         ?>

</main>
 </section>

 <?php 
 get_sidebar();
 get_footer();


 