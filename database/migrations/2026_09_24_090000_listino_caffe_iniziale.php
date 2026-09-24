<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Il listino del caffè e dei solubili, com'era nella migration che lo creava
 * insieme alla tabella (21/09/2026).
 *
 * Con la compattazione delle migration in un file di schema (24/09/2026) la
 * tabella arriva vuota: lo schema porta le tabelle, non i dati. Questo
 * listino però deve esserci anche su un database nuovo, e non può stare in un
 * seeder perché `update.sh` i seeder non li lancia — è l'unico modo perché
 * arrivi in produzione col deploy.
 *
 * Scrive solo se la tabella è vuota: in produzione il listino c'è già (e può
 * essere stato ritoccato dal pannello), e non va toccato.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('prodotti_caffe')->exists()) {
            return;
        }

        // I formati sono quelli corretti dopo: il Lyrae è tutto da 1 kg e il
        // cioccolato da 500 g (detto dall'ufficio il 21/09/2026). Erano due
        // migration a parte, qui è una riga sola — è il senso di compattare.
        $righe = [
            ['caffe', 'Caffè Lyrae Marco Polo', '1 kg', 15.00],
            ['caffe', 'Caffè Lyrae Dorsoduro', '1 kg', 17.00],
            ['caffe', 'Caffè Lyrae Bucintoro', '1 kg', 21.00],
            ['caffe', 'Caffè Decaffeinato', '250 g', 8.50],
            ['caffe', 'Caffè Decaffeinato Lyrae', '1 kg', 22.50],
            ['caffe', 'Caffè Decaffeinato monodose', '50 pz', 18.00],
            ['liofilizzati', 'Cioccolato', '500 g', 17.80],
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
        // Il listino si gestisce dal pannello: non si cancella tornando indietro.
    }
};
