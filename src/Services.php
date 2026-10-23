<?php

namespace Bot;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use PDO;

/** Wires the routing / transcription objects from config.php (one instance per webhook request). */
final class Services
{
    public array $settings;
    public JsonStore $store;
    public ClientInterface $http;
    public ModelHealth $health;
    public ModelCatalog $catalog;
    public PaidBudget $budget;
    public RoutingLog $log;
    public Router $router;
    /** @var callable|null */
    private $alert;

    public function __construct(array $config, ?ClientInterface $http = null, ?callable $alert = null)
    {
        $this->settings = Settings::fromConfig($config);
        Redactor::registerSecrets([
            $this->settings['openrouter']['api_key'],
            $this->settings['transcription']['api_key'],
            $config['gpt']['api_key'] ?? null,
            $config['telegram']['bot_token'] ?? null,
            $config['database']['password'] ?? null,
            $config['payment']['provider_token'] ?? null,
            $config['admin']['password'] ?? null,
        ]);
        $this->alert   = $alert;
        $this->http    = $http ?? new Client();
        $this->store   = new JsonStore($this->settings['storage_dir']);
        $this->health  = new ModelHealth($this->store, $this->settings);
        $this->catalog = new ModelCatalog($this->store, $this->http, $this->settings, $this->health);
        $this->budget  = new PaidBudget($this->store, $this->settings);
        $this->log     = new RoutingLog($this->store);
        $this->router  = new Router(
            $this->settings,
            $this->catalog,
            $this->health,
            new OpenRouterClient($this->http, $this->settings),
            new PaidClient($this->http, $this->settings, $this->budget, null, null, $this->store),
            $this->log,
            $this->store,
            $alert
        );
    }

    public function entitlements(PDO $db): Entitlements
    {
        return new Entitlements($db, $this->settings['table_prefix']);
    }

    public function voice(PDO $db, string $botToken, callable $reply, ?callable $proCheck = null): VoiceTranscriber
    {
        return new VoiceTranscriber($db, $this->http, $this->settings, $this->entitlements($db), $this->store, $botToken, $reply, $this->alert, null, $proCheck);
    }

    public function statsText(int $days = 1): string
    {
        return RoutingLog::format($this->log->summary($days, $this->health->snapshot(), $this->budget->status(), $this->catalog->state()));
    }
}
