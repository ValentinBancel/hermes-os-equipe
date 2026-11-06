<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L4 — pont distant (R4-R9) : instance reliée, codes d'appairage, nonces anti-rejeu, relevés, actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pont_instances', function (Blueprint $table) {
            $table->id();
            $table->uuid('instance_id')->unique();
            $table->text('secret'); // chiffré (cast encrypted, R6)
            $table->string('nom', 100);
            $table->string('hote', 255)->nullable();
            $table->string('version', 40)->nullable();
            $table->json('profils')->nullable();
            $table->unsignedInteger('latence_ms')->nullable();
            $table->timestamp('dernier_contact_le')->nullable();
            $table->timestamp('dernier_releve_le')->nullable();
            $table->timestamp('revoquee_le')->nullable()->index();
            $table->foreignId('relie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pont_codes', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('essais_faux')->default(0);
            $table->timestamp('expire_le');
            $table->timestamp('utilise_le')->nullable();
            $table->timestamp('invalide_le')->nullable();
            $table->timestamps();
        });

        Schema::create('pont_nonces', function (Blueprint $table) {
            $table->id();
            $table->uuid('instance_id');
            $table->char('nonce', 32);
            $table->timestamp('cree_le')->index();
            $table->unique(['instance_id', 'nonce']);
        });

        Schema::create('pont_releves', function (Blueprint $table) {
            $table->id();
            $table->string('cle', 400)->unique();
            $table->unsignedSmallInteger('statut');
            $table->longText('donnees');
            $table->timestamp('recu_le');
        });

        Schema::create('pont_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('action', 60);
            $table->json('donnees');
            $table->string('acteur')->nullable();
            $table->uuid('instance_id')->nullable()->index(); // instance qui a reçu l'action (secu m8)
            $table->string('statut', 20)->default('en_attente')->index(); // en_attente|envoyee|terminee|expiree
            $table->json('resultat')->nullable();
            $table->timestamp('expire_le');
            $table->timestamp('envoyee_le')->nullable();
            $table->timestamp('terminee_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pont_actions');
        Schema::dropIfExists('pont_releves');
        Schema::dropIfExists('pont_nonces');
        Schema::dropIfExists('pont_codes');
        Schema::dropIfExists('pont_instances');
    }
};
