<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutheliaAccount extends Model
{
    protected $fillable = ['issuer', 'subject', 'actor_id'];

    public function user(): BelongsTo
    {
        // User is a view of actors with a login; removing a login disables SSO too.
        return $this->belongsTo(User::class, 'actor_id');
    }
}
