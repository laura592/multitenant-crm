<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Il listino del caffe' e dei solubili, per le offerte caffe'.
 *
 * Tabella a se' e non righe di products: l'ufficio non li vuole nel
 * preventivo della macchina (21/09/2026). L'offerta caffe' e' un documento
 * separato, perche' quanti chili il cliente comprera' non si sa: si offre un
 * prezzo, non una fornitura.
 *
 * Il listino iniziale entra qui e non da un seeder, perche' update.sh non
 * lancia seeder: e' l'unico modo perche' arrivi in produzione col deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prodotti_caffe', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('gruppo');
            $table->string('nome');
            $table->string('formato')->nullable();
            $table->decimal('prezzo', 10, 2);
            $table->unsignedInteger('ordinamento')->default(0);
            $table->boolean('attivo')->default(true);
            $table->timestamps();
        });

        // Il listino come l'ha dato l'ufficio. Il formato manca dove il
        // listino non lo diceva: si completa dal pannello, non si indovina.
        $righe = [
            ['caffe', 'Caffè Lyrae Marco Polo', null, 15.00],
            ['caffe', 'Caffè Lyrae Dorsoduro', null, 17.00],
            ['caffe', 'Caffè Lyrae Bucintoro', null, 21.00],
            ['caffe', 'Caffè Decaffeinato', '250 g', 8.50],
            ['caffe', 'Caffè Decaffeinato Lyrae', '1 kg', 22.50],
            ['caffe', 'Caffè Decaffeinato monodose', '50 pz', 18.00],
            ['liofilizzati', 'Cioccolato', null, 17.80],
            ['liofilizzati', 'Orzo granulare', '250 g', 7.00],
            ['liofilizzati', 'Orzo in polvere', '500 g', 7.40],
        ];

        $adesso = now();

        DB::table('prodotti_caffe')->insert(array_map(
            fn (array $r, int $i) => [
                'id' => (string) Str::uuid(),
                'gruppo' => $r[0],
                'nome' => $r[1],
                'formato' => $r[2],
                'prezzo' => $r[3],
                'ordinamento' => ($i + 1) * 10,
                'attivo' => true,
                'created_at' => $adesso,
                'updated_at' => $adesso,
            ],
            $righe,
            array_keys($righe),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('prodotti_caffe');
    }
};
