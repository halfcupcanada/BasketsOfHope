<?php
/**
 * Page template - used for all WP Pages (Home, Donate, RSVP, etc.).
 * Renders the page content directly; sections handle their own layout
 * (cover blocks span full-width, content groups self-contain padding).
 *
 * @package BoH
 */
get_header();

while ( have_posts() ) :
    the_post(); ?>
    <article id="post-<?php the_ID(); ?>" <?php post_class( 'boh-page' ); ?>>
      <div class="entry-content">
        <?php the_content(); ?>
      </div>
    </article>
<?php endwhile;

get_footer();
