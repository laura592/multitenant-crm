<?php

namespace App\Support\Brevo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Il minimo dell'API Brevo che serve al CRM: creare/aggiornare un contatto,
 * metterlo in blacklist, trovare (o creare) una lista per nome.
 *
 * Brevo risponde 429 quando si va troppo veloce e, a volte, non risponde
 * proprio per un minuto (06/10/2026, durante un import da file): si riprova
 * con pause crescenti invece di fermare tutto il giro.
 */
class BrevoClient
{
    /** @var array<string, int>|null */
    private ?array $liste = null;

    public function __construct(
        private readonly string $key,
        private readonly string $baseUrl = 'https://api.brevo.com/v3',
    ) {}

    public static function fromConfig(): ?self
    {
        $key = config('services.brevo.key');

        return filled($key) ? new self($key, config('services.brevo.base_url')) : null;
    }

    /**
     * Crea il contatto o, se l'email c'e' gia', lo aggiorna (updateEnabled).
     *
     * @param  array<string, mixed>  $attributi
     * @param  array<int, int>  $liste
     */
    public function salvaContatto(string $email, array $attributi, array $liste = []): void
    {
        $body = ['email' => $email, 'attributes' => $attributi, 'updateEnabled' => true];

        if ($liste !== []) {
            $body['listIds'] = array_values($liste);
        }

        $risposta = $this->richiesta('post', '/contacts', $body);

        // Il numero di cellulare in Brevo e' un identificativo unico: se e'
        // gia' di un altro contatto il salvataggio fallisce tutto. Meglio il
        // contatto senza SMS che nessun contatto.
        if ($risposta->status() === 400 && isset($attributi['SMS']) && str_contains($risposta->body(), 'SMS')) {
            unset($body['attributes']['SMS']);
            $risposta = $this->richiesta('post', '/contacts', $body);
        }

        if (! in_array($risposta->status(), [201, 204], true)) {
            throw new RuntimeException("Brevo: salvataggio di {$email} rifiutato ({$risposta->status()}): ".mb_substr($risposta->body(), 0, 200));
        }
    }

    /** Consenso revocato: niente piu' email promozionali a questo indirizzo. */
    public function blacklist(string $email): void
    {
        $risposta = $this->richiesta('put', '/contacts/'.rawurlencode($email), ['emailBlacklisted' => true]);

        // 404: su Brevo non c'e', quindi non c'e' niente da bloccare.
        if (! in_array($risposta->status(), [204, 404], true)) {
            throw new RuntimeException("Brevo: blacklist di {$email} rifiutata ({$risposta->status()}).");
        }
    }

    public function idLista(string $nome): ?int
    {
        return $this->liste()[$nome] ?? null;
    }

    public function idListaOCreala(string $nome): int
    {
        if ($id = $this->idLista($nome)) {
            return $id;
        }

        $cartelle = $this->richiesta('get', '/contacts/folders?limit=50')->json('folders') ?? [];
        $risposta = $this->richiesta('post', '/contacts/lists', [
            'name' => $nome,
            'folderId' => $cartelle[0]['id'] ?? 1,
        ]);

        return $this->liste[$nome] = (int) $risposta->json('id');
    }

    /** @return array<string, int> */
    private function liste(): array
    {
        if ($this->liste === null) {
            $this->liste = [];
            $offset = 0;

            do {
                $pagina = $this->richiesta('get', "/contacts/lists?limit=50&offset={$offset}")->json();

                foreach ($pagina['lists'] ?? [] as $lista) {
                    $this->liste[$lista['name']] = (int) $lista['id'];
                }

                $offset += 50;
            } while ($offset < ($pagina['count'] ?? 0));
        }

        return $this->liste;
    }

    /** @param  array<string, mixed>  $body */
    private function richiesta(string $metodo, string $path, array $body = []): Response
    {
        $ultimoErrore = null;

        foreach ([0, 10, 30, 60] as $attesa) {
            if ($attesa > 0) {
                sleep($attesa);
            }

            try {
                $risposta = $this->http()->{$metodo}($path, $body);

                if ($risposta->status() !== 429 && $risposta->status() < 500) {
                    return $risposta;
                }

                $ultimoErrore = "HTTP {$risposta->status()}";
            } catch (ConnectionException $e) {
                $ultimoErrore = $e->getMessage();
            }
        }

        throw new RuntimeException("Brevo non risponde ({$path}): {$ultimoErrore}");
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['api-key' => $this->key])
            ->acceptJson()
            ->asJson()
            ->timeout(120);
    }
}
