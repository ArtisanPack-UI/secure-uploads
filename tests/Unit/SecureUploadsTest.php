<?php

declare( strict_types=1 );

use ArtisanPackUI\SecureUploads\SecureUploads;

it( 'instantiates the SecureUploads class', function (): void {
    expect( new SecureUploads() )->toBeInstanceOf( SecureUploads::class );
} );

it( 'reports its current version', function (): void {
    expect( ( new SecureUploads() )->version() )->toBeString();
} );
