<?php

require_once __DIR__ . '/CourierProviderInterface.php';

abstract class AbstractCourierProvider implements CourierProviderInterface
{
    public function authenticate(array $settings): bool
    {
        return !empty($settings['api_enabled'])
            && (
                trim((string) ($settings['api_key'] ?? '')) !== ''
                || (trim((string) ($settings['api_username'] ?? '')) !== '' && trim((string) ($settings['api_password'] ?? '')) !== '')
            );
    }

    public function trackShipment(string $trackingNumber, array $settings): array
    {
        if (!$this->authenticate($settings)) {
            return ['success' => false, 'error' => 'Courier API credentials not configured'];
        }

        return $this->fetchTracking($trackingNumber, $settings);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{success: bool, status?: string, title?: string, description?: string, location?: string, raw?: mixed, error?: string}
     */
    abstract protected function fetchTracking(string $trackingNumber, array $settings): array;

    public function mapStatus(string $providerStatus): string
    {
        $s = mb_strtolower(trim($providerStatus));

        if (str_contains($s, 'deliver') && !str_contains($s, 'out for')) {
            return str_contains($s, 'fail') ? 'failed_delivery' : 'delivered';
        }
        if (str_contains($s, 'out for')) {
            return 'out_for_delivery';
        }
        if (str_contains($s, 'hub') || str_contains($s, 'facility') || str_contains($s, 'arrived')) {
            return 'arrived_at_hub';
        }
        if (str_contains($s, 'transit') || str_contains($s, 'on the way')) {
            return 'in_transit';
        }
        if (str_contains($s, 'pick') || str_contains($s, 'collect')) {
            return 'picked_up';
        }
        if (str_contains($s, 'return')) {
            return 'returned';
        }

        return 'in_transit';
    }
}

/** Stub — wire real API when credentials available. */
class LeopardsProvider extends AbstractCourierProvider
{
    public function getSlug(): string { return 'leopards'; }
    public function getLabel(): string { return 'Leopards Courier'; }

    protected function fetchTracking(string $trackingNumber, array $settings): array
    {
        return ['success' => false, 'error' => 'Leopards API sync pending — use manual updates for now'];
    }
}

class TCSProvider extends AbstractCourierProvider
{
    public function getSlug(): string { return 'tcs'; }
    public function getLabel(): string { return 'TCS'; }

    protected function fetchTracking(string $trackingNumber, array $settings): array
    {
        return ['success' => false, 'error' => 'TCS API sync pending — use manual updates for now'];
    }
}

class BlueExProvider extends AbstractCourierProvider
{
    public function getSlug(): string { return 'blueex'; }
    public function getLabel(): string { return 'BlueEx'; }

    protected function fetchTracking(string $trackingNumber, array $settings): array
    {
        return ['success' => false, 'error' => 'BlueEx API sync pending — use manual updates for now'];
    }
}

class TraxProvider extends AbstractCourierProvider
{
    public function getSlug(): string { return 'trax'; }
    public function getLabel(): string { return 'Trax'; }

    protected function fetchTracking(string $trackingNumber, array $settings): array
    {
        return ['success' => false, 'error' => 'Trax API sync pending — use manual updates for now'];
    }
}

class MandPProvider extends AbstractCourierProvider
{
    public function getSlug(): string { return 'mandp'; }
    public function getLabel(): string { return 'M&P Courier'; }

    protected function fetchTracking(string $trackingNumber, array $settings): array
    {
        return ['success' => false, 'error' => 'M&P API sync pending — use manual updates for now'];
    }
}

class DHLProvider extends AbstractCourierProvider
{
    public function getSlug(): string { return 'dhl'; }
    public function getLabel(): string { return 'DHL'; }

    protected function fetchTracking(string $trackingNumber, array $settings): array
    {
        return ['success' => false, 'error' => 'DHL API sync pending — use manual updates for now'];
    }
}

class FedExProvider extends AbstractCourierProvider
{
    public function getSlug(): string { return 'fedex'; }
    public function getLabel(): string { return 'FedEx'; }

    protected function fetchTracking(string $trackingNumber, array $settings): array
    {
        return ['success' => false, 'error' => 'FedEx API sync pending — use manual updates for now'];
    }
}

class UPSProvider extends AbstractCourierProvider
{
    public function getSlug(): string { return 'ups'; }
    public function getLabel(): string { return 'UPS'; }

    protected function fetchTracking(string $trackingNumber, array $settings): array
    {
        return ['success' => false, 'error' => 'UPS API sync pending — use manual updates for now'];
    }
}

class ManualCourierProvider extends AbstractCourierProvider
{
    public function getSlug(): string { return 'manual'; }
    public function getLabel(): string { return 'Manual (no API)'; }

    public function authenticate(array $settings): bool
    {
        return false;
    }

    protected function fetchTracking(string $trackingNumber, array $settings): array
    {
        return ['success' => false, 'error' => 'Manual mode — update status in dashboard'];
    }
}

function courier_provider_registry(): array
{
    static $providers = null;
    if ($providers !== null) {
        return $providers;
    }

    $providers = [
        'manual'   => new ManualCourierProvider(),
        'leopards' => new LeopardsProvider(),
        'tcs'      => new TCSProvider(),
        'blueex'   => new BlueExProvider(),
        'trax'     => new TraxProvider(),
        'mandp'    => new MandPProvider(),
        'dhl'      => new DHLProvider(),
        'fedex'    => new FedExProvider(),
        'ups'      => new UPSProvider(),
    ];

    return $providers;
}

function courier_provider(string $slug): ?CourierProviderInterface
{
    return courier_provider_registry()[$slug] ?? null;
}

function courier_provider_options(): array
{
    $out = [];
    foreach (courier_provider_registry() as $slug => $provider) {
        if ($slug === 'manual') {
            continue;
        }
        $out[$slug] = $provider->getLabel();
    }
    return $out;
}

function courier_manual_presets(): array
{
    return [
        'Leopards' => 'Leopards',
        'TCS'      => 'TCS',
        'BlueEx'   => 'BlueEx',
        'Trax'     => 'Trax',
        'M&P'      => 'M&P',
        'PostEx'   => 'PostEx',
        'DHL'      => 'DHL',
        'FedEx'    => 'FedEx',
        'UPS'      => 'UPS',
        'Other'    => 'Other',
    ];
}
