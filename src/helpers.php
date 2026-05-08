<?php

/**
 * SecureUploads helper functions.
 *
 * @package    ArtisanPack_UI
 * @subpackage SecureUploads
 *
 * @since      1.0.0
 */

use ArtisanPackUI\SecureUploads\SecureUploads;

if ( ! function_exists( 'secure_uploads' ) ) {
    /**
     * Get the SecureUploads instance.
     *
     * @since 1.0.0
     *
     * @return SecureUploads
     */
    function secure_uploads(): SecureUploads
    {
        return app( 'secure-uploads' );
    }
}
