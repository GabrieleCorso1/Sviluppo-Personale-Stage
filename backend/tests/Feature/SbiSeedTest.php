<?php

use App\Models\Sbi;
use App\Models\SbiRevision;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FeedbackSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SbiSeedTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sbi_schema_and_seed_relationships(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('sbis', 3);
        $this->assertDatabaseCount('sbi_revisions', 3);
        $this->assertDatabaseCount('sbi_replies', 1);

        foreach (Sbi::with(['writer', 'receiver', 'project', 'revisions.reply'])->get() as $sbi) {
            $this->assertNotNull($sbi->writer);
            $this->assertNotNull($sbi->receiver);
            $this->assertNotNull($sbi->project);
            $this->assertCount(1, $sbi->revisions);
            $revision = $sbi->revisions->first();
            $this->assertSame(1, $revision->revision_number);
            $this->assertSame($sbi->id, $revision->sbi->id);
            if ($revision->reply !== null) {
                $this->assertSame($revision->id, $revision->reply->revision->id);
                $this->assertNotNull($revision->viewed_at);
            }
        }

        $revision = SbiRevision::firstOrFail();
        $revision->update(['personal_notes' => 'Nota privata di prova']);
        $this->assertArrayNotHasKey('personal_notes', $revision->fresh()->toArray());

        $this->seed(FeedbackSeeder::class);
        $this->assertDatabaseCount('sbis', 3);
        $this->assertDatabaseCount('sbi_revisions', 3);
        $this->assertDatabaseCount('sbi_replies', 1);
        $this->assertSame('Nota privata di prova', $revision->fresh()->personal_notes);
    }
}
