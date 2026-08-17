<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// La descrizione (campo obbligatorio) copre gia' quello che serve al
// tecnico su ogni riga lavaggio: il campo note libero era ridondante e non
// veniva mai popolato in pratica. Thread 2026-08-13.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lavaggi', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }

    public function down(): void
    {
        Schema::table('lavaggi', function (Blueprint $table) {
            $table->text('note')->nullable();
        });
    }
};
