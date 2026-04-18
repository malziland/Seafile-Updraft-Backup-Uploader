<?php
/**
 * Exception thrown by the TestCase's wp_send_json_success / wp_send_json_error
 * stubs. Lets tests exercise AJAX handlers without the real wp_die() that
 * would kill the PHPUnit process — catch the exception, inspect payload.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Helpers;

use RuntimeException;

final class JsonResponse extends RuntimeException {

    public bool $success;

    /** @var mixed */
    public $data;

    /** @param mixed $data */
    public function __construct( bool $success, $data ) {
        parent::__construct( $success ? 'json_success' : 'json_error' );
        $this->success = $success;
        $this->data    = $data;
    }
}
