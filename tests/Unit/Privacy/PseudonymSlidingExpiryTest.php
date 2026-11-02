<?php

declare(strict_types=1);

namespace ContextGate\Tests\Unit\Privacy;

use ContextGate\Privacy\Pseudonymizer;
use ContextGate\Tests\TestCase;
use Illuminate\Support\Carbon;

/** Spec 008 US3 #3, FR-010 (T036): a persistent session slides for one hour of idleness and never past seven days. */
class PseudonymSlidingExpiryTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The session state is encrypted in the cache; the fixture application config has no key or cipher.
        $app['config']->set('app.key', 'base64:RkFLRV9GSVhUVVJFX09OTFlfVEVTVF9LRVlfMzJCWVQ=');
        $app['config']->set('app.cipher', 'AES-256-CBC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Pseudonymizer::usePersistentSessions(false);

        parent::tearDown();
    }

    private function at(int $seconds): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampUTC(1_800_000_000 + $seconds));
    }

    public function test_each_use_slides_the_session_by_an_hour(): void
    {
        Pseudonymizer::usePersistentSessions(true, 3600);
        $pseudonymizer = new Pseudonymizer();
        $scope = 'session:slide-'.bin2hex(random_bytes(3));

        $this->at(0);
        $pseudonym = $pseudonymizer->pseudonym('cus', 'customers', '7', $scope);

        // A use is an issue (pseudonym()), which saves the state with its sliding expiry; a read alone does not slide.
        $this->at(3000);
        $this->assertSame($pseudonym, $pseudonymizer->pseudonym('cus', 'customers', '7', $scope)); // slides to 6600

        $this->at(6000);
        $this->assertNotNull($pseudonymizer->resolve($pseudonym, $scope), 'still alive: the last use slid the session');
    }

    public function test_an_idle_hour_ends_the_session(): void
    {
        Pseudonymizer::usePersistentSessions(true, 3600);
        $pseudonymizer = new Pseudonymizer();
        $scope = 'session:idle-'.bin2hex(random_bytes(3));

        $this->at(0);
        $pseudonym = $pseudonymizer->pseudonym('cus', 'customers', '7', $scope);

        $this->at(3601);
        $this->assertNull($pseudonymizer->resolve($pseudonym, $scope), 'idle for an hour: the pseudonym no longer resolves');
    }

    public function test_the_session_never_outlives_seven_days_from_creation(): void
    {
        Pseudonymizer::usePersistentSessions(true, 3600);
        $pseudonymizer = new Pseudonymizer();
        $scope = 'session:cap-'.bin2hex(random_bytes(3));

        $this->at(0);
        $pseudonym = $pseudonymizer->pseudonym('cus', 'customers', '7', $scope);

        // Use it every 50 minutes, so it never idles out, until the cap is reached.
        for ($t = 3000; $t < 604800; $t += 3000) {
            $this->at($t);
            $pseudonymizer->pseudonym('cus', 'customers', '7', $scope);
        }

        $this->at(604799);
        $this->assertNotNull($pseudonymizer->resolve($pseudonym, $scope), 'alive just before seven days');

        $this->at(604801);
        $this->assertNull($pseudonymizer->resolve($pseudonym, $scope), 'gone after seven days from creation, however often it was used');
    }
}
