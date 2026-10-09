<?php
declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Improvement;

use Novora\KaizenBundle\Improvement\InvestigationStore;
use PHPUnit\Framework\TestCase;

final class InvestigationStoreTest extends TestCase
{
    private string $directory;
    private InvestigationStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/kaizen_'.bin2hex(random_bytes(6));
        $this->store = new InvestigationStore($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testLeanWorkflowIsPersisted(): void
    {
        $case = $this->store->create('Repeated failures', 'Impact unclear');
        $id = $case['id'];
        $this->store->addGemba($id, 'Job failed at 10:00', 'Request ID 123');
        $this->store->addCause($id, 'dependencies', 'Database unavailable', 'Connection refused');
        $this->store->assessCause($id, 0, 'confirmed', 'DB down between 10:00 and 10:05');
        $this->store->setWhy($id, 1, 'Database connection interrupted');
        $this->store->setPdca($id, 'plan', 'Reduce retry storms');
        $this->store->setStatus($id, 'verifying');

        $case = (new InvestigationStore($this->directory))->find($id);
        self::assertSame('verifying', $case['status']);
        self::assertCount(1, $case['gemba']);
        self::assertSame('confirmed', $case['causes'][0]['assessment']);
        self::assertSame('Database connection interrupted', $case['whys']['1']);
        self::assertSame('Reduce retry storms', $case['pdca']['plan']);
        self::assertCount(1, $this->store->all());
    }

    public function testPersistsCorrectionTimestampWithoutClosingTheCase(): void
    {
        $fingerprint = str_repeat('a', 64);
        $case = $this->store->create('Session permissions', 'PHP GC cannot access sessions', $fingerprint);
        self::assertNull($case['correction_applied_at']);

        $when = new \DateTimeImmutable('2026-10-09T12:20:00+03:00');
        $this->store->recordCorrection($case['id'], $when);

        $stored = (new InvestigationStore($this->directory))->find($case['id']);
        self::assertSame('2026-10-09T09:20:00+00:00', $stored['correction_applied_at']);
        self::assertSame('open', $stored['status']);
        self::assertSame($fingerprint, $stored['fingerprint']);
    }

    public function testCannotVerifyCaseWithoutLogFingerprint(): void
    {
        $case = $this->store->create('Manual case', '');
        $this->expectException(\DomainException::class);
        $this->store->recordCorrection($case['id'], new \DateTimeImmutable('-1 hour'));
    }

    public function testRejectsCorrectionTimestampInTheFuture(): void
    {
        $case = $this->store->create('Session permissions', '', str_repeat('a', 64));
        $this->expectException(\InvalidArgumentException::class);
        $this->store->recordCorrection($case['id'], new \DateTimeImmutable('+5 minutes'));
    }

    public function testRepeatedInvestigateReusesAnActiveCaseWithoutChangingItsEvidence(): void
    {
        $fingerprint = str_repeat('a', 64);
        $original = $this->store->createOrReuse('Session GC', 'Permission denied', $fingerprint);
        $this->store->addGemba($original['id'], 'Observed at 10:00', 'dev.log');
        $this->store->setStatus($original['id'], 'verifying');

        $again = $this->store->createOrReuse('Renamed headline', 'Revised text', $fingerprint);

        self::assertSame($original['id'], $again['id']);
        self::assertSame('Session GC', $again['title']);
        self::assertSame('Permission denied', $again['problem']);
        self::assertCount(1, $again['gemba']);
        self::assertSame('verifying', $again['status']);
        self::assertCount(1, $this->store->all());
    }

    public function testClosedInvestigationDoesNotBlockNewOne(): void
    {
        $fingerprint = str_repeat('b', 64);
        $first = $this->store->createOrReuse('First occurrence', '', $fingerprint);
        $this->store->setStatus($first['id'], 'closed');

        $second = $this->store->createOrReuse('New recurrence', '', $fingerprint);

        self::assertNotSame($first['id'], $second['id']);
        self::assertSame('open', $second['status']);
        self::assertCount(2, $this->store->all());
        self::assertSame(
            $second['id'],
            $this->store->createOrReuse('Clicked again', '', $fingerprint)['id'],
        );
    }

    public function testManualCasesAndDifferentFingerprintsStayIndependent(): void
    {
        $manual1 = $this->store->createOrReuse('Manual issue', '');
        $manual2 = $this->store->createOrReuse('Manual issue', '');
        $linked1 = $this->store->createOrReuse('First error', '', str_repeat('c', 64));
        $linked2 = $this->store->createOrReuse('Second error', '', str_repeat('d', 64));

        self::assertCount(4, array_unique([$manual1['id'], $manual2['id'], $linked1['id'], $linked2['id']]));
    }

    public function testInvalidInputIsRejectedEvenWhenFingerprintAlreadyHasAnOpenCase(): void
    {
        $fingerprint = str_repeat('e', 64);
        $this->store->createOrReuse('Valid title', '', $fingerprint);
        $this->expectException(\InvalidArgumentException::class);
        $this->store->createOrReuse('', '', $fingerprint);
    }

    public function testStoragePreflightDistinguishesMissingWritableDirectoryFromInvalidTarget(): void
    {
        self::assertTrue($this->store->isStorageWritable());
        $case = $this->store->create('Working storage', '');
        self::assertNotEmpty($case['id']);

        $file = tempnam(sys_get_temp_dir(), 'kaizen_not_dir_');
        self::assertNotFalse($file);
        try {
            $store = new InvestigationStore($file);
            self::assertFalse($store->isStorageWritable());
            try {
                $store->create('Should fail clearly', '');
                self::fail('File path incorrectly accepted as storage directory.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('storage', strtolower($exception->getMessage()));
            }
        } finally {
            unlink($file);
        }
    }

    public function testRejectsPathTraversalAndUnsubstantiatedConfirmedCause(): void
    {
        $case = $this->store->create('Investigate', '');
        $this->store->addCause($case['id'], 'code', 'Possible bug', '');
        try {
            $this->store->assessCause($case['id'], 0, 'confirmed', '');
            self::fail('Confirmed hypothesis without evidence was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame('unverified', $this->store->find($case['id'])['causes'][0]['assessment']);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->store->find('../config');
    }

    public function testRejectsInvalidPhaseAndExcessiveText(): void
    {
        $case = $this->store->create('Test', '');
        $this->expectException(\InvalidArgumentException::class);
        $this->store->setPdca($case['id'], 'invented', 'foo');
    }

    public function testRejectsExcessivelyLongObservations(): void
    {
        $case = $this->store->create('Test', '');
        $this->expectException(\InvalidArgumentException::class);
        $this->store->addGemba($case['id'], str_repeat('x', 4001), '');
    }
}
