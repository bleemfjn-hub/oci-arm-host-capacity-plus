<?php
declare(strict_types=1);


// useful when script is being executed by cron user
$pathPrefix = ''; // e.g. /usr/share/nginx/oci-arm-host-capacity/

require "{$pathPrefix}vendor/autoload.php";

use Dotenv\Dotenv;
use Hitrov\Exception\ApiCallException;
use Hitrov\Exception\TooManyRequestsWaiterException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

/*
 * No need to modify any value in this file anymore!
 * Copy .env.example to .env and adjust there instead.
 *
 * README.md now has all the information.
 */
$config = new OciConfig(
    getenv('OCI_REGION'),
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_KEY_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY_FILENAME'),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null, // null or '' or 'jYtI:PHX-AD-1' or ['jYtI:PHX-AD-1','jYtI:PHX-AD-2']
    getenv('OCI_SUBNET_ID'),
    getenv('OCI_IMAGE_ID'),
    (int) getenv('OCI_OCPUS'),
    (int) getenv('OCI_MEMORY_IN_GBS')
);

$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

$api = new OciApi();
if (getenv('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}
if (getenv('TOO_MANY_REQUESTS_TIME_WAIT')) {
    $api->setWaiter(new TooManyRequestsWaiter((int) getenv('TOO_MANY_REQUESTS_TIME_WAIT')));
}
$notifier = (function (): \Hitrov\Interfaces\NotifierInterface {
    /*
     * if you have own https://core.telegram.org/bots
     * and set TELEGRAM_BOT_API_KEY and your TELEGRAM_USER_ID in .env
     *
     * then you can get notified when script will succeed.
     * otherwise - don't mind OR develop you own NotifierInterface
     * to e.g. send SMS or email.
     */
    return new \Hitrov\Notification\Telegram();
})();

$shape = getenv('OCI_SHAPE');

$maxRunningInstancesOfThatShape = 1;
if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape = (int) getenv('OCI_MAX_INSTANCES');
}

$instances = $api->getInstances($config);

$existingInstances = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);
if ($existingInstances) {
    echo "$existingInstances\n";
    return;
}

if (!empty($config->availabilityDomains)) {
    if (is_array($config->availabilityDomains)) {
        $availabilityDomains = $config->availabilityDomains;
    } else {
        $availabilityDomains = [ $config->availabilityDomains ];
    }
} else {
    $availabilityDomains = $api->getAvailabilityDomains($config);
}

foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
    try {
        $instanceDetails = $api->createInstance($config, $shape, getenv('OCI_SSH_PUBLIC_KEY'), $availabilityDomain);
    } catch(TooManyRequestsWaiterException $e) {
        echo "429 backoff active: " . $e->getMessage() . "\n";
        return;
    } catch(ApiCallException $e) {
        $message = $e->getMessage();
        echo "$message\n";
//            if ($notifier->isSupported()) {
//                $notifier->notify($message);
//            }

        if (
            $e->getCode() === 500 &&
            strpos($message, 'InternalError') !== false &&
            strpos($message, 'Out of host capacity') !== false
        ) {
            // trying next availability domain
            sleep(16);
            continue;
        }

        // current config is broken
        return;
    }

    // success
    $instanceId = $instanceDetails['id'] ?? '';
    $publicIp = '';
    if ($instanceId) {
        try {
            $vnics = $api->getInstanceVnics($config, $instanceId);
            if (isset($vnics['data']) && is_array($vnics['data'])) {
                foreach ($vnics['data'] as $vnic) {
                    if (!empty($vnic['publicIp'])) {
                        $publicIp = $vnic['publicIp'];
                        break;
                    }
                }
            } elseif (!empty($vnics['publicIp'])) {
                $publicIp = $vnics['publicIp'];
            }
        } catch (Exception $e) {
            // ignore, public IP is best-effort
        }
    }
    $displayName = $instanceDetails['displayName'] ?? '';
    $shape = $instanceDetails['shape'] ?? '';
    $ocpus = $instanceDetails['shapeConfig']['ocpus'] ?? '';
    $memoryInGBs = $instanceDetails['shapeConfig']['memoryInGBs'] ?? '';
    $lifecycleState = $instanceDetails['lifecycleState'] ?? '';
    $availabilityDomain = $instanceDetails['availabilityDomain'] ?? '';
    $timeCreated = $instanceDetails['timeCreated'] ?? '';
    $message = "🎉 抢到 ARM 实例了！\n"
        . "─────────────────\n"
        . "📌 实例名: " . $displayName . "\n"
        . "🆔 OCID: " . $instanceId . "\n"
        . "📐 规格: " . $shape . " (" . $ocpus . " OCPU / " . $memoryInGBs . "GB)\n"
        . "📍 可用域: " . $availabilityDomain . "\n"
        . "🟢 状态: " . $lifecycleState . "\n"
        . "🌐 公网IP: " . ($publicIp ?: "待绑定") . "\n"
        . "🕐 创建时间: " . $timeCreated . "\n"
        . "🔑 SSH: ssh -i <your_key> ubuntu@" . ($publicIp ?: "<IP>") . "\n";
    echo $message . "\n";
    if ($notifier->isSupported()) {
        $notifier->notify($message);
    }

    // ---- optional auto-resize (grab small, then grow) ----
    $targetOcpus = (int) getenv('OCI_RESIZE_TARGET_OCPUS');
    $targetMemory = (int) getenv('OCI_RESIZE_TARGET_MEMORY_IN_GBS');
    if ($instanceId && $targetOcpus > 0 && $targetMemory > 0) {
        $currentOcpus = (int) ($instanceDetails['shapeConfig']['ocpus'] ?? 0);
        $currentMemory = (int) ($instanceDetails['shapeConfig']['memoryInGBs'] ?? 0);
        if ($currentOcpus >= $targetOcpus && $currentMemory >= $targetMemory) {
            echo "resize: already at target ({$currentOcpus} OCPU / {$currentMemory} GB), skip\n";
        } else {
            echo "resize: trying {$currentOcpus}/{$currentMemory} -> {$targetOcpus}/{$targetMemory} ...\n";
            try {
                $resizeResult = $api->updateInstanceShape($config, $instanceId, $targetOcpus, $targetMemory);
                $newOcpus = $resizeResult['shapeConfig']['ocpus'] ?? $targetOcpus;
                $newMemory = $resizeResult['shapeConfig']['memoryInGBs'] ?? $targetMemory;
                $resizeMsg = "⬆️ 实例已升级！\n"
                    . "─────────────────\n"
                    . "📌 实例名: " . $displayName . "\n"
                    . "📐 新规格: " . $newOcpus . " OCPU / " . $newMemory . "GB\n"
                    . "🌐 公网IP: " . ($publicIp ?: "待绑定") . "\n"
                    . "🔑 SSH: ssh -i <your_key> ubuntu@" . ($publicIp ?: "<IP>") . "\n";
                echo $resizeMsg . "\n";
                if ($notifier->isSupported()) {
                    $notifier->notify($resizeMsg);
                }
            } catch (ApiCallException $e) {
                $resizeErr = $e->getMessage();
                echo "resize: failed (instance stays at {$currentOcpus}/{$currentMemory}) - " . $resizeErr . "\n";
                if ($notifier->isSupported()) {
                    $notifier->notify("⚠️ 实例已抢到（{$currentOcpus} OCPU / {$currentMemory}GB），但升级到 {$targetOcpus}/{$targetMemory} 失败：\n" . $resizeErr . "\n\n实例可正常使用，稍后可重试升级。");
                }
            }
        }
    }

    return;
}
