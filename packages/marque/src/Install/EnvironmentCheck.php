<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The gate that runs before anything is written.
 *
 * Ordering matters: this runs BEFORE the interview, so the operator is never
 * asked a series of questions whose answers are about to be discarded. That in
 * turn means it cannot know which tracker was chosen, so whether Redis is
 * required is passed in rather than inferred.
 */
final class EnvironmentCheck
{
    public function __construct(private readonly Application $app) {}

    public function run(bool $needsRedis): EnvironmentReport
    {
        $report = $this->runUnconditional();

        if ($needsRedis) {
            $this->checkRedis($report);
        }

        return $report;
    }

    /**
     * The checks that hold regardless of what the operator chooses, run BEFORE
     * the interview so nobody answers a page of questions only to be told
     * their database is unreachable.
     *
     * Redis is deliberately not here: whether it is required depends on
     * whether the deployment announces, which is an interview answer. So the
     * gate is split rather than the ordering compromised.
     */
    public function runUnconditional(): EnvironmentReport
    {
        $report = new EnvironmentReport;

        $this->checkDatabase($report);
        $this->checkMail($report);

        return $report;
    }

    /**
     * The part that needs an answer first. Called after the interview with the
     * selection's own requirement.
     */
    public function runConditional(EnvironmentReport $report, bool $needsRedis): EnvironmentReport
    {
        if ($needsRedis) {
            $this->checkRedis($report);
        }

        return $report;
    }

    /**
     * Fatal. Migrations, the admin seed and every package's tables depend on
     * it, so continuing without one produces an app that looks installed and
     * has no schema.
     */
    private function checkDatabase(EnvironmentReport $report): void
    {
        try {
            DB::connection()->getPdo();
            $report->pass('database');
        } catch (Throwable $e) {
            $connection = (string) config('database.default');

            $report->fail('database', sprintf(
                'The database is not reachable on connection [%s]: %s'
                ."\n".'  Check DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env,'
                ."\n".'  make sure the server is running, then run marque:install again.',
                $connection,
                $this->firstLine($e->getMessage()),
            ));
        }
    }

    /**
     * Fatal when the deployment announces.
     *
     * threepio's PeerService is Redis-backed and every announce goes through
     * it. Redis holds the baseline that transfer deltas are diffed against, so
     * an install that cannot reach it does not degrade gracefully — it credits
     * zero, silently, and the bytes are unrecoverable (see the comments on
     * PeerService::class and job #10700).
     */
    private function checkRedis(EnvironmentReport $report): void
    {
        try {
            Redis::connection(config('threepio.redis.connection', 'default'))->ping();
            $report->pass('redis');
        } catch (Throwable $e) {
            $report->fail('redis', sprintf(
                'Redis is not reachable: %s'
                ."\n".'  Marque stores live peer state in Redis, and announce accounting is'
                ."\n".'  diffed against it — a tracker cannot run without one.'
                ."\n".'  Check REDIS_HOST and REDIS_PORT in .env and that the server is running.',
                $this->firstLine($e->getMessage()),
            ));
        }
    }

    /**
     * Warn only.
     *
     * Deferring mail on first setup is normal and what it costs is bounded and
     * obvious, so it does not justify refusing to install. The warning names
     * the features that will not work rather than merely reporting a yellow
     * check, because an operator who defers this needs to know what they have
     * deferred.
     */
    private function checkMail(EnvironmentReport $report): void
    {
        $mailer = (string) config('mail.default');

        // `log` and `array` are Laravel's non-delivering defaults: mail is
        // written to the log or discarded. Nothing is sent, which is fine for
        // local work and wrong for a live tracker.
        if (in_array($mailer, ['log', 'array', ''], true)) {
            $report->warn('mail', sprintf(
                'Mail is set to [%s], so no mail will actually be delivered.'
                ."\n".'  Registration and password reset will not work until you configure a'
                ."\n".'  real mailer. Everything else installs and runs normally.',
                $mailer === '' ? 'unset' : $mailer,
            ));

            return;
        }

        if ($mailer === 'smtp' && blank(config('mail.mailers.smtp.host'))) {
            $report->warn('mail', 'Mail is set to [smtp] but no host is configured.'
                ."\n".'  Registration and password reset will not work until MAIL_HOST is set.');

            return;
        }

        $report->pass('mail');
    }

    /**
     * Driver exceptions arrive as multi-line messages with stack context. The
     * first line carries the actual cause; the rest is noise in a console
     * refusal.
     */
    private function firstLine(string $message): string
    {
        return trim(explode("\n", $message)[0]);
    }
}
