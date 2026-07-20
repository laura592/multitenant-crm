<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dati di riferimento italiani (comune/provincia/CAP), letti al seed da un
     * dataset statico: una riga per ogni coppia comune-CAP (i comuni con piu'
     * CAP, es. le grandi citta', hanno piu' righe). Non e' scoped per tenant
     * ne' referenziato da foreign key: serve solo a suggerire/validare i campi
     * street/postal_code/city/province di customers, che restano testo libero.
     */
    public function up(): void
    {
        Schema::create('municipality_postal_codes', function (Blueprint $table) {
            $table->id();
            $table->string('municipality_name');
            $table->string('province_name');
            $table->string('province_code', 2);
            $table->string('postal_code', 5);

            $table->index('municipality_name');
            $table->index('postal_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipality_postal_codes');
    }
};
