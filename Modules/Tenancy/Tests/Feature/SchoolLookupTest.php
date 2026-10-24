<?php

declare(strict_types=1);

namespace Modules\Tenancy\Tests\Feature;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The landing page's "enter your school code" box: public, central addresses only. */
class SchoolLookupTest extends TestCase
{
    private const CENTRAL = 'http://localhost/api/school-lookup';

    private function lookup(string $code, string $url = self::CENTRAL): TestResponse
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $this->getJson($url.'?code='.urlencode($code));
    }

    public function test_an_existing_school_is_found_with_the_address_of_its_sign_in(): void
    {
        $this->lookup('demo')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('url', 'http://demo.mudarisy.test/login')
            ->assertJsonStructure(['found', 'name', 'url']);
    }

    public function test_the_code_is_matched_without_regard_to_case_or_surrounding_spaces(): void
    {
        $this->lookup('  DeMo ')->assertOk()->assertJsonPath('found', true);
    }

    public function test_an_unknown_school_is_not_found(): void
    {
        $this->lookup('no-such-school')->assertNotFound()->assertExactJson(['found' => false]);
    }

    #[DataProvider('malformedCodes')]
    public function test_a_malformed_code_gets_the_same_answer_as_an_unknown_one(string $code): void
    {
        $this->lookup($code)->assertNotFound()->assertExactJson(['found' => false]);
    }

    public static function malformedCodes(): array
    {
        return [
            'empty' => [''],
            'path trick' => ['../demo'],
            'domain' => ['demo.mudarisy.test'],
            'arabic' => ['مركز'],
            'too long' => [str_repeat('a', 80)],
            'wildcard' => ['de%'],
        ];
    }

    public function test_the_answer_exposes_nothing_beyond_the_name_and_the_address(): void
    {
        $body = $this->lookup('demo')->assertOk()->json();

        $this->assertEqualsCanonicalizing(['found', 'name', 'url'], array_keys($body));
    }

    public function test_it_is_not_reachable_through_a_school_address(): void
    {
        $this->lookup('demo', 'http://demo.mudarisy.test/api/school-lookup')->assertNotFound();
    }

    public function test_it_is_rate_limited_so_codes_cannot_be_guessed_in_bulk(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->lookup('guess-'.$i)->assertNotFound();
        }

        $this->lookup('guess-21')->assertStatus(429);
    }
}
