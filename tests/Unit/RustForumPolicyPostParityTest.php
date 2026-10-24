<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Forumpost;
use App\Models\User;
use App\Policies\CategoryPolicy;
use App\Policies\ForumPolicy;
use App\Policies\PostPolicy;
use App\Policies\ThreadPolicy;
use App\Support\RustPipelineLibrary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use TeamTeaTime\Forum\Models\Category;
use TeamTeaTime\Forum\Models\Post;
use TeamTeaTime\Forum\Models\Thread;
use Tests\TestCase;

/**
 * Wave 24 Slice 24.3 parity, C-ABI safety and paired benchmark for the forum policy and
 * `Forumpost` helpers exported from `rust/pipeline-ffi/src/categorize.rs`
 * (`forum_policy_and_post`).
 *
 * Paired benchmark evidence (macOS Darwin arm64, PHP 8 FFI, release build of
 * `nntmux-pipeline-ffi` `categorize` domain, 3 rounds x 41 paired samples x 200 iterations,
 * warmup = 6, seeded random pair order; per-sample batch latency in ms):
 *
 * - Common path (admin category action, forum admin capability, int-id post/thread ownership,
 *   short post payload, root-topic routing):
 *   PHP mean 0.0616 ms (p99 0.0967 ms) vs Rust FFI mean 0.4869 ms (p99 0.6438 ms)
 *   -> 0.13x mean (0.15x p99).
 * - Uncommon workload (unknown/view category actions, guest capability, string/null ids,
 *   8 KiB and empty messages, PHP_INT_MAX parents):
 *   PHP mean 0.0973 ms (p99 0.1368 ms) vs Rust FFI mean 0.7752 ms (p99 0.9650 ms)
 *   -> 0.13x mean (0.14x p99).
 *
 * Reproduce: `NNTMUX_BENCH_REPORT=1 php artisan test --compact tests/Unit/RustForumPolicyPostParityTest.php`.
 *
 * Per `.ai/rules/rust-performance.md`, the per-call PHP FFI marshaling (`FFI::new`, string
 * arguments, CData reads) dominates these nanosecond-scale boolean checks, so neither workload
 * meets the 1.5x threshold. All five classes keep `$useRustFfi = false` in production while the
 * Rust exports and `*Native` / `*Php` pairs stay public for parity verification.
 */
