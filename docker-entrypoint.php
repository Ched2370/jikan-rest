#!/usr/bin/env php
<?php

use Dotenv\Dotenv;

require_once __DIR__.'/vendor/autoload.php';

$safe_defaults = [
    // SCOUT_DRIVER must stay ABSENT: Laravel env() converts the literal "null" to PHP null,
    // and both "" and null != "null" enable Scout. Only the config default 'null' disables it.
    // APP_URL must point to the internal RoadRunner HTTP port (8080), otherwise the
    // indexer:* commands fail silently calling http://localhost/v4/... (Connection refused).
    "APP_URL" => "http://localhost:8080",
    "SCOUT_QUEUE" => false,
    "THROTTLE" => false,
    "QUEUE_CONNECTION" => "database",
    "DB_CACHING" => true,
    "DB_HOST" => "localhost",
    "DB_PORT" => 27017,
    "DB_DATABASE" => "jikan",
    "DB_USERNAME" => "",
    "DB_PASSWORD" => ""
];

// get a copy of the current env vars.
// these are the ones that are set during the container creation
$current_env = $_ENV;

if (!file_exists(".env")) {
    copy(".env.dist", ".env");
    $writer = new \MirazMac\DotEnv\Writer(__DIR__ . '/' . '.env');

    foreach ($safe_defaults as $env_var_name => $env_var_default) {
        $writer->set($env_var_name, env($env_var_name, $env_var_default));
    }
    $writer->write();
}

// We'd like to support Container secrets. So we'll check if any of the env vars has a __FILE suffix
// then we'll try to load the file and set the env var to the contents of the file.
// https://docs.docker.com/engine/swarm/secrets/
// Additionally we need to write the secrets to the .env file so the workers in roadrunner can access them.
// (it might just pass down the global env vars, but haven't tested that yet)
$envWriter = new \MirazMac\DotEnv\Writer(__DIR__ . '/' . '.env');
$itemsWritten = 0;
foreach (array_keys($current_env) as $env_key) {
    if (!str_contains($env_key, "__FILE")) {
        continue;
    }
    if (!file_exists($current_env[$env_key])) {
        echo "Couldn't load secret: " . $_ENV[$env_key] . PHP_EOL;
        continue;
    }
    $originalKey = str_replace("__FILE", "", $env_key);
    $secretsFileContents = file_get_contents($current_env[$env_key]);
    $envWriter->set($originalKey, str_replace(["\n", "\r"], "", $secretsFileContents));
    $itemsWritten++;
}

if ($itemsWritten > 0) {
    $envWriter->write();
    echo "Secrets loaded successfully.\n";
}

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$current_env = $_ENV;

if (($current_env["SCOUT_DRIVER"] ?? null) === "typesense" && empty($current_env["TYPESENSE_API_KEY"])) {
    echo "Please set the TYPESENSE_API_KEY environment variable when setting SCOUT_DRIVER to typesense.";
    exit(1);
}

$rrConfig = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(".rr.yaml"));
$rrConfig["http"]["pool"]["num_workers"] = (int) env("RR_NUM_WORKERS", 0);
$rrConfig["http"]["pool"]["supervisor"]["max_worker_memory"] = (int) env("RR_MAX_WORKER_MEMORY", 128);
$rrConfig["http"]["max_request_size"] = (int) env("RR_MAX_REQUEST_SIZE_MB", 256);
$rrConfig["service"]["laravel_queue_worker_1"]["process_num"] = (int) env("JIKAN_QUEUE_WORKER_PROCESS_NUM", 1);
$periodical_full_indexer_key = "JIKAN_ENABLE_PERIODICAL_FULL_INDEXER";
if (array_key_exists($periodical_full_indexer_key, $current_env) && in_array($current_env[$periodical_full_indexer_key], [1, '1', 'true', 'True', 'TRUE'])) {
    $supercronic_schedule = file_get_contents("/etc/supercronic/laravel");
    $supercronic_schedule .= PHP_EOL;
    $supercronic_schedule .= "0 1 * * 1 php /app/artisan indexer:anime --fail && php /app/artisan indexer:anime --resume && php /app/artisan indexer:manga --fail && php /app/artisan indexer:manga --resume";
    $supercronic_schedule .= PHP_EOL;
    file_put_contents("/etc/supercronic/laravel", $supercronic_schedule);
    $current_time = time();
    echo json_encode(["level" => "info", "ts" => "$current_time.0", "logger" => "container_entrypoint", "msg" => "Full anime/manga indexer is enabled. They will run every Monday at 1am."]) . PHP_EOL;
}
file_put_contents(".rr.yaml", \Symfony\Component\Yaml\Yaml::dump($rrConfig, 8));
