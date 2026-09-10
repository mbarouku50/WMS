<?php
/**
 * WMS - Access point monitoring contract.
 *
 * An access point is a different device from the router in front of it, so
 * "the router is up" is not evidence that the AP is up. Each monitor below
 * answers only for the APs it can genuinely observe, and says nothing about
 * the rest - the caller then leaves those as UNKNOWN.
 *
 *      AccessPointMonitor
 *          ├── RouterBasedMonitor   (implemented - MikroTik interfaces/registration)
 *          ├── SnmpMonitor          (not implemented)
 *          └── ControllerMonitor    (not implemented - UniFi / Omada / CAPsMAN)
 *
 * Adding a vendor later means adding one class here and one branch in
 * NetworkService::monitorFor(); nothing else in WMS changes.
 */
interface AccessPointMonitor
{
    /** The value stored in access_points.monitoring_source. */
    public function sourceKey(): string;

    /** Human name for the screen. */
    public function label(): string;

    /** False when this monitor cannot run right now (no credentials, etc.). */
    public function isAvailable(): bool;

    /**
     * Observes the given access point records.
     *
     * The result is keyed by access point id and contains ONLY the records
     * this monitor could actually observe. An AP that is absent from the
     * result has not been measured, and its status must be left alone or set
     * to unknown - never guessed.
     *
     * @param array $accessPoints rows from the access_points table
     * @return array<int,array{status:string,clients?:int,health?:int,detail?:string}>
     */
    public function observe(array $accessPoints): array;
}
