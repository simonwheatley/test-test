<?php
/*
Plugin Name: SW Test Test
Plugin URI: https://github.com/simonwheatley/test-test
*/

function sw_test_test( $title ) {
	$title .= " SW TEST TEST";
	return $title;
}
add_filter( 'the_title', 'sw_test_test' );