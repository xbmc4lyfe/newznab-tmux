<?php
// Golden output of PHP's Prometheus formatter: php <this file> <php-final checkout> > metrics.txt
require $argv[1].'/vendor/autoload.php';

use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Services\Monitoring\Prometheus\TextFormatter;

echo (new TextFormatter)->format([
    MetricFamily::gauge('nntmux_up', 'Whether the NNTmux metrics exporter ran.')->add(1),
    MetricFamily::gauge('nntmux_empty', 'No samples, so no output.'),
    MetricFamily::gauge('nntmux_help', "Help with a \\ backslash\nand a newline.")->add(0.5),
    MetricFamily::gauge('nntmux_values', 'Float rendering.')
        ->add(1.0, ['kind' => 'whole'])
        ->add(0.1234567, ['kind' => 'rounded'])
        ->add(1234567.5, ['kind' => 'large'])
        ->add(-0.0000001, ['kind' => 'negative zero'])
        ->add(NAN, ['kind' => 'nan'])
        ->add(INF, ['kind' => 'inf'])
        ->add(-INF, ['kind' => 'neg inf']),
    MetricFamily::gauge('nntmux_labels', 'Label escaping.')
        ->add(2, ['a' => 'quote " here', 'b' => 'back \\ slash', 'c' => "new\nline"]),
]);
