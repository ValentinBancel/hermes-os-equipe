<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plusieurs comptes (L3) : désactivation sans suppression, présence (« connecté » = activité < 5 min)
 * et compte à l'origine de la création (null pour un compte créé en ligne de commande).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('desactive_le')->nullable()->after('mot_de_passe_provisoire');
            $table->timestamp('derniere_activite_le')->nullable()->after('desactive_le');
            $table->foreignId('cree_par')->nullable()->after('derniere_activite_le')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cree_par');
            $table->dropColumn(['desactive_le', 'derniere_activite_le']);
        });
    }
};
