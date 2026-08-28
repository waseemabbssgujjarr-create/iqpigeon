<?php

interface CourierProviderInterface
{
    public function getSlug(): string;

    public function getLabel(): string;

    /**
     * @param array<string, mixed> $settings From bot_courier_settings
     */
    public function authenticate(array $settings): bool;

    /**
     * @param array<string, mixed> $settings
     * @return array{success: bool, status?: string, title?: string, description?: string, location?: string, raw?: mixed, error?: string}
     */
    public function trackShipment(string $trackingNumber, array $settings): array;

    /**
     * Map provider-specific status string to internal shipment status key.
     */
    public function mapStatus(string $providerStatus): string;
}
