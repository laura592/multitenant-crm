<?php

namespace Tests\Unit;

use App\Models\LeaveRequest;
use App\Support\Presenze\Festivi;
use Carbon\Carbon;
use Tests\TestCase;

class LeaveRequestDaysTest extends TestCase
{
    private function request(string $type, string $from, string $to): LeaveRequest
    {
        return new LeaveRequest(['type' => $type, 'date_from' => $from, 'date_to' => $to]);
    }

    public function test_ferie_skip_saturday_and_sunday(): void
    {
        // mar 8 - ven 18 settembre 2026: 11 giorni di calendario, 9 lavorativi.
        $this->assertSame(9, $this->request(LeaveRequest::TYPE_FERIE, '2026-09-08', '2026-09-18')->days);
        $this->assertSame(0, $this->request(LeaveRequest::TYPE_FERIE, '2026-09-12', '2026-09-13')->days);
    }

    public function test_malattia_counts_calendar_days(): void
    {
        $this->assertSame(11, $this->request(LeaveRequest::TYPE_MALATTIA, '2026-09-08', '2026-09-18')->days);
    }

    public function test_days_within_clips_to_the_period(): void
    {
        // gio 27 agosto - mar 8 settembre: 3 lavorativi ad agosto, 6 a settembre.
        $ferie = $this->request(LeaveRequest::TYPE_FERIE, '2026-08-27', '2026-09-08');

        $this->assertSame(3, $ferie->daysWithin(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')->endOfDay()));
        $this->assertSame(6, $ferie->daysWithin(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')->endOfDay()));
        $this->assertSame(9, $ferie->days);
    }

    public function test_ferie_skip_public_holidays(): void
    {
        // lun 7 - ven 11 dicembre 2026: l'8 (Immacolata) e' festa, restano 4.
        $this->assertSame(4, $this->request(LeaveRequest::TYPE_FERIE, '2026-12-07', '2026-12-11')->days);
        // Pasquetta 2027 e' lunedi' 29 marzo.
        $this->assertSame(0, $this->request(LeaveRequest::TYPE_FERIE, '2027-03-29', '2027-03-29')->days);
        $this->assertSame('2026-04-05', Festivi::pasqua(2026)->format('Y-m-d'));
    }
}
