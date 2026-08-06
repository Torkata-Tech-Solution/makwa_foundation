<?php

namespace App\Console\Commands;

use App\Models\Issue;
use Illuminate\Console\Command;

class MigrateIssueAuthorFees extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-issue-author-fees {--force : Overwrite existing author_fee values on issues}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate/copy author_fee from parent Journal to existing Issue records.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting migration of author_fee for issues...');

        $force = $this->option('force');
        $query = Issue::with('journal');

        if (! $force) {
            // Only update issues where author_fee is 0 or null
            $query->where(function ($q) {
                $q->whereNull('author_fee')->orWhere('author_fee', 0);
            });
        }

        $issues = $query->get();

        if ($issues->isEmpty()) {
            $this->info('No issues found needing author_fee migration.');

            return Command::SUCCESS;
        }

        $count = 0;
        $bar = $this->output->createProgressBar($issues->count());
        $bar->start();

        foreach ($issues as $issue) {
            if ($issue->journal) {
                $issue->author_fee = $issue->journal->author_fee ?? 0;
                $issue->saveQuietly();
                $count++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info("Successfully updated author_fee for {$count} issue(s).");

        return Command::SUCCESS;
    }
}
