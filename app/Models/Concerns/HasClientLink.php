<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Il link personale che il cliente riceve nella mail del preventivo o
 * dell'offerta globale. Il token nasce al primo invio e poi resta lo stesso:
 * le mail gia' mandate continuano a funzionare anche dopo un nuovo invio.
 */
trait HasClientLink
{
    public function ensurePublicToken(): string
    {
        if (! $this->public_token) {
            $this->forceFill(['public_token' => Str::random(48)])->saveQuietly();
        }

        return $this->public_token;
    }

    public function clientUrl(?string $azione = null): string
    {
        return route('client.quote.show', array_filter([
            'token' => $this->ensurePublicToken(),
            'azione' => $azione,
        ]));
    }

    public function recordClientView(): void
    {
        $now = now();

        $this->forceFill([
            'client_first_viewed_at' => $this->client_first_viewed_at ?? $now,
            'client_last_viewed_at' => $now,
            'client_view_count' => (int) $this->client_view_count + 1,
        ])->saveQuietly();
    }
}
