<?php
/**
 * Generic fallback template - used when no more specific template exists
 * (archives, search results, the blog index).
 *
 * @package BoH
 */
get_header(); ?>

<div class="boh-archive">
  <div class="boh-archive__inner">

    <?php if ( have_posts() ) : ?>
      <header class="boh-archive__header">
        <h1 class="boh-archive__title">
          <?php
          if ( is_search() ) {
              printf( /* translators: %s: search term */ esc_html__( 'Results for "%s"', 'boh' ), get_search_query() );
          } elseif ( is_archive() ) {
              the_archive_title();
          } else {
              esc_html_e( 'Latest', 'boh' );
          }
          ?>
        </h1>
      </header>

      <div class="boh-archive__list">
        <?php while ( have_posts() ) : the_post(); ?>
          <article <?php post_class( 'boh-archive__item' ); ?>>
            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
            <p class="boh-archive__meta"><?php echo esc_html( get_the_date() ); ?></p>
            <div class="boh-archive__excerpt"><?php the_excerpt(); ?></div>
          </article>
        <?php endwhile; ?>
      </div>

      <?php the_posts_pagination( [ 'mid_size' => 1 ] ); ?>

    <?php else : ?>
      <div class="boh-empty">
        <h1><?php esc_html_e( 'Nothing here yet.', 'boh' ); ?></h1>
        <p><a href="<?php echo esc_url( home_url( '/' ) ); ?>">&larr; <?php esc_html_e( 'Back to home', 'boh' ); ?></a></p>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php get_footer();
