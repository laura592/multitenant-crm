<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->json('emails')->nullable()->after('email');
            $table->json('phones')->nullable()->after('mobile');
            $table->string('pec')->nullable()->after('sdi');
        });

        DB::table('customers')->orderBy('id')->chunkById(200, function ($customers) {
            foreach ($customers as $customer) {
                DB::table('customers')->where('id', $customer->id)->update([
                    'emails' => json_encode(array_values(array_filter([$customer->email]))),
                    'phones' => json_encode(array_values(array_filter([$customer->mobile]))),
                ]);
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['email', 'mobile']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
        });

        DB::table('customers')->orderBy('id')->chunkById(200, function ($customers) {
            foreach ($customers as $customer) {
                $emails = json_decode($customer->emails ?? '[]', true) ?: [];
                $phones = json_decode($customer->phones ?? '[]', true) ?: [];

                DB::table('customers')->where('id', $customer->id)->update([
                    'email' => $emails[0] ?? null,
                    'mobile' => $phones[0] ?? null,
                ]);
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['emails', 'phones', 'pec']);
        });
    }
};
