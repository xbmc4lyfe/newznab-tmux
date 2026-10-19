<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Regression tests for how the root and docker Justfiles read trailing KEY=VAL arguments, docker/.env
 * and free-form `cmd` strings. They run the real recipes against fake docker/sail binaries on PATH.
 */
class JustfileArgumentsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/justfile-args-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/docker/bin', 0700, true);
        mkdir($this->directory.'/scripts', 0700);
        mkdir($this->directory.'/bin', 0700);
        mkdir($this->directory.'/storage/app/monitoring/public', 0700, true);
        copy(__DIR__.'/../../Justfile', $this->directory.'/Justfile');
        copy(__DIR__.'/../../docker/Justfile', $this->directory.'/docker/Justfile');
        copy(__DIR__.'/../../docker/bin/compose', $this->directory.'/docker/bin/compose');
        copy(__DIR__.'/../../docker/bin/compose_dotenv.py', $this->directory.'/docker/bin/compose_dotenv.py');
        copy(__DIR__.'/../../scripts/dotenv-get', $this->directory.'/scripts/dotenv-get');
        chmod($this->directory.'/docker/bin/compose', 0755);
        chmod($this->directory.'/scripts/dotenv-get', 0755);
        // macOS ships bash 3.2 as /bin/bash; recipes must run there (and on Linux's newer bash).
        if (is_executable('/bin/bash')) {
            symlink('/bin/bash', $this->directory.'/bin/bash');
        }
        // Prints one bracketed line per argument plus the environment the recipes hand to Compose/Sail.
        file_put_contents($this->directory.'/bin/fake', <<<'SH'
#!/bin/sh
printf 'COMPOSE_FILE=[%s]\n' "${COMPOSE_FILE-unset}"
printf 'WEB_IMAGE=[%s]\n' "${WEB_IMAGE-unset}"
printf 'COMPOSE_PROFILES=[%s]\n' "${COMPOSE_PROFILES-unset}"
for argument in "$@"; do printf 'arg:[%s]\n' "$argument"; done
SH);
        chmod($this->directory.'/bin/fake', 0755);
        // `docker compose ps` stand-in: `--format json` yields whole-object lines, a Go template is
        // rendered per container, and FAKE_PS_FAIL makes the formatted call fail.
        file_put_contents($this->directory.'/bin/fakecompose', <<<'SH'
#!/bin/sh
if [ "$1" != ps ] || [ "${2:-}" != --format ]; then echo plain-ps; exit 0; fi
[ -z "${FAKE_PS_FAIL:-}" ] || exit 1
while IFS='|' read -r service health; do
    case "$3" in
        json) printf '{"ID":"c0ffee","Name":"nntmux-%s-1","Service":"%s","Health":"%s","State":"running"}\n' "$service" "$service" "$health" ;;
        *) printf '%s\n' "$3" | sed -e "s/{{.Service}}/$service/" -e "s/{{.Health}}/$health/" ;;
    esac
