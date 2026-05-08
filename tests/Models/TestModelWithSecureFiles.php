<?php

namespace Tests\Models;

use ArtisanPackUI\SecureUploads\Concerns\HasSecureFiles;
use Illuminate\Database\Eloquent\Model;

class TestModelWithSecureFiles extends Model
{
    use HasSecureFiles;

    protected $table = 'test_models';

    protected $fillable = ['name'];
}
