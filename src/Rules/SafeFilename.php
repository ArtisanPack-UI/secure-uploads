<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\Rules;

use ArtisanPackUI\SecureUploads\Contracts\FileValidatorInterface;
use Illuminate\Contracts\Validation\Rule;

class SafeFilename implements Rule
{
    /**
     * Validation errors.
     */
    protected array $errors = [];

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     */
    public function passes( $attribute, $value ): bool
    {
        $this->errors = [];

        if ( ! is_string( $value ) ) {
            $this->errors[] = 'The :attribute must be a string.';

            return false;
        }

        $validator = app( FileValidatorInterface::class );

        if ( $validator->hasUnsafeFilename( $value ) ) {
            $this->errors[] = 'The :attribute contains unsafe characters or patterns.';

            return false;
        }

        // Additional checks for filename safety

        // Check for null bytes
        if ( str_contains( $value, "\0" ) || str_contains( $value, '%00' ) ) {
            $this->errors[] = 'The :attribute contains invalid characters.';

            return false;
        }

        // Check for path traversal
        if ( preg_match( '/\.\.[\/\\\\]/', $value ) ) {
            $this->errors[] = 'The :attribute contains path traversal sequences.';

            return false;
        }

        // Check for double extensions with dangerous types
        $dangerousExtensions = config( 'artisanpack.secure-uploads.blockedExtensions', [
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps',
            'exe', 'com', 'bat', 'cmd', 'sh', 'bash',
            'js', 'jsx', 'ts', 'tsx',
            'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb',
        ] );

        // Check for any dangerous extension anywhere in the filename
        $lowerFilename = strtolower( $value );
        foreach ( $dangerousExtensions as $ext ) {
            if ( preg_match( '/\.' . $ext . '(\.|$)/i', $lowerFilename ) ) {
                $this->errors[] = 'The :attribute contains a blocked file extension.';

                return false;
            }
        }

        // Check for suspicious characters
        if ( preg_match( '/[<>:"|?*]/', $value ) ) {
            $this->errors[] = 'The :attribute contains invalid characters.';

            return false;
        }

        // Check for hidden files (Unix-style)
        if ( str_starts_with( $value, '.' ) ) {
            $this->errors[] = 'The :attribute cannot start with a dot.';

            return false;
        }

        // Check for reserved Windows names
        $reservedNames  = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
        $nameWithoutExt = strtoupper( pathinfo( $value, PATHINFO_FILENAME ) );
        if ( in_array( $nameWithoutExt, $reservedNames, true ) ) {
            $this->errors[] = 'The :attribute uses a reserved system name.';

            return false;
        }

        // Check maximum length
        if ( strlen( $value ) > 255 ) {
            $this->errors[] = 'The :attribute must not exceed 255 characters.';

            return false;
        }

        // Check for empty filename after trimming
        if ( empty( trim( $value ) ) ) {
            $this->errors[] = 'The :attribute cannot be empty.';

            return false;
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return array|string
     */
    public function message(): array|string
    {
        return $this->errors;
    }
}
