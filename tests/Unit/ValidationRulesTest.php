<?php

namespace Tests\Unit;

use ArtisanPackUI\SecureUploads\Rules\SecureFile;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ValidatesInput;
use Tests\TestCase;

class ValidationRulesTest extends TestCase
{
    use ValidatesInput;

    #[Test]
    public function it_validates_secure_files(): void
    {
        // Test with a default rule - valid jpg file
        $rule = new SecureFile();
        $file = UploadedFile::fake()->image( 'avatar.jpg', 100, 100 );
        $this->assertValidates( $rule, $file );

        // Test with blocked extension (php) - should fail
        $rule = new SecureFile();
        $file = UploadedFile::fake()->create( 'malware.php', 100, 'text/x-php' );
        $this->assertFailsValidation( $rule, $file );

        // Test with size limit exceeded
        $rule = (new SecureFile())->maxKilobytes( 1 ); // 1KB max
        $file = UploadedFile::fake()->create( 'large.jpg', 2048, 'image/jpeg' ); // 2MB file
        $this->assertFailsValidation( $rule, $file );
    }
}
