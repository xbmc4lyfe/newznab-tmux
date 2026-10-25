<?php

declare(strict_types=1);

namespace App\Support\Configuration;

use App\Providers\AppServiceProvider;

final readonly class ConfigurationField
{
    // Retain PHP unless complete caller mean and p99 both clear the 1.5x gate.
    public const bool RUST_FFI_DEFAULT = false;

    public static function useRustFfi(?bool $enabled): void
    {
        AppServiceProvider::w376Use(self::class, $enabled);
    }

    public static function usesRustFfi(): bool
    {
        return AppServiceProvider::w376Enabled(self::class);
    }

    public static function resetRustFfi(): void
    {
        self::useRustFfi(null);
    }

    /** @return array{control: string, rule_count: int, option_count: int, sensitive: bool, has_options: bool} */
    public static function summarizeFieldMetadata(string $control, int $ruleCount, int $optionCount, bool $sensitive): array
    {
        return (self::usesRustFfi() ? self::summarizeFieldMetadataNative($control, $ruleCount, $optionCount, $sensitive) : null)
            ?? self::summarizeFieldMetadataPhp($control, $ruleCount, $optionCount, $sensitive);
    }

    /** @return array{control: string, rule_count: int, option_count: int, sensitive: bool, has_options: bool} */
    private static function summarizeFieldMetadataPhp(string $control, int $ruleCount, int $optionCount, bool $sensitive): array
    {
        return ['control' => $control, 'rule_count' => max(0, $ruleCount), 'option_count' => max(0, $optionCount), 'sensitive' => $sensitive, 'has_options' => $optionCount > 0];
    }

    /** @return array{control: string, rule_count: int, option_count: int, sensitive: bool, has_options: bool}|null */
    private static function summarizeFieldMetadataNative(string $control, int $ruleCount, int $optionCount, bool $sensitive): ?array
    {
        $frame = AppServiceProvider::w376Frame($control);
        if ($frame === null) {
            return null;
        }
        $output = AppServiceProvider::w376Call(3, chr((int) $sensitive).pack('q2', $ruleCount, $optionCount).$frame);
        if ($output === null || strlen($output) < 22 || ord($output[16]) > 1 || ord($output[17]) > 1) {
            return null;
        }
        $control = AppServiceProvider::w376ReadString($output, 18);
        $counts = unpack('qrule/qoption', substr($output, 0, 16));
        if ($control === null || $counts === false || $counts['rule'] < 0 || $counts['option'] < 0) {
            return null;
        }

        return ['control' => $control, 'rule_count' => (int) $counts['rule'], 'option_count' => (int) $counts['option'], 'sensitive' => $output[16] === "\1", 'has_options' => $output[17] === "\1"];
    }

    /**
     * @param  list<string>  $rules
     * @param  array<int|string, string>  $options
     */
    public function __construct(
        public string $column,
        public string $label,
        public string $help,
        public string $control,
        public array $rules,
        public array $options = [],
        public ?string $unit = null,
        public bool $sensitive = false,
        public ?string $guidance = null,
    ) {}
}
