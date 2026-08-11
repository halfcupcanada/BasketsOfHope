<?php
/**
 * Header template — minimal, transparent over the hero, becomes
 * fixed/white-blur on scroll. Layout is handled entirely by CSS.
 *
 * @package BoH
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo( 'charset' ); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="profile" href="https://gmpg.org/xfn/11">
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="boh-skip-link screen-reader-text" href="#content"><?php esc_html_e( 'Skip to content', 'boh' ); ?></a>

<header id="masthead" class="boh-header" role="banner">
  <div class="boh-header__inner">
    <div class="boh-header__brand">
      <a class="boh-header__title" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
        <img class="boh-header__logo" src="<?php echo esc_url( function_exists( 'boh_logo_url' ) ? boh_logo_url( 'icon' ) : home_url( '/wp-content/uploads/2026/06/boh-logo-150x150.png' ) ); ?>" alt="" width="34" height="34" decoding="async">
        <span><?php bloginfo( 'name' ); ?></span>
      </a>
    </div>

    <nav class="boh-nav" aria-label="<?php esc_attr_e( 'Primary', 'boh' ); ?>">
      <?php
      wp_nav_menu( [
        'theme_location' => 'primary',
        'menu_class'     => 'boh-nav__list',
        'container'      => false,
        'fallback_cb'    => false,
        'depth'          => 1,
      ] );
      ?>
    </nav>

    <button class="boh-nav__toggle" aria-label="<?php esc_attr_e( 'Menu', 'boh' ); ?>" aria-expanded="false" aria-controls="boh-mobile-nav">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>

<main id="content" class="boh-main" role="main">
