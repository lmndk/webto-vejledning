<?php
/**
 * Søgeindekset til panelet på sitet (hentes først, når man søger). Kun for brugere, der må læse vejledningen.
 *
 * @package Webto_Vejledning
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'webto-vejledning/v1',
			'/indeks',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'webto_vejledning_can_read',
				'callback'            => static fn() => rest_ensure_response( webto_vejledning_index() ),
			)
		);
	}
);
