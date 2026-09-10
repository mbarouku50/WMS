<?php
/**
 * WMS - Demo network provider.
 *
 * Used when a router has no API credentials, or when the system is running in
 * Demo mode.  It lets every screen and workflow be exercised end to end
 * WITHOUT inventing router telemetry: status is reported as "unknown", live
 * readings come back ok = false with an explanation, and anything it does
 * return is tagged source = 'demo'.
 *
 * The hotspot user / disconnect calls succeed locally so the business flow
 * completes, and say plainly that nothing was pushed to real hardware.
 */
class DemoNetworkProvider implements NetworkProvider
{
    private array $router;

    public function __construct(array $router = [])
    {
        $this->router = $router;
    }

    public function isLive(): bool
    {
        return false;
    }

    public function connect(): array
    {
        return ['ok' => false, 'message' => 'Demo mode: no router credentials are configured, so nothing was contacted.'];
    }

    public function disconnect(): void
    {
    }

    private function unavailable(string $what): array
    {
        return [
            'ok'      => false,
            'data'    => [],
            'message' => 'Demo mode: ' . $what . ' is only available once a router is configured in Live mode.',
            'source'  => 'demo',
        ];
    }

    public function getRouterIdentity(): array
    {
        return $this->unavailable('the router identity');
    }

    public function getRouterStatus(): array
    {
        // Deliberately "unknown" rather than a made-up "online".
        return [
            'ok'      => false,
            'status'  => 'unknown',
            'source'  => 'demo',
            'message' => 'Demo mode: this router has no live status because no API credentials are configured.',
        ];
    }

    public function getSystemResources(): array
    {
        return $this->unavailable('system resource monitoring');
    }

    public function getInterfaces(): array
    {
        return $this->unavailable('the interface list');
    }

    public function getTrafficStatistics(?string $interface = null): array
    {
        return $this->unavailable('live traffic statistics');
    }

    public function createHotspotUser(array $user): array
    {
        Logger::network('Demo mode: hotspot user not pushed to hardware', ['user' => $user['name'] ?? '']);
        return [
            'ok'      => true,
            'message' => 'Access recorded in WMS. Demo mode: no hotspot user was created on a router.',
            'source'  => 'demo',
        ];
    }

    public function updateHotspotUser(string $username, array $changes): array
    {
        return ['ok' => true, 'message' => 'Updated in WMS only (demo mode).', 'source' => 'demo'];
    }

    public function deleteHotspotUser(string $username): array
    {
        return ['ok' => true, 'message' => 'Removed in WMS only (demo mode).', 'source' => 'demo'];
    }

    public function getHotspotUsers(): array
    {
        return $this->unavailable('the router hotspot user list');
    }

    public function getActiveSessions(): array
    {
        return $this->unavailable('live router sessions');
    }

    public function getActiveSession(string $username): array
    {
        return $this->unavailable('live session lookup');
    }

    public function disconnectUser(string $username): array
    {
        return [
            'ok'      => true,
            'message' => 'Session closed in WMS. Demo mode: no router was asked to drop the connection.',
            'source'  => 'demo',
        ];
    }

    /* Provisioning helpers - a demo provider has nothing to inspect. */

    public function getHotspotServers(): array
    {
        return $this->unavailable('the hotspot server list');
    }

    public function getHotspotProfiles(): array
    {
        return $this->unavailable('the hotspot profile list');
    }

    public function getIpPools(): array
    {
        return $this->unavailable('the IP pool list');
    }

    public function diagnose(): array
    {
        return [
            'ok'     => false,
            'checks' => [[
                'key'    => 'api',
                'label'  => 'RouterOS API reachable',
                'ok'     => false,
                'detail' => 'This router has no API credentials, or is still set to Demo mode, so nothing was contacted.',
                'fix'    => null,
            ]],
            'summary' => 'Set this router to Live mode and give it API credentials, then run the check again.',
        ];
    }

    /**
     * Demo mode contacts nothing, so the test reports exactly that rather
     * than a fabricated "Connected".
     */
    public function testConnection(): array
    {
        return [
            'ok'      => false,
            'message' => 'Demo mode: no router was contacted. Switch this router to Live mode and give it API credentials to run a real test.',
            'checks'  => [[
                'key'    => 'api',
                'label'  => 'API connection',
                'ok'     => false,
                'detail' => 'Not attempted - this router is in Demo mode.',
            ]],
            'facts'   => ['identity' => null, 'version' => null, 'board' => null, 'uptime' => null,
                          'response_ms' => null, 'hotspot' => null, 'hotspots' => [], 'active_users' => null],
            'causes'  => [],
        ];
    }

    /* Access point sources - a demo provider observes no hardware at all. */

    public function getCapsmanAccessPoints(): array
    {
        return $this->unavailable('CAPsMAN access point discovery') + ['supported' => false];
    }

    public function getCapsmanClientCounts(): array
    {
        return $this->unavailable('CAPsMAN client counts') + ['supported' => false];
    }

    public function getArpTable(): array
    {
        return $this->unavailable('the router ARP table');
    }

    public function createBandwidthProfile(array $profile): array
    {
        return ['ok' => true, 'message' => 'Profile saved in WMS. Demo mode: nothing was pushed to a router.', 'source' => 'demo'];
    }

    public function updateBandwidthProfile(string $name, array $profile): array
    {
        return ['ok' => true, 'message' => 'Profile saved in WMS. Demo mode: nothing was pushed to a router.', 'source' => 'demo'];
    }
}
