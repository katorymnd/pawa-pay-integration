<?php
/**
 * File: scripts/mno_and_active_conf.php
 *
 * Purpose
 * - Fetch and render pawaPay Availability and Active Configuration using V1 by default, with a surgical switch to V2.
 * - Save raw JSON responses to disk for inspection.
 * - Normalize V1 and V2 payloads into a single view model for one coherent HTML output.
 *
 * What is new in this version
 * - V2 providers show 'displayName' prominently.
 * - V2 operations include 'pinPromptInstructions' and 'pinPromptRevivable' when available.
 * - Duplicate 'pinPromptInstructions.channels' entries are de-duplicated for V2 so the UI is clean.
 * - V1 behavior remains unchanged.
 *
 * How to switch versions
 * - Set ENVIRONMENT to 'sandbox' or 'production'.
 * - Set PAWAPAY_{ENV}_API_TOKEN in your .env accordingly.
 * - Set PAWAPAY_API_VERSION to 'v1' or 'v2'. Default is 'v1' below.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Dotenv\Dotenv;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;
use Symfony\Component\Intl\Countries;
use League\ISO3166\ISO3166;

/**
 * Bootstrap, env, and client
 */
$whoops = new Run();
$whoops->pushHandler(new PrettyPageHandler());
$whoops->register();

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$environment = getenv('ENVIRONMENT') ?: 'sandbox';
$sslVerify   = $environment === 'production';
$apiVersion  = getenv('PAWAPAY_API_VERSION') ?: 'v1';

$apiTokenKey = 'PAWAPAY_' . strtoupper($environment) . '_API_TOKEN';
$apiToken    = $_ENV[$apiTokenKey] ?? null;
if (!$apiToken) {
    throw new Exception("API token not found for the selected environment");
}

/**
 * Create API client with version awareness
 */
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify, $apiVersion);

/**
 * Fetch and persist raw data
 */
