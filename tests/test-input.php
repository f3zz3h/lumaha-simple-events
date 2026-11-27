<?php
/**
 * Posted arrays are unslashed and sanitised as soon as they are read.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

class LSET_Test_Input extends LSET_Test_Case {

	public function test_posted_array_is_unslashed_and_sanitised() {
		$_POST = array(
			'lset_att' => array(
				'7' => array(
					'1' => array(
						'first_name' => "O\\'Brien <b>Amy</b>",
						'f'          => array( '3' => "Line one\nLine two <script>x</script>" ),
					),
				),
				'Bad Key!' => 'x',
			),
			'lset_qty' => 'not an array',
		);
		$in    = LSET_Plugin::posted_array( 'lset_att' );
		$_POST = array();

		$this->assertSame( "O'Brien Amy", $in[7][1]['first_name'] );
		$this->assertSame( "Line one\nLine two", $in[7][1]['f'][3] );
		$this->assertArrayHasKey( 'badkey', $in );
		$this->assertArrayNotHasKey( 'Bad Key!', $in );
		$this->assertSame( array(), LSET_Plugin::posted_array( 'lset_qty' ) );
		$this->assertSame( array(), LSET_Plugin::posted_array( 'missing' ) );
	}

	public function test_availability_route_has_a_real_permission_check() {
		$routes = rest_get_server()->get_routes();
		$route  = $routes['/lset/v1/availability/(?P<id>\d+)'][0];
		$this->assertSame( array( 'LSET_Frontend', 'can_read_availability' ), $route['permission_callback'] );

		add_filter( 'lset_rest_can_read', '__return_false' );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/lset/v1/availability/' . $this->event() ) );
		remove_filter( 'lset_rest_can_read', '__return_false' );
		$this->assertSame( 401, $response->get_status() );
	}
}
