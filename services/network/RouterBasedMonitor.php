<?php
/**
 * WMS - Access point monitoring through the parent MikroTik.
 *
 * This is the only monitor implemented today, because it is the only one the
 * current deployment can actually support: the router is already configured,
 * already reachable, and already speaks to WMS.
 *
 * What counts as evidence, in order of strength:
 *
 *   1. CAPsMAN  - if the router runs CAPsMAN and the access point is one of
 *                 its CAPs, the router knows for certain whether it is
 *                 connected, and how many clients it is carrying. This is a
 *                 direct measurement of the AP itself.
 *
 *   2. ARP      - a complete ARP entry for the AP's management address or MAC
 *                 means the AP answered the router recently. An entry that is
 *                 present but incomplete means the router asked and got no
 *                 reply, which is real evidence of an outage.
 *
 * What is NOT evidence:
 *
 *   - The router being online. They are separate devices (spec §25).
 *   - The absence of an ARP entry. Entries age out; silence is not an
 *     outage, so those access points are simply not reported here and are
 *     left UNKNOWN by the caller.
 *
 * Client counts come only from CAPsMAN. ARP cannot tell how many phones are
 * associated with a radio, so nothing is reported rather than guessed.
 */
class RouterBasedMonitor implements AccessPointMonitor
{
    private NetworkProvider $provider;
    private array $router;

    public function __construct(NetworkProvider $provider, array $router)
    {
        $this->provider = $provider;
        $this->router   = $router;
    }

    public function sourceKey(): string
    {
        return 'router';
    }

    public function label(): string
    {
        return 'Parent router';
    }

    public function isAvailable(): bool
    {
        return $this->provider->isLive();
    }

    /**
     * @inheritDoc
     */
    public function observe(array $accessPoints): array
    {
        if (!$this->isAvailable() || $accessPoints === []) {
            return [];
        }

        $observations = [];

        /* ---------------------------------------------------- 1. CAPsMAN */

        $caps = $this->provider->getCapsmanAccessPoints();
        $capsByMac = [];
        if (!empty($caps['supported']) && !empty($caps['ok'])) {
            foreach ($caps['data'] as $cap) {
                if ($cap['mac'] !== null) {
                    $capsByMac[$cap['mac']] = $cap;
                }
            }

            $clients = $this->provider->getCapsmanClientCounts();
            $clientsByMac = !empty($clients['supported']) ? $clients['data'] : [];

            foreach ($accessPoints as $ap) {
                $mac = normalise_mac($ap['mac_address'] ?? '');
                if ($mac === null) {
                    continue;
                }
                if (isset($capsByMac[$mac])) {
                    $observations[(int)$ap['id']] = [
                        'status'  => 'online',
                        'clients' => array_key_exists($mac, $clientsByMac) ? (int)$clientsByMac[$mac] : null,
                        'detail'  => 'Connected to CAPsMAN on ' . ($this->router['name'] ?? 'the router')
                                     . ($capsByMac[$mac]['identity'] !== '' ? ' as "' . $capsByMac[$mac]['identity'] . '"' : ''),
                    ];
                }
            }

            /*
             * A CAPsMAN router knows its whole fleet, so an access point that
             * WMS believes is CAPsMAN-managed and is NOT in the list is
             * genuinely disconnected. We only apply that to access points
             * that CAPsMAN has reported before - identified by their status
             * having previously come from this monitor - so a plain AP behind
             * a CAPsMAN router is not mislabelled.
             */
            foreach ($accessPoints as $ap) {
                $id  = (int)$ap['id'];
                $mac = normalise_mac($ap['mac_address'] ?? '');
                if ($mac === null || isset($observations[$id])) {
                    continue;
                }
                if (($ap['monitoring_source'] ?? '') === 'router' && ($ap['status'] ?? '') === 'online') {
                    $observations[$id] = [
                        'status'  => 'offline',
                        'clients' => null,
                        'detail'  => 'No longer connected to CAPsMAN on ' . ($this->router['name'] ?? 'the router') . '.',
                    ];
                }
            }
        }

        /* -------------------------------------------------------- 2. ARP */

        $arp = $this->provider->getArpTable();
        if (empty($arp['ok'])) {
            return $observations;
        }

        $byMac     = [];
        $byAddress = [];
        foreach ($arp['data'] as $entry) {
            if ($entry['disabled']) {
                continue;
            }
            if ($entry['mac'] !== null) {
                // A complete entry anywhere wins over an incomplete one.
                $byMac[$entry['mac']] = ($byMac[$entry['mac']] ?? false) || $entry['complete'];
            }
            if ($entry['address'] !== '') {
                $byAddress[$entry['address']] = ($byAddress[$entry['address']] ?? false) || $entry['complete'];
            }
        }

        foreach ($accessPoints as $ap) {
            $id = (int)$ap['id'];
            if (isset($observations[$id])) {
                continue;                      // CAPsMAN already answered
            }

            $mac     = normalise_mac($ap['mac_address'] ?? '');
            $address = trim((string)($ap['ip_address'] ?? ''));

            $complete = null;
            if ($mac !== null && array_key_exists($mac, $byMac)) {
                $complete = $byMac[$mac];
            } elseif ($address !== '' && array_key_exists($address, $byAddress)) {
                $complete = $byAddress[$address];
            }

            if ($complete === null) {
                continue;                      // no entry - unknown, not down
            }

            $observations[$id] = [
                'status'  => $complete ? 'online' : 'offline',
                'clients' => null,             // ARP cannot count Wi-Fi clients
                'detail'  => $complete
                    ? 'Answered the router at ' . ($address !== '' ? $address : (string)$mac) . '.'
                    : 'The router has an unresolved ARP entry for it - it is not answering.',
            ];
        }

        return $observations;
    }
}
