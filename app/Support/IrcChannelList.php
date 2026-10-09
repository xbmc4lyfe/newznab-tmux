<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parses SCRAPE_IRC_CHANNELS ("#chan1,#chan2:password") into the [channel => password|null]
 * map the IRC scraper joins. A missing leading "#" is added.
 */
final class IrcChannelList
{
    /**
     * @return array<string, string|null>
     */
    public static function parse(?string $value, string $default = '#PreNNTmux'): array
    {
        $channels = [];

        foreach (explode(',', (string) $value) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }

            [$name, $password] = array_pad(explode(':', $item, 2), 2, null);
            $name = trim($name);
            if ($name === '' || $name === '#') {
                continue;
            }

            $channels[str_starts_with($name, '#') ? $name : '#'.$name] = ($password === null || trim($password) === '') ? null : trim($password);
        }

        return $channels === [] ? [$default => null] : $channels;
    }
}
