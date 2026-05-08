<?php

declare( strict_types=1 );

use ArtisanPackUI\SecureUploads\SecureUploads;

it( 'binds the secure-uploads singleton', function (): void {
    expect( app( 'secure-uploads' ) )->toBeInstanceOf( SecureUploads::class );
} );

it( 'returns the same instance on subsequent resolutions', function (): void {
    expect( app( 'secure-uploads' ) )->toBe( app( 'secure-uploads' ) );
} );

it( 'exposes the secure_uploads() helper', function (): void {
    expect( secure_uploads() )->toBeInstanceOf( SecureUploads::class );
} );
