<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Appareils et réseaux de confiance (ADR 0003, amendement « appareil de confiance ») : la connexion depuis un
 * appareil reconnu ET un réseau déclaré saute le code TOTP. Seule l'empreinte SHA-256 du jeton est stockée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseaux_de_confiance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('libelle', 60);
            $table->string('plage', 49);
            $table->timestamps();

            $table->unique(['user_id', 'plage']);
        });

        Schema::create('appareils_de_confiance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('jeton_hash', 64)->unique();
            $table->string('libelle', 100);
            $table->string('ip_creation', 45)->nullable();
            $table->string('derniere_ip', 45)->nullable();
            $table->timestamp('utilise_le')->nullable();
            $table->timestamp('expire_le');
            $table->timestamps();

            $table->index(['user_id', 'expire_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appareils_de_confiance');
        Schema::dropIfExists('reseaux_de_confiance');
    }
};
