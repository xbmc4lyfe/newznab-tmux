<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Predb\PredbIdentityRebuild;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PredbIdentityRebuildCommand extends Command
{
    protected $signature = 'predb:identity-rebuild {action} {--generation=} {--chunk=128}';

    protected $description = 'Execute one finite PreDB identity rebuild operation';

    public function handle(PredbIdentityRebuild $rebuild): int
    {
        $action = $this->argument('action');
        $generation = $this->option('generation');
        $chunk = $this->option('chunk');
        if (! in_array($action, ['start', 'chunk', 'certify'], true)) {
            return $this->invalid('Action must be start, chunk, or certify.');
        }
        if ($action === 'start' && ($generation !== null || $this->input->hasParameterOption('--generation', true))) {
            return $this->invalid('Start does not accept a generation.');
        }
        if ($action !== 'start' && (! is_string($generation) || preg_match('/\A[0-9a-f]{32}\z/D', $generation) !== 1)) {
            return $this->invalid('A 32-character lowercase hexadecimal generation is required.');
        }
        if (! is_string($chunk) || strlen($chunk) > 3 || preg_match('/\A[1-9][0-9]{0,2}\z/D', $chunk) !== 1 || (int) $chunk > 256) {
            return $this->invalid('Chunk must be a canonical integer from 1 to 256.');
        }
        if ($action !== 'chunk' && $chunk !== '128') {
            return $this->invalid('A custom chunk applies only to the chunk action.');
        }
        try {
            $connection = DB::connection();
            if ($action === 'start') {
                $r = $rebuild->start($connection);
                $result = ['phase' => 'start', ...$r, 'certified' => false];
            } elseif ($action === 'chunk') {
                $r = $rebuild->chunk($connection, $generation, (int) $chunk);
                $result = ['phase' => 'chunk', ...$r, 'certified' => false];
            } else {
                $rebuild->certify($connection, $generation);
                $result = ['phase' => 'certify', 'generation' => $generation, 'certified' => true];
            }

        } catch (Throwable $e) {
            $safe = ['Unsupported PreDB rebuild page.', 'Unsupported PreDB rebuild generation.', 'PreDB rebuild generation, epoch or loaded policy changed.', 'PreDB rebuild inventory is incomplete.', 'PreDB rebuild inventory changed.', 'PreDB rebuild state is unavailable.', 'Unsupported PreDB schema attestation driver.', 'Unsupported PreDB rebuild traversal metadata driver.', 'PreDB rebuild requires its own supported transaction.', 'PreDB loaded identity policy is unavailable.'];
            $this->error(in_array($e->getMessage(), $safe, true) ? $e->getMessage() : 'PreDB identity rebuild failed; no certification was reported.');

            return self::FAILURE;
        }
        try {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            try {
                $this->error('PreDB identity operation committed; result output failed. Inspect state before retrying.');
            } catch (Throwable) {
                // The output channel can remain unavailable after the committed operation.
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
