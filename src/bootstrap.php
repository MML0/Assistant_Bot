<?php
// Loads the AI routing + voice transcription layer (no Composer autoload changes needed).

require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/JsonStore.php';
require_once __DIR__ . '/Redactor.php';
require_once __DIR__ . '/ErrorClassifier.php';
require_once __DIR__ . '/ModelHealth.php';
require_once __DIR__ . '/ModelCatalog.php';
require_once __DIR__ . '/TaskClassifier.php';
require_once __DIR__ . '/ContextFitter.php';
require_once __DIR__ . '/OpenRouterClient.php';
require_once __DIR__ . '/PaidBudget.php';
require_once __DIR__ . '/PaidClient.php';
require_once __DIR__ . '/RoutingLog.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/UpdateDeduper.php';
require_once __DIR__ . '/Entitlements.php';
require_once __DIR__ . '/VoiceTranscriber.php';
require_once __DIR__ . '/Services.php';
