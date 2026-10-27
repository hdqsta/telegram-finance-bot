<?php

namespace Tests\Unit;

use App\Services\ExpenseParserService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExpenseParserServiceTest extends TestCase
{
    private ExpenseParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ExpenseParserService;
        Carbon::setTestNow('2026-09-27 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_parses_standard_expense_defaults_to_today(): void
    {
        $result = $this->parser->parse('Makan siang 25000');

        $this->assertNotNull($result);
        $this->assertEquals(25000, $result['amount']);
        $this->assertEquals('Makan siang', $result['description']);
        $this->assertEquals('withdrawal', $result['type']);
        $this->assertEquals('2026-09-27', $result['date']);
    }

    public function test_parses_relative_date_kemarin(): void
    {
        $result = $this->parser->parse('kemarin makan siang 25000');

        $this->assertNotNull($result);
        $this->assertEquals('2026-09-26', $result['date']);
        $this->assertEquals('makan siang', $result['description']);
    }

    public function test_parses_relative_date_kemarin_lusa(): void
    {
        $result = $this->parser->parse('kemarin lusa beli bensin 35rb');

        $this->assertNotNull($result);
        $this->assertEquals('2026-09-25', $result['date']);
        $this->assertEquals('beli bensin', $result['description']);
    }

    public function test_parses_relative_date_hari_ini(): void
    {
        $result = $this->parser->parse('hari ini bayar listrik 150rb');

        $this->assertNotNull($result);
        $this->assertEquals('2026-09-27', $result['date']);
        $this->assertEquals('bayar listrik', $result['description']);
    }

    public function test_parses_date_with_month_name(): void
    {
        $result = $this->parser->parse('3 sept beli bensin 35rb');

        $this->assertNotNull($result);
        $this->assertEquals('2026-09-03', $result['date']);
        $this->assertEquals('beli bensin', $result['description']);
    }

    public function test_parses_numeric_day_month_date(): void
    {
        $result = $this->parser->parse('03-09 beli bensin 35rb');

        $this->assertNotNull($result);
        $this->assertEquals('2026-09-03', $result['date']);
        $this->assertEquals('beli bensin', $result['description']);
    }

    public function test_parses_date_with_slash_and_year(): void
    {
        $result = $this->parser->parse('3/9/2025 beli bensin 35rb');

        $this->assertNotNull($result);
        $this->assertEquals('2025-09-03', $result['date']);
        $this->assertEquals('beli bensin', $result['description']);
    }

    public function test_rolls_back_future_date_without_year_to_previous_year(): void
    {
        $result = $this->parser->parse('10 des bayar servis 200rb');

        $this->assertNotNull($result);
        $this->assertEquals('2025-12-10', $result['date']);
        $this->assertEquals('bayar servis', $result['description']);
    }

    public function test_parses_amount_with_dots_and_rupiah(): void
    {
        $result = $this->parser->parse('Rp 50.000 Bensin');

        $this->assertNotNull($result);
        $this->assertEquals(50000, $result['amount']);
        $this->assertEquals('Bensin', $result['description']);
        $this->assertEquals('withdrawal', $result['type']);
    }

    public function test_parses_amount_with_k_suffix(): void
    {
        $result = $this->parser->parse('Kopi 25k');

        $this->assertNotNull($result);
        $this->assertEquals(25000, $result['amount']);
        $this->assertEquals('Kopi', $result['description']);
        $this->assertEquals('withdrawal', $result['type']);
    }

    public function test_parses_deposit_income(): void
    {
        $result = $this->parser->parse('+5000000 Gaji');

        $this->assertNotNull($result);
        $this->assertEquals(5000000, $result['amount']);
        $this->assertEquals('Gaji', $result['description']);
        $this->assertEquals('deposit', $result['type']);
    }

    public function test_parses_ambiguous_numbers_prioritizing_suffixed_amount(): void
    {
        $result = $this->parser->parse('beli 2 galon 40rb');

        $this->assertNotNull($result);
        $this->assertEquals(40000, $result['amount']);
        $this->assertEquals('beli 2 galon', $result['description']);
    }

    public function test_parses_ambiguous_numbers_taking_last_number_when_all_plain(): void
    {
        $result = $this->parser->parse('beli 2 galon 40000');

        $this->assertNotNull($result);
        $this->assertEquals(40000, $result['amount']);
        $this->assertEquals('beli 2 galon', $result['description']);
    }

    public function test_parses_decimal_million_formats(): void
    {
        $res1 = $this->parser->parse('1,5jt bayar sewa');
        $this->assertNotNull($res1);
        $this->assertEquals(1500000, $res1['amount']);
        $this->assertEquals('bayar sewa', $res1['description']);

        $res2 = $this->parser->parse('1.5jt bayar sewa');
        $this->assertNotNull($res2);
        $this->assertEquals(1500000, $res2['amount']);
        $this->assertEquals('bayar sewa', $res2['description']);
    }

    public function test_detects_source_account_keywords(): void
    {
        $res1 = $this->parser->parse('tf beli sate 18rb');
        $this->assertNotNull($res1);
        $this->assertEquals('Mandiri', $res1['source_account']);
        $this->assertEquals('beli sate', $res1['description']);

        $res2 = $this->parser->parse('qris main billiard 2 jam 200rb');
        $this->assertNotNull($res2);
        $this->assertEquals('Mandiri', $res2['source_account']);
        $this->assertEquals('main billiard 2 jam', $res2['description']);

        $res3 = $this->parser->parse('60rb beli kertas');
        $this->assertNotNull($res3);
        $this->assertEquals('dompet', $res3['source_account']);
        $this->assertEquals('beli kertas', $res3['description']);

        $res4 = $this->parser->parse('trf bayar listrik 150rb');
        $this->assertNotNull($res4);
        $this->assertEquals('Mandiri', $res4['source_account']);
        $this->assertEquals('bayar listrik', $res4['description']);

        $res5 = $this->parser->parse('gorengan 10rb');
        $this->assertNotNull($res5);
        $this->assertEquals('dompet', $res5['source_account']);
        $this->assertEquals('gorengan', $res5['description']);
    }

    public function test_returns_null_for_invalid_input(): void
    {
        $result = $this->parser->parse('Hello world tanpa angka');

        $this->assertNull($result);
    }
}
