<?php
/**
 * Fixture theme setup.
 *
 * @package SDIFixture
 */

add_action(
	'after_setup_theme',
	function () {
		add_theme_support( 'woocommerce' );
		add_theme_support( 'post-thumbnails' );
		register_nav_menus(
			array(
				'primary' => 'Primary',
				'footer'  => 'Footer',
			)
		);
	}
);
