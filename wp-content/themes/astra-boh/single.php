<?php
/**
 * Single post template - used for individual posts (blog, custom post types
 * that don't have their own template, etc.).
 *
 * @package BoH
 */
get_header(); ?>

<div class="boh-single">
  <div class="boh-single__inner">
    <?php while ( have_posts() ) : the_post(); ?>
      <article id="post-<?php the_ID(); ?>" <?php post_class( 'boh-single__article' ); ?>>
        <header class="boh-single__header">
          <h1 class="boh-single__title"><?php the_title(); ?></h1>
          <p class="boh-single__meta"><?php echo esc_html( get_the_date() ); ?></p>
        </header>

        <div class="entry-content boh-single__content">
          <?php
          the_content();
          wp_link_pages( [
            'before' => '<nav class="boh-single__pages">',
            'after'  => '</nav>',
          ] );
          ?>
        </div>
      </article>

      <?php if ( comments_open() || get_comments_number() ) : comments_template(); endif; ?>
    <?php endwhile; ?>
  </div>
</div>

<?php get_footer();