class RustForumPolicyPostParityTest extends TestCase
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_category_policy_evaluate_permission(
    const char *action_ptr,
    size_t action_len,
    uint8_t is_user_instance,
    uint8_t is_admin
);
int32_t nntmux_forum_policy_evaluate_admin_capability(uint8_t has_user, uint8_t has_admin_role);
int32_t nntmux_post_policy_can_modify(
    uint8_t is_user_instance,
    uint8_t is_admin,
    uint8_t user_kind,
    int64_t user_int,
    const char *user_ptr,
    size_t user_len,
    uint8_t author_kind,
    int64_t author_int,
    const char *author_ptr,
    size_t author_len
);
int32_t nntmux_thread_policy_can_modify(
    uint8_t is_user_instance,
    uint8_t is_admin,
    uint8_t user_kind,
    int64_t user_int,
    const char *user_ptr,
    size_t user_len,
    uint8_t author_kind,
    int64_t author_int,
    const char *author_ptr,
    size_t author_len
);
int32_t nntmux_forumpost_build_post_attributes(
    int64_t parent_id,
    int64_t user_id,
    const char *message_ptr,
    size_t message_len,
    int64_t locked,
    int64_t sticky,
    int64_t replies,
    int64_t *out_fields,
    size_t out_cap
);
int32_t nntmux_forumpost_is_root_topic(int64_t parent_id);
CDEF;

    private const CATEGORY_ACTIONS = [
        'manageThreads',
        'deleteThreads',
        'restoreThreads',
        'enableThreads',
        'moveThreadsFrom',
        'moveThreadsTo',
        'lockThreads',
        'pinThreads',
        'view',
        'delete',
        'edit',
    ];

    private const BENCH_ROUNDS = 3;

    private const BENCH_SAMPLES = 41;

    private const BENCH_ITERATIONS = 200;

    private const BENCH_WARMUP = 6;

    private const ADOPTION_THRESHOLD = 1.5;

    /**
     * @var list<class-string>
     */
    private const FFI_CLASSES = [
        CategoryPolicy::class,
        ForumPolicy::class,
        PostPolicy::class,
        ThreadPolicy::class,
        Forumpost::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::FFI_CLASSES as $class) {
            $class::resetRustFfi();
        }

        parent::tearDown();
    }

    public function test_production_defaults_and_toggle_lifecycle(): void
    {
        foreach (self::FFI_CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} should default to PHP implementation");

            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());

            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());

            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());

            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    public function test_category_permission_parity(): void
    {
        $this->requireNativeLibrary();

        $actions = [...self::CATEGORY_ACTIONS, 'createThreads', 'View', 'EDIT', '', "view\0", ' edit', 'manageCategories', str_repeat('x', 4096)];
        foreach ($actions as $action) {
            foreach ([[false, false], [true, false], [false, true], [true, true]] as [$isUser, $isAdmin]) {
                $this->assertSame(
                    CategoryPolicy::evaluateCategoryPermissionPhp($action, $isUser, $isAdmin),
                    CategoryPolicy::evaluateCategoryPermissionNative($action, $isUser, $isAdmin),
                    "action={$action} user={$isUser} admin={$isAdmin}"
                );
            }
        }

        $this->assertTrue(CategoryPolicy::evaluateCategoryPermissionPhp('view', true, false));
        $this->assertFalse(CategoryPolicy::evaluateCategoryPermissionPhp('view', false, true));
        $this->assertTrue(CategoryPolicy::evaluateCategoryPermissionPhp('edit', true, true));
        $this->assertFalse(CategoryPolicy::evaluateCategoryPermissionPhp('edit', true, false));
        $this->assertFalse(CategoryPolicy::evaluateCategoryPermissionPhp('createThreads', true, true));
    }

    public function test_forum_admin_capability_parity(): void
    {
        $this->requireNativeLibrary();

        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$hasUser, $hasAdmin]) {
            $this->assertSame($hasUser && $hasAdmin, ForumPolicy::evaluateForumAdminCapabilityPhp($hasUser, $hasAdmin));
            $this->assertSame(
                ForumPolicy::evaluateForumAdminCapabilityPhp($hasUser, $hasAdmin),
                ForumPolicy::evaluateForumAdminCapabilityNative($hasUser, $hasAdmin)
            );
        }
    }

    public function test_post_and_thread_modify_parity_uses_strict_identity(): void
    {
        $this->requireNativeLibrary();

        $ids = [null, 0, 1, 5, -5, PHP_INT_MAX, PHP_INT_MIN, '', '0', '1', '5', '05', ' 5', "5\0", 'abc', str_repeat('9', 300)];
        foreach ($ids as $userId) {
            foreach ($ids as $authorId) {
                foreach ([[false, false], [true, false], [false, true], [true, true]] as [$isUser, $isAdmin]) {
                    $expected = $isAdmin || ($isUser && $userId === $authorId);
                    $label = var_export([$isUser, $isAdmin, $userId, $authorId], true);

                    $this->assertSame($expected, PostPolicy::canModifyPostPhp($isUser, $isAdmin, $userId, $authorId), $label);
                    $this->assertSame($expected, PostPolicy::canModifyPostNative($isUser, $isAdmin, $userId, $authorId), $label);
                    $this->assertSame($expected, ThreadPolicy::canModifyThreadPhp($isUser, $isAdmin, $userId, $authorId), $label);
                    $this->assertSame($expected, ThreadPolicy::canModifyThreadNative($isUser, $isAdmin, $userId, $authorId), $label);
                }
            }
        }
    }

    public function test_forumpost_attribute_and_root_topic_parity(): void
    {
        $this->requireNativeLibrary();

        $messages = ['', 'hello', '0', ' ', "line\0nul", str_repeat('m', 8192), 'üñíçødé'];
        $ints = [0, 1, -1, 42, PHP_INT_MAX, PHP_INT_MIN];
        foreach ($messages as $message) {
            foreach ($ints as $value) {
                $php = Forumpost::validateAndBuildPostAttributesPhp($value, $value, 'Subject '.$value, $message, $value, 1, $value);
                $native = Forumpost::validateAndBuildPostAttributesNative($value, $value, 'Subject '.$value, $message, $value, 1, $value);
                $this->assertSame($php, $native);
            }
        }

        $this->assertNull(Forumpost::validateAndBuildPostAttributesPhp(0, 1, 'S', '', 0, 0, 0));
        $this->assertSame(
            ['forumid' => 1, 'parentid' => 3, 'users_id' => 9, 'subject' => 'S', 'message' => 'M', 'locked' => 1, 'sticky' => 0, 'replies' => 2],
            Forumpost::validateAndBuildPostAttributesNative(3, 9, 'S', 'M', 1, 0, 2)
        );

        foreach ($ints as $parentId) {
            $this->assertSame($parentId === 0, Forumpost::isRootTopicPostPhp($parentId));
            $this->assertSame(Forumpost::isRootTopicPostPhp($parentId), Forumpost::isRootTopicPostNative($parentId));
        }
    }

    public function test_policy_callers_match_original_semantics_with_rust_enabled_and_disabled(): void
    {
        $this->requireNativeLibrary();

        $admin = $this->makeUser(5, true);
        $member = $this->makeUser(7, false);
        $stringKeyMember = $this->makeUser('7', false);
        $users = [null, $admin, $member, $stringKeyMember];
        $authors = [null, 5, 7, '7', 8];

        foreach ([false, true] as $useRust) {
            foreach (self::FFI_CLASSES as $class) {
                $class::setUseRustFfi($useRust);
            }

            $categoryPolicy = new CategoryPolicy;
            $forumPolicy = new ForumPolicy;
            $postPolicy = new PostPolicy;
            $threadPolicy = new ThreadPolicy;
            $category = new Category;

            foreach ($users as $user) {
                $isUser = $user instanceof User;
                $isAdmin = $isUser && $user->hasRole('Admin');

                foreach (self::CATEGORY_ACTIONS as $action) {
                    $expected = $action === 'view' ? $isUser : $isAdmin;
                    $this->assertSame($expected, $categoryPolicy->{$action}($user, $category), "category {$action}");
                }

                foreach (['createCategories', 'manageCategories', 'moveCategories', 'renameCategories', 'viewTrashedThreads', 'viewTrashedPosts', 'deleteCategories', 'deletePosts', 'deleteThreads', 'restorePosts', 'restoreThreads', 'purgePosts', 'purgeThreads', 'deleteThrashedPosts'] as $ability) {
                    $this->assertSame($isAdmin, $forumPolicy->{$ability}($user), "forum {$ability}");
                }

                foreach ($authors as $authorId) {
                    $isAuthor = $isUser && $user->getKey() === $authorId;
                    $expected = $isAdmin || $isAuthor;

                    $post = new Post;
                    $post->setRawAttributes(['author_id' => $authorId]);
                    foreach (['edit', 'delete', 'restore'] as $ability) {
                        $this->assertSame($expected, $postPolicy->{$ability}($user, $post), "post {$ability}");
                    }

                    $thread = new Thread;
                    $thread->setRawAttributes(['author_id' => $authorId, 'locked' => false]);
                    foreach (['rename', 'replyWithoutApproval', 'delete', 'restore'] as $ability) {
                        $this->assertSame($expected, $threadPolicy->{$ability}($user, $thread), "thread {$ability}");
                    }
                    $this->assertSame($isUser, $threadPolicy->deletePosts($user, $thread));
                    $this->assertSame($isUser, $threadPolicy->restorePosts($user, $thread));
                }
            }

            $floatAuthorPost = new Post;
            $floatAuthorPost->setRawAttributes(['author_id' => 7.0]);
            $this->assertFalse($postPolicy->edit($member, $floatAuthorPost));
            $this->assertTrue($postPolicy->edit($admin, $floatAuthorPost));
        }
    }

    public function test_forumpost_add_and_delete_post_callers_match_with_rust_enabled_and_disabled(): void
    {
        $this->requireNativeLibrary();

        $snapshots = [];
        foreach ([false, true] as $useRust) {
            Schema::dropIfExists('forumpost');
            Schema::dropIfExists('users');
            Schema::create('users', static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('username')->nullable();
            });
            Schema::create('forumpost', static function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('forumid')->default(1);
                $table->integer('parentid')->default(0);
                $table->integer('users_id');
                $table->string('subject');
                $table->text('message');
                $table->boolean('locked')->default(false);
                $table->boolean('sticky')->default(false);
                $table->integer('replies')->default(0);
                $table->timestamps();
            });

            Forumpost::setUseRustFfi($useRust);

            $this->assertSame(-1, Forumpost::add(0, 1, 'Empty', ''));
            $root = Forumpost::add(0, 1, 'Root topic', 'First post', 0, 1, 0);
            $reply = Forumpost::add($root, 2, 'Re: Root topic', 'A reply');
            $secondReply = Forumpost::add($root, 3, 'Re: Root topic', 'Another reply', 1);
            $otherRoot = Forumpost::add(0, 4, 'Other topic', 'Other body');
            $legacyRoot = Forumpost::add('0', '5', 'Legacy', 'Untyped caller');

            $this->assertSame(2, (int) Forumpost::query()->whereKey($root)->value('replies'));

            Forumpost::deletePost($reply);
            $afterReplyDelete = Forumpost::query()->orderBy('id')->pluck('id')->all();
            Forumpost::deletePost($root);
            $afterRootDelete = Forumpost::query()->orderBy('id')->pluck('id')->all();

            $snapshots[] = [
                'ids' => [$root, $reply, $secondReply, $otherRoot, $legacyRoot],
                'after_reply_delete' => $afterReplyDelete,
                'after_root_delete' => $afterRootDelete,
            ];

            $this->assertNotContains($reply, $afterReplyDelete);
            $this->assertContains($secondReply, $afterReplyDelete);
            $this->assertNotContains($secondReply, $afterRootDelete);
            $this->assertContains($otherRoot, $afterRootDelete);
        }

        $this->assertSame($snapshots[0], $snapshots[1]);
        Schema::dropIfExists('forumpost');
        Schema::dropIfExists('users');
    }

    public function test_disabled_ffi_falls_back_cleanly_to_php(): void
    {
        foreach (self::FFI_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::enableRustFfi();
        }

        $this->assertTrue(CategoryPolicy::evaluateCategoryPermission('edit', true, true));
        $this->assertFalse(CategoryPolicy::evaluateCategoryPermission('unknown', true, true));
        $this->assertTrue(ForumPolicy::evaluateForumAdminCapability(true, true));
        $this->assertFalse(ForumPolicy::evaluateForumAdminCapability(false, true));
        $this->assertTrue(PostPolicy::canModifyPost(true, false, 5, 5));
        $this->assertFalse(PostPolicy::canModifyPost(true, false, 5, '5'));
        $this->assertTrue(ThreadPolicy::canModifyThread(false, true, null, 1));
        $this->assertFalse(ThreadPolicy::canModifyThread(false, false, null, null));
        $this->assertNull(Forumpost::validateAndBuildPostAttributes(0, 1, 'S', '', 0, 0, 0));
        $this->assertSame(
            Forumpost::validateAndBuildPostAttributesPhp(2, 1, 'S', 'M', 0, 0, 0),
            Forumpost::validateAndBuildPostAttributes(2, 1, 'S', 'M', 0, 0, 0)
        );
        $this->assertTrue(Forumpost::isRootTopicPost(0));
        $this->assertFalse(Forumpost::isRootTopicPost(9));
    }

    public function test_c_abi_rejects_null_unaligned_invalid_and_undersized_inputs(): void
    {
        $ffi = $this->requireNativeLibrary();

        // Invalid flags and null action pointer decline with -1.
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_category_policy_evaluate_permission(null, 0, 1, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_category_policy_evaluate_permission('edit', 4, 2, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_forum_policy_evaluate_admin_capability(1, 7));

        // Unknown id kinds and null string pointers for kind 2 decline; other kinds ignore the pointer.
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_post_policy_can_modify(1, 0, 3, 0, '', 0, 1, 0, '', 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_thread_policy_can_modify(1, 0, 2, 0, null, 0, 1, 0, '', 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_thread_policy_can_modify(1, 0, 1, 5, null, 0, 1, 5, null, 0));

        // Undersized capacity returns the negated required length and leaves output untouched.
        $out = $ffi->new('int64_t[6]');
        for ($i = 0; $i < 6; $i++) {
            $out[$i] = -777;
        }
        // @phpstan-ignore method.notFound
        $this->assertSame(-6, $ffi->nntmux_forumpost_build_post_attributes(1, 2, 'msg', 3, 0, 0, 0, $out, 5));
        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(-777, $out[$i]);
        }

        // Empty message reports invalid without writing.
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_forumpost_build_post_attributes(1, 2, '', 0, 0, 0, 0, $out, 6));
        $this->assertSame(-777, $out[0]);

        // Null and misaligned output pointers, null message pointers decline.
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_forumpost_build_post_attributes(1, 2, 'msg', 3, 0, 0, 0, null, 6));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_forumpost_build_post_attributes(1, 2, null, 0, 0, 0, 0, $out, 6));
        $raw = $ffi->new('uint8_t[64]');
        \FFI::memset($raw, 0x5A, 64);
        $unaligned = $ffi->cast('int64_t *', \FFI::addr($raw[1]));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_forumpost_build_post_attributes(1, 2, 'msg', 3, 0, 0, 0, $unaligned, 6));
        for ($i = 0; $i < 64; $i++) {
            $this->assertSame(0x5A, $raw[$i]);
        }

        // Valid call writes the fixed field layout.
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_forumpost_build_post_attributes(4, 9, 'msg', 3, 1, 0, 2, $out, 6));
        $this->assertSame([1, 4, 9, 1, 0, 2], [$out[0], $out[1], $out[2], $out[3], $out[4], $out[5]]);

        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_forumpost_is_root_topic(0));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_forumpost_is_root_topic(PHP_INT_MIN));
    }

    public function test_paired_randomized_benchmark_keeps_php_default_below_threshold(): void
    {
        $this->requireNativeLibrary();

        $report = [];
        foreach (['common', 'uncommon'] as $workload) {
            for ($w = 0; $w < self::BENCH_WARMUP; $w++) {
                $this->runWorkload($workload, false, self::BENCH_ITERATIONS);
                $this->runWorkload($workload, true, self::BENCH_ITERATIONS);
            }

            mt_srand(243);
            $phpTimes = [];
            $rustTimes = [];
            for ($round = 0; $round < self::BENCH_ROUNDS; $round++) {
                for ($sample = 0; $sample < self::BENCH_SAMPLES; $sample++) {
                    if (mt_rand(0, 1) === 1) {
                        $rustTimes[] = $this->runWorkload($workload, true, self::BENCH_ITERATIONS);
                        $phpTimes[] = $this->runWorkload($workload, false, self::BENCH_ITERATIONS);
                    } else {
                        $phpTimes[] = $this->runWorkload($workload, false, self::BENCH_ITERATIONS);
                        $rustTimes[] = $this->runWorkload($workload, true, self::BENCH_ITERATIONS);
                    }
                }
            }

            $expectedSamples = self::BENCH_ROUNDS * self::BENCH_SAMPLES;
            $this->assertCount($expectedSamples, $phpTimes);
            $this->assertCount($expectedSamples, $rustTimes);

            $phpMean = array_sum($phpTimes) / $expectedSamples;
            $rustMean = array_sum($rustTimes) / $expectedSamples;
            $phpP99 = $this->percentile($phpTimes, 0.99);
            $rustP99 = $this->percentile($rustTimes, 0.99);
            $this->assertGreaterThan(0.0, $rustMean);
            $this->assertGreaterThan(0.0, $rustP99);

            $report[$workload] = [
                'php_mean' => $phpMean,
                'php_p99' => $phpP99,
                'rust_mean' => $rustMean,
                'rust_p99' => $rustP99,
                'mean_speedup' => $phpMean / $rustMean,
                'p99_speedup' => $phpP99 / $rustP99,
            ];
        }

        if (getenv('NNTMUX_BENCH_REPORT') === '1') {
            fwrite(STDERR, PHP_EOL.json_encode($report, JSON_PRETTY_PRINT).PHP_EOL);
        }

        $minSpeedup = min(array_merge(...array_map(
            static fn (array $stats): array => [$stats['mean_speedup'], $stats['p99_speedup']],
            array_values($report)
        )));
        if ($minSpeedup < self::ADOPTION_THRESHOLD) {
            foreach (self::FFI_CLASSES as $class) {
                $class::resetRustFfi();
                $this->assertFalse($class::usesRustFfi(), "{$class} must keep the PHP default below 1.5x");
            }
        }
    }

    /**
     * Returns the batch latency in milliseconds.
     */
    private function runWorkload(string $workload, bool $native, int $iterations): float
    {
        $sink = 0;
        $start = hrtime(true);
        if ($workload === 'common') {
            for ($i = 0; $i < $iterations; $i++) {
                if ($native) {
                    $sink += (int) CategoryPolicy::evaluateCategoryPermissionNative('manageThreads', true, true);
                    $sink += (int) ForumPolicy::evaluateForumAdminCapabilityNative(true, true);
                    $sink += (int) PostPolicy::canModifyPostNative(true, false, 42, 42);
                    $sink += (int) ThreadPolicy::canModifyThreadNative(true, false, 42, 43);
                    $sink += count(Forumpost::validateAndBuildPostAttributesNative(12, 42, 'Re: Topic', 'Thanks for the update!', 0, 0, 0) ?? []);
                    $sink += (int) Forumpost::isRootTopicPostNative(0);
                } else {
                    $sink += (int) CategoryPolicy::evaluateCategoryPermissionPhp('manageThreads', true, true);
                    $sink += (int) ForumPolicy::evaluateForumAdminCapabilityPhp(true, true);
                    $sink += (int) PostPolicy::canModifyPostPhp(true, false, 42, 42);
                    $sink += (int) ThreadPolicy::canModifyThreadPhp(true, false, 42, 43);
                    $sink += count(Forumpost::validateAndBuildPostAttributesPhp(12, 42, 'Re: Topic', 'Thanks for the update!', 0, 0, 0) ?? []);
                    $sink += (int) Forumpost::isRootTopicPostPhp(0);
                }
            }
        } else {
            $longMessage = str_repeat('Long forum body. ', 512);
            for ($i = 0; $i < $iterations; $i++) {
                if ($native) {
                    $sink += (int) CategoryPolicy::evaluateCategoryPermissionNative('createThreads', true, true);
                    $sink += (int) CategoryPolicy::evaluateCategoryPermissionNative('view', false, false);
                    $sink += (int) ForumPolicy::evaluateForumAdminCapabilityNative(false, false);
                    $sink += (int) PostPolicy::canModifyPostNative(true, false, '12345678901234567890', '12345678901234567890');
                    $sink += (int) ThreadPolicy::canModifyThreadNative(true, false, null, '42');
                    $sink += count(Forumpost::validateAndBuildPostAttributesNative(PHP_INT_MAX, 42, 'Long', $longMessage, 1, 1, 9) ?? []);
                    $sink += count(Forumpost::validateAndBuildPostAttributesNative(0, 42, 'Empty', '', 0, 0, 0) ?? []);
                    $sink += (int) Forumpost::isRootTopicPostNative(PHP_INT_MAX);
                } else {
                    $sink += (int) CategoryPolicy::evaluateCategoryPermissionPhp('createThreads', true, true);
                    $sink += (int) CategoryPolicy::evaluateCategoryPermissionPhp('view', false, false);
                    $sink += (int) ForumPolicy::evaluateForumAdminCapabilityPhp(false, false);
                    $sink += (int) PostPolicy::canModifyPostPhp(true, false, '12345678901234567890', '12345678901234567890');
                    $sink += (int) ThreadPolicy::canModifyThreadPhp(true, false, null, '42');
                    $sink += count(Forumpost::validateAndBuildPostAttributesPhp(PHP_INT_MAX, 42, 'Long', $longMessage, 1, 1, 9) ?? []);
                    $sink += count(Forumpost::validateAndBuildPostAttributesPhp(0, 42, 'Empty', '', 0, 0, 0) ?? []);
                    $sink += (int) Forumpost::isRootTopicPostPhp(PHP_INT_MAX);
                }
            }
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000.0;
        $this->assertGreaterThan(0, $sink);

        return $elapsed;
    }

    /**
     * @param  list<float>  $values
     */
    private function percentile(array $values, float $quantile): float
    {
        sort($values);
        $index = (int) ceil($quantile * count($values)) - 1;

        return $values[max(0, min($index, count($values) - 1))];
    }

    private function makeUser(int|string $id, bool $admin): User
    {
        $user = new User;
        $user->setRawAttributes(['id' => $id]);
        $roles = $admin ? [(new Role)->forceFill(['name' => 'Admin', 'guard_name' => 'web'])] : [];
        $user->setRelation('roles', new Collection($roles));

        return $user;
    }

    private function requireNativeLibrary(): \FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF);
        if (! $ffi instanceof \FFI) {
            $this->markTestSkipped('Rust categorize FFI library is not available.');
        }

        return $ffi;
    }
}