try {
    // Availability - version aware
    $mnoResponse = $pawaPayClient->checkMNOAvailabilityAuto();
    if ($mnoResponse['status'] === 200) {
        ensureDataDir();
        $mnoJsonFilePath = __DIR__ . '/../data/mno_availability_' . $apiVersion . '.json';
        file_put_contents($mnoJsonFilePath, json_encode($mnoResponse['response'], JSON_PRETTY_PRINT));
        // echo "MNO availability retrieved and saved successfully. [version={$apiVersion}]\n";
    } else {
        echo "Error: Unable to retrieve MNO availability. [version={$apiVersion}]\n";
        print_r($mnoResponse);
    }

    // Active configuration - version aware
    $activeConfResponse = $pawaPayClient->checkActiveConfAuto();
    if ($activeConfResponse['status'] === 200) {
        ensureDataDir();
        $activeConfJsonFilePath = __DIR__ . '/../data/active_conf_' . $apiVersion . '.json';
        file_put_contents($activeConfJsonFilePath, json_encode($activeConfResponse['response'], JSON_PRETTY_PRINT));
        // echo "Active Configuration retrieved and saved successfully. [version={$apiVersion}]\n";
    } else {
        echo "Error: Unable to retrieve Active Configuration. [version={$apiVersion}]\n";
        print_r($activeConfResponse);
    }

    // Generate unified HTML
    $mnoData       = ($mnoResponse['status'] === 200) ? $mnoResponse['response'] : null;
    $activeConfRaw = ($activeConfResponse['status'] === 200) ? $activeConfResponse['response'] : null;

    generateHtmlOutput(
        $apiVersion,
        normalizeAvailability($apiVersion, $mnoData),
        normalizeActiveConf($apiVersion, $activeConfRaw)
    );

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

/**
 * Ensure data dir exists
 */
function ensureDataDir(): void
{
    $dir = __DIR__ . '/../data';
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

/**
 * Normalize Availability to a common structure for both versions.
 *
 * V1 expected shape example
 * [
 *   ['country'=>'ZMB','correspondents'=>[
 *       ['correspondent'=>'MTN_MOMO_ZMB','operationTypes'=>[['operationType'=>'DEPOSIT','status'=>'OPERATIONAL'], ...]],
 *   ]]
 *
 * V2 shape example
 * [
 *   ['country'=>'ZMB','providers'=>[
 *       ['provider'=>'MTN_MOMO_ZMB','operationTypes'=>['DEPOSIT'=>'OPERATIONAL','PAYOUT'=>'OPERATIONAL']],
 *   ]]
 */
function normalizeAvailability(string $apiVersion, $mnoData): array
{
    if (!$mnoData || !is_array($mnoData)) {
        return [];
    }

    $normalized = [];

    foreach ($mnoData as $countryItem) {
        $country = $countryItem['country'] ?? 'N/A';
        $providers = [];

        // V1 uses 'correspondents', V2 uses 'providers'
        $list = $countryItem['correspondents'] ?? $countryItem['providers'] ?? [];

        foreach ($list as $prov) {
            $code = $prov['correspondent'] ?? $prov['provider'] ?? 'N/A';

            $ops = [];
            // V1 availability list
            if (isset($prov['operationTypes']) && is_array($prov['operationTypes']) && array_is_list($prov['operationTypes'])) {
                foreach ($prov['operationTypes'] as $op) {
                    $ops[] = [
                        'operationType' => $op['operationType'] ?? 'UNKNOWN',
                        'status'        => $op['status'] ?? 'UNKNOWN',
                        'min'           => null,
                        'max'           => null,
                        'authType'      => null,
                        'pinPrompt'     => null,
                        'pinPromptRevivable' => null,
                        'pinPromptInstructions' => null,
                        'decimals'      => null,
                    ];
                }
            }
            // V2 availability map
            elseif (isset($prov['operationTypes']) && is_array($prov['operationTypes'])) {
                foreach ($prov['operationTypes'] as $opType => $status) {
                    if (is_string($opType) && (is_string($status) || is_null($status))) {
                        $ops[] = [
                            'operationType' => $opType,
                            'status'        => $status ?? 'UNKNOWN',
                            'min'           => null,
                            'max'           => null,
                            'authType'      => null,
                            'pinPrompt'     => null,
                            'pinPromptRevivable' => null,
                            'pinPromptInstructions' => null,
                            'decimals'      => null,
                        ];
                    } elseif (is_array($status) && isset($status['operationType'], $status['status'])) {
                        $ops[] = [
                            'operationType' => $status['operationType'],
                            'status'        => $status['status'],
                            'min'           => null,
                            'max'           => null,
                            'authType'      => null,
                            'pinPrompt'     => null,
                            'pinPromptRevivable' => null,
                            'pinPromptInstructions' => null,
                            'decimals'      => null,
                        ];
                    }
                }
            }

            $providers[] = [
                'code'        => $code,
                'displayName' => null,
                'ownerName'   => null,
                'currency'    => null,
                'logo'        => null,
                'operations'  => $ops,
            ];
        }

        $normalized[] = [
            'country'   => $country,
            'providers' => $providers,
        ];
    }

    return $normalized;
}

/**
 * Deduplicate pinPromptInstructions channels for cleanliness in V2.
 *
 * We compute a signature over type, displayName.en, quickLink, variables and the English instruction texts.
 * If identical entries repeat, we keep one.
 *
 * @param array|null $instr
 * @return array|null
 */
function dedupePinPromptInstructions(?array $instr): ?array
{
    if (!$instr || empty($instr['channels']) || !is_array($instr['channels'])) {
        return $instr;
    }

    $seen = [];
    $unique = [];

    foreach ($instr['channels'] as $ch) {
        $type = $ch['type'] ?? null;

        // normalize display name to a single string for hashing
        $dnRaw = $ch['displayName'] ?? null;
        $dn = is_array($dnRaw) ? ($dnRaw['en'] ?? json_encode($dnRaw)) : $dnRaw;

        $quick = $ch['quickLink'] ?? null;

        // variables and instruction texts
        $vars = $ch['variables'] ?? null;
        $stepsEn = [];
        if (isset($ch['instructions']['en']) && is_array($ch['instructions']['en'])) {
            foreach ($ch['instructions']['en'] as $st) {
                $stepsEn[] = $st['text'] ?? '';
            }
        }

        $signature = json_encode([
            't' => $type,
            'd' => $dn,
            'q' => $quick,
            'v' => $vars,
            's' => $stepsEn,
        ]);

        if (!isset($seen[$signature])) {
            $seen[$signature] = true;
            $unique[] = $ch;
        }
    }

    $instr['channels'] = $unique;
    return $instr;
}

/**
 * Normalize Active Configuration for both V1 and V2 into a lookup map:
 * $lookup = [
 *   '_merchantName' => '...',
 *   '_companyName'  => '...',
 *   'countries' => [
 *     'ZMB' => [
 *       'MTN_MOMO_ZMB' => [
 *         'displayName' => 'MTN',
 *         'ownerName'   => 'Name to customer',
 *         'currencies'  => ['ZMW', ...],
 *         'logo'        => 'https://...',
 *         'operations'  => [
 *           'DEPOSIT' => [
 *              'min','max','authType','pinPrompt','pinPromptRevivable','pinPromptInstructions','decimals'
 *           ],
 *           ...
 *         ]
 *       ]
 *     ]
 *   ]
 * ]
 */
function normalizeActiveConf(string $apiVersion, $activeConfData): array
{
    $lookup = [
        '_merchantName' => null,
        '_companyName'  => null,
        'countries'     => []
    ];

    if (!$activeConfData || !is_array($activeConfData)) {
        return $lookup;
    }

    // Headline ids
    $lookup['_merchantName'] = $activeConfData['merchantName'] ?? null; // V1 style
    $lookup['_companyName']  = $activeConfData['companyName']  ?? null; // V2 style

    $countries = $activeConfData['countries'] ?? [];
    foreach ($countries as $country) {
        $countryCode = $country['country'] ?? 'N/A';

        // V1 key 'correspondents', V2 key 'providers'
        $list = $country['correspondents'] ?? $country['providers'] ?? [];
        foreach ($list as $prov) {
            $code = $prov['correspondent'] ?? $prov['provider'] ?? 'N/A';

            // Names
            $displayName = $prov['displayName'] ?? null; // V2
            $ownerName   = $prov['ownerName'] ?? ($prov['nameDisplayedToCustomer'] ?? null); // V1 or V2
            $logo        = $prov['logo'] ?? null; // V2

            $currencies = [];
            $operations = [];

            if (isset($country['correspondents'])) {
                // V1
                if (!empty($prov['currency'])) {
                    $currencies[] = $prov['currency'];
                }
                $opList = $prov['operationTypes'] ?? [];
                foreach ($opList as $op) {
                    if (!empty($op['operationType'])) {
                        $operations[$op['operationType']] = [
                            'min'      => $op['minTransactionLimit'] ?? null,
                            'max'      => $op['maxTransactionLimit'] ?? null,
                            'authType' => null,
                            'pinPrompt'=> null,
                            'pinPromptRevivable' => null,
                            'pinPromptInstructions' => null,
                            'decimals' => null,
                        ];
                        continue;
                    }
                    foreach ($op as $k => $v) {
                        if (is_array($v)) {
                            $operations[$k] = [
                                'min'      => $v['minTransactionLimit'] ?? null,
                                'max'      => $v['maxTransactionLimit'] ?? null,
                                'authType' => null,
                                'pinPrompt'=> null,
                                'pinPromptRevivable' => null,
                                'pinPromptInstructions' => null,
                                'decimals' => null,
                            ];
                        }
                    }
                }
            } else {
                // V2
                $currList = $prov['currencies'] ?? [];
                foreach ($currList as $c) {
                    if (!empty($c['currency'])) {
                        $currencies[] = $c['currency'];
                    }

                    $opBlock = $c['operationTypes'] ?? [];

                    // Case A: associative map of operationType to details
                    if (is_array($opBlock) && !array_is_list($opBlock)) {
                        foreach ($opBlock as $opType => $details) {
                            if (!is_array($details)) { continue; }

                            $ppi = $details['pinPromptInstructions'] ?? null;
                            $ppi = dedupePinPromptInstructions($ppi);

                            $operations[$opType] = [
                                'min'      => $details['minAmount'] ?? $details['minTransactionLimit'] ?? null,
                                'max'      => $details['maxAmount'] ?? $details['maxTransactionLimit'] ?? null,
                                'authType' => $details['authType'] ?? null,
                                'pinPrompt'=> $details['pinPrompt'] ?? null,
                                'pinPromptRevivable'    => array_key_exists('pinPromptRevivable', $details) ? (bool)$details['pinPromptRevivable'] : null,
                                'pinPromptInstructions' => $ppi,
                                'decimals' => $details['decimalsInAmount'] ?? $details['decimals'] ?? null,
                            ];
                        }
                    }
                    // Case B: list with mixed entries
                    elseif (is_array($opBlock) && array_is_list($opBlock)) {
                        foreach ($opBlock as $entry) {
                            // {'operationType':'PAYOUT', ...}
                            if (isset($entry['operationType'])) {
                                $opType  = $entry['operationType'];
                                $ppi = $entry['pinPromptInstructions'] ?? null;
                                $ppi = dedupePinPromptInstructions($ppi);

                                $operations[$opType] = [
                                    'min'      => $entry['minAmount'] ?? $entry['minTransactionLimit'] ?? null,
                                    'max'      => $entry['maxAmount'] ?? $entry['maxTransactionLimit'] ?? null,
                                    'authType' => $entry['authType'] ?? null,
                                    'pinPrompt'=> $entry['pinPrompt'] ?? null,
                                    'pinPromptRevivable'    => array_key_exists('pinPromptRevivable', $entry) ? (bool)$entry['pinPromptRevivable'] : null,
                                    'pinPromptInstructions' => $ppi,
                                    'decimals' => $entry['decimalsInAmount'] ?? $entry['decimals'] ?? null,
                                ];
                                continue;
                            }
                            // {'DEPOSIT': {...}}
                            if (is_array($entry)) {
                                foreach ($entry as $opType => $details) {
                                    if (!is_array($details)) { continue; }

                                    $ppi = $details['pinPromptInstructions'] ?? null;
                                    $ppi = dedupePinPromptInstructions($ppi);

                                    $operations[$opType] = [
                                        'min'      => $details['minAmount'] ?? $details['minTransactionLimit'] ?? null,
                                        'max'      => $details['maxAmount'] ?? $details['maxTransactionLimit'] ?? null,
                                        'authType' => $details['authType'] ?? null,
                                        'pinPrompt'=> $details['pinPrompt'] ?? null,
                                        'pinPromptRevivable'    => array_key_exists('pinPromptRevivable', $details) ? (bool)$details['pinPromptRevivable'] : null,
                                        'pinPromptInstructions' => $ppi,
                                        'decimals' => $details['decimalsInAmount'] ?? $details['decimals'] ?? null,
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            $lookup['countries'][$countryCode][$code] = [
                'displayName' => $displayName,
                'ownerName'   => $ownerName,
                'currencies'  => array_values(array_unique($currencies)),
                'logo'        => $logo,
                'operations'  => $operations,
            ];
        }
    }

    return $lookup;
}

/**
 * HTML generator
 * - Enrich availability rows with active-conf fields where possible.
 * - For V2, show displayName, pinPromptRevivable and pinPromptInstructions when available.
 */
function generateHtmlOutput(string $apiVersion, array $availability, array $activeConfLookup): void
{
    $iso3166     = new ISO3166();
    $companyName = $activeConfLookup['_companyName'] ?? null;
    $merchantName= $activeConfLookup['_merchantName'] ?? null;
    ?>
<!DOCTYPE html>
<html>

<head>
    <title>MNO Availability and Active Configuration</title>
    <style>
    body {
        font-family: Arial, sans-serif;
        margin: 20px;
        background-color: #f7f7f9;
        opacity: 0;
        transition: opacity 0.5s ease-in-out;
    }

    h1 {
        text-align: center;
    }

    .meta {
        text-align: center;
        margin-bottom: 16px;
        color: #333;
    }

    .pill {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 999px;
        background: #eceff4;
        margin: 0 6px;
        font-size: 12px;
    }

    .section {
        background-color: #fff;
        padding: 20px;
        margin-bottom: 20px;
        border-radius: 8px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
    }

    .section h2 {
        color: #333;
        border-bottom: 2px solid #007BFF;
        padding-bottom: 10px;
    }

    .country-section {
        margin-top: 20px;
    }

    .country-name {
        font-size: 20px;
        margin-bottom: 10px;
        color: #333;
    }

    .provider {
        margin-left: 20px;
        margin-bottom: 16px;
        display: grid;
        grid-template-columns: 64px auto;
        grid-gap: 12px;
        align-items: start;
    }

    .provider .avatar {
        width: 48px;
        height: 48px;
        border-radius: 6px;
        background: #f0f3f7;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }

    .provider .avatar img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .provider-name {
        font-size: 18px;
        color: #007BFF;
    }

    .owner,
    .currency {
        font-size: 14px;
        color: #555;
    }

    .operation-list {
        margin-left: 0;
        padding-left: 18px;
    }

    .operation-item {
        font-size: 14px;
        color: #444;
        margin: 6px 0;
    }

    .separator {
        height: 1px;
        background-color: #e5e9f0;
        margin: 20px 0;
    }

    .hint {
        font-size: 12px;
        color: #666;
        margin-left: 6px;
    }

    .extras {
        font-size: 12px;
        color: #555;
        margin-left: 6px;
    }

    .instr {
        margin: 6px 0 0 0;
        padding-left: 18px;
    }

    .instr li {
        font-size: 12px;
        color: #444;
        margin: 3px 0;
    }

    .chip {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 999px;
        background: #eef3ff;
        font-size: 12px;
        color: #0a58ca;
        margin-left: 6px;
    }

    .muted {
        color: #777;
    }
    </style>
</head>

<body>
    <h1>MNO Availability and Active Configuration</h1>
    <div class="meta">
        <span class="pill">Environment: <?php echo htmlspecialchars(getenv('ENVIRONMENT') ?: 'sandbox'); ?></span>
        <span class="pill">API version: <?php echo htmlspecialchars($apiVersion); ?></span>
        <?php if ($companyName): ?><span class="pill">Company:
            <?php echo htmlspecialchars($companyName); ?></span><?php endif; ?>
        <?php if ($merchantName): ?><span class="pill">Merchant:
            <?php echo htmlspecialchars($merchantName); ?></span><?php endif; ?>
    </div>

    <?php if (!empty($availability)): ?>
    <div class="section">
        <h2>Available Providers</h2>
        <?php foreach ($availability as $countryBlock): ?>
        <?php
                $countryCodeAlpha3 = $countryBlock['country'] ?? 'N/A';
                try {
                    $countryInfo = (new ISO3166())->alpha3($countryCodeAlpha3);
                    $countryCodeAlpha2 = $countryInfo['alpha2'];
                    $countryName = Countries::getName($countryCodeAlpha2, 'en');
                } catch (\Exception $e) {
                    $countryName = $countryCodeAlpha3;
                }
            ?>
        <div class="country-section">
            <div class="country-name">Country: <?php echo htmlspecialchars($countryName); ?>
                (<?php echo htmlspecialchars($countryCodeAlpha3); ?>)</div>

            <?php foreach ($countryBlock['providers'] as $p): ?>
            <?php
                        $code = $p['code'];
                        $enriched = $activeConfLookup['countries'][$countryCodeAlpha3][$code] ?? null;

                        $displayName = $p['displayName'] ?? ($enriched['displayName'] ?? $code);
                        $ownerName   = $p['ownerName']   ?? ($enriched['ownerName']   ?? 'N/A');
                        $currency    = $p['currency']    ?? (($enriched['currencies'][0] ?? null) ?: 'N/A');
                        $logo        = $p['logo']        ?? ($enriched['logo'] ?? null);

                        // Limits and extras per operation from active-conf
                        $limits = $enriched['operations'] ?? [];

                        // Merge availability ops with limits and extras
                        $ops = [];
                        foreach ($p['operations'] as $op) {
                            $ot     = $op['operationType'];
                            $ops[] = [
                                'operationType' => $ot,
                                'status'        => $op['status'],
                                'min'           => $limits[$ot]['min'] ?? 'N/A',
                                'max'           => $limits[$ot]['max'] ?? 'N/A',
                                'authType'      => $limits[$ot]['authType'] ?? null,
                                'pinPrompt'     => $limits[$ot]['pinPrompt'] ?? null,
                                'pinPromptRevivable'    => $limits[$ot]['pinPromptRevivable'] ?? null,
                                'pinPromptInstructions' => $limits[$ot]['pinPromptInstructions'] ?? null,
                                'decimals'      => $limits[$ot]['decimals'] ?? null,
                            ];
                        }
                    ?>
            <div class="provider">
                <div class="avatar">
                    <?php if ($logo): ?>
                    <img src="<?php echo htmlspecialchars($logo); ?>"
                        alt="<?php echo htmlspecialchars($displayName); ?>">
                    <?php else: ?>
                    <span class="muted">N/A</span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="provider-name">
                        <?php echo htmlspecialchars($displayName); ?>
                        <span class="hint">(<?php echo htmlspecialchars($code); ?>)</span>
                        <?php if ($displayName && $displayName !== $code): ?>
                        <span class="chip">displayName</span>
                        <?php endif; ?>
                    </div>
                    <div class="owner">Name to customer: <?php echo htmlspecialchars($ownerName); ?></div>
                    <div class="currency">Primary currency: <?php echo htmlspecialchars($currency); ?></div>

                    <?php if (!empty($ops)): ?>
                    <ul class="operation-list">
                        <?php foreach ($ops as $row): ?>
                        <li class="operation-item">
                            Operation: <strong><?php echo htmlspecialchars($row['operationType']); ?></strong>,
                            Status: <?php echo htmlspecialchars($row['status']); ?>,
                            Min: <?php echo htmlspecialchars($row['min']); ?>,
                            Max: <?php echo htmlspecialchars($row['max']); ?>
                            <?php if ($row['authType'] || $row['pinPrompt'] || $row['decimals'] || $row['pinPromptRevivable'] !== null): ?>
                            <span class="extras">
                                <?php if ($row['authType']): ?> - Auth:
                                <?php echo htmlspecialchars($row['authType']); ?><?php endif; ?>
                                <?php if ($row['pinPrompt']): ?> - PIN:
                                <?php echo htmlspecialchars($row['pinPrompt']); ?><?php endif; ?>
                                <?php if ($row['pinPromptRevivable'] !== null): ?> - Revivable:
                                <?php echo $row['pinPromptRevivable'] ? 'Yes' : 'No'; ?><?php endif; ?>
                                <?php if ($row['decimals']): ?> - Decimals:
                                <?php echo htmlspecialchars($row['decimals']); ?><?php endif; ?>
                            </span>
                            <?php endif; ?>

                            <?php
                                            $instr = $row['pinPromptInstructions'] ?? null;
                                            if (is_array($instr) && !empty($instr['channels']) && is_array($instr['channels'])):
                                            ?>
                            <ul class="instr">
                                <?php foreach ($instr['channels'] as $ch): ?>
                                <?php
                                                            $ctype = $ch['type'] ?? 'CHANNEL';
                                                            $cname = null;
                                                            if (isset($ch['displayName'])) {
                                                                $cname = is_array($ch['displayName'])
                                                                    ? ($ch['displayName']['en'] ?? (reset($ch['displayName']) ?: null))
                                                                    : $ch['displayName'];
                                                            }
                                                            $quick = $ch['quickLink'] ?? null;

                                                            $steps = [];
                                                            if (isset($ch['instructions']) && is_array($ch['instructions'])) {
                                                                if (isset($ch['instructions']['en']) && is_array($ch['instructions']['en'])) {
                                                                    foreach ($ch['instructions']['en'] as $st) {
                                                                        if (isset($st['text'])) $steps[] = $st['text'];
                                                                    }
                                                                } else {
                                                                    $firstLang = reset($ch['instructions']);
                                                                    if (is_array($firstLang)) {
                                                                        foreach ($firstLang as $st) {
                                                                            if (isset($st['text'])) $steps[] = $st['text'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        ?>
                                <li>
                                    <strong><?php echo htmlspecialchars($ctype); ?></strong>
                                    <?php if ($cname): ?>, <?php echo htmlspecialchars($cname); ?><?php endif; ?>
                                    <?php if ($quick): ?>, quick:
                                    <code><?php echo htmlspecialchars($quick); ?></code><?php endif; ?>
                                    <?php if (!empty($steps)): ?>
                                    <ul class="instr">
                                        <?php foreach ($steps as $t): ?>
                                        <li><?php echo htmlspecialchars($t); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <div class="muted">No operations listed.</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="separator"></div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="section">
        <h2>Available Providers</h2>
        <p class="muted">No data available.</p>
    </div>
    <?php endif; ?>

    <script>
    window.addEventListener('load', function() {
        document.body.style.opacity = '1';
    });
    </script>
</body>

</html>
<?php
}

/**
 * End of file
 */