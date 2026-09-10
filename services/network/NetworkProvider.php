<?php
/**
 * WMS - Network provider contract.
 *
 * Everything the application needs from network equipment is expressed here.
 * MikroTikService talks to a real router; DemoNetworkProvider stands in when
 * no router is configured and always reports its data as simulated.
 *
 * Implementations must never throw for an unreachable device - they return a
 * result array with ok = false so pages keep working when the router is down.
 */
interface NetworkProvider
{
    /** Opens the connection. @return array{ok:bool,message:string} */
    public function connect(): array;

    public function disconnect(): void;

    /** Whether this provider talks to real equipment. */
    public function isLive(): bool;

    /** @return array{ok:bool,identity?:string,message?:string} */
    public function getRouterIdentity(): array;

    /**
     * Board/health summary.
     * @return array{ok:bool,status:string,identity?:string,version?:string,cpu_load?:int,memory_used_pct?:int,uptime?:string,active_users?:int,message?:string,source:string}
     */
    public function getRouterStatus(): array;

    /** @return array{ok:bool,data?:array,message?:string} */
    public function getSystemResources(): array;

    /** @return array{ok:bool,data:array,message?:string} interface list */
    public function getInterfaces(): array;

    /** @return array{ok:bool,data:array,message?:string} rx/tx counters */
    public function getTrafficStatistics(?string $interface = null): array;

    /* ------------------------------------------------------- hotspot users */

    /** @return array{ok:bool,message:string,id?:string} */
    public function createHotspotUser(array $user): array;

    public function updateHotspotUser(string $username, array $changes): array;

    public function deleteHotspotUser(string $username): array;

    /** @return array{ok:bool,data:array,message?:string} */
    public function getHotspotUsers(): array;

    /* ----------------------------------------------------- active sessions */

    /** @return array{ok:bool,data:array,message?:string} */
    public function getActiveSessions(): array;

    /** @return array{ok:bool,data?:array,message?:string} */
    public function getActiveSession(string $username): array;

    /** Kicks a user off the hotspot. */
    public function disconnectUser(string $username): array;

    /* ------------------------------------------------------- provisioning */

    /** @return array{ok:bool,data?:array,message?:string} hotspot servers */
    public function getHotspotServers(): array;

    /** @return array{ok:bool,data?:array,message?:string} hotspot user profiles */
    public function getHotspotProfiles(): array;

    /** @return array{ok:bool,data?:array,message?:string} IP pools */
    public function getIpPools(): array;

    /**
     * Readiness check: can this router actually grant internet access?
     * Writes a temporary probe user, so it is an explicit operator action.
     * @return array{ok:bool,checks:array,summary:string}
     */
    public function diagnose(): array;

    /**
     * Read-only connection test: reach, authenticate, identify, and confirm
     * the access WMS needs. Safe to run against production at any time.
     * @return array{ok:bool,message:string,checks:array,facts:array,causes:array}
     */
    public function testConnection(): array;

    /* ------------------------------------------------- access point sources */

    /**
     * CAPsMAN-managed access points, when the router runs CAPsMAN.
     * `supported` is false when it does not - which is not the same as an
     * empty fleet, and callers must not read it as "every AP is down".
     * @return array{ok:bool,data:array,supported:bool,message?:string}
     */
    public function getCapsmanAccessPoints(): array;

    /** Client counts per CAPsMAN access point, keyed by AP MAC. */
    public function getCapsmanClientCounts(): array;

    /** The router's ARP table - evidence that a device answered recently. */
    public function getArpTable(): array;

    /* -------------------------------------------------- bandwidth profiles */

    public function createBandwidthProfile(array $profile): array;

    public function updateBandwidthProfile(string $name, array $profile): array;
}
