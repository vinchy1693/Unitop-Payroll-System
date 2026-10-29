<?php
// =====================================================================
// dimobank/state.php
// Shared state for the demo DimoBank. The JSON file is protected from direct
// HTTP access by dimobank/.htaccess.
// =====================================================================

function dimobank_state_path(): string {
    return __DIR__ . "/bank_state.json";
}

function dimobank_read_state(): array {
    $path = dimobank_state_path();
    if (!is_file($path)) {
        return ["online" => true, "updated_at" => null];
    }

    $raw = @file_get_contents($path);
    $state = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($state)) {
        return ["online" => true, "updated_at" => null];
    }

    return [
        "online" => !empty($state["online"]),
        "updated_at" => $state["updated_at"] ?? null,
    ];
}

function dimobank_set_online(bool $online): array {
    $path = dimobank_state_path();
    $fp = fopen($path, "c+");
    if ($fp === false) {
        throw new RuntimeException("DimoBank state file is not writable.");
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException("DimoBank state file could not be locked.");
        }
        rewind($fp);
        $state = [
            "online" => $online,
            "updated_at" => date("c"),
        ];
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("DimoBank state could not be encoded.");
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json . "\n");
        fflush($fp);
        flock($fp, LOCK_UN);
        return $state;
    } finally {
        fclose($fp);
    }
}

function dimobank_is_online(): bool {
    return dimobank_read_state()["online"];
}
