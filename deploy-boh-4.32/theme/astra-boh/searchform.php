<?php
/**
 * Search form template.
 *
 * @package BoH
 */
?>
<form role="search" method="get" class="boh-search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
  <label class="screen-reader-text" for="boh-s"><?php esc_html_e( 'Search for:', 'boh' ); ?></label>
  <input type="search" id="boh-s" class="boh-search__input" placeholder="<?php esc_attr_e( 'Search…', 'boh' ); ?>" value="<?php echo esc_attr( get_search_query() ); ?>" name="s" />
  <button type="submit" class="boh-search__submit"><?php esc_html_e( 'Search', 'boh' ); ?></button>
</form>