done <<'ROWS'
web|healthy
redis|healthy
horizon|
ROWS
SH);
        chmod($this->directory.'/bin/fakecompose', 0755);
        touch($this->directory.'/storage/app/monitoring/public/grafana-jwt.pub');
        touch($this->directory.'/a.txt');
        touch($this->directory.'/b.txt');
        file_put_contents($this->directory.'/.env', "MONITORING_ENABLED=false\n");
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    // T32 ----------------------------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function interpolatedDockerEnvLines(): array
    {
        return [
            'unquoted image' => ['APP_IMAGE=${REGISTRY}/nntmux:${TAG}', 'APP_IMAGE'],
            'double-quoted image' => ['WEB_IMAGE="$REGISTRY/web:1"', 'WEB_IMAGE'],
            'exported role image' => ['export INDEXER_IMAGE=${R}/indexer', 'INDEXER_IMAGE'],
            'compose file' => ['COMPOSE_FILE=$STACK/a.yml:$STACK/b.yml', 'COMPOSE_FILE'],
            'compose command' => ['DC=${DOCKER:-docker} compose', 'DC'],
        ];
    }

    #[DataProvider('interpolatedDockerEnvLines')]
    public function test_docker_env_interpolation_in_literal_keys_fails_closed(string $line, string $key): void
    {
        file_put_contents($this->directory.'/docker/.env', "BASE_IMAGE=nntmux/base:local\n".$line."\n");

        // The Justfile prelude refuses before any Compose command runs.
        $environment = $key === 'DC' ? [] : ['DC' => $this->directory.'/bin/fake'];
        $just = $this->just('docker/Justfile', ['ps'], $environment);
        $just->run();
        $this->assertSame(1, $just->getExitCode());
        $this->assertStringContainsString($key, $just->getErrorOutput());
        $this->assertStringContainsString('interpolation', $just->getErrorOutput());
        $this->assertStringNotContainsString('arg:[', $just->getOutput());

        // So does the Compose wrapper when it is run on its own.
        $wrapper = new Process([$this->directory.'/docker/bin/compose', 'ps'], env: $this->environment(['DC' => $this->directory.'/bin/fake']));
        $wrapper->run();
        $this->assertNotSame(0, $wrapper->getExitCode());
        $this->assertStringContainsString($key, $wrapper->getErrorOutput());
        $this->assertStringContainsString('interpolation', $wrapper->getErrorOutput());
        $this->assertStringNotContainsString('arg:[', $wrapper->getOutput());
    }

    public function test_interpolated_database_values_fail_closed_for_shell_recipes(): void
    {
        file_put_contents($this->directory.'/docker/.env', "DB_ROOTPASSWORD=\${ROOT_SECRET}\n");
        $process = $this->just('docker/Justfile', ['ps'], ['DC' => $this->directory.'/bin/fake']);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('DB_ROOTPASSWORD must be literal', $process->getErrorOutput());
    }

    public function test_only_the_last_assignment_of_a_literal_key_can_be_interpolated(): void
    {
        file_put_contents($this->directory.'/docker/.env', "APP_IMAGE=\${REGISTRY}/app\nAPP_IMAGE=registry/app:2\n");
        $process = $this->just('docker/Justfile', ['ps'], ['DC' => $this->directory.'/bin/fake']);
        $process->mustRun();
        $this->assertStringContainsString('WEB_IMAGE=[registry/app:2]', $process->getOutput());
    }

    public function test_single_quoted_values_and_other_keys_may_contain_a_dollar_sign(): void
    {
        file_put_contents($this->directory.'/docker/.env', "APP_IMAGE='registry/\$literal:1' # not interpolated by Compose\nDB_PASSWORD=pa\$\$word\nMARIADB_IMAGE=\${REGISTRY}/mariadb\n");
        $process = $this->just('docker/Justfile', ['ps'], ['DC' => $this->directory.'/bin/fake']);
        $process->mustRun();
        $this->assertStringContainsString('WEB_IMAGE=[registry/$literal:1]', $process->getOutput());
    }

    public function test_double_quoted_docker_env_escapes_are_decoded_like_compose(): void
    {
        // Compose initializes MariaDB with `pa\ss"q`; the recipes must pass the same password.
        file_put_contents($this->directory.'/docker/.env', "DB_ROOTPASSWORD=\"pa\\\\ss\\\"q\" # root\nDB_DATABASE='nn\\\\tmux'\nAPP_IMAGE=\"registry/app:\\\"1\\\"\"\n");
        // phpunit.xml's DB_DATABASE would win over docker/.env.
        $process = $this->just('docker/Justfile', ['mysql'], ['DC' => $this->directory.'/bin/fake', 'DB_DATABASE' => false]);
        $process->mustRun();
        $this->assertContains('-ppa\\ss"q', $this->arguments($process));
        // Single-quoted values stay literal.
        $this->assertContains('nn\\\\tmux', $this->arguments($process));

        // The Compose wrapper reads docker/.env with the same parser.
        $wrapper = new Process([$this->directory.'/docker/bin/compose'], env: $this->environment(['DC' => 'env']));
        $wrapper->mustRun();
        $this->assertStringContainsString("\nWEB_IMAGE=registry/app:\"1\"\n", "\n".$wrapper->getOutput());

        // Other backslash sequences stay as written; `\0NNN` is octal (Compose gives `a\cbA`).
        file_put_contents($this->directory.'/docker/.env', "DB_ROOTPASSWORD=\"a\\cb\\0101\"\nDB_DATABASE=nntmux\n");
        $kept = $this->just('docker/Justfile', ['mysql'], ['DC' => $this->directory.'/bin/fake', 'DB_DATABASE' => false]);
        $kept->mustRun();
        $this->assertContains('-pa\\cbA', $this->arguments($kept));

        // An escaped backslash before the closing quote still closes it; `\'` unescapes in single quotes.
        file_put_contents($this->directory.'/docker/.env', "DB_ROOTPASSWORD=\"secret\\\\\" # trailing\nDB_DATABASE='it\\'s'\n");
        $closed = $this->just('docker/Justfile', ['mysql'], ['DC' => $this->directory.'/bin/fake', 'DB_DATABASE' => false]);
        $closed->mustRun();
        $this->assertContains('-psecret\\', $this->arguments($closed));
        $this->assertContains("it's", $this->arguments($closed));

        // An escaped `$` is literal in Compose, so it is no interpolation either.
        file_put_contents($this->directory.'/docker/.env', "APP_IMAGE=\"registry/\\\$app:1\"\n");
        $literal = $this->just('docker/Justfile', ['ps'], ['DC' => $this->directory.'/bin/fake']);
        $literal->mustRun();
        $this->assertStringContainsString('WEB_IMAGE=[registry/$app:1]', $literal->getOutput());
    }

    // T43 ----------------------------------------------------------------------------------------

    public function test_root_artisan_forwards_cmd_as_an_argument_array(): void
    {
        $process = $this->just('Justfile', ['artisan', 'cmd=some:command --label="two words" '."'single quoted' *"], ['SAIL' => $this->directory.'/bin/fake']);
        $process->mustRun();
        $this->assertSame(['artisan', 'some:command', '--label=two words', 'single quoted', '*'], $this->arguments($process));
    }

    public function test_root_artisan_handles_an_empty_cmd_and_the_cmd_environment_fallback(): void
    {
        $empty = $this->just('Justfile', ['artisan'], ['SAIL' => $this->directory.'/bin/fake']);
        $empty->mustRun();
        $this->assertSame(['artisan'], $this->arguments($empty));

        $fallback = $this->just('Justfile', ['artisan'], ['SAIL' => $this->directory.'/bin/fake', 'CMD' => 'migrate:status --env=testing']);
        $fallback->mustRun();
        $this->assertSame(['artisan', 'migrate:status', '--env=testing'], $this->arguments($fallback));
    }

    public function test_root_artisan_rejects_unbalanced_quotes_without_running_anything(): void
    {
        $process = $this->just('Justfile', ['artisan', 'cmd=some:command --label="two words'], ['SAIL' => $this->directory.'/bin/fake']);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Invalid quoting in cmd', $process->getErrorOutput());
        $this->assertStringNotContainsString('arg:[', $process->getOutput());
    }

    public function test_docker_artisan_forwards_cmd_as_an_argument_array(): void
    {
        $process = $this->just('docker/Justfile', ['artisan', 'cmd=some:command --label="two words" *'], ['DC' => $this->directory.'/bin/fake']);
        $process->mustRun();
        $this->assertSame(['exec', 'web', 'php', 'artisan', 'some:command', '--label=two words', '*'], $this->arguments($process));

        $empty = $this->just('docker/Justfile', ['artisan'], ['DC' => $this->directory.'/bin/fake']);
        $empty->mustRun();
        $this->assertSame(['exec', 'web', 'php', 'artisan'], $this->arguments($empty));
    }

    // T50 ----------------------------------------------------------------------------------------

    public function test_explicit_empty_trailing_overrides_clear_docker_env_values(): void
    {
        file_put_contents($this->directory.'/docker/.env', "COMPOSE_FILE=/stack/base.yml\nWEB_IMAGE=env/web:1\n");
        $environment = ['DC' => $this->directory.'/bin/fake'];

        $plain = $this->just('docker/Justfile', ['ps'], $environment);
        $plain->mustRun();
        $this->assertStringContainsString('COMPOSE_FILE=[/stack/base.yml]', $plain->getOutput());
        $this->assertStringContainsString('WEB_IMAGE=[env/web:1]', $plain->getOutput());

        // COMPOSE_FILE= returns to the stack's own docker-compose.yml; WEB_IMAGE= falls back to the
        // role default (Compose reads the empty value through `${WEB_IMAGE:-nntmux/web:local}`).
        $cleared = $this->just('docker/Justfile', ['ps', 'COMPOSE_FILE=', 'WEB_IMAGE='], $environment);
        $cleared->mustRun();
        $this->assertStringContainsString('COMPOSE_FILE=['.realpath($this->directory).'/docker/docker-compose.yml]', $cleared->getOutput());
        $this->assertStringContainsString('WEB_IMAGE=[]', $cleared->getOutput());
        $this->assertStringNotContainsString('env/web:1', $cleared->getOutput());
    }

    public function test_explicit_empty_role_image_does_not_fall_back_to_docker_env_in_the_wrapper(): void
    {
        file_put_contents($this->directory.'/docker/.env', "WEB_IMAGE=env/web:1\n");
        $process = $this->just('docker/Justfile', ['ps', 'APP_IMAGE=custom/app:1', 'WEB_IMAGE='], ['DC' => $this->directory.'/bin/fake']);
        $process->mustRun();
        $this->assertStringContainsString('WEB_IMAGE=[custom/app:1]', $process->getOutput());
    }

    public function test_environment_values_still_beat_docker_env_and_trailing_arguments_beat_the_environment(): void
    {
        file_put_contents($this->directory.'/docker/.env', "COMPOSE_FILE=/stack/base.yml\n");
        $environment = ['DC' => $this->directory.'/bin/fake', 'COMPOSE_FILE' => '/caller.yml'];

        $fromEnvironment = $this->just('docker/Justfile', ['ps'], $environment);
        $fromEnvironment->mustRun();
        $this->assertStringContainsString('COMPOSE_FILE=[/caller.yml]', $fromEnvironment->getOutput());

        $fromArgument = $this->just('docker/Justfile', ['ps', 'COMPOSE_FILE=/argument.yml'], $environment);
        $fromArgument->mustRun();
        $this->assertStringContainsString('COMPOSE_FILE=[/argument.yml]', $fromArgument->getOutput());
    }

    // T53 ----------------------------------------------------------------------------------------

    public function test_trailing_monitoring_override_reaches_the_lifecycle_recipes(): void
    {
        $overlay = 'COMPOSE_FILE=[docker-compose.yml:docker-compose.monitoring.yml]';
        $environment = ['SAIL' => $this->directory.'/bin/fake', 'DOCKER_COMPOSE' => $this->directory.'/bin/fake'];

        // .env disables monitoring: only the override enables the overlay.
        $off = $this->just('Justfile', ['up'], $environment);
        $off->mustRun();
        $this->assertStringContainsString('COMPOSE_FILE=[unset]', $off->getOutput());
        foreach (['up', 'down', 'restart', 'recreate', 'pull', 'build', 'rebuild', 'update', 'logs', 'status', 'artisan', 'test'] as $recipe) {
            $on = $this->just('Justfile', [$recipe, 'MONITORING_ENABLED=true'], $environment + ['FORCE' => '1']);
            $on->mustRun();
            $this->assertStringContainsString($overlay, $on->getOutput(), $recipe);
        }

        // .env enables monitoring: =false and = (empty) switch it off for this run.
        file_put_contents($this->directory.'/.env', "MONITORING_ENABLED=true\n");
        $enabled = $this->just('Justfile', ['up'], $environment);
        $enabled->mustRun();
        $this->assertStringContainsString($overlay, $enabled->getOutput());
        foreach (['MONITORING_ENABLED=false', 'MONITORING_ENABLED='] as $override) {
            $disabled = $this->just('Justfile', ['up', $override], $environment);
            $disabled->mustRun();
            $this->assertStringContainsString('COMPOSE_FILE=[unset]', $disabled->getOutput(), $override);
        }
    }

    public function test_an_explicit_empty_profile_override_clears_the_env_profile(): void
    {
        file_put_contents($this->directory.'/.env', "SEARCH_DRIVER=manticore\n");
        $environment = ['SAIL' => $this->directory.'/bin/fake'];

        $default = $this->just('Justfile', ['up'], $environment);
        $default->mustRun();
        $this->assertStringContainsString('COMPOSE_PROFILES=[manticore]', $default->getOutput());

        $cleared = $this->just('Justfile', ['up', 'COMPOSE_PROFILES='], $environment);
        $cleared->mustRun();
        $this->assertStringContainsString('COMPOSE_PROFILES=[]', $cleared->getOutput());
    }

    public function test_a_quoted_value_ends_at_its_own_closing_quote(): void
    {
        file_put_contents($this->directory.'/.env', "MONITORING_ENABLED=\"true\" # turn off with \"false\"\n");
        $process = new Process([dirname(__DIR__, 2).'/scripts/dotenv-get', 'MONITORING_ENABLED'], $this->directory);
        $process->mustRun();
        $this->assertSame('true', $process->getOutput());

        file_put_contents($this->directory.'/docker/.env', "COMPOSE_FILE=\"/stack/base.yml\" # not \"/other.yml\"\n");
        $plain = $this->just('docker/Justfile', ['ps'], ['DC' => $this->directory.'/bin/fake']);
        $plain->mustRun();
        $this->assertStringContainsString('COMPOSE_FILE=[/stack/base.yml]', $plain->getOutput());
    }

    public function test_root_env_values_are_interpolated_like_compose(): void
    {
        file_put_contents($this->directory.'/.env', "SEARCH_BACKEND=manticore\nSEARCH_DRIVER=\${SEARCH_BACKEND}\nMODE=\"\${UNSET_X:-fallback}\"\nLITERAL='\${SEARCH_BACKEND}'\n");
        foreach (['SEARCH_DRIVER' => 'manticore', 'MODE' => 'fallback', 'LITERAL' => '${SEARCH_BACKEND}'] as $key => $expected) {
            $process = new Process([dirname(__DIR__, 2).'/scripts/dotenv-get', $key], $this->directory, $this->environment([]));
            $process->mustRun();
            $this->assertSame($expected, $process->getOutput(), $key);
        }
    }

    public function test_check_env_honours_the_trailing_monitoring_override(): void
    {
        unlink($this->directory.'/storage/app/monitoring/public/grafana-jwt.pub');
        $enabled = $this->just('Justfile', ['check-env', 'MONITORING_ENABLED=true']);
        $enabled->mustRun();
        $this->assertStringContainsString('monitoring stays off', $enabled->getOutput());

        file_put_contents($this->directory.'/.env', "MONITORING_ENABLED=true\n");
        $disabled = $this->just('Justfile', ['check-env', 'MONITORING_ENABLED=false']);
        $disabled->mustRun();
        $this->assertStringNotContainsString('monitoring stays off', $disabled->getOutput());
        $this->assertStringContainsString('monitoring stays off', $this->just('Justfile', ['check-env'])->mustRun()->getOutput());
    }

    // T66 ----------------------------------------------------------------------------------------

    public function test_health_reports_service_and_health_from_a_compose_template(): void
    {
        $process = $this->just('Justfile', ['health'], ['DOCKER_COMPOSE' => $this->directory.'/bin/fakecompose']);
        $process->mustRun();
        $this->assertSame("horizon: \nredis: healthy\nweb: healthy\n", $process->getOutput());
    }

    public function test_health_falls_back_to_plain_ps_when_the_formatted_call_fails(): void
    {
        $process = $this->just('Justfile', ['health'], ['DOCKER_COMPOSE' => $this->directory.'/bin/fakecompose', 'FAKE_PS_FAIL' => '1']);
        $process->mustRun();
        $this->assertSame("plain-ps\n", $process->getOutput());
    }

    // helpers ------------------------------------------------------------------------------------

    /** @return list<string> the `arg:[...]` lines printed by bin/fake */
    private function arguments(Process $process): array
    {
        preg_match_all('/^arg:\[(.*)\]$/m', $process->getOutput(), $matches);

        return $matches[1];
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    private function just(string $justfile, array $arguments, array $environment = []): Process
    {
        return new Process(
            ['just', '--justfile', $this->directory.'/'.$justfile, '--working-directory', $this->directory.'/'.dirname($justfile), ...$arguments],
            $this->directory.'/'.dirname($justfile),
            $this->environment($environment),
        );
    }

    /**
     * @param  array<string, string|false>  $environment
     * @return array<string, string|false>
     */
    private function environment(array $environment): array
    {
        // Drop anything of the developer's shell that the recipes would otherwise pick up.
        $clean = [];
        foreach (['COMPOSE_FILE', 'COMPOSE_PROFILES', 'DC', 'DOCKER_COMPOSE', 'SAIL', 'CMD', 'cmd', 'FORCE', 'MONITORING_ENABLED', 'APP_IMAGE', 'WEB_IMAGE', 'HORIZON_IMAGE', 'SCHEDULER_IMAGE', 'INDEXER_IMAGE', 'BASE_IMAGE', 'HELPER_IMAGE', 'DEPLOY_BY', 'SEARCH_DRIVER', 'FAKE_PS_FAIL'] as $name) {
            $clean[$name] = false;
        }

        return array_merge($clean, ['PATH' => $this->directory.'/bin:'.getenv('PATH')], $environment);
    }
}
