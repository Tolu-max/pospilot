<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderSecretRecord extends Model
{
    protected $primaryKey = 'secret_reference';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['secret_reference', 'provider_connection_id', 'agent_profile_id', 'provider_id', 'secrets'];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return ['secrets' => 'encrypted:array'];
    }
}
