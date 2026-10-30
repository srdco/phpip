<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authelia_accounts', function (Blueprint $table) {
            $table->id();
            // OIDC identifiers are case sensitive, unlike most phpIP fields.
            $table->string('issuer')->collation('utf8mb4_bin');
            $table->string('subject')->collation('utf8mb4_bin');
            $table->unsignedInteger('actor_id');
            $table->timestamps();
            $table->unique(['issuer', 'subject']);
            $table->foreign('actor_id')->references('id')->on('actor')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authelia_accounts');
    }
};
