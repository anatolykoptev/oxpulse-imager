<?php
/**
 * Bounded history of imgproxy up/down flips (#137).
 *
 * ImgproxyHealthCache::write() is the single place the imgproxy health
 * verdict changes, so it is the single place a flip is detected and
 * recorded here — the status screen reads this log instead of keeping a
 * second health store.
 *
 * Stored in ONE non-autoloaded option, kept bounded two ways: at most
 * MAX_EVENTS flip timestamps are retained (oldest dropped), and the
 * total is a plain integer. Flips are only observed when a probe runs
 * (activation, settings save, hourly cron, version re-probe), so the
 * window figure is coarse by construction.
 *
 * @package OXPulse\Imager\Infrastructure\Imgproxy
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Imgproxy;

final class ImgproxyFlapLog
{
    public const OPTION = 'oxpulse_imgproxy_flaps';

    /** Flip timestamps retained. */
    public const MAX_EVENTS = 20;

    /** Window for the "recent" count (seconds). */
    public const WINDOW = 86400;

    /**
     * Record a flip into $to ('up'|'down') at $now.
     */
    public function record(string $to, ?int $now = null): void
    {
        if ($to !== 'up' && $to !== 'down') {
            return;
        }
        $now = $now ?? time();
        $data = $this->load();
        $data['total']++;
        $data['last'] = $now;
        $data['state'] = $to;
        $data['events'][] = $now;
        if (count($data['events']) > self::MAX_EVENTS) {
            $data['events'] = array_slice($data['events'], -self::MAX_EVENTS);
        }
        update_option(self::OPTION, $data, false);
    }

    /**
     * @return array{total:int, last:int, state:string, recent:int, recentCapped:bool}
     *   total: all flips ever recorded; last: unix time of the latest
     *   (0 = none); state: the state it flipped to; recent: retained
     *   flips within WINDOW; recentCapped: the retained history is full
     *   and still entirely inside the window, so `recent` is a floor.
     */
    public function summary(?int $now = null): array
    {
        $now = $now ?? time();
        $data = $this->load();
        $recent = 0;
        foreach ($data['events'] as $ts) {
            if ($now - $ts <= self::WINDOW) {
                $recent++;
            }
        }
        return [
            'total'        => $data['total'],
            'last'         => $data['last'],
            'state'        => $data['state'],
            'recent'       => $recent,
            'recentCapped' => $recent >= self::MAX_EVENTS,
        ];
    }

    /**
     * @return array{total:int, last:int, state:string, events:array<int,int>}
     */
    private function load(): array
    {
        $raw = get_option(self::OPTION, null);
        $out = ['total' => 0, 'last' => 0, 'state' => '', 'events' => []];
        if (!is_array($raw)) {
            return $out;
        }
        $out['total'] = max(0, (int) ($raw['total'] ?? 0));
        $out['last'] = max(0, (int) ($raw['last'] ?? 0));
        $out['state'] = ($raw['state'] ?? '') === 'down' ? 'down' : (($raw['state'] ?? '') === 'up' ? 'up' : '');
        $events = is_array($raw['events'] ?? null) ? $raw['events'] : [];
        $clean = [];
        foreach ($events as $ts) {
            if (is_int($ts) || (is_string($ts) && ctype_digit($ts))) {
                $clean[] = (int) $ts;
            }
        }
        $out['events'] = array_slice($clean, -self::MAX_EVENTS);
        return $out;
    }
}
